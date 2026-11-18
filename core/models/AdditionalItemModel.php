<?php
declare(strict_types=1);

final class AdditionalItemModel
{
    public function __construct(private mysqli $conn) {}

    public function all(): array
    {
        return with_photo_urls($this->conn->query(
            'SELECT * FROM additional_item_types ORDER BY item_name'
        )->fetch_all(MYSQLI_ASSOC));
    }

    public function active(): array
    {
        return with_photo_urls($this->conn->query(
            "SELECT * FROM additional_item_types WHERE status='active' ORDER BY item_name"
        )->fetch_all(MYSQLI_ASSOC));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM additional_item_types WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function create(array $data, int $actorId, array $files = []): bool
    {
        $name = trim((string) ($data['item_name'] ?? ''));
        $price = (float) ($data['unit_price'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $status = (string) ($data['status'] ?? 'active');
        if ($name === '' || $price < 0) {
            flash('error', 'Item name and price are required.');
            return false;
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        $photoResult = store_service_photo($files['photo'] ?? []);
        if (!$photoResult['ok']) {
            flash('error', $photoResult['message']);
            return false;
        }
        $photo = $photoResult['path'];
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO additional_item_types (item_name, unit_price, description, photo, status) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sdsss', $name, $price, $description, $photo, $status);
            $stmt->execute();
            audit($this->conn, $actorId, 'additional_item_create', $name);
            flash('success', 'Additional item added.');
            return true;
        } catch (Throwable) {
            flash('error', 'Item name may already exist.');
            return false;
        }
    }

    public function update(array $data, int $actorId, array $files = []): bool
    {
        $id = (int) ($data['id'] ?? 0);
        $existing = $this->find($id);
        if (!$existing) {
            flash('error', 'Item not found.');
            return false;
        }
        $name = trim((string) ($data['item_name'] ?? ''));
        $price = (float) ($data['unit_price'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $status = (string) ($data['status'] ?? 'active');
        if ($name === '' || $price < 0) {
            flash('error', 'Item name and price are required.');
            return false;
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            flash('error', 'Choose Active or Inactive for the item status.');
            return false;
        }
        $photoResult = store_service_photo($files['photo'] ?? [], $existing['photo'] ?? null);
        if (!$photoResult['ok']) {
            flash('error', $photoResult['message']);
            return false;
        }
        $photo = $photoResult['path'];
        if (!empty($data['remove_photo']) && empty($files['photo']['name'])) {
            delete_stored_photo($photo);
            $photo = null;
        }
        try {
            $this->conn->begin_transaction();
            $stmt = $this->conn->prepare(
                'UPDATE additional_item_types SET item_name=?, unit_price=?, description=?, photo=?, status=? WHERE id=?'
            );
            $stmt->bind_param('sdsssi', $name, $price, $description, $photo, $status, $id);
            $stmt->execute();
            if ($status === 'inactive' && $existing['status'] === 'active') {
                $this->markRequestsUnavailable($id, 'inactive', $actorId);
            }
            audit($this->conn, $actorId, 'additional_item_update', "Updated item #{$id}");
            $this->conn->commit();
            flash('success', 'Additional item updated.');
            return true;
        } catch (Throwable $error) {
            $this->conn->rollback();
            flash('error', 'Could not update item.');
            return false;
        }
    }

    public function delete(int $id, int $actorId): bool
    {
        $row = $this->find($id);
        if (!$row) {
            flash('error', 'Item not found.');
            return false;
        }
        $this->conn->begin_transaction();
        try {
            if ($row['status'] !== 'inactive') {
                $this->markRequestsUnavailable($id, 'deleted', $actorId);
                $stmt = $this->conn->prepare("UPDATE additional_item_types SET status = 'inactive' WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
            }
            audit($this->conn, $actorId, 'additional_item_archive', $row['item_name']);
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
        flash('success', 'Item archived. Existing requests and transaction history were kept; guests can no longer request this item.');
        return true;
    }

    public function permanentlyDelete(int $id, int $actorId): bool
    {
        $item = $this->find($id);
        if (!$item) {
            flash('error', 'Item not found.');
            return false;
        }
        if ($item['status'] === 'active') {
            flash('error', 'Archive the item before permanently deleting it.');
            return false;
        }
        $this->conn->begin_transaction();
        try {
            $this->markRequestsUnavailable($id, 'deleted', $actorId, true, true);
            $stmt = $this->conn->prepare('DELETE FROM additional_item_types WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            audit($this->conn, $actorId, 'additional_item_permanent_delete', "Permanently deleted item #{$id}: {$item['item_name']}");
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
        flash('success', 'Item permanently removed from the catalog. Guest requests and transaction history were retained.');
        return true;
    }

    public function restore(int $id, int $actorId): bool
    {
        $item = $this->find($id);
        if (!$item) {
            flash('error', 'Item not found.');
            return false;
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("UPDATE additional_item_types SET status = 'active' WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            audit($this->conn, $actorId, 'additional_item_restore', "Restored item #{$id}: {$item['item_name']}");
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
        flash('success', 'Item restored and available for new guest requests. Existing archived requests remain unchanged.');
        return true;
    }

    private function markRequestsUnavailable(
        int $itemId,
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
             WHERE gr.additional_item_id = ?
               {$existingMarkerFilter}"
        );
        $billItems->bind_param('ssi', $description, $description, $itemId);
        $billItems->execute();
        $where = $replaceExisting ? '' : ' AND item_unavailable_at IS NULL';
        $requests = $this->conn->prepare(
            "UPDATE guest_requests
             SET item_unavailable_at = COALESCE(item_unavailable_at, NOW()),
                 item_unavailable_reason = ?,
                 item_unavailable_by = ?
             WHERE additional_item_id = ?{$where}"
        );
        $requests->bind_param('sii', $reason, $actorId, $itemId);
        $requests->execute();
    }
}
