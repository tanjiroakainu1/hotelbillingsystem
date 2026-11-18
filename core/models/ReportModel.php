<?php
declare(strict_types=1);

final class ReportModel
{
    public function __construct(private mysqli $conn) {}

    public function billSummary(string $from, string $to): array
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS bill_count,
                    COALESCE(SUM(total_amount),0) AS billed,
                    COALESCE(SUM(paid_amount),0) AS paid,
                    COALESCE(SUM(balance),0) AS outstanding
             FROM bills WHERE DATE(created_at) BETWEEN ? AND ?"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [
            'bill_count' => 0,
            'billed' => 0,
            'paid' => 0,
            'outstanding' => 0,
        ];
    }

    public function billSummaryByStatus(string $from, string $to): array
    {
        $stmt = $this->conn->prepare(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
             FROM bills WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY status ORDER BY status"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Datasets used by Admin and Front Desk report charts. */
    public function charts(string $from, string $to): array
    {
        $range = [$from, $to];
        return [
            'daily' => $this->rows(
                "SELECT DATE(paid_at) AS label, SUM(amount) AS value FROM payments
                 WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DATE(paid_at) ORDER BY label",
                'ss', $range
            ),
            'monthly' => $this->rows(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, SUM(total_amount) AS billed, SUM(paid_amount) AS collected
                 FROM bills WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
                 GROUP BY label ORDER BY label"
            ),
            'methods' => $this->rows(
                "SELECT method AS label, SUM(amount) AS value, COUNT(*) AS cnt FROM payments
                 WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY method",
                'ss', $range
            ),
            'bill_types' => $this->rows(
                "SELECT bi.item_type AS label, SUM(bi.amount) AS value FROM bill_items bi
                 JOIN bills b ON b.id = bi.bill_id WHERE DATE(b.created_at) BETWEEN ? AND ?
                 GROUP BY bi.item_type ORDER BY value DESC",
                'ss', $range
            ),
            'weekday' => $this->rows(
                "SELECT DAYOFWEEK(paid_at) AS label, SUM(amount) AS value FROM payments
                 WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DAYOFWEEK(paid_at) ORDER BY label",
                'ss', $range
            ),
            'rooms' => $this->rows("SELECT status AS label, COUNT(*) AS value FROM rooms GROUP BY status"),
            'room_types' => $this->rows(
                "SELECT r.room_type AS label, COUNT(*) AS value, COALESCE(SUM(r.rate_per_night),0) AS rate
                 FROM bookings b JOIN rooms r ON r.id = b.room_id
                 WHERE b.check_in <= ? AND b.check_out >= ? AND b.status <> 'cancelled'
                 GROUP BY r.room_type ORDER BY value DESC",
                'ss', [$to, $from]
            ),
            'bookings' => $this->rows(
                "SELECT status AS label, COUNT(*) AS value FROM bookings
                 WHERE check_in <= ? AND check_out >= ? GROUP BY status",
                'ss', [$to, $from]
            ),
            'request_types' => $this->rows(
                "SELECT request_type AS label, COUNT(*) AS value FROM guest_requests
                 WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY request_type",
                'ss', $range
            ),
            'request_status' => $this->rows(
                "SELECT status AS label, COUNT(*) AS value FROM guest_requests
                 WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status",
                'ss', $range
            ),
            'top_guests' => $this->rows(
                "SELECT g.full_name AS label, SUM(b.total_amount) AS value FROM bills b
                 JOIN guests g ON g.id = b.guest_id WHERE DATE(b.created_at) BETWEEN ? AND ?
                 GROUP BY g.id ORDER BY value DESC LIMIT 5",
                'ss', $range
            ),
        ];
    }
    public function buildReport(string $from, string $to): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'hotel_name' => (string) hotel_config('hotel_name'),
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => $this->billSummary($from, $to),
            'by_status' => $this->billSummaryByStatus($from, $to),
            'collected' => (new PaymentModel($this->conn))->reportCollected($from, $to),
            'daily' => (new PaymentModel($this->conn))->dailySales($from, $to, 31),
            'services' => (new ServiceModel($this->conn))->revenueByService($from, $to, 20),
            'charts' => $this->charts($from, $to),
        ];
    }
}
