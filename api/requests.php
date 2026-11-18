<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class RequestsApi
{
    public function getStaffPage(array $data = []): void
    {
        $user = api_require_role('admin', 'frontdesk', 'kitchen', 'laundry');
        $conn = api_conn();
        $status = (string) ($data['status'] ?? '');
        $types = match ($user['role']) {
            'kitchen' => ['food'],
            'laundry' => ['extra', 'laundry'],
            default => [],
        };
        $model = new GuestRequestModel($conn);
        $isManager = in_array($user['role'], ['admin', 'frontdesk'], true);
        api_ok([
            'requests' => $model->all(
                $status,
                $types,
                $user['role'] === 'laundry' ? (int) $user['id'] : null,
                in_array($user['role'], ['kitchen', 'laundry'], true)
            ),
            'catalog' => $isManager ? (new AdditionalItemModel($conn))->all() : [],
            'can_manage_catalog' => $user['role'] === 'admin',
            'queue_role' => $user['role'],
            'user_id' => (int) $user['id'],
            'assignable_staff' => $isManager
                ? $conn->query("SELECT id, full_name, email FROM users WHERE role = 'laundry' AND status = 'active' ORDER BY full_name, id")->fetch_all(MYSQLI_ASSOC)
                : [],
        ]);
    }

    public function getGuestPage(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        $model = new GuestRequestModel($conn);
        api_ok([
            'requests' => $model->forGuest($guestId),
            'catalog' => $model->catalogForGuest(),
            'booking' => (new BookingModel($conn))->checkedInForGuest($guestId),
            'bookings' => (new BookingModel($conn))->checkedInStaysForGuest($guestId),
        ]);
    }

    public function createRequest(array $data): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        (new GuestRequestModel($conn))->create($data, $guestId, (int) $user['id']);
        api_from_flash('Request submitted.');
    }

    public function updateRequestStatus(array $data): void
    {
        $user = api_require_role('kitchen', 'laundry');
        (new GuestRequestModel(api_conn()))->updateStatus(
            (int) ($data['id'] ?? 0),
            (string) ($data['status'] ?? ''),
            (int) $user['id'],
            (string) $user['role']
        );
        api_from_flash('Request status updated.');
    }

    public function assignRequest(array $data): void
    {
        $user = api_require_role('admin', 'frontdesk');
        (new GuestRequestModel(api_conn()))->assign(
            (int) ($data['id'] ?? 0),
            (int) ($data['assigned_to'] ?? 0),
            (int) $user['id']
        );
        api_from_flash('Request assigned.');
    }

    public function confirmDelivered(array $data): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        (new GuestRequestModel($conn))->confirmDelivered(
            (int) ($data['id'] ?? 0),
            $guestId,
            (int) $user['id']
        );
        api_from_flash('Delivery confirmed.');
    }

    public function reportNotDelivered(array $data): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        (new GuestRequestModel($conn))->reportNotDelivered(
            (int) ($data['id'] ?? 0),
            $guestId,
            (int) $user['id']
        );
        api_from_flash('Your delivery report was sent to staff.');
    }

    public function cancelRequest(array $data): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        (new GuestRequestModel($conn))->cancelByGuest(
            (int) ($data['id'] ?? 0),
            $guestId,
            (int) $user['id']
        );
        api_from_flash('Request cancelled.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new RequestsApi(), $operation, $data);
