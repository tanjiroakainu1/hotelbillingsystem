<?php
declare(strict_types=1);

final class AuditModel
{
    public function __construct(private mysqli $conn) {}

    /** All system activity for administrators. */
    public function recentSystem(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        return $this->conn->query(
            "SELECT a.*, u.full_name, u.email, u.role
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** Activity from every role, for front desk visibility. */
    public function recentStaff(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        return $this->conn->query(
            "SELECT a.*, u.full_name, u.email, u.role
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }
}
