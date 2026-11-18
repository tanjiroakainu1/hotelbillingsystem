<?php
declare(strict_types=1);

final class GuestModel
{
    public function __construct(private mysqli $conn) {}

    public function all(int $limit = 100): array
    {
        return $this->conn->query(
            "SELECT g.*,
                    CASE WHEN g.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_portal,
                    u.email AS portal_email, u.status AS portal_status
             FROM guests g
             LEFT JOIN users u ON u.id = g.user_id
             ORDER BY g.id DESC
             LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** Front desk guest_registrations log (linked guests + staff who registered them). */
    public function registrations(?string $q = null, int $limit = 200): array
    {
        $sql = "SELECT gr.*,
                       g.user_id,
                       CASE WHEN g.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_portal,
                       u.email AS portal_email,
                       u.status AS portal_status,
                       staff.full_name AS registered_by_name
                FROM guest_registrations gr
                JOIN guests g ON g.id = gr.guest_id
                LEFT JOIN users u ON u.id = g.user_id
                LEFT JOIN users staff ON staff.id = gr.registered_by";
        if ($q !== null && trim($q) !== '') {
            $like = '%' . trim($q) . '%';
            $stmt = $this->conn->prepare(
                $sql . ' WHERE gr.full_name LIKE ? OR gr.email LIKE ? OR gr.phone LIKE ?
                         ORDER BY gr.id DESC LIMIT ' . (int) $limit
            );
            $stmt->bind_param('sss', $like, $like, $like);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        return $this->conn->query($sql . ' ORDER BY gr.id DESC LIMIT ' . (int) $limit)->fetch_all(MYSQLI_ASSOC);
    }

    public function search(string $q): array
    {
        $like = '%' . $q . '%';
        $stmt = $this->conn->prepare(
            "SELECT g.*,
                    CASE WHEN g.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_portal,
                    u.email AS portal_email, u.status AS portal_status
             FROM guests g
             LEFT JOIN users u ON u.id = g.user_id
             WHERE g.full_name LIKE ? OR g.email LIKE ? OR g.phone LIKE ?
             ORDER BY g.id DESC"
        );
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function listSimple(): array
    {
        return $this->conn->query('SELECT id, full_name FROM guests ORDER BY full_name')->fetch_all(MYSQLI_ASSOC);
    }

    /** Guests for booking appointment, with portal user flag when linked. */
    public function listForBooking(): array
    {
        return $this->conn->query(
            "SELECT g.id, g.full_name, g.email, g.phone, g.user_id,
                    u.email AS user_email, u.status AS user_status,
                    CASE WHEN g.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_portal
             FROM guests g
             LEFT JOIN users u ON u.id = g.user_id
             ORDER BY has_portal DESC, g.full_name"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM guests WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function profileId(int $userId): ?int
    {
        $stmt = $this->conn->prepare('SELECT id FROM guests WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int) $row['id'] : null;
    }

    public function ensureProfile(int $userId, string $name, string $email, string $phone): int
    {
        $existing = $this->profileId($userId);
        if ($existing) {
            return $existing;
        }
        $nameParts = split_person_name($name);
        $stmt = $this->conn->prepare(
            'INSERT INTO guests (user_id, full_name, first_name, last_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $userId, $name, $nameParts['first_name'], $nameParts['last_name'], $email, $phone);
        $stmt->execute();
        return (int) $this->conn->insert_id;
    }

    public function registerQuick(array $data, int $actorId): bool
    {
        $nameParts = split_person_name(trim((string) ($data['full_name'] ?? '')));
        $data['password'] = $nameParts['last_name'];
        return $this->register($data, $actorId, true);
    }

    public function register(array $data, int $actorId, bool $quickRegister = false): bool
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
        $gender = (string) ($data['gender'] ?? '');
        if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
            flash('error', 'Please select a valid gender.');
            return false;
        }
        if ($gender === '') {
            $gender = null;
        }
        $address = trim($data['address'] ?? '');
        $idType = trim($data['id_type'] ?? '');
        $idNumber = trim($data['id_number'] ?? '');
        $notes = trim($data['notes'] ?? '');
        $createLogin = !empty($data['create_login']);
        $password = (string) ($data['password'] ?? '');
        if ($name === '') {
            flash('error', 'Guest name is required.');
            return false;
        }
        if ($createLogin) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'A valid email is required to create a portal login.');
                return false;
            }
            if ($quickRegister && $nameParts['last_name'] === '') {
                flash('error', 'Enter the guest’s first and last name to create a portal login.');
                return false;
            }
            if (!$quickRegister) {
                if (strlen($password) < 12) {
                    flash('error', 'Choose a unique portal password with at least 12 characters.');
                    return false;
                }
                $normalizedPassword = strtolower($password);
                $lastName = strtolower(trim($nameParts['last_name']));
                $emailName = strtolower((string) strstr($email, '@', true));
                if ((strlen($lastName) > 2 && str_contains($normalizedPassword, $lastName))
                    || (strlen($emailName) > 3 && str_contains($normalizedPassword, $emailName))) {
                    flash('error', 'Do not use the guest name or email in the portal password.');
                    return false;
                }
            }
        }
        try {
            $this->conn->begin_transaction();
            $guestId = 0;
            if ($createLogin) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $role = 'guest';
                $u = $this->conn->prepare(
                    'INSERT INTO users (full_name, first_name, last_name, email, password, role, phone, gender, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active")'
                );
                $u->bind_param(
                    'ssssssss',
                    $name,
                    $nameParts['first_name'],
                    $nameParts['last_name'],
                    $email,
                    $hash,
                    $role,
                    $phone,
                    $gender
                );
                $u->execute();
                $userId = (int) $this->conn->insert_id;
                $g = $this->conn->prepare(
                    'INSERT INTO guests (user_id, full_name, first_name, last_name, email, phone, gender, address, id_type, id_number, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $g->bind_param(
                    'issssssssss',
                    $userId,
                    $name,
                    $nameParts['first_name'],
                    $nameParts['last_name'],
                    $email,
                    $phone,
                    $gender,
                    $address,
                    $idType,
                    $idNumber,
                    $notes
                );
                $g->execute();
                $guestId = (int) $this->conn->insert_id;
            } else {
                $g = $this->conn->prepare(
                    'INSERT INTO guests (user_id, full_name, first_name, last_name, email, phone, gender, address, id_type, id_number, notes)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $g->bind_param(
                    'ssssssssss',
                    $name,
                    $nameParts['first_name'],
                    $nameParts['last_name'],
                    $email,
                    $phone,
                    $gender,
                    $address,
                    $idType,
                    $idNumber,
                    $notes
                );
                $g->execute();
                $guestId = (int) $this->conn->insert_id;
            }

            $portal = $createLogin ? 1 : 0;
            $reg = $this->conn->prepare(
                'INSERT INTO guest_registrations
                   (guest_id, registered_by, full_name, email, phone, gender, address, id_type, id_number, notes, portal_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $reg->bind_param(
                'iissssssssi',
                $guestId,
                $actorId,
                $name,
                $email,
                $phone,
                $gender,
                $address,
                $idType,
                $idNumber,
                $notes,
                $portal
            );
            $reg->execute();

            audit($this->conn, $actorId, 'guest_register', "Registered guest {$name}");
            $this->conn->commit();
            flash('success', 'Guest registered' . ($createLogin ? ' with portal login.' : '.'));
            return true;
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Could not register guest. Email may already exist for a login account.');
            return false;
        }
    }

    public function update(int $id, array $data): void
    {
        $name = trim($data['full_name'] ?? '');
        $nameParts = split_person_name($name);
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $phoneCheck = validate_phone($phone);
        if (!$phoneCheck['ok']) {
            flash('error', $phoneCheck['message']);
            return;
        }
        $phone = $phoneCheck['phone'];
        $address = trim($data['address'] ?? '');
        $stmt = $this->conn->prepare(
            'UPDATE guests SET full_name=?, first_name=?, last_name=?, email=?, phone=?, address=? WHERE id=?'
        );
        $stmt->bind_param(
            'ssssssi',
            $name,
            $nameParts['first_name'],
            $nameParts['last_name'],
            $email,
            $phone,
            $address,
            $id
        );
        $stmt->execute();
        flash('success', 'Guest profile updated.');
    }

    public function resetPortalPassword(int $guestId, string $password, int $actorId): void
    {
        if ($guestId <= 0 || strlen($password) < 12) {
            api_error('Choose a unique guest password with at least 12 characters.');
        }
        $stmt = $this->conn->prepare(
            "SELECT u.id, u.email, u.last_name
             FROM guests g JOIN users u ON u.id = g.user_id
             WHERE g.id = ? AND u.role = 'guest' LIMIT 1"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $guest = $stmt->get_result()->fetch_assoc();
        if (!$guest) {
            api_error('This guest does not have a portal account.');
        }
        $normalizedPassword = strtolower($password);
        $emailName = strtolower((string) strstr((string) $guest['email'], '@', true));
        $lastName = strtolower(trim((string) ($guest['last_name'] ?? '')));
        if ((strlen($lastName) > 2 && str_contains($normalizedPassword, $lastName))
            || (strlen($emailName) > 3 && str_contains($normalizedPassword, $emailName))) {
            api_error('Do not use the guest name or email in the new password.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $this->conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $userId = (int) $guest['id'];
        $update->bind_param('si', $hash, $userId);
        $update->execute();
        audit($this->conn, $actorId, 'guest_password_reset', "Reset portal password for guest #{$guestId}");
    }

    public function setPortalStatus(int $guestId, string $status, int $actorId): void
    {
        if ($guestId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            api_error('Choose a valid portal account status.');
        }
        $stmt = $this->conn->prepare(
            "UPDATE users u
             JOIN guests g ON g.user_id = u.id
             SET u.status = ?
             WHERE g.id = ? AND u.role = 'guest'"
        );
        $stmt->bind_param('si', $status, $guestId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $check = $this->conn->prepare(
                "SELECT u.status FROM guests g JOIN users u ON u.id = g.user_id
                 WHERE g.id = ? AND u.role = 'guest' LIMIT 1"
            );
            $check->bind_param('i', $guestId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            if (!$existing) {
                api_error('This guest does not have a portal account.');
            }
            if ($existing['status'] === $status) {
                api_error('The guest account already has that status.');
            }
        }
        audit($this->conn, $actorId, 'guest_portal_status', "Set guest #{$guestId} portal account to {$status}");
    }
}
