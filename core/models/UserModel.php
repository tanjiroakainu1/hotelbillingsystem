<?php
declare(strict_types=1);

final class UserModel
{
    public function __construct(private mysqli $conn) {}

    public function all(): array
    {
        return $this->conn->query(
            'SELECT id, full_name, first_name, last_name, email, role, phone, status, created_at FROM users ORDER BY role, full_name'
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function count(): int
    {
        return (int) $this->conn->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'];
    }

    public function create(array $data, int $actorId): bool
    {
        $name = trim($data['full_name'] ?? '');
        $nameParts = split_person_name($name);
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $phoneCheck = validate_phone($phone);
        if (!$phoneCheck['ok']) {
            flash('error', $phoneCheck['message']);
            return false;
        }
        $phone = $phoneCheck['phone'];
        $role = $data['role'] ?? 'frontdesk';
        $password = $data['password'] ?? '';
        if (!in_array($role, ['admin', 'frontdesk', 'guest', 'kitchen', 'laundry', 'housekeeping'], true)) {
            flash('error', 'Invalid role.');
            return false;
        }
        if ($name === '' || $email === '' || strlen($password) < 6) {
            flash('error', 'Name, email, and password (6+) are required.');
            return false;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $this->conn->begin_transaction();
            $stmt = $this->conn->prepare(
                'INSERT INTO users (full_name, first_name, last_name, email, password, role, phone, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "active")'
            );
            $stmt->bind_param('sssssss', $name, $nameParts['first_name'], $nameParts['last_name'], $email, $hash, $role, $phone);
            $stmt->execute();
            $uid = (int) $this->conn->insert_id;
            if ($role === 'guest') {
                $g = $this->conn->prepare(
                    'INSERT INTO guests (user_id, full_name, first_name, last_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $g->bind_param('isssss', $uid, $name, $nameParts['first_name'], $nameParts['last_name'], $email, $phone);
                $g->execute();
            }
            audit($this->conn, $actorId, 'user_create', "Created user {$email} as {$role}");
            $this->conn->commit();
            flash('success', 'User created successfully.');
            return true;
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Could not create user. Email may already exist.');
            return false;
        }
    }

    public function resetPassword(int $id, string $password, int $actorId): bool
    {
        if (!$id || strlen($password) < 6) {
            flash('error', 'Password must be at least 6 characters.');
            return false;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'user_password_reset', "Reset password for user #{$id}");
        flash('success', 'Password reset.');
        return true;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT id, full_name, email, role, phone, gender, status, created_at FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function updateProfile(int $userId, array $data, int $actorId): bool
    {
        $user = $this->find($userId);
        if (!$user) {
            flash('error', 'Account not found.');
            return false;
        }

        $name = trim($data['full_name'] ?? '');
        $nameParts = split_person_name($name);
        $email = trim($data['email'] ?? '');
        $phoneCheck = validate_phone($data['phone'] ?? '');
        if (!$phoneCheck['ok']) {
            flash('error', $phoneCheck['message']);
            return false;
        }
        $phone = $phoneCheck['phone'];
        $gender = $user['gender'];

        if ($name === '' || $email === '') {
            flash('error', 'Name and email are required.');
            return false;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid email address.');
            return false;
        }

        if ($user['role'] === 'guest') {
            $gender = $data['gender'] ?? '';
            if (!in_array($gender, ['male', 'female'], true)) {
                flash('error', 'Please select gender (Male or Female).');
                return false;
            }
        }

        $check = $this->conn->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $check->bind_param('si', $email, $userId);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            flash('error', 'Email is already used by another account.');
            return false;
        }

        try {
            $this->conn->begin_transaction();
            $stmt = $this->conn->prepare(
                'UPDATE users SET full_name = ?, first_name = ?, last_name = ?, email = ?, phone = ?, gender = ? WHERE id = ?'
            );
            $stmt->bind_param('ssssssi', $name, $nameParts['first_name'], $nameParts['last_name'], $email, $phone, $gender, $userId);
            $stmt->execute();

            if ($user['role'] === 'guest') {
                $guest = $this->conn->prepare(
                    'UPDATE guests SET full_name = ?, first_name = ?, last_name = ?, email = ?, phone = ?, gender = ? WHERE user_id = ?'
                );
                $guest->bind_param('ssssssi', $name, $nameParts['first_name'], $nameParts['last_name'], $email, $phone, $gender, $userId);
                $guest->execute();
            }

            audit($this->conn, $actorId, 'profile_update', "Updated profile for user #{$userId}");
            $this->conn->commit();
            flash('success', 'Profile updated successfully.');
            return true;
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Could not update profile.');
            return false;
        }
    }

    public function changePassword(int $userId, string $current, string $new, string $confirm, int $actorId): bool
    {
        if ($current === '') {
            flash('error', 'Current password is required.');
            return false;
        }
        if (strlen($new) < 6) {
            flash('error', 'New password must be at least 6 characters.');
            return false;
        }
        if ($new !== $confirm) {
            flash('error', 'New passwords do not match.');
            return false;
        }

        $stmt = $this->conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !password_verify($current, $row['password'])) {
            flash('error', 'Current password is incorrect.');
            return false;
        }
        if (password_verify($new, $row['password'])) {
            flash('error', 'New password must be different from your current password.');
            return false;
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $this->conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $upd->bind_param('si', $hash, $userId);
        $upd->execute();
        audit($this->conn, $actorId, 'password_change', "Changed password for user #{$userId}");
        flash('success', 'Password changed successfully.');
        return true;
    }
}
