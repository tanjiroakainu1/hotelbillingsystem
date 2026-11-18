<?php
declare(strict_types=1);

final class PaymentRequestModel
{
    public function __construct(private mysqli $conn) {}

    public function forGuest(int $guestId, int $billId = 0): array
    {
        $sql = "SELECT pr.*, b.invoice_number, u.full_name AS processed_by_name,
                       COALESCE((
                           SELECT GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ', ')
                           FROM bookings anchor
                           LEFT JOIN bookings related
                             ON related.id = anchor.id
                             OR (anchor.stay_group IS NOT NULL AND related.stay_group = anchor.stay_group)
                           LEFT JOIN rooms r ON r.id = related.room_id
                           WHERE anchor.id = pr.booking_id
                       ), '') AS room_numbers,
                       COALESCE((
                           SELECT GROUP_CONCAT(p.id ORDER BY p.id)
                           FROM payments p WHERE p.payment_request_id = pr.id
                       ), '') AS payment_ids
                FROM payment_requests pr
                JOIN bills b ON b.id = pr.bill_id
                LEFT JOIN users u ON u.id = pr.processed_by
                WHERE pr.guest_id = ?";
        if ($billId > 0) {
            $sql .= ' AND pr.bill_id = ?';
        }
        $sql .= ' ORDER BY pr.id DESC';
        $stmt = $this->conn->prepare($sql);
        if ($billId > 0) {
            $stmt->bind_param('ii', $guestId, $billId);
        } else {
            $stmt->bind_param('i', $guestId);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function staffQueue(int $limit = 50): array
    {
        return $this->conn->query(
            "SELECT pr.*, g.full_name, b.invoice_number, u.full_name AS processed_by_name,
                    COALESCE((
                        SELECT GROUP_CONCAT(p.id ORDER BY p.id)
                        FROM payments p WHERE p.payment_request_id = pr.id
                    ), '') AS payment_ids,
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ', ')
                        FROM bookings anchor
                        LEFT JOIN bookings related
                          ON related.id = anchor.id
                          OR (anchor.stay_group IS NOT NULL AND related.stay_group = anchor.stay_group)
                        LEFT JOIN rooms r ON r.id = related.room_id
                        WHERE anchor.id = pr.booking_id
                    ), '') AS room_numbers
             FROM payment_requests pr
             JOIN guests g ON g.id = pr.guest_id
             JOIN bills b ON b.id = pr.bill_id
             LEFT JOIN users u ON u.id = pr.processed_by
             ORDER BY (pr.status = 'pending') DESC, pr.id DESC
             LIMIT " . max(1, min($limit, 200))
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function createForGuest(array $data, int $guestId, int $actorId, BillModel $bills): int|false
    {
        $billId = (int) ($data['bill_id'] ?? 0);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $choice = strtolower(trim((string) ($data['method'] ?? '')));
        $reference = trim((string) ($data['reference_no'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($amount < 0.01) {
            flash('error', 'Payment request amount must be at least 0.01.');
            return false;
        }
        if ($billId <= 0 || !in_array($choice, ['cash', 'gcash', 'bank_transfer'], true)) {
            flash('error', 'Select your guest account and choose a payment method.');
            return false;
        }
        $anchor = $bills->find($billId);
        if (!$anchor || (int) $anchor['guest_id'] !== $guestId || $anchor['status'] === 'void') {
            flash('error', 'The selected guest account is unavailable.');
            return false;
        }
        $bookingId = (int) ($anchor['booking_id'] ?? 0);
        $records = $bills->accountRecords($bookingId, $billId);
        if (!$records || array_filter(
            $records,
            static fn(array $record): bool => (int) $record['guest_id'] !== $guestId
        )) {
            flash('error', 'No room bills are available on this guest account.');
            return false;
        }
        $method = match ($choice) {
            'cash' => 'cash',
            'gcash' => 'online',
            'bank_transfer' => 'bank',
        };
        if ($method === 'online') {
            $digits = preg_replace('/\D+/', '', $reference);
            if (strlen($digits) < 8) {
                flash('error', 'Enter the GCash reference number for your transfer.');
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

        $billIds = array_values(array_unique(array_map(
            static fn(array $record): int => (int) ($record['bill_id'] ?? 0),
            array_filter($records, static fn(array $record): bool => !empty($record['bill_id']))
        )));
        if (!in_array($billId, $billIds, true)) {
            flash('error', 'The selected account does not contain that bill.');
            return false;
        }

        $this->conn->begin_transaction();
        try {
            sort($billIds);
            $lockBill = $this->conn->prepare('SELECT id, balance, status FROM bills WHERE id = ? FOR UPDATE');
            $balance = 0.0;
            foreach ($billIds as $linkedBillId) {
                $lockBill->bind_param('i', $linkedBillId);
                $lockBill->execute();
                $bill = $lockBill->get_result()->fetch_assoc();
                if ($bill && $bill['status'] !== 'void') {
                    $balance += max(0.0, (float) $bill['balance']);
                }
            }
            $placeholders = implode(',', array_fill(0, count($billIds), '?'));
            $pending = $this->conn->prepare(
                "SELECT COALESCE(SUM(amount), 0) AS amount
                 FROM payment_requests
                 WHERE guest_id = ? AND status = 'pending' AND bill_id IN ({$placeholders})"
            );
            $pendingTypes = 'i' . str_repeat('i', count($billIds));
            $pendingParams = [$pendingTypes, &$guestId];
            foreach ($billIds as $index => $linkedBillId) {
                $pendingParams[] = &$billIds[$index];
            }
            call_user_func_array([$pending, 'bind_param'], $pendingParams);
            $pending->execute();
            $pendingAmount = (float) ($pending->get_result()->fetch_assoc()['amount'] ?? 0);
            if ($amount > $balance - $pendingAmount + 0.009) {
                $this->conn->rollback();
                flash('error', 'This amount exceeds the available balance after pending requests.');
                return false;
            }

            $insert = $this->conn->prepare(
                'INSERT INTO payment_requests (guest_id, booking_id, bill_id, amount, method, reference_no, notes)
                 VALUES (?, NULLIF(?, 0), ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'))'
            );
            $insert->bind_param('iiidsss', $guestId, $bookingId, $billId, $amount, $method, $reference, $notes);
            $insert->execute();
            $requestId = (int) $this->conn->insert_id;
            audit($this->conn, $actorId, 'payment_request', "Guest #{$guestId} requested " . money($amount) . ' via ' . payment_method_label($method));
            $this->conn->commit();
            flash('success', 'Payment request submitted. It will be completed after Front Desk confirms the payment.');
            return $requestId;
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function complete(int $requestId, int $actorId, PaymentModel $payments, BillModel $bills): bool
    {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM payment_requests WHERE id = ? FOR UPDATE'
            );
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            if (!$request || $request['status'] !== 'pending') {
                $this->conn->rollback();
                flash('error', 'This payment request is no longer pending.');
                return false;
            }
            $recorded = $payments->recordAccountPayment([
                'booking_id' => (int) ($request['booking_id'] ?? 0),
                'bill_id' => (int) $request['bill_id'],
                'amount' => (float) $request['amount'],
                'method' => $request['method'],
                'reference_no' => (string) ($request['reference_no'] ?? ''),
                'notes' => (string) ($request['notes'] ?? ''),
                'payment_request_id' => $requestId,
            ], $bills, $actorId, false);
            if (!$recorded) {
                $this->conn->rollback();
                return false;
            }
            $update = $this->conn->prepare(
                "UPDATE payment_requests SET status = 'completed', processed_by = ?, processed_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $update->bind_param('ii', $actorId, $requestId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                throw new RuntimeException('The payment request changed before it could be completed.');
            }
            audit($this->conn, $actorId, 'payment_request', "Payment request #{$requestId} completed.");
            $this->conn->commit();
            return true;
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function cancel(int $requestId, int $actorId, ?int $guestId = null): bool
    {
        $sql = "UPDATE payment_requests SET status = 'cancelled', processed_by = ?, processed_at = NOW()
                WHERE id = ? AND status = 'pending'";
        if ($guestId !== null) {
            $sql .= ' AND guest_id = ?';
        }
        $stmt = $this->conn->prepare($sql);
        if ($guestId !== null) {
            $stmt->bind_param('iii', $actorId, $requestId, $guestId);
        } else {
            $stmt->bind_param('ii', $actorId, $requestId);
        }
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            flash('error', 'Only a pending payment request can be deleted.');
            return false;
        }
        audit($this->conn, $actorId, 'payment_request', "Payment request #{$requestId} cancelled.");
        flash('success', 'Payment request deleted.');
        return true;
    }
}
