<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class AdditionalItemsApi
{
    public function getAllItems(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new AdditionalItemModel(api_conn()))->all());
    }

    public function getActiveItems(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new AdditionalItemModel(api_conn()))->active());
    }

    public function createItem(array $data): void
    {
        $user = api_require_role('admin');
        (new AdditionalItemModel(api_conn()))->create($data, (int) $user['id'], $_FILES);
        api_from_flash('Item created.');
    }

    public function updateItem(array $data): void
    {
        $user = api_require_role('admin');
        (new AdditionalItemModel(api_conn()))->update($data, (int) $user['id'], $_FILES);
        api_from_flash('Item updated.');
    }

    public function deleteItem(array $data): void
    {
        $user = api_require_role('admin');
        (new AdditionalItemModel(api_conn()))->delete((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Item archived.');
    }

    public function restoreItem(array $data): void
    {
        $user = api_require_role('admin');
        (new AdditionalItemModel(api_conn()))->restore((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Item restored.');
    }

    public function permanentlyDeleteItem(array $data): void
    {
        $user = api_require_role('admin');
        (new AdditionalItemModel(api_conn()))->permanentlyDelete((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Item permanently deleted.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new AdditionalItemsApi(), $operation, $data);
