<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class UsersApi
{
    public function getAllUsers(array $data = []): void
    {
        api_require_role('admin');
        api_ok((new UserModel(api_conn()))->all());
    }

    public function createUser(array $data): void
    {
        $user = api_require_role('admin');
        (new UserModel(api_conn()))->create($data, (int) $user['id']);
        api_from_flash('User created.');
    }

    public function resetPassword(array $data): void
    {
        $user = api_require_role('admin');
        (new UserModel(api_conn()))->resetPassword(
            (int) ($data['id'] ?? 0),
            (string) ($data['password'] ?? ''),
            (int) $user['id']
        );
        api_from_flash('Password reset.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new UsersApi(), $operation, $data);
