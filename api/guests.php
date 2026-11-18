<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class GuestsApi
{
    public function getAllGuests(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $q = trim((string) ($data['q'] ?? ''));
        $model = new GuestModel(api_conn());
        api_ok($q !== '' ? $model->search($q) : $model->all());
    }

    public function getRegisteredGuests(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $q = trim((string) ($data['q'] ?? ''));
        api_ok((new GuestModel(api_conn()))->registrations($q !== '' ? $q : null));
    }

    public function getSimpleGuests(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        api_ok((new GuestModel(api_conn()))->listSimple());
    }

    public function createGuest(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        (new GuestModel(api_conn()))->register($data, (int) $user['id']);
        api_from_flash('Guest registered.');
    }

    public function createQuickGuest(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        (new GuestModel(api_conn()))->registerQuick($data, (int) $user['id']);
        api_from_flash('Guest registered. The guest’s last name is their initial portal password.');
    }

    public function updateGuest(array $data): void
    {
        api_require_role('frontdesk', 'admin');
        (new GuestModel(api_conn()))->update((int) ($data['id'] ?? 0), $data);
        api_from_flash('Guest updated.');
    }

    public function resetPortalPassword(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        (new GuestModel(api_conn()))->resetPortalPassword(
            (int) ($data['id'] ?? 0),
            (string) ($data['password'] ?? ''),
            (int) $user['id']
        );
        api_ok(null, 'Guest portal password reset.');
    }

    public function setPortalStatus(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        (new GuestModel(api_conn()))->setPortalStatus(
            (int) ($data['id'] ?? 0),
            (string) ($data['status'] ?? ''),
            (int) $user['id']
        );
        api_ok(null, 'Guest portal account status updated.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new GuestsApi(), $operation, $data);
