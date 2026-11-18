<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class DiscountsApi
{
    public function getAllDiscounts(array $data = []): void
    {
        api_require_role('admin');
        $model = new DiscountModel(api_conn());
        api_ok([
            'all' => $model->all(),
            'senior' => $model->findByCode('senior_citizen'),
            'pwd' => $model->findByCode('pwd'),
            'employee' => $model->findByCode('employee'),
            'custom' => $model->custom(),
        ]);
    }

    public function getActiveDiscounts(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new DiscountModel(api_conn()))->active());
    }

    public function updateSenior(array $data): void
    {
        $user = api_require_role('admin');
        (new DiscountModel(api_conn()))->updateSystem('senior_citizen', $data, (int) $user['id']);
        api_from_flash('Senior Citizen discount updated.');
    }

    public function updatePwd(array $data): void
    {
        $user = api_require_role('admin');
        (new DiscountModel(api_conn()))->updateSystem('pwd', $data, (int) $user['id']);
        api_from_flash('PWD discount updated.');
    }

    public function updateEmployee(array $data): void
    {
        $user = api_require_role('admin');
        (new DiscountModel(api_conn()))->updateSystem('employee', $data, (int) $user['id']);
        api_from_flash('Employee discount updated.');
    }

    public function createDiscount(array $data): void
    {
        $user = api_require_role('admin');
        (new DiscountModel(api_conn()))->create($data, (int) $user['id']);
        api_from_flash('Discount created.');
    }

    public function updateDiscount(array $data): void
    {
        $user = api_require_role('admin');
        (new DiscountModel(api_conn()))->update($data, (int) $user['id']);
        api_from_flash('Discount updated.');
    }

    public function deleteDiscount(array $data): void
    {
        $user = api_require_role('admin');
        (new DiscountModel(api_conn()))->delete((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Discount deleted.');
    }

    public function toggleDiscount(array $data): void
    {
        $user = api_require_role('admin');
        (new DiscountModel(api_conn()))->toggle((int) ($data['id'] ?? 0), (int) $user['id']);
        api_from_flash('Discount status updated.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new DiscountsApi(), $operation, $data);
