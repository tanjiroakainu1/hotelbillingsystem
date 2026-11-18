<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

final class AppointmentsApi
{
    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function validateStayDates(string $checkIn, string $checkOut): void
    {
        if (!$this->validDate($checkIn) || !$this->validDate($checkOut)
            || $checkIn < date('Y-m-d') || $checkOut <= $checkIn) {
            api_error('Choose a future check-in date and a later check-out date.');
        }
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }

    private function validateAppointmentDateTimes(string $checkIn, string $checkInTime, string $checkOut, string $checkOutTime): void
    {
        $this->validateStayDates($checkIn, $checkOut);
        foreach ([$checkInTime, $checkOutTime] as $time) {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) {
                api_error('Choose valid check-in and check-out times.');
            }
        }
        if (!is_hotel_check_in_time($checkInTime) || !is_hotel_check_out_time($checkOutTime)) {
            api_error('Check-in must be between 3:00 and 3:59 PM, and check-out is fixed at 2:00 PM.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $checkIn . ' ' . $this->normalizeTime($checkInTime));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $checkOut . ' ' . $this->normalizeTime($checkOutTime));
        if (!$start || !$end || $start <= new DateTimeImmutable() || $end <= $start) {
            api_error('Choose a future check-in time and a later check-out time.');
        }
    }

    private function availableRooms(string $checkIn, string $checkOut, int $roomTypeId, int $excludeAppointmentId = 0): array
    {
        $this->validateStayDates($checkIn, $checkOut);
        $rooms = (new RoomModel(api_conn()))->availableForDates($checkIn, $checkOut, $excludeAppointmentId);
        $rooms = array_values(array_filter(
            $rooms,
            static fn(array $room): bool => (int) ($room['room_type_id'] ?? 0) === $roomTypeId
        ));
        return $rooms;
    }

    private function lockRoomType(mysqli $conn, int $roomTypeId): void
    {
        $stmt = $conn->prepare('SELECT id FROM rooms WHERE room_type_id = ? ORDER BY id FOR UPDATE');
        $stmt->bind_param('i', $roomTypeId);
        $stmt->execute();
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function lockRooms(mysqli $conn, array $roomIds): void
    {
        $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds), static fn(int $id): bool => $id > 0)));
        if (!$roomIds) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
        $stmt = $conn->prepare("SELECT id FROM rooms WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
        $params = [str_repeat('i', count($roomIds))];
        foreach ($roomIds as $index => &$roomId) {
            $params[] = &$roomId;
        }
        unset($roomId);
        call_user_func_array([$stmt, 'bind_param'], $params);
        $stmt->execute();
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function assignAppointmentRooms(mysqli $conn, int $appointmentId, array $availableRooms, int $roomCount): void
    {
        $delete = $conn->prepare('DELETE FROM room_appointment_rooms WHERE appointment_id = ?');
        $delete->bind_param('i', $appointmentId);
        $delete->execute();
        $insert = $conn->prepare('INSERT INTO room_appointment_rooms (appointment_id, room_id) VALUES (?, ?)');
        foreach (array_slice($availableRooms, 0, $roomCount) as $room) {
            $roomId = (int) $room['id'];
            $insert->bind_param('ii', $appointmentId, $roomId);
            $insert->execute();
        }
    }

    private function validateCapacity(array $rooms, int $roomCount, int $adults, int $children): void
    {
        if ($roomCount < 1 || $roomCount > count($rooms)) {
            api_error('That room type does not have enough rooms available for those dates.');
        }
        $maxAdults = max(array_map(static fn(array $room): int => (int) $room['max_adults'], $rooms));
        $maxChildren = max(array_map(static fn(array $room): int => (int) $room['max_children'], $rooms));
        if ($adults < 1 || $adults > $maxAdults * $roomCount || $children < 0 || $children > $maxChildren * $roomCount) {
            api_error('The guest count is above the selected rooms’ capacity.');
        }
    }

    private function selectedRoomIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            api_error('Select available room(s) for the appointment.');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $value),
            static fn(int $id): bool => $id > 0
        )));
        if (count($ids) !== count($value) || !$ids) {
            api_error('Select each available room only once.');
        }
        return $ids;
    }

    private function validateSelectedRooms(array $availableRooms, array $roomIds, int $adults, int $children): array
    {
        $availableById = [];
        foreach ($availableRooms as $room) {
            $availableById[(int) $room['id']] = $room;
        }
        $selected = [];
        $adultCapacity = 0;
        $childCapacity = 0;
        foreach ($roomIds as $roomId) {
            if (!isset($availableById[$roomId])) {
                api_error('A selected room is no longer available. Refresh availability and choose again.');
            }
            $room = $availableById[$roomId];
            $selected[] = $room;
            $adultCapacity += (int) $room['max_adults'];
            $childCapacity += (int) $room['max_children'];
        }
        if ($adults < count($selected) || $adults > $adultCapacity || $children < 0 || $children > $childCapacity) {
            api_error('The selected rooms do not fit the total adults and children. Change the room selection.');
        }
        return $selected;
    }

    private function appointmentNameExists(mysqli $conn, string $firstName, string $lastName, string $fullName): bool
    {
        $stmt = $conn->prepare(
            "SELECT 1 FROM guests
             WHERE (LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?))
                OR LOWER(TRIM(full_name)) = LOWER(?)
             UNION ALL
             SELECT 1 FROM room_appointments
             WHERE (LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?))
                OR LOWER(TRIM(full_name)) = LOWER(?)
             LIMIT 1"
        );
        $stmt->bind_param('ssssss', $firstName, $lastName, $fullName, $firstName, $lastName, $fullName);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function getPublicAvailability(array $data): void
    {
        $checkIn = trim((string) ($data['check_in'] ?? ''));
        $checkOut = trim((string) ($data['check_out'] ?? ''));
        $this->validateStayDates($checkIn, $checkOut);
        $excludeAppointmentId = 0;
        $code = strtoupper(trim((string) ($data['appointment_code'] ?? '')));
        if ($code !== '' && preg_match('/^[A-F0-9]{16}$/', $code)) {
            $appointmentStmt = api_conn()->prepare('SELECT id FROM room_appointments WHERE appointment_code = ?');
            $appointmentStmt->bind_param('s', $code);
            $appointmentStmt->execute();
            $excludeAppointmentId = (int) ($appointmentStmt->get_result()->fetch_assoc()['id'] ?? 0);
        }
        $rooms = (new RoomModel(api_conn()))->availableForDates($checkIn, $checkOut, $excludeAppointmentId);
        $activeTypes = [];
        foreach (api_conn()->query("SELECT id, name, description FROM room_types WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC) as $type) {
            $activeTypes[(int) $type['id']] = $type;
        }
        $roomTypeIds = [];
        foreach ($rooms as $room) {
            $id = (int) ($room['room_type_id'] ?? 0);
            if ($id > 0 && isset($activeTypes[$id])) {
                $roomTypeIds[$id] = true;
            }
        }
        $grouped = [];
        $roomCards = [];
        foreach (array_keys($roomTypeIds) as $id) {
            if (!isset($activeTypes[(int) $id])) {
                continue;
            }
            $available = $this->availableRooms($checkIn, $checkOut, (int) $id, $excludeAppointmentId);
            foreach ($available as $room) {
                if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'room_type_id' => $id,
                    'room_type' => $activeTypes[(int) $id]['name'],
                    'description' => $activeTypes[(int) $id]['description'] ?? '',
                    'available_rooms' => 0,
                    'rate_from' => (float) $room['rate_per_night'],
                    'max_adults_per_room' => (int) $room['max_adults'],
                    'max_children_per_room' => (int) $room['max_children'],
                    'room_features' => (string) ($room['room_features'] ?? ''),
                    'photo_url' => room_photo_url($room['photo'] ?? null, (string) $activeTypes[(int) $id]['name']),
                ];
                }
                $grouped[$id]['available_rooms']++;
                $grouped[$id]['rate_from'] = min($grouped[$id]['rate_from'], (float) $room['rate_per_night']);
                $grouped[$id]['max_adults_per_room'] = max($grouped[$id]['max_adults_per_room'], (int) $room['max_adults']);
                $grouped[$id]['max_children_per_room'] = max($grouped[$id]['max_children_per_room'], (int) $room['max_children']);
                $roomCards[] = [
                    'id' => (int) $room['id'],
                    'room_number' => (string) $room['room_number'],
                    'room_type_id' => $id,
                    'room_type' => (string) $room['room_type'],
                    'rate_per_night' => (float) $room['rate_per_night'],
                    'max_adults' => (int) $room['max_adults'],
                    'max_children' => (int) $room['max_children'],
                    'room_features' => (string) ($room['room_features'] ?? ''),
                    'photo_url' => room_photo_url($room['photo'] ?? null, (string) $room['room_type']),
                ];
            }
        }
        api_ok(['room_types' => array_values($grouped), 'rooms' => $roomCards]);
    }

    public function createPublicAppointment(array $data): void
    {
        $conn = api_conn();
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $firstName = preg_replace('/\s+/u', ' ', $firstName) ?? $firstName;
        $lastName = preg_replace('/\s+/u', ' ', $lastName) ?? $lastName;
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $checkIn = trim((string) ($data['check_in'] ?? ''));
        $checkInTime = $this->normalizeTime(trim((string) ($data['check_in_time'] ?? HOTEL_CHECK_IN_TIME)));
        $checkOut = trim((string) ($data['check_out'] ?? ''));
        $checkOutTime = $this->normalizeTime(trim((string) ($data['check_out_time'] ?? HOTEL_CHECK_OUT_TIME)));
        $roomIds = $this->selectedRoomIds($data['room_ids'] ?? []);
        $adults = (int) ($data['adults'] ?? 1);
        $children = (int) ($data['children'] ?? 0);
        $notes = trim((string) ($data['notes'] ?? ''));
        $paymentMethod = (string) ($data['payment_method'] ?? '');
        $paymentReference = trim((string) ($data['payment_reference'] ?? ''));

        if (!valid_appointment_name($firstName) || !valid_appointment_name($lastName)) {
            api_error('First and last names must contain letters only, with spaces, hyphens, apostrophes, or periods between name parts (up to 120 characters each).');
        }
        $fullName = trim($firstName . ' ' . $lastName);
        if (preg_match('/^.{1,241}$/us', $fullName) !== 1) {
            api_error('The combined first and last name must be 241 characters or fewer.');
        }
        if ($this->appointmentNameExists($conn, $firstName, $lastName, $fullName)) {
            api_error('A guest with this name is already in our system. Please use another name for your appointment.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150 || !preg_match('/@gmail\.com$/i', $email) || $phone === '' || strlen($phone) > 40) {
            api_error('Enter a valid Gmail address and phone number. Appointment guest accounts require Gmail.');
        }
        $email = strtolower($email);
        $cooldownStmt = $conn->prepare(
            "SELECT GREATEST(0, 300 - TIMESTAMPDIFF(SECOND, created_at, NOW())) AS retry_after_seconds
             FROM room_appointments
             WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $cooldownStmt->bind_param('s', $email);
        $cooldownStmt->execute();
        $cooldown = $cooldownStmt->get_result()->fetch_assoc();
        if ($cooldown) {
            api_error(
                'Please wait 5 minutes after your last appointment request before submitting another request with this email.',
                429,
                ['retry_after_seconds' => (int) $cooldown['retry_after_seconds']]
            );
        }
        if (strlen($notes) > 2000) {
            api_error('Notes must be 2,000 characters or fewer.');
        }
        if (!in_array($paymentMethod, ['gcash', 'bank_transfer'], true)
            || $paymentReference === '' || strlen($paymentReference) > 80) {
            api_error('Choose GCash or bank transfer and enter a valid transfer reference.');
        }
        $this->validateAppointmentDateTimes($checkIn, $checkInTime, $checkOut, $checkOutTime);
        $availableRooms = (new RoomModel($conn))->availableForDates($checkIn, $checkOut);
        $selectedRooms = $this->validateSelectedRooms($availableRooms, $roomIds, $adults, $children);
        $nights = (int) (new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days;
        $estimatedTotal = round(array_sum(array_map(
            static fn(array $room): float => (float) $room['rate_per_night'],
            $selectedRooms
        )) * $nights, 2);
        $depositAmount = round($estimatedTotal * 0.25, 2);
        if ($nights < 1 || $estimatedTotal <= 0 || $depositAmount <= 0) {
            api_error('The selected rooms do not have a valid estimated stay price.');
        }
        $selectedTypeIds = array_values(array_unique(array_map(
            static fn(array $room): int => (int) $room['room_type_id'],
            $selectedRooms
        )));
        $selectedTypeNames = array_values(array_unique(array_map(
            static fn(array $room): string => (string) $room['room_type'],
            $selectedRooms
        )));
        $roomTypeId = $selectedTypeIds[0];
        $roomCount = count($selectedRooms);
        $roomTypeName = implode(', ', $selectedTypeNames);
        $stmt = $conn->prepare(
            "INSERT INTO room_appointments
                (appointment_code, first_name, last_name, full_name, email, phone, room_type_id, room_type_name,
                 room_count, adults, children, check_in, check_in_time, check_out, check_out_time, notes,
                 estimated_total, deposit_amount, payment_method, payment_reference, payment_status, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')"
        );
        $code = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = strtoupper(bin2hex(random_bytes(8)));
            $conn->begin_transaction();
            try {
                $stmt->bind_param(
                    'ssssssisiiisssssddss',
                    $code, $firstName, $lastName, $fullName, $email, $phone, $roomTypeId,
                    $roomTypeName, $roomCount, $adults, $children, $checkIn, $checkInTime, $checkOut, $checkOutTime,
                    $notes, $estimatedTotal, $depositAmount, $paymentMethod, $paymentReference
                );
                $stmt->execute();
                $appointmentId = (int) $conn->insert_id;
                $roomInsert = $conn->prepare('INSERT INTO room_appointment_rooms (appointment_id, room_id) VALUES (?, ?)');
                foreach ($roomIds as $roomId) {
                    $roomInsert->bind_param('ii', $appointmentId, $roomId);
                    $roomInsert->execute();
                }
                $conn->commit();
                api_ok([
                    'appointment_code' => $code,
                    'estimated_total' => $estimatedTotal,
                    'deposit_amount' => $depositAmount,
                    'payment_method' => $paymentMethod,
                    'payment_status' => 'pending',
                ], 'Appointment request and transfer reference submitted for staff review.');
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                if ($exception->getCode() !== 1062 || $attempt === 4) {
                    throw $exception;
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                throw $exception;
            }
        }
        api_error('Could not create an appointment code. Please try again.', 500);
    }

    public function getPublicAppointment(array $data): void
    {
        $code = strtoupper(trim((string) ($data['appointment_code'] ?? '')));
        if (!preg_match('/^[A-F0-9]{16}$/', $code)) {
            api_error('Enter the 16-character appointment code.');
        }
        $conn = api_conn();
        $stmt = $conn->prepare(
            "SELECT a.id, a.appointment_code, a.first_name, a.last_name, a.email, a.phone,
                    a.room_type_id, a.room_type_name, a.room_count, a.adults, a.children,
                    a.check_in, a.check_in_time, a.check_out, a.check_out_time, a.notes,
                    a.estimated_total, a.deposit_amount, a.payment_method, a.payment_reference,
                    a.payment_status, a.payment_review_note, a.payment_reviewed_at,
                    CASE WHEN a.account_user_id IS NOT NULL THEN 1 ELSE 0 END AS account_linked,
                    a.converted_at,
                    a.status, a.review_note, a.reviewed_at, a.created_at,
                    reviewer.full_name AS reviewed_by_name,
                    payment_reviewer.full_name AS payment_reviewed_by_name,
                    (SELECT GROUP_CONCAT(r.room_number ORDER BY r.room_number SEPARATOR ', ')
                     FROM room_appointment_rooms ar JOIN rooms r ON r.id = ar.room_id
                     WHERE ar.appointment_id = a.id) AS assigned_room_numbers,
                    (SELECT r.photo FROM rooms r
                     WHERE r.room_type_id = a.room_type_id AND r.status <> 'maintenance'
                     ORDER BY r.room_number LIMIT 1) AS room_photo,
                    c.id AS change_id, c.change_type, c.proposed_room_type_id, c.proposed_room_type_name,
                    c.proposed_room_count, c.proposed_adults, c.proposed_children,
                    c.proposed_check_in, c.proposed_check_in_time, c.proposed_check_out, c.proposed_check_out_time,
                    c.current_room_type_name, c.current_room_count, c.current_check_in, c.current_check_in_time,
                    c.current_check_out, c.current_check_out_time, c.reason AS change_reason,
                    c.status AS change_status, c.review_note AS change_review_note, c.reviewed_at AS change_reviewed_at,
                    change_reviewer.full_name AS change_reviewed_by_name
             FROM room_appointments a
             LEFT JOIN users reviewer ON reviewer.id = a.reviewed_by
             LEFT JOIN users payment_reviewer ON payment_reviewer.id = a.payment_reviewed_by
             LEFT JOIN room_appointment_changes c ON c.id = (
                 SELECT MAX(latest.id) FROM room_appointment_changes latest WHERE latest.appointment_id = a.id
             )
             LEFT JOIN users change_reviewer ON change_reviewer.id = c.reviewed_by
             WHERE a.appointment_code = ?"
        );
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $appointment = $stmt->get_result()->fetch_assoc();
        if (!$appointment) {
            api_error('No appointment was found for that code.', 404);
        }
        $appointment['room_photo_url'] = room_photo_url($appointment['room_photo'] ?? null, (string) $appointment['room_type_name']);
        api_ok($appointment);
    }

    public function claimGuestAccount(array $data): void
    {
        $staff = api_require_role('admin', 'frontdesk');
        $code = strtoupper(trim((string) ($data['appointment_code'] ?? '')));
        if (!preg_match('/^[A-F0-9]{16}$/', $code)) {
            api_error('Enter the 16-character appointment code.');
        }
        $conn = api_conn();
        $appointmentStmt = $conn->prepare('SELECT * FROM room_appointments WHERE appointment_code = ?');
        $appointmentStmt->bind_param('s', $code);
        $appointmentStmt->execute();
        $appointment = $appointmentStmt->get_result()->fetch_assoc();
        if (!$appointment) {
            api_error('No appointment was found for that code.', 404);
        }
        if (!empty($appointment['converted_at'])) {
            api_error('This appointment has already been checked in.');
        }
        if (!preg_match('/@gmail\.com$/i', (string) $appointment['email'])) {
            api_error('Guest accounts from online appointments require a Gmail address.');
        }
        if (!in_array($appointment['status'], ['pending', 'confirmed'], true)) {
            api_error('An account can only be created for a pending or confirmed appointment.');
        }

        $conn->begin_transaction();
        try {
            $lockedStmt = $conn->prepare('SELECT * FROM room_appointments WHERE id = ? FOR UPDATE');
            $appointmentId = (int) $appointment['id'];
            $lockedStmt->bind_param('i', $appointmentId);
            $lockedStmt->execute();
            $appointment = $lockedStmt->get_result()->fetch_assoc();
            if (!$appointment || !in_array($appointment['status'], ['pending', 'confirmed'], true)) {
                api_error('An account can only be created for a pending or confirmed appointment.');
            }
            $email = strtolower((string) $appointment['email']);
            $existingStmt = $conn->prepare('SELECT id, role FROM users WHERE email = ? LIMIT 1');
            $existingStmt->bind_param('s', $email);
            $existingStmt->execute();
            $existingUser = $existingStmt->get_result()->fetch_assoc();
            if ($existingUser && $existingUser['role'] !== 'guest') {
                $conn->commit();
                api_ok(['account_status' => 'unavailable', 'account_message' => 'This Gmail address already belongs to a staff account. Contact Front Desk to continue.']);
            }

            $accountStatus = 'created';
            $userId = (int) ($existingUser['id'] ?? 0);
            if ($userId > 0) {
                $accountStatus = 'existing';
            } else {
                $passwordHash = password_hash($code, PASSWORD_DEFAULT);
                $fullName = (string) $appointment['full_name'];
                $firstName = (string) $appointment['first_name'];
                $lastName = (string) $appointment['last_name'];
                $phone = (string) $appointment['phone'];
                $createUser = $conn->prepare(
                    "INSERT INTO users (full_name, first_name, last_name, email, password, role, phone, status)
                     VALUES (?, ?, ?, ?, ?, 'guest', ?, 'active')"
                );
                $createUser->bind_param('ssssss', $fullName, $firstName, $lastName, $email, $passwordHash, $phone);
                $createUser->execute();
                $userId = (int) $conn->insert_id;
            }

            $guestStmt = $conn->prepare('SELECT id FROM guests WHERE user_id = ? LIMIT 1');
            $guestStmt->bind_param('i', $userId);
            $guestStmt->execute();
            $guest = $guestStmt->get_result()->fetch_assoc();
            if ($guest) {
                $guestId = (int) $guest['id'];
            } else {
                $guestEmailStmt = $conn->prepare('SELECT id FROM guests WHERE email = ? AND user_id IS NULL ORDER BY id LIMIT 1');
                $guestEmailStmt->bind_param('s', $email);
                $guestEmailStmt->execute();
                $guest = $guestEmailStmt->get_result()->fetch_assoc();
                if ($guest) {
                    $guestId = (int) $guest['id'];
                    $attachGuest = $conn->prepare(
                        'UPDATE guests SET user_id = ?, full_name = ?, first_name = ?, last_name = ?, phone = ? WHERE id = ?'
                    );
                    $attachGuest->bind_param(
                        'issssi',
                        $userId,
                        $appointment['full_name'],
                        $appointment['first_name'],
                        $appointment['last_name'],
                        $appointment['phone'],
                        $guestId
                    );
                    $attachGuest->execute();
                } else {
                    $createGuest = $conn->prepare(
                        'INSERT INTO guests (user_id, full_name, first_name, last_name, email, phone)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $createGuest->bind_param(
                        'isssss',
                        $userId,
                        $appointment['full_name'],
                        $appointment['first_name'],
                        $appointment['last_name'],
                        $email,
                        $appointment['phone']
                    );
                    $createGuest->execute();
                    $guestId = (int) $conn->insert_id;
                }
            }
            if ($conn->query("SHOW TABLES LIKE 'guest_registrations'")->fetch_assoc()) {
                $existingRegistrationStmt = $conn->prepare(
                    'SELECT id FROM guest_registrations WHERE guest_id = ? ORDER BY id DESC LIMIT 1'
                );
                $existingRegistrationStmt->bind_param('i', $guestId);
                $existingRegistrationStmt->execute();
                $existingRegistration = $existingRegistrationStmt->get_result()->fetch_assoc();
                $staffId = (int) $staff['id'];
                $appointmentNotes = (string) ($appointment['notes'] ?? '');
                if ($existingRegistration) {
                    $registration = $conn->prepare(
                        "UPDATE guest_registrations
                         SET registered_by = COALESCE(registered_by, ?), full_name = ?, email = ?, phone = ?,
                             notes = CASE WHEN ? <> '' THEN ? ELSE notes END, portal_created = 1
                         WHERE id = ?"
                    );
                    $registrationId = (int) $existingRegistration['id'];
                    $registration->bind_param(
                        'isssssi',
                        $staffId,
                        $appointment['full_name'],
                        $email,
                        $appointment['phone'],
                        $appointmentNotes,
                        $appointmentNotes,
                        $registrationId
                    );
                } else {
                    $registration = $conn->prepare(
                        'INSERT INTO guest_registrations
                         (guest_id, registered_by, full_name, email, phone, notes, portal_created)
                         VALUES (?, ?, ?, ?, ?, ?, 1)'
                    );
                    $registration->bind_param(
                        'iissss',
                        $guestId,
                        $staffId,
                        $appointment['full_name'],
                        $email,
                        $appointment['phone'],
                        $appointmentNotes
                    );
                }
                $registration->execute();
            }
            $linkAccount = $conn->prepare('UPDATE room_appointments SET account_user_id = ? WHERE id = ?');
            $linkAccount->bind_param('ii', $userId, $appointmentId);
            $linkAccount->execute();
            $roomStmt = $conn->prepare(
                'SELECT r.id, r.room_number, r.room_type, r.rate_per_night, r.max_adults, r.max_children
                 FROM room_appointment_rooms appointment_room
                 JOIN rooms r ON r.id = appointment_room.room_id
                 WHERE appointment_room.appointment_id = ?
                 ORDER BY r.room_number'
            );
            $roomStmt->bind_param('i', $appointmentId);
            $roomStmt->execute();
            $roomDetails = $roomStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $roomIds = array_map('intval', array_column($roomDetails, 'id'));
            foreach ($roomDetails as &$roomDetail) {
                $roomDetail['id'] = (int) $roomDetail['id'];
                $roomDetail['rate_per_night'] = (float) $roomDetail['rate_per_night'];
                $roomDetail['max_adults'] = (int) $roomDetail['max_adults'];
                $roomDetail['max_children'] = (int) $roomDetail['max_children'];
            }
            unset($roomDetail);
            audit(
                $conn,
                (int) $staff['id'],
                $accountStatus === 'created' ? 'appointment_guest_account_created' : 'appointment_guest_account_linked',
                "Appointment #{$appointmentId} · {$email}"
            );
            $conn->commit();
            api_ok([
                'account_status' => $accountStatus,
                'email' => $email,
                'guest_id' => $guestId,
                'appointment' => [
                    'id' => $appointmentId,
                    'status' => (string) $appointment['status'],
                    'first_name' => (string) $appointment['first_name'],
                    'last_name' => (string) $appointment['last_name'],
                    'email' => $email,
                    'phone' => (string) $appointment['phone'],
                    'check_in' => (string) $appointment['check_in'],
                    'check_in_time' => (string) $appointment['check_in_time'],
                    'check_out' => (string) $appointment['check_out'],
                    'check_out_time' => (string) $appointment['check_out_time'],
                    'adults' => (int) $appointment['adults'],
                    'children' => (int) $appointment['children'],
                    'room_ids' => $roomIds,
                    'rooms' => $roomDetails,
                    'notes' => (string) ($appointment['notes'] ?? ''),
                    'deposit_amount' => (float) $appointment['deposit_amount'],
                    'payment_method' => (string) $appointment['payment_method'],
                    'payment_reference' => (string) $appointment['payment_reference'],
                    'payment_status' => (string) $appointment['payment_status'],
                    'converted_at' => $appointment['converted_at'] ?? null,
                ],
            ], $accountStatus === 'created' ? 'Guest account created.' : 'Existing guest account linked.');
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function requestPublicChange(array $data): void
    {
        $conn = api_conn();
        $code = strtoupper(trim((string) ($data['appointment_code'] ?? '')));
        $changeType = (string) ($data['change_type'] ?? '');
        if (!preg_match('/^[A-F0-9]{16}$/', $code) || !in_array($changeType, ['cancel', 'reschedule'], true)) {
            api_error('Enter a valid appointment code and requested change.');
        }
        $conn->begin_transaction();
        $stmt = $conn->prepare(
            'SELECT id, status, room_type_name, room_count, adults, children,
                    check_in, check_in_time, check_out, check_out_time
             FROM room_appointments WHERE appointment_code = ? FOR UPDATE'
        );
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $appointment = $stmt->get_result()->fetch_assoc();
        if (!$appointment || !in_array($appointment['status'], ['pending', 'confirmed'], true)) {
            api_error('This appointment cannot be changed because it was not found or is no longer active.');
        }
        $appointmentId = (int) $appointment['id'];
        $pending = $conn->prepare("SELECT id FROM room_appointment_changes WHERE appointment_id = ? AND status = 'pending'");
        $pending->bind_param('i', $appointmentId);
        $pending->execute();
        if ($pending->get_result()->fetch_assoc()) {
            api_error('A change request is already waiting for staff review.');
        }

        $roomTypeId = null;
        $roomTypeName = null;
        $roomCount = null;
        $proposedRoomIds = null;
        $adults = null;
        $children = null;
        $checkIn = null;
        $checkInTime = null;
        $checkOut = null;
        $checkOutTime = null;
        if ($changeType === 'reschedule') {
            $checkIn = trim((string) ($data['check_in'] ?? ''));
            $checkInTime = $this->normalizeTime(trim((string) ($data['check_in_time'] ?? HOTEL_CHECK_IN_TIME)));
            $checkOut = trim((string) ($data['check_out'] ?? ''));
            $checkOutTime = $this->normalizeTime(trim((string) ($data['check_out_time'] ?? HOTEL_CHECK_OUT_TIME)));
            $adults = (int) ($data['adults'] ?? 1);
            $children = (int) ($data['children'] ?? 0);
            $this->validateAppointmentDateTimes($checkIn, $checkInTime, $checkOut, $checkOutTime);
            $roomIds = $this->selectedRoomIds($data['room_ids'] ?? []);
            $rooms = (new RoomModel($conn))->availableForDates($checkIn, $checkOut, $appointmentId);
            $selectedRooms = $this->validateSelectedRooms($rooms, $roomIds, $adults, $children);
            $roomTypeIds = array_values(array_unique(array_map(
                static fn(array $room): int => (int) $room['room_type_id'],
                $selectedRooms
            )));
            $roomTypeNames = array_values(array_unique(array_map(
                static fn(array $room): string => (string) $room['room_type'],
                $selectedRooms
            )));
            $roomTypeId = $roomTypeIds[0];
            $roomTypeName = implode(', ', $roomTypeNames);
            $roomCount = count($selectedRooms);
            $proposedRoomIds = json_encode($roomIds, JSON_THROW_ON_ERROR);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) > 1000) {
            api_error('Reason must be 1,000 characters or fewer.');
        }
        $insert = $conn->prepare(
            "INSERT INTO room_appointment_changes
                (appointment_id, change_type, current_room_type_name, current_room_count,
                 current_adults, current_children, current_check_in, current_check_in_time,
                 current_check_out, current_check_out_time,
                 proposed_room_type_id, proposed_room_type_name, proposed_room_count, proposed_room_ids,
                 proposed_adults, proposed_children, proposed_check_in, proposed_check_in_time,
                 proposed_check_out, proposed_check_out_time, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $currentRoomTypeName = (string) $appointment['room_type_name'];
        $currentRoomCount = (int) $appointment['room_count'];
        $currentAdults = (int) $appointment['adults'];
        $currentChildren = (int) $appointment['children'];
        $currentCheckIn = (string) $appointment['check_in'];
        $currentCheckInTime = (string) $appointment['check_in_time'];
        $currentCheckOut = (string) $appointment['check_out'];
        $currentCheckOutTime = (string) $appointment['check_out_time'];
        $insert->bind_param(
            'issiiissssisisiisssss',
            $appointmentId, $changeType, $currentRoomTypeName, $currentRoomCount,
            $currentAdults, $currentChildren, $currentCheckIn, $currentCheckInTime, $currentCheckOut, $currentCheckOutTime,
            $roomTypeId, $roomTypeName, $roomCount, $proposedRoomIds, $adults, $children, $checkIn, $checkInTime,
            $checkOut, $checkOutTime, $reason
        );
        $insert->execute();
        $conn->commit();
        api_ok(null, 'Your request was sent to the front desk for review.');
    }

    public function getStaffAppointments(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        $conn = api_conn();
        $appointments = $conn->query(
            "SELECT a.*, COALESCE(a.room_type_name, rt.name) AS room_type_label,
                    (SELECT GROUP_CONCAT(r.room_number ORDER BY r.room_number SEPARATOR ', ')
                     FROM room_appointment_rooms ar
                     JOIN rooms r ON r.id = ar.room_id
                     WHERE ar.appointment_id = a.id) AS room_numbers,
                    u.full_name AS reviewed_by_name, pu.full_name AS payment_reviewed_by_name
             FROM room_appointments a
             LEFT JOIN room_types rt ON rt.id = a.room_type_id
             LEFT JOIN users u ON u.id = a.reviewed_by
             LEFT JOIN users pu ON pu.id = a.payment_reviewed_by
             ORDER BY FIELD(a.status, 'pending','confirmed','rejected','cancelled'), a.created_at DESC
             LIMIT 300"
        )->fetch_all(MYSQLI_ASSOC);
        $changes = $conn->query(
            "SELECT c.*, a.appointment_code, a.full_name, a.email, a.phone,
                    c.current_check_in, c.current_check_out,
                    c.current_room_type_name AS current_room_type, c.current_room_count,
                    COALESCE(c.proposed_room_type_name, rt.name) AS proposed_room_type_label,
                    u.full_name AS reviewed_by_name
             FROM room_appointment_changes c
             JOIN room_appointments a ON a.id = c.appointment_id
             LEFT JOIN room_types rt ON rt.id = c.proposed_room_type_id
             LEFT JOIN users u ON u.id = c.reviewed_by
             ORDER BY FIELD(c.status, 'pending','accepted','rejected'), c.created_at DESC
             LIMIT 300"
        )->fetch_all(MYSQLI_ASSOC);
        api_ok(['appointments' => $appointments, 'changes' => $changes]);
    }

    public function reviewAppointment(array $data): void
    {
        $user = api_require_role('admin', 'frontdesk');
        $conn = api_conn();
        $id = (int) ($data['id'] ?? 0);
        $decision = (string) ($data['decision'] ?? '');
        $note = trim((string) ($data['review_note'] ?? ''));
        if ($id <= 0 || !in_array($decision, ['confirmed', 'rejected'], true) || strlen($note) > 1000) {
            api_error('Choose Confirm or Reject and enter a valid review note.');
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT * FROM room_appointments WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            if (!$appointment || $appointment['status'] !== 'pending') {
                api_error('This appointment has already been reviewed.');
            }
            if ($decision === 'confirmed') {
                if (!in_array($appointment['payment_status'], ['verified', 'not_required'], true)) {
                    api_error('Verify the 25% deposit before confirming this appointment.');
                }
                $this->validateAppointmentDateTimes(
                    (string) $appointment['check_in'],
                    (string) $appointment['check_in_time'],
                    (string) $appointment['check_out'],
                    (string) $appointment['check_out_time']
                );
                $requestedRoomIds = $conn->prepare('SELECT room_id FROM room_appointment_rooms WHERE appointment_id = ? ORDER BY room_id');
                $requestedRoomIds->bind_param('i', $id);
                $requestedRoomIds->execute();
                $roomIds = array_map('intval', array_column($requestedRoomIds->get_result()->fetch_all(MYSQLI_ASSOC), 'room_id'));
                if ($roomIds) {
                    $this->lockRooms($conn, $roomIds);
                    $availableRooms = (new RoomModel($conn))->availableForDates(
                        (string) $appointment['check_in'],
                        (string) $appointment['check_out'],
                        (int) $appointment['id']
                    );
                    $this->validateSelectedRooms(
                        $availableRooms,
                        $roomIds,
                        (int) $appointment['adults'],
                        (int) $appointment['children']
                    );
                } else {
                    $this->lockRoomType($conn, (int) $appointment['room_type_id']);
                    $rooms = $this->availableRooms(
                        (string) $appointment['check_in'],
                        (string) $appointment['check_out'],
                        (int) $appointment['room_type_id']
                    );
                    $this->validateCapacity(
                        $rooms,
                        (int) $appointment['room_count'],
                        (int) $appointment['adults'],
                        (int) $appointment['children']
                    );
                    $this->assignAppointmentRooms($conn, $id, $rooms, (int) $appointment['room_count']);
                }
            }
            $update = $conn->prepare('UPDATE room_appointments SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
            $userId = (int) $user['id'];
            $update->bind_param('ssii', $decision, $note, $userId, $id);
            $update->execute();
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
        api_ok(null, $decision === 'confirmed' ? 'Appointment confirmed.' : 'Appointment rejected.');
    }

    public function reviewAppointmentPayment(array $data): void
    {
        $user = api_require_role('admin', 'frontdesk');
        $conn = api_conn();
        $id = (int) ($data['id'] ?? 0);
        $decision = (string) ($data['decision'] ?? '');
        $note = trim((string) ($data['review_note'] ?? ''));
        if ($id <= 0 || !in_array($decision, ['verified', 'rejected'], true) || strlen($note) > 1000
            || ($decision === 'rejected' && $note === '')) {
            api_error('Choose Verify or Reject. A reason is required when rejecting a transfer.');
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "SELECT payment_reference FROM room_appointments
                 WHERE id = ? AND status = 'pending' AND payment_status = 'pending' FOR UPDATE"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            if (!$appointment) {
                api_error('This appointment transfer is no longer awaiting verification.');
            }
            $update = $conn->prepare(
                'UPDATE room_appointments
                 SET payment_status = ?, payment_review_note = ?, payment_reviewed_by = ?, payment_reviewed_at = NOW()
                 WHERE id = ?'
            );
            $userId = (int) $user['id'];
            $update->bind_param('ssii', $decision, $note, $userId, $id);
            $update->execute();
            audit(
                $conn,
                $userId,
                $decision === 'verified' ? 'appointment_deposit_verified' : 'appointment_deposit_rejected',
                "Appointment #{$id} · {$appointment['payment_reference']}" . ($note !== '' ? " · {$note}" : '')
            );
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
        api_ok(null, $decision === 'verified' ? 'Appointment deposit verified.' : 'Appointment transfer rejected.');
    }

    public function reviewChangeRequest(array $data): void
    {
        $user = api_require_role('admin', 'frontdesk');
        $conn = api_conn();
        $id = (int) ($data['id'] ?? 0);
        $decision = (string) ($data['decision'] ?? '');
        $note = trim((string) ($data['review_note'] ?? ''));
        if ($id <= 0 || !in_array($decision, ['accepted', 'rejected'], true) || strlen($note) > 1000) {
            api_error('Choose Accept or Reject and enter a valid review note.');
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT * FROM room_appointment_changes WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $change = $stmt->get_result()->fetch_assoc();
            if (!$change || $change['status'] !== 'pending') {
                api_error('This change request has already been reviewed.');
            }
            $appointmentId = (int) $change['appointment_id'];
            $appointmentStmt = $conn->prepare('SELECT * FROM room_appointments WHERE id = ? FOR UPDATE');
            $appointmentStmt->bind_param('i', $appointmentId);
            $appointmentStmt->execute();
            $appointment = $appointmentStmt->get_result()->fetch_assoc();
            if (!$appointment || !in_array($appointment['status'], ['pending', 'confirmed'], true)) {
                api_error('The appointment is no longer active.');
            }
            if ($decision === 'accepted' && $change['change_type'] === 'reschedule') {
                $this->validateAppointmentDateTimes(
                    (string) $change['proposed_check_in'],
                    (string) $change['proposed_check_in_time'],
                    (string) $change['proposed_check_out'],
                    (string) $change['proposed_check_out_time']
                );
                $requestedRoomIds = json_decode((string) ($change['proposed_room_ids'] ?? ''), true);
                if (is_array($requestedRoomIds) && $requestedRoomIds) {
                    $requestedRoomIds = array_map('intval', $requestedRoomIds);
                    $this->lockRooms($conn, $requestedRoomIds);
                    $availableRooms = (new RoomModel($conn))->availableForDates(
                        (string) $change['proposed_check_in'],
                        (string) $change['proposed_check_out'],
                        $appointmentId
                    );
                    $rooms = $this->validateSelectedRooms(
                        $availableRooms,
                        $requestedRoomIds,
                        (int) $change['proposed_adults'],
                        (int) $change['proposed_children']
                    );
                } else {
                    $this->lockRoomType($conn, (int) $change['proposed_room_type_id']);
                    $rooms = $this->availableRooms(
                        (string) $change['proposed_check_in'],
                        (string) $change['proposed_check_out'],
                        (int) $change['proposed_room_type_id'],
                        $appointmentId
                    );
                    $this->validateCapacity(
                        $rooms,
                        (int) $change['proposed_room_count'],
                        (int) $change['proposed_adults'],
                        (int) $change['proposed_children']
                    );
                }
                $updateAppointment = $conn->prepare(
                    'UPDATE room_appointments
                     SET room_type_id = ?, room_type_name = ?, room_count = ?, adults = ?, children = ?,
                         check_in = ?, check_in_time = ?, check_out = ?, check_out_time = ?,
                         status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
                     WHERE id = ?'
                );
                $appointmentStatus = (string) $appointment['status'];
                $proposedRoomTypeId = (int) $change['proposed_room_type_id'];
                $proposedRoomTypeName = (string) $change['proposed_room_type_name'];
                $proposedRoomCount = (int) $change['proposed_room_count'];
                $proposedAdults = (int) $change['proposed_adults'];
                $proposedChildren = (int) $change['proposed_children'];
                $proposedCheckIn = (string) $change['proposed_check_in'];
                $proposedCheckInTime = (string) $change['proposed_check_in_time'];
                $proposedCheckOut = (string) $change['proposed_check_out'];
                $proposedCheckOutTime = (string) $change['proposed_check_out_time'];
                $userId = (int) $user['id'];
                $updateAppointment->bind_param(
                    'isiiissssssii',
                    $proposedRoomTypeId, $proposedRoomTypeName,
                    $proposedRoomCount, $proposedAdults, $proposedChildren,
                    $proposedCheckIn, $proposedCheckInTime, $proposedCheckOut, $proposedCheckOutTime,
                    $appointmentStatus, $note, $userId, $appointmentId
                );
                $updateAppointment->execute();
                $this->assignAppointmentRooms(
                    $conn,
                    $appointmentId,
                    $rooms,
                    (int) $change['proposed_room_count']
                );
            } elseif ($decision === 'accepted' && $change['change_type'] === 'cancel') {
                $cancel = $conn->prepare("UPDATE room_appointments SET status = 'cancelled', review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                $userId = (int) $user['id'];
                $cancel->bind_param('sii', $note, $userId, $appointmentId);
                $cancel->execute();
            }
            $review = $conn->prepare('UPDATE room_appointment_changes SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
            $userId = (int) $user['id'];
            $review->bind_param('ssii', $decision, $note, $userId, $id);
            $review->execute();
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
        api_ok(null, $decision === 'accepted' ? 'Appointment change accepted.' : 'Appointment change rejected.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new AppointmentsApi(), $operation, $data);
