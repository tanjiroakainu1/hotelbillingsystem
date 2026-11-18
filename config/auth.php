<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please sign in to continue.');
        redirect(base_path('login.html'));
    }
}

function require_role(string ...$roles): void
{
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        flash('error', 'You do not have access to that area.');
        redirect(role_home($user['role'] ?? 'guest'));
    }
}

function base_path(string $file = ''): string
{
    // Absolute web path from project root (works from any role folder)
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $projectRoot = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: '');
    $relative = '';
    if ($docRoot && str_starts_with($projectRoot, $docRoot)) {
        $relative = substr($projectRoot, strlen($docRoot));
    } else {
        $relative = '/hotelbillingsystem';
    }
    $relative = '/' . trim($relative, '/');
    if ($relative === '/') {
        $relative = '';
    }
    return $relative . '/' . ltrim($file, '/');
}

function role_home(string $role): string
{
    return match ($role) {
        'admin' => base_path('admin/index.html'),
        'frontdesk' => base_path('frontdesk/index.html'),
        'guest' => base_path('guest/index.html'),
        'kitchen' => base_path('kitchen/index.html'),
        'laundry' => base_path('laundry/index.html'),
        'housekeeping' => base_path('housekeeping/index.html'),
        default => base_path('index.html'),
    };
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'phone' => $user['phone'] ?? null,
        'gender' => $user['gender'] ?? null,
    ];
}

function attempt_login(mysqli $conn, string $email, string $password): bool
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }
    login_user($user);
    audit($conn, (int) $user['id'], 'login', 'User signed in');
    return true;
}

/** @deprecated Public self-registration removed — Front Desk uses GuestModel::register + guest_registrations. */
function register_guest(mysqli $conn, array $data): array
{
    return [
        'ok' => false,
        'message' => 'Public registration is disabled. Ask Front Desk to register your guest account.',
    ];
}

function guest_profile_id(mysqli $conn, int $userId): ?int
{
    $stmt = $conn->prepare('SELECT id FROM guests WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : null;
}

function refresh_current_user(mysqli $conn): void
{
    $user = current_user();
    if (!$user) {
        return;
    }
    $stmt = $conn->prepare(
        'SELECT id, full_name, email, role, phone, gender, status FROM users WHERE id = ? AND status = "active" LIMIT 1'
    );
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        login_user($row);
    }
}
