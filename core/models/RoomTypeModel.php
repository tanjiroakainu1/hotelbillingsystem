<?php
declare(strict_types=1);

final class RoomTypeModel
{
    public function __construct(private mysqli $conn) {}

    public function all(): array
    {
        return $this->conn->query(
            'SELECT * FROM room_types ORDER BY base_rate, name'
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function active(): array
    {
        return $this->conn->query(
            "SELECT * FROM room_types WHERE status='active' ORDER BY base_rate, name"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM room_types WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** Public home showcase with live rate ranges from rooms. */
    public function publicShowcase(): array
    {
        return $this->conn->query(
            "SELECT rt.id, rt.name, rt.description, rt.base_rate, rt.room_features,
                    COUNT(r.id) AS room_count,
                    MIN(r.rate_per_night) AS rate_from,
                    MAX(r.rate_per_night) AS rate_to,
                    COALESCE(
                      (
                        SELECT r2.room_features FROM rooms r2
                        WHERE r2.room_type_id = rt.id
                        ORDER BY r2.room_number LIMIT 1
                      ),
                      rt.room_features
                    ) AS sample_room_features
             FROM room_types rt
             LEFT JOIN rooms r ON r.room_type_id = rt.id
             WHERE rt.status = 'active'
             GROUP BY rt.id, rt.name, rt.description, rt.base_rate, rt.room_features
             ORDER BY rt.base_rate, rt.name"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function create(array $data, int $actorId): bool
    {
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $baseRate = (float) ($data['base_rate'] ?? 0);
        $features = trim((string) ($data['room_features'] ?? ''));
        $status = (string) ($data['status'] ?? 'active');
        if ($name === '') {
            flash('error', 'Room type name is required.');
            return false;
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO room_types (name, description, base_rate, room_features, status) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('ssdss', $name, $description, $baseRate, $features, $status);
            $stmt->execute();
            audit($this->conn, $actorId, 'room_type_create', $name);
            flash('success', 'Room type added.');
            return true;
        } catch (Throwable) {
            flash('error', 'Room type name may already exist.');
            return false;
        }
    }

    public function update(array $data, int $actorId): bool
    {
        $id = (int) ($data['id'] ?? 0);
        $existing = $this->find($id);
        if (!$existing) {
            flash('error', 'Room type not found.');
            return false;
        }
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $baseRate = (float) ($data['base_rate'] ?? $existing['base_rate']);
        $features = trim((string) ($data['room_features'] ?? ''));
        $status = (string) ($data['status'] ?? $existing['status']);
        if ($name === '') {
            flash('error', 'Room type name is required.');
            return false;
        }
        try {
            $stmt = $this->conn->prepare(
                'UPDATE room_types SET name=?, description=?, base_rate=?, room_features=?, status=? WHERE id=?'
            );
            $stmt->bind_param('ssdssi', $name, $description, $baseRate, $features, $status, $id);
            $stmt->execute();
            $sync = $this->conn->prepare('UPDATE rooms SET room_type=? WHERE room_type_id=?');
            $sync->bind_param('si', $name, $id);
            $sync->execute();
            audit($this->conn, $actorId, 'room_type_update', "Updated room type #{$id}");
            flash('success', 'Room type updated.');
            return true;
        } catch (Throwable) {
            flash('error', 'Could not update room type. Name may already exist.');
            return false;
        }
    }

    public function delete(int $id, int $actorId): bool
    {
        $existing = $this->find($id);
        if (!$existing) {
            flash('error', 'Room type not found.');
            return false;
        }
        $rooms = (int) $this->conn->query("SELECT COUNT(*) c FROM rooms WHERE room_type_id={$id}")->fetch_assoc()['c'];
        if ($rooms > 0) {
            flash('error', 'Cannot delete a room type still linked to rooms.');
            return false;
        }
        $stmt = $this->conn->prepare('DELETE FROM room_types WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit($this->conn, $actorId, 'room_type_delete', $existing['name']);
        flash('success', 'Room type deleted.');
        return true;
    }
}
