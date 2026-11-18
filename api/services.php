<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class ServicesApi
{
    public function getAllServices(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new ServiceModel(api_conn()))->all());
    }

    public function getActiveServices(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new ServiceModel(api_conn()))->active());
    }

    public function createService(array $data): void
    {
        $user = api_require_role('admin');
        $services = new ServiceModel(api_conn());
        if (!$services->create($data, $_FILES)) {
            api_error('Could not create service.');
        }
        audit(api_conn(), (int) $user['id'], 'service_create', (string) ($data['name'] ?? ''));
        api_ok(null, 'Service created.');
    }

    public function updateService(array $data): void
    {
        $user = api_require_role('admin');
        (new ServiceModel(api_conn()))->update($data, $_FILES, (int) $user['id']);
        api_from_flash('Service updated.');
    }

    public function deleteService(array $data): void
    {
        $user = api_require_role('admin');
        (new ServiceModel(api_conn()))->delete((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Service archived.');
    }

    public function restoreService(array $data): void
    {
        $user = api_require_role('admin');
        (new ServiceModel(api_conn()))->restore((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Service restored.');
    }

    public function permanentlyDeleteService(array $data): void
    {
        $user = api_require_role('admin');
        (new ServiceModel(api_conn()))->permanentlyDelete((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Service permanently deleted.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new ServicesApi(), $operation, $data);
