<?php
declare(strict_types=1);

final class PaymentModel
{
    public array $lastPaymentIds = [];

    public function __construct(private mysqli $conn) {}

    public function todayTotal(): float
    {
        return (float) $this->conn->query(
            "SELECT COALESCE(SUM(amount),0) s FROM payments WHERE DATE(paid_at)=CURDATE()"
        )->fetch_assoc()['s'];
    }

    public function history(int $limit = 40): array
    {
        return $this->conn->query(
            "SELECT p.*, b.invoice_number, g.full_name, r.room_number, u.full_name AS cashier
             FROM payments p JOIN bills b ON b.id = p.bill_id JOIN guests g ON g.id = b.guest_id
             LEFT JOIN bookings bk ON bk.id = b.booking_id
             LEFT JOIN rooms r ON r.id = bk.room_id
             LEFT JOIN users u ON u.id = p.received_by ORDER BY p.id DESC LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function forBill(int $billId): array
    {
        return $this->conn->query(
            "SELECT p.*, u.full_name AS cashier FROM payments p
             LEFT JOIN users u ON u.id = p.received_by
             WHERE p.bill_id={$billId} ORDER BY p.paid_at DESC, p.id DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function forBills(array $billIds): array
    {
        $billIds = array_values(array_unique(array_filter(
            array_map('intval', $billIds),
            static fn(int $billId): bool => $billId > 0
        )));
        if (!$billIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($billIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.bill_id, p.amount, p.method, p.reference_no, p.notes, p.paid_at,
                    b.invoice_number, b.booking_id, g.full_name, r.room_number, u.full_name AS cashier
             FROM payments p
             JOIN bills b ON b.id = p.bill_id
             JOIN guests g ON g.id = b.guest_id
             LEFT JOIN bookings bk ON bk.id = b.booking_id
             LEFT JOIN rooms r ON r.id = bk.room_id
             LEFT JOIN users u ON u.id = p.received_by
             WHERE p.bill_id IN ({$placeholders})
             ORDER BY p.paid_at DESC, p.id DESC"
        );
        $types = str_repeat('i', count($billIds));
        $params = [$types];
        foreach ($billIds as $index => $billId) {
            $params[] = &$billIds[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function forGuest(int $guestId): array
    {
        return $this->conn->query(
            "SELECT p.id, p.bill_id, p.amount, p.method, p.reference_no, p.notes, p.paid_at,
                    b.invoice_number, b.booking_id, r.room_number, u.full_name AS cashier
             FROM payments p
             JOIN bills b ON b.id = p.bill_id
             LEFT JOIN bookings bk ON bk.id = b.booking_id
             LEFT JOIN rooms r ON r.id = bk.room_id
             LEFT JOIN users u ON u.id = p.received_by
             WHERE b.guest_id = {$guestId}
             ORDER BY p.paid_at DESC, p.id DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function receipts(array $paymentIds, ?int $guestId = null): array
    {
        $paymentIds = array_values(array_unique(array_filter(
            array_map('intval', $paymentIds),
            static fn(int $paymentId): bool => $paymentId > 0
        )));
        if (!$paymentIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $sql = "SELECT p.id, p.amount, p.method, p.reference_no, p.notes, p.paid_at,
                       b.invoice_number, g.full_name, r.room_number, u.full_name AS cashier
                FROM payments p
                JOIN bills b ON b.id = p.bill_id
                JOIN guests g ON g.id = b.guest_id
                LEFT JOIN bookings bk ON bk.id = b.booking_id
                LEFT JOIN rooms r ON r.id = bk.room_id
                LEFT JOIN users u ON u.id = p.received_by
                WHERE p.id IN ({$placeholders})";
        if ($guestId !== null) {
            $sql .= ' AND b.guest_id = ?';
        }
        $stmt = $this->conn->prepare($sql);
        $params = $paymentIds;
        if ($guestId !== null) {
            $params[] = $guestId;
        }
        $bindParams = [str_repeat('i', count($params))];
        foreach ($params as $index => &$param) {
            $bindParams[] = &$param;
        }
        unset($param);
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function record(array $data, BillModel $bills, int $actorId): bool
    {
        $this->lastPaymentIds = [];
        $billId = (int) ($data['bill_id'] ?? 0);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount < 0.01) {
            flash('error', 'Payment amount must be at least 0.01.');
            return false;
        }
        $choice = strtolower((string) ($data['method'] ?? 'cash'));
        $reference = trim((string) ($data['reference_no'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        if (!in_array($choice, ['cash', 'gcash', 'online', 'bank_transfer', 'bank'], true)) {
            flash('error', 'Choose Cash, GCash or Bank Transfer as the payment method.');
            return false;
        }
        // payments.method is ENUM('cash','online','bank'); GCash is stored as 'online'.
        $method = match ($choice) {
            'cash' => 'cash',
            'bank_transfer', 'bank' => 'bank',
            default => 'online',
        };
        if ($method !== 'cash' && $reference === '') {
            flash('error', 'A transfer reference number is required.');
            return false;
        }
        if ($method === 'online') {
            $digits = preg_replace('/\D+/', '', $reference);
            if (strlen($digits) < 8) {
                flash('error', 'Enter the GCash reference number from the payment receipt.');
                return false;
            }
            $reference = 'GCASH-' . $digits;
        } elseif ($method === 'bank') {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $reference);
            if (strlen($clean) < 6) {
                flash('error', 'Enter the bank transfer reference number.');
                return false;
            }
            $reference = 'BANK-' . strtoupper($clean);
        }
        $bill = $bills->find($billId);
        if (!$bill) {
            flash('error', 'Bill not found.');
            return false;
        }
        if ($bill['status'] === 'void' || (float) $bill['balance'] <= 0.009) {
            flash('error', 'This bill has no outstanding balance to pay.');
            return false;
        }
        if ($amount > ((float) $bill['balance'] + 0.009)) {
            flash('error', 'Payment exceeds outstanding balance.');
            return false;
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO payments (bill_id, amount, method, reference_no, notes, received_by) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $storedReference = $method === 'cash' ? 'CASH-PENDING' : $reference;
            $stmt->bind_param('idsssi', $billId, $amount, $method, $storedReference, $notes, $actorId);
            $stmt->execute();
            $paymentId = (int) $this->conn->insert_id;
            if ($method === 'cash') {
                $this->setCashReceiptReference($paymentId);
            }
            $this->lastPaymentIds[] = $paymentId;
            $bills->addPaidAmount($billId, $amount);
            audit($this->conn, $actorId, 'payment', "Bill #{$billId} paid " . money($amount) . ' via ' . payment_method_label($method));
            $this->conn->commit();
            flash('success', 'Payment recorded and balance updated.');
            return true;
        } catch (Throwable) {
            $this->conn->rollback();
            flash('error', 'Payment failed.');
            return false;
        }
    }

    public function recordAccountPayment(
        array $data,
        BillModel $bills,
        int $actorId,
        bool $manageTransaction = true
    ): bool
    {
        $this->lastPaymentIds = [];
        $bookingId = (int) ($data['booking_id'] ?? 0);
        $accountBillId = (int) ($data['bill_id'] ?? 0);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $choice = strtolower((string) ($data['method'] ?? 'cash'));
        $reference = trim((string) ($data['reference_no'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        $paymentRequestId = (int) ($data['payment_request_id'] ?? 0);
        if (!in_array($choice, ['cash', 'gcash', 'online', 'bank_transfer', 'bank'], true)) {
            flash('error', 'Choose Cash, GCash or Bank Transfer as the payment method.');
            return false;
        }
        $method = match ($choice) {
            'cash' => 'cash',
            'bank_transfer', 'bank' => 'bank',
            default => 'online',
        };
        if ($method !== 'cash' && $reference === '') {
            flash('error', 'A transfer reference number is required.');
            return false;
        }
        if ($method === 'online') {
            $digits = preg_replace('/\D+/', '', $reference);
            if (strlen($digits) < 8) {
                flash('error', 'Enter the GCash reference number from the payment receipt.');
                return false;
            }
            $reference = 'GCASH-' . $digits;
        } elseif ($method === 'bank') {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $reference);
            if (strlen($clean) < 6) {
                flash('error', 'Enter the bank transfer reference number.');
                return false;
            }
            $reference = 'BANK-' . strtoupper($clean);
        }
        if ($amount < 0.01) {
            flash('error', 'Payment amount must be at least 0.01.');
            return false;
        }
        if ($bookingId <= 0 && $accountBillId <= 0) {
            flash('error', 'Select a guest account before recording a payment.');
            return false;
        }

        $records = $bills->accountRecords($bookingId, $accountBillId);
        $billIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['bill_id'] ?? 0),
            array_filter($records, static fn (array $row): bool => !empty($row['bill_id']))
        )));
        if (!$billIds) {
            flash('error', 'No room bills are available on this guest account.');
            return false;
        }

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $locked = [];
            $lockBill = $this->conn->prepare(
                "SELECT id, balance, status FROM bills WHERE id = ? FOR UPDATE"
            );
            foreach ($billIds as $billId) {
                $lockBill->bind_param('i', $billId);
                $lockBill->execute();
                $bill = $lockBill->get_result()->fetch_assoc();
                if ($bill && $bill['status'] !== 'void' && (float) $bill['balance'] > 0.009) {
                    $locked[] = $bill;
                }
            }
            $totalBalance = round(array_sum(array_map(
                static fn (array $bill): float => (float) $bill['balance'],
                $locked
            )), 2);
            if ($amount > $totalBalance + 0.009) {
                if ($manageTransaction) {
                    $this->conn->rollback();
                }
                flash('error', 'Payment exceeds the guest account balance of ' . money($totalBalance) . '.');
                return false;
            }
            if ($totalBalance <= 0.009) {
                if ($manageTransaction) {
                    $this->conn->rollback();
                }
                flash('error', 'This guest account has no outstanding balance.');
                return false;
            }

            $remaining = $amount;
            $remainingBalance = $totalBalance;
            $insert = $this->conn->prepare(
                'INSERT INTO payments (bill_id, payment_request_id, amount, method, reference_no, notes, received_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($locked as $index => $bill) {
                $balance = (float) $bill['balance'];
                if ($index === count($locked) - 1) {
                    $allocation = min($balance, $remaining);
                } else {
                    $allocation = min($balance, round($remaining * ($balance / $remainingBalance), 2));
                }
                if ($allocation <= 0.009) {
                    $remainingBalance -= $balance;
                    continue;
                }
                $billId = (int) $bill['id'];
                $paymentNotes = trim($notes . ' · Guest account payment distributed across room balances');
                $requestId = $paymentRequestId ?: null;
                $storedReference = $method === 'cash' ? 'CASH-PENDING' : $reference;
                $insert->bind_param('iidsssi', $billId, $requestId, $allocation, $method, $storedReference, $paymentNotes, $actorId);
                $insert->execute();
                $paymentId = (int) $this->conn->insert_id;
                if ($method === 'cash') {
                    $this->setCashReceiptReference($paymentId);
                }
                $this->lastPaymentIds[] = $paymentId;
                $bills->addPaidAmount($billId, $allocation);
                audit(
                    $this->conn,
                    $actorId,
                    'payment',
                    'Guest account payment · Bill #' . $billId . ' paid ' . money($allocation) . ' via ' . payment_method_label($method)
                );
                $remaining = round($remaining - $allocation, 2);
                $remainingBalance -= $balance;
            }
            if ($remaining > 0.009) {
                throw new RuntimeException('Could not distribute the full payment across the room bills.');
            }
            if ($manageTransaction) {
                $this->conn->commit();
            }
            flash('success', money($amount) . ' payment distributed across ' . count($locked) . ' room bill(s).');
            return true;
        } catch (Throwable $error) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            throw $error;
        }
    }

    public function reportCollected(string $from, string $to): array
    {
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(p.amount),0) AS collected, COUNT(p.id) AS payment_count
             FROM payments p WHERE DATE(p.paid_at) BETWEEN ? AND ?"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: ['collected' => 0, 'payment_count' => 0];
    }

    private function setCashReceiptReference(int $paymentId): void
    {
        $reference = sprintf('OR-CASH-%s-%06d', date('Ymd'), $paymentId);
        $stmt = $this->conn->prepare('UPDATE payments SET reference_no = ? WHERE id = ?');
        $stmt->bind_param('si', $reference, $paymentId);
        $stmt->execute();
    }

    public function dailySales(string $from, string $to, int $limit = 14): array
    {
        $stmt = $this->conn->prepare(
            "SELECT DATE(paid_at) d, SUM(amount) total FROM payments
             WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DATE(paid_at) ORDER BY d DESC LIMIT {$limit}"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
