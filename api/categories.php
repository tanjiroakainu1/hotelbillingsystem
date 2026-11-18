<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class CategoriesApi
{
    public function getAllCategories(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        $type = (string) ($data['category_type'] ?? '');
        api_ok((new CategoryModel(api_conn()))->all($type !== '' ? $type : null));
    }

    public function getServiceCategories(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok([
            ['name' => 'Food', 'description' => 'Meals, drinks, and in-room food service'],
            ['name' => 'Laundry', 'description' => 'Washing, ironing, and dry cleaning'],
            ['name' => 'Items', 'description' => 'Guest comfort and supply items'],
        ]);
    }

    public function getRoomCategories(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new RoomTypeModel(api_conn()))->active());
    }

    /** Public (no login): room types for the home page. */
    public function getPublicRoomTypes(array $data = []): void
    {
        api_ok((new RoomTypeModel(api_conn()))->publicShowcase());
    }

}

[$operation, $data] = array_values(api_input());
api_dispatch(new CategoriesApi(), $operation, $data);
