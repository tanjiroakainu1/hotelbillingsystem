<?php
declare(strict_types=1);

final class RoomModel
{
    public function __construct(private mysqli $conn) {}

    public function all(): array
    {
        return $this->conn->query(
            'SELECT r.*, rt.name AS room_type_name, rt.base_rate,
                    c.name AS category_name, c.category_type
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             LEFT JOIN categories c ON c.id = r.category_id
             ORDER BY r.room_number'
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function count(): int
    {
        return (int) $this->conn->query('SELECT COUNT(*) c FROM rooms')->fetch_assoc()['c'];
    }

    public function occupiedCount(): int
    {
        return (int) $this->conn->query("SELECT COUNT(*) c FROM rooms WHERE status='occupied'")->fetch_assoc()['c'];
    }

    public function available(): array
    {
        return $this->conn->query(
            "SELECT r.id, r.room_number, r.room_type, r.room_type_id, r.category_id, r.rate_per_night,
                    r.capacity, r.max_adults, r.max_children, r.photo, r.room_features
             FROM rooms r WHERE r.status='available' ORDER BY r.room_number"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function activeForGuest(int $guestId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_number, r.room_type, r.room_type_id, r.photo, r.room_features,
                    b.id AS booking_id, b.status AS booking_status, b.check_in, b.check_out
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             WHERE b.guest_id = ? AND b.status IN ('reserved','checked_in')
             ORDER BY r.room_number"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rooms as &$room) {
            $room['photo_url'] = room_photo_url(
                isset($room['photo']) ? (string) $room['photo'] : null,
                isset($room['room_type']) ? (string) $room['room_type'] : null
            );
        }
        unset($room);
        return $rooms;
    }

    public function availableForDates(string $checkIn, string $checkOut, int $excludeAppointmentId = 0): array
    {
        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_number, r.room_type, r.room_type_id, r.category_id, r.rate_per_night,
                    r.capacity, r.max_adults, r.max_children, r.photo, r.room_features
             FROM rooms r
             WHERE r.status NOT IN ('maintenance','cleaning')
               AND NOT EXISTS (
                 SELECT 1 FROM bookings b
                 WHERE b.room_id = r.id AND b.status IN ('reserved','checked_in')
                   AND b.check_in < ? AND b.check_out > ?
               )
               AND NOT EXISTS (
                 SELECT 1
                 FROM room_appointment_rooms appointment_room
                 JOIN room_appointments appointment ON appointment.id = appointment_room.appointment_id
                 WHERE appointment_room.room_id = r.id
                   AND appointment.id <> ? AND appointment.status = 'confirmed'
                   AND appointment.converted_at IS NULL
                   AND appointment.check_in < ? AND appointment.check_out > ?
               )
             ORDER BY r.room_number"
        );
        $stmt->bind_param('ssiss', $checkOut, $checkIn, $excludeAppointmentId, $checkOut, $checkIn);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Public home: available rooms only, with photo + features for showcase. */
    public function publicAvailable(): array
    {
        return $this->conn->query(
            "SELECT r.id, r.room_number, r.room_type, r.room_type_id, r.rate_per_night,
                    r.floor, r.capacity, r.max_adults, r.max_children, r.room_features, r.photo, r.status,
                    rt.name AS room_type_name, rt.description AS room_type_description
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.status = 'available'
             ORDER BY r.rate_per_night, r.room_number"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM rooms WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function managementList(string $date): array
    {
        $stmt = $this->conn->prepare(
                "SELECT r.id, r.room_number, r.room_type, r.rate_per_night, r.status,
                    EXISTS (
                        SELECT 1 FROM housekeeping_tasks pending_task
                        WHERE pending_task.room_id = r.id AND pending_task.status <> 'completed'
                    ) AS housekeeping_pending,
                    b.id AS booking_id, b.check_in, b.check_out, b.check_in_time, b.check_out_time, b.status AS booking_status,
                    b.adults, b.children, b.notes AS booking_notes,
                    g.full_name AS guest_name, g.email AS guest_email, g.phone AS guest_phone
             FROM rooms r
             LEFT JOIN bookings b ON b.id = (
                 SELECT active.id FROM bookings active
                 WHERE active.room_id = r.id AND active.status IN ('reserved','checked_in')
                   AND active.check_in <= ? AND active.check_out > ?
                 ORDER BY FIELD(active.status,'checked_in','reserved'), active.check_in, active.id
                 LIMIT 1
             )
             LEFT JOIN guests g ON g.id = b.guest_id
             ORDER BY r.room_number"
        );
        $stmt->bind_param('ss', $date, $date);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function resolveType(array $data, ?array $existing = null): array
    {
        $typeId = (int) ($data['room_type_id'] ?? $existing['room_type_id'] ?? 0);
        $typeName = trim((string) ($data['room_type'] ?? $existing['room_type'] ?? ''));
        $rate = (float) ($data['rate_per_night'] ?? $existing['rate_per_night'] ?? 0);

        if ($typeId > 0) {
            $rt = (new RoomTypeModel($this->conn))->find($typeId);
            if ($rt) {
                $typeName = (string) $rt['name'];
                if ($rate <= 0) {
                    $rate = (float) $rt['base_rate'];
                }
            }
        }

        return [$typeId, $typeName, $rate];
    }

    /** Per-room guest limits: [max adults, max children]. */
    private function guestLimits(array $data, ?array $existing = null): array
    {
        $adults = (int) ($data['max_adults'] ?? $existing['max_adults'] ?? 2);
        $children = (int) ($data['max_children'] ?? $existing['max_children'] ?? 1);
        return [max(1, min(20, $adults)), max(0, min(20, $children))];
    }
    public function create(array $data, array $files, int $actorId): bool
    {
        $number = trim((string) ($data['room_number'] ?? ''));
        $floor = (int) ($data['floor'] ?? 1);
        [$maxAdults, $maxChildren] = $this->guestLimits($data);
        $capacity = $maxAdults + $maxChildren;
        $roomFeatures = trim((string) ($data['room_features'] ?? ''));
        $status = (string) ($data['status'] ?? 'available');
        [$typeId, $typeName, $rate] = $this->resolveType($data);

        if ($number === '' || $typeId <= 0 || $typeName === '' || $rate <= 0) {
            flash('error', 'Room number, room type, and rate are required.');
            return false;
        }

        if ($roomFeatures === '') {
            $rt = (new RoomTypeModel($this->conn))->find($typeId);
            $roomFeatures = trim((string) ($rt['room_features'] ?? ''));
        }

        $photoResult = store_room_photo($files['photo'] ?? []);
        if (!$photoResult['ok']) {
            flash('error', $photoResult['message']);
            return false;
        }
        $photo = $photoResult['path'];

        try {
            $this->conn->begin_transaction();
            $stmt = $this->conn->prepare(
                'INSERT INTO rooms (room_number, room_type_id, room_type, rate_per_night, floor, capacity, max_adults, max_children, room_features, photo, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sisdiiiisss', $number, $typeId, $typeName, $rate, $floor, $capacity, $maxAdults, $maxChildren, $roomFeatures, $photo, $status);
            $stmt->execute();
            $roomId = (int) $this->conn->insert_id;
            $inventory = $this->conn->prepare(
                "INSERT IGNORE INTO room_amenities (room_id, amenity_id)
                 SELECT ?, id FROM housekeeping_amenities WHERE status = 'active'"
            );
            $inventory->bind_param('i', $roomId);
            $inventory->execute();
            audit($this->conn, $actorId, 'room_create', "Room {$number}");
            $this->conn->commit();
            flash('success', 'Room added.');
            return true;
        } catch (Throwable) {
            if ($this->conn->errno !== 0) {
                $this->conn->rollback();
            }
            if ($photo && is_file(app_root() . '/' . $photo)) {
                @unlink(app_root() . '/' . $photo);
            }
            flash('error', 'Room number may already exist.');
            return false;
        }
    }

    public function update(array $data, array $files, int $actorId): void
    {
        $id = (int) ($data['id'] ?? 0);
        $existing = $this->find($id);
        if (!$existing) {
            flash('error', 'Room not found.');
            return;
        }

        [$typeId, $typeName, $rate] = $this->resolveType($data, $existing);
        $status = (string) ($data['status'] ?? $existing['status']);
        $roomFeatures = trim((string) ($data['room_features'] ?? $existing['room_features'] ?? ''));
        [$maxAdults, $maxChildren] = $this->guestLimits($data, $existing);
        $capacity = $maxAdults + $maxChildren;

        if ($typeId <= 0 || $typeName === '' || $rate <= 0) {
            flash('error', 'Room type and rate are required.');
            return;
        }
        if (!in_array($status, ['available', 'occupied', 'maintenance', 'reserved', 'cleaning'], true)) {
            flash('error', 'Invalid room status.');
            return;
        }

        $photoResult = store_room_photo($files['photo'] ?? [], $existing['photo'] ?? null);
        if (!$photoResult['ok']) {
            flash('error', $photoResult['message']);
            return;
        }
        $photo = $photoResult['path'];

        $stmt = $this->conn->prepare(
            'UPDATE rooms SET room_type_id=?, room_type=?, rate_per_night=?, status=?, capacity=?, max_adults=?, max_children=?, room_features=?, photo=? WHERE id=?'
        );
        $stmt->bind_param('isdsiiissi', $typeId, $typeName, $rate, $status, $capacity, $maxAdults, $maxChildren, $roomFeatures, $photo, $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'room_update', "Updated room #{$id}");
        flash('success', 'Room updated.');
    }

    public function delete(int $id, int $actorId): bool
    {
        $existing = $this->find($id);
        if (!$existing) {
            flash('error', 'Room not found.');
            return false;
        }
        $active = $this->conn->query(
            "SELECT COUNT(*) c FROM bookings WHERE room_id={$id} AND status IN ('reserved','checked_in')"
        )->fetch_assoc();
        if ((int) ($active['c'] ?? 0) > 0) {
            flash('error', 'Cannot delete a room with active bookings.');
            return false;
        }
        $stmt = $this->conn->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if (!empty($existing['photo']) && is_file(app_root() . '/' . $existing['photo'])) {
            @unlink(app_root() . '/' . $existing['photo']);
        }
        audit($this->conn, $actorId, 'room_delete', 'Room ' . $existing['room_number']);
        flash('success', 'Room deleted.');
        return true;
    }

    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->conn->prepare('UPDATE rooms SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
    }

    public function setOperationalStatus(int $id, string $status, int $actorId): bool
    {
        if ($id <= 0 || !in_array($status, ['available', 'maintenance', 'cleaning'], true)) {
            flash('error', 'Choose Available, Maintenance, or Cleaning.');
            return false;
        }

        $room = $this->find($id);
        if (!$room) {
            flash('error', 'Room not found.');
            return false;
        }
        $active = $this->conn->prepare(
            "SELECT id FROM bookings WHERE room_id = ? AND status IN ('reserved','checked_in')
             AND check_in <= CURRENT_DATE AND check_out > CURRENT_DATE LIMIT 1"
        );
        $active->bind_param('i', $id);
        $active->execute();
        if ($active->get_result()->fetch_assoc()) {
            flash('error', 'Room status cannot be changed while a reservation or check-in is active.');
            return false;
        }
        if ($status === 'available') {
            $pending = $this->conn->prepare(
                "SELECT id FROM housekeeping_tasks WHERE room_id = ? AND status <> 'completed' LIMIT 1"
            );
            $pending->bind_param('i', $id);
            $pending->execute();
            if ($pending->get_result()->fetch_assoc()) {
                flash('error', 'Complete the housekeeping inspection before marking this room available.');
                return false;
            }
        }

        $stmt = $this->conn->prepare('UPDATE rooms SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'room_status_update', "Room {$room['room_number']} set to {$status}");
        flash('success', "Room {$room['room_number']} status updated.");
        return true;
    }

    public function syncOperationalStatus(int $id): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE rooms r SET r.status = CASE
                WHEN r.status = 'maintenance' THEN 'maintenance'
                WHEN r.status = 'cleaning' THEN 'cleaning'
                WHEN EXISTS (
                    SELECT 1 FROM bookings b
                    WHERE b.room_id = r.id AND b.status = 'checked_in'
                      AND b.check_in <= CURRENT_DATE AND b.check_out > CURRENT_DATE
                ) THEN 'occupied'
                WHEN EXISTS (
                    SELECT 1 FROM bookings b
                    WHERE b.room_id = r.id AND b.status = 'reserved'
                      AND b.check_in <= CURRENT_DATE AND b.check_out > CURRENT_DATE
                ) THEN 'reserved'
                ELSE 'available'
             END WHERE r.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
    }
}
