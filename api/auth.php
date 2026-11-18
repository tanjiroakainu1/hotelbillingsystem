<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class AuthApi
{
    public function login(array $data): void
    {
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if ($email === '' || $password === '') {
            api_error('Email and password are required.');
        }
        if (!attempt_login(api_conn(), $email, $password)) {
            api_error('Invalid email or password.', 401);
        }
        $user = current_user();
        api_ok([
            'user' => $user,
            'home' => role_home($user['role']),
        ], 'Signed in successfully.');
    }

    public function register(array $data): void
    {
        api_error('Public registration is disabled. Ask Front Desk to register your guest account.', 403);
    }

    public function logout(array $data = []): void
    {
        $user = current_user();
        if ($user) {
            audit(api_conn(), (int) $user['id'], 'logout', 'User signed out');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        api_ok(null, 'Signed out.');
    }

    public function me(array $data = []): void
    {
        $user = current_user();
        if (!$user) {
            api_ok(['user' => null, 'authenticated' => false]);
        }
        api_ok([
            'user' => $user,
            'authenticated' => true,
            'home' => role_home($user['role']),
            'hotel_name' => (string) hotel_config('hotel_name'),
        ]);
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new AuthApi(), $operation, $data);
