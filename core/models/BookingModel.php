<?php
declare(strict_types=1);

final class BookingModel
{
    public ?int $lastBookingId = null;
    public string $lastStatus = 'reserved';
    public ?int $lastBillId = null;

    public function __construct(private mysqli $conn) {}

    public function inHouse(): array
    {
        return $this->conn->query(
            "SELECT b.id, b.check_in, b.check_out, b.check_in_time, b.check_out_time, g.full_name, r.room_number, r.room_type,
                    bl.id AS bill_id, bl.invoice_number, bl.total_amount, bl.balance, bl.status AS bill_status
             FROM bookings b
             JOIN guests g ON g.id = b.guest_id
             JOIN rooms r ON r.id = b.room_id
             LEFT JOIN bills bl ON bl.id = (SELECT MAX(x.id) FROM bills x WHERE x.booking_id = b.id)
             WHERE b.status = 'checked_in'
             ORDER BY b.check_in DESC, b.id DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** Checked-in and checked-out stays (with account and nightly rate) that can be extended or reopened. */
    public function extendable(int $limit = 200): array
    {
        return $this->conn->query(
            "SELECT b.id, b.guest_id, b.stay_group, b.status, b.check_in, b.check_in_time, b.check_out, b.check_out_time,
                    g.full_name, r.room_number, r.room_type, r.rate_per_night,
                    bl.id AS bill_id, bl.invoice_number, bl.total_amount, bl.balance, bl.status AS bill_status
             FROM bookings b
             JOIN guests g ON g.id = b.guest_id
             JOIN rooms r ON r.id = b.room_id
             LEFT JOIN bills bl ON bl.id = (SELECT MAX(x.id) FROM bills x WHERE x.booking_id = b.id)
             WHERE b.status IN ('checked_in','checked_out')
             ORDER BY FIELD(b.status,'checked_in','checked_out'), b.id DESC
             LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function extensionHistory(array $bookingIds): array
    {
        $bookingIds = array_values(array_unique(array_filter(
            array_map('intval', $bookingIds),
            static fn(int $bookingId): bool => $bookingId > 0
        )));
        if (!$bookingIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT a.action, a.details, a.created_at, u.full_name AS performed_by
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.action IN ('booking_extend','booking_reopen')
               AND a.details LIKE 'Booking #%'
               AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(a.details, ' ·', 1), '#', -1) AS UNSIGNED)
                   IN ({$placeholders})
             ORDER BY a.created_at DESC, a.id DESC"
        );
        $types = str_repeat('i', count($bookingIds));
        $params = [$types];
        foreach ($bookingIds as $index => $bookingId) {
            $params[] = &$bookingIds[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $params);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($history as &$entry) {
            preg_match('/^Booking #(\d+)/', (string) $entry['details'], $matches);
            $entry['booking_id'] = (int) ($matches[1] ?? 0);
        }
        unset($entry);
        return $history;
    }

    public function activeStays(int $limit = 10): array
    {
        return $this->conn->query(
            "SELECT b.id, b.check_in, b.check_out, b.check_in_time, b.check_out_time, b.status, g.full_name, r.room_number, r.room_type
             FROM bookings b JOIN guests g ON g.id = b.guest_id JOIN rooms r ON r.id = b.room_id
             WHERE b.status IN ('reserved','checked_in') ORDER BY b.check_in ASC LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function list(int $limit = 50): array
    {
        return $this->conn->query(
            "SELECT b.*, g.full_name, r.room_number, r.room_type,
                    COALESCE((
                        SELECT SUM(account_bill.balance)
                        FROM bills account_bill
                        LEFT JOIN bookings account_booking ON account_booking.id = account_bill.booking_id
                        WHERE account_bill.status <> 'void'
                          AND (
                              account_bill.booking_id = b.id
                              OR (
                                  b.stay_group IS NOT NULL
                                  AND account_booking.guest_id = b.guest_id
                                  AND account_booking.stay_group = b.stay_group
                              )
                          )
                    ), 0) AS account_balance
             FROM bookings b JOIN guests g ON g.id = b.guest_id JOIN rooms r ON r.id = b.room_id
             ORDER BY FIELD(b.status,'checked_in','reserved','checked_out','cancelled'), b.id DESC
             LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function activeForGuest(int $guestId): ?array
    {
        return $this->conn->query(
            "SELECT b.*, r.room_number, r.room_type, r.rate_per_night, r.room_features
             FROM bookings b JOIN rooms r ON r.id=b.room_id
             WHERE b.guest_id={$guestId} AND b.status IN ('reserved','checked_in')
             ORDER BY b.id DESC LIMIT 1"
        )->fetch_assoc() ?: null;
    }

    public function activeStaysForGuest(int $guestId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT b.id, b.check_in, b.check_out, b.check_in_time, b.check_out_time,
                    b.status, r.room_number, r.room_type
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             WHERE b.guest_id = ? AND b.status IN ('reserved','checked_in')
             ORDER BY b.id DESC"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function checkedInForGuest(int $guestId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT b.*, r.room_number, r.room_type, r.rate_per_night, r.room_features
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             WHERE b.guest_id = ? AND b.status = 'checked_in'
             ORDER BY b.id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function checkedInStaysForGuest(int $guestId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT b.id, b.check_in, b.check_out, b.check_in_time, b.check_out_time,
                    b.status, r.room_number, r.room_type
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             WHERE b.guest_id = ? AND b.status = 'checked_in'
             ORDER BY b.id DESC"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function historyForGuest(int $guestId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT b.*, r.room_number, r.room_type, r.rate_per_night, r.room_features, r.photo
             FROM bookings b JOIN rooms r ON r.id = b.room_id
             WHERE b.guest_id = ? ORDER BY b.id DESC"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($bookings as &$booking) {
            $booking['photo_url'] = room_photo_url(
                isset($booking['photo']) ? (string) $booking['photo'] : null,
                isset($booking['room_type']) ? (string) $booking['room_type'] : null
            );
        }
        unset($booking);
        return $bookings;
    }

    public function activeSimple(): array
    {
        $bookings = $this->conn->query(
            "SELECT b.id, b.guest_id, b.status, b.check_in, b.check_out,
                    g.full_name, r.id AS room_id, r.room_number,
                    r.room_type, r.photo, r.room_features
             FROM bookings b
             JOIN guests g ON g.id=b.guest_id JOIN rooms r ON r.id=b.room_id
             WHERE b.status IN ('reserved','checked_in') ORDER BY b.id DESC"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($bookings as &$booking) {
            $booking['photo_url'] = room_photo_url(
                isset($booking['photo']) ? (string) $booking['photo'] : null,
                isset($booking['room_type']) ? (string) $booking['room_type'] : null
            );
        }
        unset($booking);
        return $bookings;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function checkedInCount(): int
    {
        return (int) $this->conn->query("SELECT COUNT(*) c FROM bookings WHERE status='checked_in'")->fetch_assoc()['c'];
    }

    public function reservedCount(): int
    {
        return (int) $this->conn->query("SELECT COUNT(*) c FROM bookings WHERE status='reserved'")->fetch_assoc()['c'];
    }

    public function create(array $data, int $actorId, RoomModel $rooms, BillModel $bills): bool
    {
        $guestId = (int) ($data['guest_id'] ?? 0);
        $roomId = (int) ($data['room_id'] ?? 0);
        $checkIn = (string) ($data['check_in'] ?? '');
        $checkOut = (string) ($data['check_out'] ?? '');
        $checkInTimeInput = $data['check_in_time'] ?? HOTEL_CHECK_IN_TIME;
        $checkOutTimeInput = $data['check_out_time'] ?? HOTEL_CHECK_OUT_TIME;
        $checkInTime = normalize_time($checkInTimeInput, HOTEL_CHECK_IN_TIME);
        $checkOutTime = normalize_time($checkOutTimeInput, HOTEL_CHECK_OUT_TIME);
        $appointmentId = (int) ($data['appointment_id'] ?? 0);
        $adults = max(1, (int) ($data['adults'] ?? 1));
        $children = max(0, (int) ($data['children'] ?? 0));
        $notes = trim($data['notes'] ?? '');
        $today = date('Y-m-d');
        if (!$guestId || !$roomId || !self::isValidStayDate($checkIn) || !self::isValidStayDate($checkOut) || $checkOut <= $checkIn) {
            flash('error', 'Guest, room, and valid stay dates are required.');
            return false;
        }
        if ($checkIn < $today) {
            flash('error', 'Check-in date cannot be in the past.');
            return false;
        }
        if (!self::isValidStayTime($checkInTimeInput) || !self::isValidStayTime($checkOutTimeInput)) {
            flash('error', 'Choose valid check-in and check-out times.');
            return false;
        }
        if (!is_hotel_check_in_time($checkInTimeInput) || !is_hotel_check_out_time($checkOutTimeInput)) {
            flash('error', 'Check-in must be between 3:00 and 3:59 PM, and check-out is fixed at 2:00 PM.');
            return false;
        }
        $checkInAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$checkIn} {$checkInTime}");
        if (!$checkInAt || ($appointmentId <= 0 && $checkIn === $today && $checkInAt <= new DateTimeImmutable())) {
            flash('error', 'Check-in time cannot be in the past.');
            return false;
        }
        try {
            $this->conn->begin_transaction();
            $roomStmt = $this->conn->prepare('SELECT * FROM rooms WHERE id = ? FOR UPDATE');
            $roomStmt->bind_param('i', $roomId);
            $roomStmt->execute();
            $room = $roomStmt->get_result()->fetch_assoc() ?: null;
            if (!$room || $room['status'] === 'maintenance') {
                $this->conn->rollback();
                flash('error', 'Selected room is unavailable for booking.');
                return false;
            }
            $maxAdults = (int) ($room['max_adults'] ?? 2);
            $maxChildren = (int) ($room['max_children'] ?? 1);
            if ($adults > $maxAdults || $children > $maxChildren) {
                $this->conn->rollback();
                flash('error', "Room {$room['room_number']} allows up to {$maxAdults} adult(s) and {$maxChildren} child(ren). Add another room for the extra guests.");
                return false;
            }
            $roomCharge = round((float) $room['rate_per_night'] * nights_between($checkIn, $checkOut), 2);
            if ($roomCharge < 0.01) {
                $this->conn->rollback();
                flash('error', 'The room stay must have charges of at least 0.01 before a bill can be created.');
                return false;
            }
            $conflictStmt = $this->conn->prepare(
                "SELECT id FROM bookings
                 WHERE room_id = ? AND status IN ('reserved','checked_in')
                   AND check_in < ? AND check_out > ?
                 UNION ALL
                 SELECT appointment.id FROM room_appointment_rooms appointment_room
                 JOIN room_appointments appointment ON appointment.id = appointment_room.appointment_id
                 WHERE appointment_room.room_id = ? AND appointment.status = 'confirmed'
                   AND appointment.converted_at IS NULL AND appointment.id <> ?
                   AND appointment.check_in < ? AND appointment.check_out > ?
                 LIMIT 1"
            );
            $conflictStmt->bind_param('issiiss', $roomId, $checkOut, $checkIn, $roomId, $appointmentId, $checkOut, $checkIn);
            $conflictStmt->execute();
            if ($conflictStmt->get_result()->fetch_assoc()) {
                $this->conn->rollback();
                flash('error', 'Selected room already has a booking during those dates.');
                return false;
            }
            $status = $checkIn === $today && date('H:i:s') >= $checkInTime ? 'checked_in' : 'reserved';
            $stmt = $this->conn->prepare(
                'INSERT INTO bookings (guest_id, room_id, check_in, check_out, check_in_time, check_out_time, adults, children, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('iissssiissi', $guestId, $roomId, $checkIn, $checkOut, $checkInTime, $checkOutTime, $adults, $children, $status, $notes, $actorId);
            $stmt->execute();
            $bookingId = (int) $this->conn->insert_id;
            $rooms->syncOperationalStatus($roomId);
            $this->lastBillId = $bills->ensureStayBill(
                ['id' => $bookingId, 'guest_id' => $guestId, 'check_in' => $checkIn, 'check_out' => $checkOut],
                $room,
                $actorId
            );
            $this->lastBookingId = $bookingId;
            $this->lastStatus = $status;
            audit($this->conn, $actorId, 'booking_create', "Booking #{$bookingId} ({$status})");
            $this->conn->commit();
            $label = $status === 'checked_in' ? 'Guest checked in and room bill started.' : 'Stay booked for ' . $checkIn . ' and room bill started.';
            flash('success', $label);
            return true;
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Booking failed.');
            return false;
        }
    }

    public function updateStayDates(array $data, int $actorId): bool
    {
        $id = (int) ($data['id'] ?? 0);
        $checkIn = (string) ($data['check_in'] ?? '');
        $checkOut = (string) ($data['check_out'] ?? '');
        $booking = $this->find($id);
        $checkInTimeInput = $data['check_in_time'] ?? HOTEL_CHECK_IN_TIME;
        $checkOutTimeInput = $data['check_out_time'] ?? HOTEL_CHECK_OUT_TIME;
        $checkInTime = normalize_time($checkInTimeInput, HOTEL_CHECK_IN_TIME);
        $checkOutTime = normalize_time($checkOutTimeInput, HOTEL_CHECK_OUT_TIME);
        if (!$booking) {
            flash('error', 'Booking not found.');
            return false;
        }
        if (!in_array($booking['status'], ['reserved', 'checked_in'], true)) {
            flash('error', 'Only active stays can have dates updated.');
            return false;
        }
        if (!self::isValidStayDate($checkIn) || !self::isValidStayDate($checkOut) || $checkOut <= $checkIn) {
            flash('error', 'Valid check-in and check-out dates are required.');
            return false;
        }
        if (!self::isValidStayTime($checkInTimeInput) || !self::isValidStayTime($checkOutTimeInput)) {
            flash('error', 'Choose valid check-in and check-out times.');
            return false;
        }
        if (!is_hotel_check_in_time($checkInTimeInput) || !is_hotel_check_out_time($checkOutTimeInput)) {
            flash('error', 'Check-in must be between 3:00 and 3:59 PM, and check-out is fixed at 2:00 PM.');
            return false;
        }
        $today = date('Y-m-d');
        if ($booking['status'] === 'reserved' && $checkIn < $today) {
            flash('error', 'A reservation cannot be moved to a past check-in date.');
            return false;
        }
        $checkInAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$checkIn} {$checkInTime}");
        if (!$checkInAt || ($booking['status'] === 'reserved' && $checkIn === $today && $checkInAt <= new DateTimeImmutable())) {
            flash('error', 'A reservation check-in time cannot be in the past.');
            return false;
        }
        if ($booking['status'] === 'checked_in' && $checkOut < $today) {
            flash('error', 'Check-out date cannot be moved into the past.');
            return false;
        }
        $conflict = $this->conn->prepare(
            "SELECT id FROM bookings
             WHERE room_id = ? AND id <> ? AND status IN ('reserved','checked_in')
               AND check_in < ? AND check_out > ?
             UNION ALL
             SELECT appointment.id FROM room_appointment_rooms appointment_room
             JOIN room_appointments appointment ON appointment.id = appointment_room.appointment_id
             WHERE appointment_room.room_id = ? AND appointment.status = 'confirmed'
               AND appointment.converted_at IS NULL
               AND appointment.check_in < ? AND appointment.check_out > ?
             LIMIT 1"
        );
        $conflict->bind_param(
            'iississ',
            $booking['room_id'], $id, $checkOut, $checkIn,
            $booking['room_id'], $checkOut, $checkIn
        );
        $conflict->execute();
        if ($conflict->get_result()->fetch_assoc()) {
            flash('error', 'Those dates conflict with another booking for this room.');
            return false;
        }
        $stmt = $this->conn->prepare('UPDATE bookings SET check_in=?, check_out=?, check_in_time=?, check_out_time=? WHERE id=?');
        $stmt->bind_param('ssssi', $checkIn, $checkOut, $checkInTime, $checkOutTime, $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'booking_dates', "Booking #{$id}: {$checkIn} {$checkInTime} → {$checkOut} {$checkOutTime}");
        flash('success', 'Check-in / check-out dates updated.');
        return true;
    }

    /** Extends a checked-in stay, or reopens a checked-out one, and posts the extra time to the guest account. */
    public function extendStay(array $data, RoomModel $rooms, BillModel $bills, int $actorId): bool
    {
        $id = (int) ($data['id'] ?? 0);
        $stamp = str_replace('T', ' ', trim((string) ($data['check_out_at'] ?? '')));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $stamp)) {
            $stamp .= ':00';
        }
        $newOut = preg_match('/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $stamp)
            ? DateTime::createFromFormat('!Y-m-d H:i:s', $stamp)
            : false;
        $parseErrors = DateTime::getLastErrors();
        if ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)) {
            $newOut = false;
        }
        $booking = $this->find($id);
        if (!$booking || !in_array($booking['status'], ['checked_in', 'checked_out'], true)) {
            flash('error', 'Only checked-in or checked-out stays can be extended.');
            return false;
        }
        if (!$newOut) {
            flash('error', 'Pick the new check-out date and time.');
            return false;
        }
        if ($newOut->format('H:i:s') !== HOTEL_CHECK_OUT_TIME) {
            flash('error', 'Extended stays must check out at the fixed 2:00 PM hotel checkout time.');
            return false;
        }
        $oldOut = new DateTime($booking['check_out'] . ' ' . HOTEL_CHECK_OUT_TIME);
        if ($newOut <= $oldOut) {
            flash('error', 'The new check-out must be later than the current check-out (' . $oldOut->format('M j, Y g:i A') . ').');
            return false;
        }
        if ($newOut <= new DateTime()) {
            flash('error', 'The new check-out cannot be in the past.');
            return false;
        }
        $room = $rooms->find((int) $booking['room_id']);
        if (!$room || $room['status'] === 'maintenance') {
            flash('error', 'The room is unavailable for an extension.');
            return false;
        }
        $newDate = $newOut->format('Y-m-d');
        $newTime = $newOut->format('H:i:s');
        try {
            $this->conn->begin_transaction();
            $conflict = $this->conn->prepare(
                "SELECT id FROM bookings
                 WHERE room_id = ? AND id <> ? AND status IN ('reserved','checked_in')
                   AND check_in < ? AND check_out > ? LIMIT 1 FOR UPDATE"
            );
            $conflict->bind_param('iiss', $booking['room_id'], $id, $newDate, $booking['check_out']);
            $conflict->execute();
            $appointmentConflict = $this->conn->prepare(
                "SELECT appointment.id
                 FROM room_appointment_rooms appointment_room
                 JOIN room_appointments appointment ON appointment.id = appointment_room.appointment_id
                 WHERE appointment_room.room_id = ? AND appointment.status = 'confirmed'
                   AND appointment.converted_at IS NULL
                   AND appointment.check_in < ? AND appointment.check_out > ? LIMIT 1 FOR UPDATE"
            );
            $appointmentConflict->bind_param('iss', $booking['room_id'], $newDate, $booking['check_out']);
            $appointmentConflict->execute();
            if ($conflict->get_result()->fetch_assoc() || $appointmentConflict->get_result()->fetch_assoc()) {
                $this->conn->rollback();
                flash('error', 'Room ' . $room['room_number'] . ' is already booked by another guest in that extra period.');
                return false;
            }
            $reopened = $booking['status'] === 'checked_out';
            $upd = $this->conn->prepare("UPDATE bookings SET status='checked_in', check_out=?, check_out_time=? WHERE id=?");
            $upd->bind_param('ssi', $newDate, $newTime, $id);
            $upd->execute();
            $rooms->syncOperationalStatus((int) $booking['room_id']);
            $billId = $bills->ensureStayBill($booking, $room, $actorId);
            $days = ($newOut->getTimestamp() - $oldOut->getTimestamp()) / 86400;
            $chargeDays = round($days, 2);
            if ($chargeDays <= 0) {
                $chargeDays = 0.01;
            }
            $extensionCharge = round($chargeDays * (float) $room['rate_per_night'], 2);
            $bills->postExtensionCharge(
                $billId, $room, $days, $actorId,
                $oldOut->format('M j, Y g:i A') . ' → ' . $newOut->format('M j, Y g:i A')
            );
            $bills->reopenDue($billId, $newDate);
            audit($this->conn, $actorId, $reopened ? 'booking_reopen' : 'booking_extend',
                "Booking #{$id} · Room {$room['room_number']} · from " . $oldOut->format('Y-m-d H:i')
                . ' to ' . $newOut->format('Y-m-d H:i') . ' · Added ' . money($extensionCharge));
            $this->conn->commit();
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Extension failed.');
            return false;
        }
        flash('success', ($reopened ? 'Check-in reopened and stay' : 'Stay') . ' extended to ' . $newOut->format('M j, Y g:i A') . '. The extra charge was added to the guest account.');
        return true;
    }

    public function checkIn(int $id, RoomModel $rooms, BillModel $bills, int $actorId): ?int
    {
        $booking = $this->find($id);
        if (!$booking || !in_array($booking['status'], ['reserved', 'checked_in'], true)) {
            flash('error', 'Only reserved stays can be checked in.');
            return null;
        }
        $today = date('Y-m-d');
        if ($booking['check_in'] !== $today || $booking['check_out'] <= $today) {
            flash('error', 'This booking is not scheduled for check-in today.');
            return null;
        }
        $room = $rooms->find((int) $booking['room_id']);
        if (!$room) {
            flash('error', 'The booked room no longer exists.');
            return null;
        }
        $roomCharge = round((float) $room['rate_per_night'] * nights_between(
            (string) $booking['check_in'],
            (string) $booking['check_out']
        ), 2);
        if ($roomCharge < 0.01) {
            flash('error', 'Cannot check in or issue an invoice for a zero-amount stay.');
            return null;
        }
        $existingBill = $bills->byBooking($id);
        if ($existingBill) {
            $bill = $bills->find((int) $existingBill['id']);
            if (!$bill || (float) $bill['total_amount'] < 0.01) {
                flash('error', 'Cannot check in or issue an invoice when the guest account total is zero.');
                return null;
            }
        }
        try {
            $this->conn->begin_transaction();
            $this->conn->query("UPDATE bookings SET status='checked_in' WHERE id={$id}");
            $rooms->syncOperationalStatus((int) $booking['room_id']);
            $billId = $bills->ensureStayBill($booking, $room, $actorId);
            $bill = $bills->find($billId);
            if (!$bill || (float) $bill['total_amount'] < 0.01) {
                throw new RuntimeException('A zero-amount invoice cannot be issued at check-in.');
            }
            audit($this->conn, $actorId, 'check_in', "Booking #{$id} · Room {$room['room_number']} · Bill #{$billId}");
            $this->conn->commit();
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Check-in failed.');
            return null;
        }
        flash('success', "Guest checked in to Room {$room['room_number']}. Guest account opened with room charges.");
        return $billId;
    }

    public function checkOut(int $id, RoomModel $rooms, int $actorId): void
    {
        $this->conn->begin_transaction();
        try {
            $bookingStmt = $this->conn->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
            $bookingStmt->bind_param('i', $id);
            $bookingStmt->execute();
            $booking = $bookingStmt->get_result()->fetch_assoc();
            if (!$booking || $booking['status'] !== 'checked_in') {
                $this->conn->rollback();
                flash('error', 'Only checked-in bookings can be checked out.');
                return;
            }

            $lateChargePosted = false;
            $checkoutDeadline = new DateTimeImmutable((string) $booking['check_out'] . ' ' . HOTEL_CHECK_OUT_TIME);
            if (new DateTimeImmutable() > $checkoutDeadline) {
                $room = $rooms->find((int) $booking['room_id']);
                $bill = (new BillModel($this->conn))->activeByBooking($id);
                if (!$room || !$bill) {
                    throw new RuntimeException('The booking room or guest bill is unavailable.');
                }
                $lateChargePosted = (new BillModel($this->conn))->postExtraNightCharge(
                    (int) $bill['id'],
                    $id,
                    (string) $room['room_number'],
                    (float) $room['rate_per_night'],
                    'Late checkout',
                    (string) $booking['check_out'],
                    $actorId
                );
            }

            if ($booking['stay_group'] !== null) {
                $billStmt = $this->conn->prepare(
                    "SELECT account_bill.id, account_bill.balance
                     FROM bills account_bill
                     JOIN bookings account_booking ON account_booking.id = account_bill.booking_id
                     WHERE account_booking.guest_id = ? AND account_booking.stay_group = ?
                       AND account_bill.status <> 'void'
                     FOR UPDATE"
                );
                $guestId = (int) $booking['guest_id'];
                $stayGroup = (int) $booking['stay_group'];
                $billStmt->bind_param('ii', $guestId, $stayGroup);
            } else {
                $billStmt = $this->conn->prepare(
                    "SELECT id, balance FROM bills
                     WHERE booking_id = ? AND status <> 'void'
                     FOR UPDATE"
                );
                $billStmt->bind_param('i', $id);
            }
            $billStmt->execute();
            $accountBalance = 0.0;
            foreach ($billStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $bill) {
                $accountBalance += max(0.0, (float) $bill['balance']);
            }
            $accountBalance = round($accountBalance, 2);
            if (self::hasOutstandingBalance($accountBalance)) {
                if ($lateChargePosted) {
                    $this->conn->commit();
                    flash('error', 'A full extra night was added for late checkout. Pay the outstanding account balance of ' . money($accountBalance) . ' before checkout.');
                    return;
                }
                $this->conn->rollback();
                flash('error', 'Cannot check out. The guest account still has an outstanding balance of ' . money($accountBalance) . '.');
                return;
            }

            $update = $this->conn->prepare("UPDATE bookings SET status='checked_out' WHERE id = ?");
            $update->bind_param('i', $id);
            $update->execute();
            $rooms->syncOperationalStatus((int) $booking['room_id']);
            (new HousekeepingModel($this->conn))->createForCheckout(
                $id,
                (int) $booking['room_id'],
                $actorId
            );
            audit($this->conn, $actorId, 'check_out', "Booking #{$id}");
            $this->conn->commit();
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Check-out failed.');
            return;
        }
        flash('success', 'Guest checked out. Room moved to Cleaning and a housekeeping inspection was created.');
    }

    public static function hasOutstandingBalance(float $balance): bool
    {
        return $balance > 0.009;
    }

    private static function isValidStayDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private static function isValidStayTime(mixed $time): bool
    {
        return is_string($time)
            && preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', trim($time)) === 1;
    }
}
