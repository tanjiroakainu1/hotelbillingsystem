<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class RoomTypesApi
{
    public function getAllRoomTypes(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new RoomTypeModel(api_conn()))->all());
    }

    public function getActiveRoomTypes(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new RoomTypeModel(api_conn()))->active());
    }

    public function getPublicRoomTypes(array $data = []): void
    {
        api_ok((new RoomTypeModel(api_conn()))->publicShowcase());
    }

    public function createRoomType(array $data): void
    {
        $user = api_require_role('admin');
        (new RoomTypeModel(api_conn()))->create($data, (int) $user['id']);
        api_from_flash('Room type created.');
    }

    public function updateRoomType(array $data): void
    {
        $user = api_require_role('admin');
        (new RoomTypeModel(api_conn()))->update($data, (int) $user['id']);
        api_from_flash('Room type updated.');
    }

    public function deleteRoomType(array $data): void
    {
        $user = api_require_role('admin');
        (new RoomTypeModel(api_conn()))->delete((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Room type deleted.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new RoomTypesApi(), $operation, $data);
