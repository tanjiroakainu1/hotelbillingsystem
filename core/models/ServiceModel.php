<?php
declare(strict_types=1);

final class ServiceModel
{
    public function __construct(private mysqli $conn) {}

    public function all(): array
    {
        return with_photo_urls($this->conn->query(
            "SELECT s.*, CASE
                       WHEN LOWER(COALESCE(c.name, s.category)) IN ('laundry') THEN 'Laundry'
                       WHEN LOWER(COALESCE(c.name, s.category)) IN ('food','restaurant','room service') THEN 'Food'
                       ELSE NULL
                    END AS category_name
             FROM services s
             LEFT JOIN categories c ON c.id = s.category_id
             WHERE LOWER(COALESCE(c.name, s.category)) IN ('food','restaurant','room service','laundry')
             ORDER BY s.category, s.name"
        )->fetch_all(MYSQLI_ASSOC));
    }

    public function active(): array
    {
        return with_photo_urls($this->conn->query(
            "SELECT s.*, CASE
                       WHEN LOWER(COALESCE(c.name, s.category)) = 'laundry' THEN 'Laundry'
                       WHEN LOWER(COALESCE(c.name, s.category)) IN ('food','restaurant','room service') THEN 'Food'
                       ELSE NULL
                    END AS category_name
             FROM services s
             LEFT JOIN categories c ON c.id = s.category_id
             WHERE s.status='active'
               AND LOWER(COALESCE(c.name, s.category)) IN ('food','restaurant','room service','laundry')
             ORDER BY s.category, s.name"
        )->fetch_all(MYSQLI_ASSOC));
    }

    public function foodActive(): array
    {
        return with_photo_urls($this->conn->query(
            "SELECT s.*, 'Food' AS category_name
             FROM services s
             LEFT JOIN categories c ON c.id = s.category_id
             WHERE s.status='active'
               AND (
                 LOWER(COALESCE(c.name, s.category)) IN ('food', 'restaurant', 'room service')
               )
             ORDER BY s.category, s.name"
        )->fetch_all(MYSQLI_ASSOC));
    }

