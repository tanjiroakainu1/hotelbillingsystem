<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class HousekeepingApi
{
    public function getManagementPage(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new HousekeepingModel(api_conn()))->managementPage());
    }

    public function assignTask(array $data): void
    {
        $user = api_require_role('admin', 'frontdesk');
        try {
            (new HousekeepingModel(api_conn()))->assign(
                (int) ($data['task_id'] ?? 0),
                (int) ($data['assigned_to'] ?? 0),
                (int) $user['id']
            );
        } catch (InvalidArgumentException $error) {
            api_error($error->getMessage());
        }
        api_ok(null, 'Housekeeping task assigned.');
    }

    public function getStaffPage(array $data = []): void
    {
        $user = api_require_role('housekeeping');
        api_ok((new HousekeepingModel(api_conn()))->staffPage((int) $user['id']));
    }

    public function claimTask(array $data): void
    {
        $user = api_require_role('housekeeping');
        try {
            (new HousekeepingModel(api_conn()))->claim(
                (int) ($data['task_id'] ?? 0),
                (int) $user['id']
            );
        } catch (InvalidArgumentException $error) {
            api_error($error->getMessage());
        }
        api_ok(null, 'Housekeeping task claimed.');
    }

    public function saveInspection(array $data): void
    {
        $user = api_require_role('housekeeping');
        $items = $data['items'] ?? [];
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        if (!is_array($items)) {
            api_error('The room checklist is invalid.');
        }
        try {
            (new HousekeepingModel(api_conn()))->saveInspection(
                (int) ($data['task_id'] ?? 0),
                (int) $user['id'],
                $items,
                filter_var($data['complete'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
        } catch (InvalidArgumentException $error) {
            api_error($error->getMessage());
        }
        api_ok(
            null,
            filter_var($data['complete'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? 'Inspection completed. Room status updated.'
                : 'Inspection progress saved.'
        );
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new HousekeepingApi(), $operation, $data);
