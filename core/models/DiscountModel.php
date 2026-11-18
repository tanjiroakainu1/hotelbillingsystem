<?php
declare(strict_types=1);

final class DiscountModel
{
    public function __construct(private mysqli $conn) {}

    public function all(): array
    {
        return $this->conn->query('SELECT * FROM discounts ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    }

    public function custom(): array
    {
        return $this->conn->query(
            "SELECT * FROM discounts WHERE code IS NULL ORDER BY name"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function active(): array
    {
        return $this->conn->query(
            "SELECT * FROM discounts WHERE status='active'
             ORDER BY CASE code
               WHEN 'senior_citizen' THEN 1
               WHEN 'pwd' THEN 2
               WHEN 'employee' THEN 3
               ELSE 4
             END, name"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM discounts WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function findActive(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM discounts WHERE id = ? AND status = "active"');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM discounts WHERE code = ? LIMIT 1');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function validateValue(string $type, float $value): ?string
    {
        if (!in_array($type, ['percent', 'fixed'], true)) {
            return 'Discount type must be percent or fixed.';
        }
        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            return 'Percent discount must be between 1 and 100.';
        }
        if ($type === 'fixed' && $value <= 0) {
            return 'Fixed discount must be greater than zero.';
        }
        return null;
    }

    public function create(array $data, int $actorId): bool
    {
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['discount_type'] ?? 'percent');
        $value = (float) ($data['value'] ?? $data['percent'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));

        if ($name === '') {
            flash('error', 'Discount name is required.');
            return false;
        }
        if ($err = $this->validateValue($type, $value)) {
            flash('error', $err);
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO discounts (name, discount_type, value, description, status) VALUES (?, ?, ?, ?, "active")'
        );
        $stmt->bind_param('ssds', $name, $type, $value, $description);
        $stmt->execute();
        audit($this->conn, $actorId, 'discount_create', $name);
        flash('success', 'Discount created.');
        return true;
    }

    public function update(array $data, int $actorId): bool
    {
        $id = (int) ($data['id'] ?? 0);
        $existing = $this->find($id);
        if (!$existing) {
            flash('error', 'Discount not found.');
            return false;
        }
        if (!empty($existing['code'])) {
            flash('error', 'System discounts must be edited from their dedicated forms.');
            return false;
        }

        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['discount_type'] ?? $existing['discount_type']);
        $value = (float) ($data['value'] ?? $existing['value']);
        $description = trim((string) ($data['description'] ?? ''));
        $status = (string) ($data['status'] ?? $existing['status']);

        if ($name === '') {
            flash('error', 'Discount name is required.');
            return false;
        }
        if ($err = $this->validateValue($type, $value)) {
            flash('error', $err);
            return false;
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = $existing['status'];
        }

        $stmt = $this->conn->prepare(
            'UPDATE discounts SET name=?, discount_type=?, value=?, description=?, status=? WHERE id=? AND code IS NULL'
        );
        $stmt->bind_param('ssdssi', $name, $type, $value, $description, $status, $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'discount_update', "Updated discount #{$id}");
        flash('success', 'Discount updated.');
        return true;
    }

    public function delete(int $id, int $actorId): bool
    {
        $row = $this->find($id);
        if (!$row) {
            flash('error', 'Discount not found.');
            return false;
        }
        if (!empty($row['code'])) {
            flash('error', 'System discounts cannot be deleted.');
            return false;
        }

        $stmt = $this->conn->prepare('DELETE FROM discounts WHERE id = ? AND code IS NULL');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'discount_delete', $row['name']);
        flash('success', 'Discount deleted.');
        return true;
    }

    public function updateSystem(string $code, array $data, int $actorId): bool
    {
        $row = $this->findByCode($code);
        if (!$row) {
            flash('error', 'System discount not found.');
            return false;
        }

        $name = trim((string) ($data['name'] ?? $row['name']));
        $value = (float) ($data['value'] ?? $data['percent'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));

        if ($name === '') {
            flash('error', 'Discount name is required.');
            return false;
        }
        if ($value <= 0 || $value > 100) {
            flash('error', 'Discount rate must be between 1 and 100 percent.');
            return false;
        }

        $stmt = $this->conn->prepare(
            'UPDATE discounts SET name = ?, value = ?, description = ? WHERE code = ?'
        );
        $stmt->bind_param('sdss', $name, $value, $description, $code);
        $stmt->execute();
        audit($this->conn, $actorId, 'discount_system_update', $code);
        flash('success', $name . ' discount updated.');
        return true;
    }

    public function toggle(int $id, int $actorId): void
    {
        $row = $this->find($id);
        if (!$row) {
            flash('error', 'Discount not found.');
            return;
        }
        if (!empty($row['code'])) {
            flash('error', 'System discounts cannot be toggled here.');
            return;
        }
        $stmt = $this->conn->prepare(
            "UPDATE discounts SET status = IF(status='active','inactive','active') WHERE id = ? AND code IS NULL"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'discount_toggle', "Toggled discount #{$id}");
        flash('success', 'Discount status updated.');
    }
}
