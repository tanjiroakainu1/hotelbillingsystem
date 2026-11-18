<?php
declare(strict_types=1);

function process_profile_request(mysqli $conn, int $userId, array $post): void
{
    $users = new UserModel($conn);
    $action = $post['action'] ?? '';

    if ($action === 'update_profile') {
        if ($users->updateProfile($userId, $post, $userId)) {
            refresh_current_user($conn);
        }
        return;
    }

    if ($action === 'change_password') {
        $users->changePassword(
            $userId,
            (string) ($post['current_password'] ?? ''),
            (string) ($post['new_password'] ?? ''),
            (string) ($post['confirm_password'] ?? ''),
            $userId
        );
        return;
    }

    flash('error', 'Invalid profile action.');
}

function profile_present_data(mysqli $conn, int $userId, bool $showGender): array
{
    $users = new UserModel($conn);
    $profile = $users->find($userId);
    return [
        'profile' => $profile,
        'showGender' => $showGender,
    ];
}
