<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once APP_ROOT . '/includes/profile_handler.php';

class ProfileApi
{
    public function getProfile(array $data = []): void
    {
        $user = api_require_login();
        $profile = (new UserModel(api_conn()))->find((int) $user['id']);
        if (!$profile) {
            api_error('Account not found.', 404);
        }
        unset($profile['password']);
        api_ok([
            'profile' => $profile,
            'show_gender' => $user['role'] === 'guest',
        ]);
    }

    public function updateProfile(array $data): void
    {
        $user = api_require_login();
        $data['action'] = 'update_profile';
        process_profile_request(api_conn(), (int) $user['id'], $data);
        api_from_flash('Profile updated.');
    }

    public function changePassword(array $data): void
    {
        $user = api_require_login();
        $data['action'] = 'change_password';
        process_profile_request(api_conn(), (int) $user['id'], $data);
        api_from_flash('Password changed.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new ProfileApi(), $operation, $data);