    public function laundryActive(): array
    {
        return with_photo_urls($this->conn->query(
            "SELECT s.*, 'Laundry' AS category_name
             FROM services s
             LEFT JOIN categories c ON c.id = s.category_id
             WHERE s.status='active'
               AND LOWER(COALESCE(c.name, s.category)) = 'laundry'
             ORDER BY s.category, s.name"
        )->fetch_all(MYSQLI_ASSOC));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function findActive(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM services WHERE id = ? AND status = "active"');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function resolveCategory(array $data): array
    {
        $categoryId = (int) ($data['category_id'] ?? 0);
        $category = strtolower(trim((string) ($data['category'] ?? '')));
        if ($categoryId > 0) {
            $stmt = $this->conn->prepare(
                "SELECT id, name FROM categories WHERE id = ? AND category_type = 'service'"
            );
            $stmt->bind_param('i', $categoryId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && in_array(strtolower((string) $row['name']), ['food', 'laundry'], true)) {
                return [(int) $row['id'], ucfirst(strtolower((string) $row['name']))];
            }
        }
        if (in_array($category, ['food', 'restaurant', 'room service', 'laundry'], true)) {
            $category = $category === 'laundry' ? 'Laundry' : 'Food';
            $stmt = $this->conn->prepare(
                "SELECT id, name FROM categories
                 WHERE category_type = 'service' AND LOWER(name) = LOWER(?)
                 LIMIT 1"
            );
            $stmt->bind_param('s', $category);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                return [(int) $row['id'], ucfirst(strtolower((string) $row['name']))];
            }
        }
        return [0, ''];
    }

    public function create(array $data, array $files = []): bool
    {
        $name = trim($data['name'] ?? '');
        $price = (float) ($data['price'] ?? 0);
        $description = trim($data['description'] ?? '');
        $minRoom = trim((string) ($data['min_room_type'] ?? ''));
        [$categoryId, $category] = $this->resolveCategory($data);
        if ($name === '' || $price < 0) {
            flash('error', 'Service name and price are required.');
            return false;
        }
        if ($categoryId <= 0 || $category === '') {
            flash('error', 'Choose a valid built-in Food or Laundry category.');
            return false;
        }
        $photoResult = store_service_photo($files['photo'] ?? []);
        if (!$photoResult['ok']) {
            flash('error', $photoResult['message']);
            return false;
        }
        $photo = $photoResult['path'];
        $stmt = $this->conn->prepare(
            'INSERT INTO services (name, category_id, category, price, min_room_type, description, photo, status)
             VALUES (?, ?, ?, ?, NULLIF(?, ""), ?, ?, "active")'
        );
        $stmt->bind_param('sisdsss', $name, $categoryId, $category, $price, $minRoom, $description, $photo);
        $stmt->execute();
        flash('success', 'Service added.');
        return true;
    }

    public function update(array $data, array $files = [], int $actorId = 0): void
    {
        $id = (int) ($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $price = (float) ($data['price'] ?? 0);
        $status = $data['status'] ?? 'active';
        $minRoom = trim((string) ($data['min_room_type'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        [$categoryId, $category] = $this->resolveCategory($data);
        if ($categoryId <= 0 || $category === '') {
            flash('error', 'Choose a valid service category.');
            return;
        }
        $existing = $this->find($id);
        if (!$existing) {
            flash('error', 'Service not found.');
            return;
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            flash('error', 'Choose Active or Inactive for the service status.');
            return;
        }
        $photoResult = store_service_photo($files['photo'] ?? [], $existing['photo'] ?? null);
        if (!$photoResult['ok']) {
            flash('error', $photoResult['message']);
            return;
        }
        $photo = $photoResult['path'];
        if (!empty($data['remove_photo']) && empty($files['photo']['name'])) {
            delete_stored_photo($photo);
            $photo = null;
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'UPDATE services SET name=?, category_id=?, category=?, price=?, min_room_type=NULLIF(?, ""), description=?, photo=?, status=? WHERE id=?'
            );
            $stmt->bind_param('sisdssssi', $name, $categoryId, $category, $price, $minRoom, $description, $photo, $status, $id);
            $stmt->execute();
            if ($status === 'inactive' && $existing['status'] === 'active') {
                $this->markRequestsUnavailable($id, 'inactive', $actorId);
            }
            if ($actorId > 0) {
                audit($this->conn, $actorId, 'service_update', "Updated service #{$id}: {$name} ({$status})");
            }
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
        flash('success', 'Service updated.');
    }

    public function delete(int $id, int $actorId): bool
    {
        $stmt = $this->conn->prepare('SELECT name, photo, status FROM services WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            flash('error', 'Service not found.');
            return false;
        }
        $this->conn->begin_transaction();
        try {
            if ($row['status'] !== 'inactive') {
                $this->markRequestsUnavailable($id, 'deleted', $actorId);
                $archive = $this->conn->prepare("UPDATE services SET status = 'inactive' WHERE id = ?");
                $archive->bind_param('i', $id);
                $archive->execute();
            }
            audit($this->conn, $actorId, 'service_archive', $row['name']);
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
        flash('success', 'Service archived. Existing requests and transaction history were kept; guests can no longer request this service.');
        return true;
    }

    public function permanentlyDelete(int $id, int $actorId): bool
    {
        $service = $this->find($id);
        if (!$service) {
            flash('error', 'Service not found.');
            return false;
        }
        if ($service['status'] === 'active') {
            flash('error', 'Archive the service before permanently deleting it.');
            return false;
        }
        $this->conn->begin_transaction();
        try {
            $this->markRequestsUnavailable($id, 'deleted', $actorId, true, true);
            $stmt = $this->conn->prepare('DELETE FROM services WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            audit($this->conn, $actorId, 'service_permanent_delete', "Permanently deleted service #{$id}: {$service['name']}");
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
        flash('success', 'Service permanently removed from the catalog. Guest requests and transaction history were retained.');
        return true;
    }

    public function restore(int $id, int $actorId): bool
    {
        $service = $this->find($id);
        if (!$service) {
            flash('error', 'Service not found.');
            return false;
        }
        $category = strtolower(trim((string) ($service['category'] ?? '')));
        if (!in_array($category, ['food', 'restaurant', 'room service', 'laundry'], true)) {
            flash('error', 'Only archived Food or Laundry services can be restored here.');
            return false;
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("UPDATE services SET status = 'active' WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            audit($this->conn, $actorId, 'service_restore', "Restored service #{$id}: {$service['name']}");
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
        flash('success', 'Service restored and available for new guest requests. Existing archived requests remain unchanged.');
        return true;
    }

    private function markRequestsUnavailable(
        int $serviceId,
        string $reason,
        int $actorId,
        bool $replaceExisting = false,
        bool $permanent = false
    ): void
    {
        $actor = $this->conn->prepare('SELECT full_name FROM users WHERE id = ?');
        $actor->bind_param('i', $actorId);
        $actor->execute();
        $actorName = $actor->get_result()->fetch_column() ?: 'Staff';
        $description = $permanent
            ? " [Catalog item permanently removed by {$actorName}]"
            : ($reason === 'deleted'
                ? " [Catalog item archived by {$actorName}]"
                : " [Catalog item marked inactive by {$actorName}]");
        $existingMarkerFilter = $permanent
            ? "AND bi.description NOT LIKE '%[Catalog item permanently removed by %'"
            : "AND bi.description NOT LIKE '%[Catalog item archived by %'
               AND bi.description NOT LIKE '%[Catalog item marked inactive by %'
               AND bi.description NOT LIKE '%[Catalog item permanently removed by %'";
        $billItems = $this->conn->prepare(
            "UPDATE bill_items bi
             JOIN guest_requests gr ON gr.bill_item_id = bi.id
             SET bi.description = CONCAT(
                 LEFT(bi.description, 255 - CHAR_LENGTH(?)),
                 ?
             )
             WHERE gr.service_id = ?
               {$existingMarkerFilter}"
        );
        $billItems->bind_param('ssi', $description, $description, $serviceId);
        $billItems->execute();
        $where = $replaceExisting ? '' : ' AND item_unavailable_at IS NULL';
        $requests = $this->conn->prepare(
            "UPDATE guest_requests
             SET item_unavailable_at = COALESCE(item_unavailable_at, NOW()),
                 item_unavailable_reason = ?,
                 item_unavailable_by = ?
             WHERE service_id = ?{$where}"
        );
        $requests->bind_param('sii', $reason, $actorId, $serviceId);
        $requests->execute();
    }

    public function revenueByService(string $from, string $to, int $limit = 8): array
    {
        $stmt = $this->conn->prepare(
            "SELECT bi.description, COALESCE(SUM(bi.amount),0) AS total
             FROM bill_items bi JOIN bills b ON b.id = bi.bill_id
             WHERE bi.item_type = 'service' AND DATE(b.created_at) BETWEEN ? AND ?
             GROUP BY bi.description ORDER BY total DESC LIMIT {$limit}"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
