<?php
declare(strict_types=1);

final class CategoryModel
{
    public function __construct(private mysqli $conn) {}

    public function all(?string $type = null): array
    {
        if ($type && in_array($type, ['room', 'service'], true)) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM categories
                 WHERE category_type = ?
                   AND (? <> 'service' OR name IN ('Food', 'Laundry'))
                 ORDER BY category_type, name"
            );
            $stmt->bind_param('ss', $type, $type);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $rows = $this->conn->query(
                "SELECT * FROM categories
                 WHERE category_type = 'room' OR name IN ('Food', 'Laundry')
                 ORDER BY category_type, name"
            )->fetch_all(MYSQLI_ASSOC);
        }
        return array_map(fn(array $row): array => $this->attachServices($row), $rows);
    }

    /** Attach linked hotel services for read-only service category lookups. */
    private function attachServices(array $row): array
    {
        if (($row['category_type'] ?? '') !== 'service') {
            return $row;
        }
        $id = (int) ($row['id'] ?? 0);
        $name = (string) ($row['name'] ?? '');
        $stmt = $this->conn->prepare(
            'SELECT id, name, price, status, category
             FROM services
             WHERE category_id = ?
                OR (category_id IS NULL AND LOWER(category) = LOWER(?))
             ORDER BY name'
        );
        $stmt->bind_param('is', $id, $name);
        $stmt->execute();
        $services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $row['services'] = $services;
        $row['service_count'] = count($services);
        return $row;
    }

    public function roomTypes(): array
    {
        return $this->all('room');
    }

    /** Public home showcase: room categories with rate range from inventory. */
    public function publicRoomShowcase(): array
    {
        return $this->conn->query(
            "SELECT c.id, c.name, c.description,
                    COUNT(r.id) AS room_count,
                    MIN(r.rate_per_night) AS rate_from,
                    MAX(r.rate_per_night) AS rate_to,
                    (
                      SELECT r2.room_features FROM rooms r2
                      WHERE r2.category_id = c.id
                      ORDER BY r2.room_number LIMIT 1
                    ) AS sample_room_features
             FROM categories c
             LEFT JOIN rooms r ON r.category_id = c.id
             WHERE c.category_type = 'room'
             GROUP BY c.id, c.name, c.description
             ORDER BY MIN(r.rate_per_night) IS NULL, MIN(r.rate_per_night), c.name"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function serviceCategories(): array
    {
        return $this->all('service');
    }

}
