<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class RoomsApi
{
    public function getAllRooms(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        $rooms = (new RoomModel(api_conn()))->all();
        foreach ($rooms as &$r) {
            $r['photo_url'] = room_photo_url($r['photo'] ?? null, $r['room_type_name'] ?? $r['room_type'] ?? null);
        }
        api_ok($rooms);
    }

    public function getRoomManagement(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        $date = (string) ($data['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        api_ok((new RoomModel(api_conn()))->managementList($date));
    }

    public function setOperationalStatus(array $data): void
    {
        $user = api_require_role('admin', 'frontdesk');
        (new RoomModel(api_conn()))->setOperationalStatus(
            (int) ($data['id'] ?? 0),
            (string) ($data['status'] ?? ''),
            (int) $user['id']
        );
        api_from_flash('Room status updated.');
    }

    public function getAvailableRoomsForDates(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        $checkIn = (string) ($data['check_in'] ?? '');
        $checkOut = (string) ($data['check_out'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)
            || $checkOut <= $checkIn) {
            api_error('Choose valid check-in and check-out dates.');
        }
        $appointmentId = (int) ($data['appointment_id'] ?? 0);
        if ($appointmentId > 0) {
            $stmt = api_conn()->prepare(
                "SELECT id FROM room_appointments
                 WHERE id = ? AND status IN ('pending','confirmed') AND converted_at IS NULL"
            );
            $stmt->bind_param('i', $appointmentId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                api_error('Only an approved, not-yet-checked-in appointment can be loaded for check-in.');
            }
        }
        api_ok((new RoomModel(api_conn()))->availableForDates($checkIn, $checkOut, $appointmentId));
    }

    public function getAvailableRooms(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new RoomModel(api_conn()))->available());
    }

    /** Public (no login): currently available rooms with photos for the home page. */
    public function getPublicAvailableRooms(array $data = []): void
    {
        $rooms = (new RoomModel(api_conn()))->publicAvailable();
        foreach ($rooms as &$r) {
            $type = $r['room_type_name'] ?? $r['room_type'] ?? null;
            $r['photo_url'] = room_photo_url($r['photo'] ?? null, is_string($type) ? $type : null);
        }
        api_ok($rooms);
    }

    public function createRoom(array $data): void
    {
        $user = api_require_role('admin');
        (new RoomModel(api_conn()))->create($data, $_FILES, (int) $user['id']);
        api_from_flash('Room created.');
    }

    public function updateRoom(array $data): void
    {
        $user = api_require_role('admin');
        (new RoomModel(api_conn()))->update($data, $_FILES, (int) $user['id']);
        api_from_flash('Room updated.');
    }

    public function deleteRoom(array $data): void
    {
        $user = api_require_role('admin');
        (new RoomModel(api_conn()))->delete((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Room deleted.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new RoomsApi(), $operation, $data);
