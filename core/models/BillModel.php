<?php
declare(strict_types=1);

final class BillModel
{
    public function __construct(private mysqli $conn) {}

    public function count(): int
    {
        return (int) $this->conn->query('SELECT COUNT(*) c FROM bills')->fetch_assoc()['c'];
    }

    public function revenueTotal(): float
    {
        return (float) $this->conn->query('SELECT COALESCE(SUM(paid_amount),0) s FROM bills')->fetch_assoc()['s'];
    }

    public function outstandingTotal(): float
    {
        return (float) $this->conn->query(
            "SELECT COALESCE(SUM(balance),0) s FROM bills WHERE status IN ('pending','partial','overdue')"
        )->fetch_assoc()['s'];
    }

    public function pendingCount(): int
    {
        return (int) $this->conn->query(
            "SELECT COUNT(*) c FROM bills WHERE status IN ('pending','partial','overdue')"
        )->fetch_assoc()['c'];
    }

    public function recent(int $limit = 8): array
    {
        return $this->conn->query(
            "SELECT b.invoice_number, b.total_amount, b.balance, b.status, g.full_name, b.created_at, b.id
             FROM bills b JOIN guests g ON g.id = b.guest_id
             ORDER BY b.id DESC LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function listFiltered(?string $status = null): array
    {
        $sql = "SELECT b.*, g.full_name AS guest_name, bk.stay_group
                FROM bills b JOIN guests g ON g.id = b.guest_id
                LEFT JOIN bookings bk ON bk.id = b.booking_id";
        if ($status && in_array($status, ['pending', 'partial', 'paid', 'overdue', 'void'], true)) {
            $sql .= " WHERE b.status = '" . $this->conn->real_escape_string($status) . "'";
        }
        $sql .= ' ORDER BY b.id DESC';
        return $this->conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    public function recentList(int $limit = 40): array
    {
        return $this->conn->query(
            "SELECT b.id, b.invoice_number, b.total_amount, b.balance, b.status, g.full_name
             FROM bills b JOIN guests g ON g.id=b.guest_id ORDER BY b.id DESC LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bills WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function findWithGuest(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT b.*, g.full_name, g.email, g.phone, g.address,
                    bk.status AS booking_status, bk.check_in AS stay_check_in, bk.check_out AS stay_check_out, bk.check_in_time AS stay_check_in_time, bk.check_out_time AS stay_check_out_time,
                    bk.adults, bk.children, r.id AS room_id, r.room_number, r.room_type, r.rate_per_night
             FROM bills b
             JOIN guests g ON g.id = b.guest_id
             LEFT JOIN bookings bk ON bk.id = b.booking_id
             LEFT JOIN rooms r ON r.id = bk.room_id
             WHERE b.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function accountHistory(): array
    {
        return $this->conn->query(
            "SELECT
                CONCAT('guest:', b.guest_id) AS account_key,
                MIN(b.id) AS representative_bill_id,
                MIN(b.booking_id) AS representative_booking_id,
                MIN(b.invoice_number) AS invoice_number,
                g.id AS guest_id,
                NULL AS stay_group,
                g.full_name,
                COUNT(DISTINCT b.id) AS bill_count,
                COUNT(DISTINCT bk.room_id) AS room_count,
                COUNT(DISTINCT bk.id) AS stay_count,
                GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ', ') AS room_numbers,
                GROUP_CONCAT(DISTINCT CONCAT(DATE_FORMAT(bk.check_in, '%Y-%m-%d'), '~', DATE_FORMAT(bk.check_out, '%Y-%m-%d'))
                    ORDER BY bk.check_in SEPARATOR '|') AS stay_periods,
                MIN(bk.check_in) AS check_in,
                MAX(bk.check_out) AS check_out,
                GROUP_CONCAT(DISTINCT bk.status ORDER BY bk.status SEPARATOR ',') AS stay_statuses,
                SUM(b.total_amount) AS total_amount,
                SUM(b.paid_amount) AS paid_amount,
                SUM(b.balance) AS balance,
                CASE
                    WHEN SUM(b.balance) <= 0.009 THEN 'paid'
                    WHEN SUM(b.paid_amount) > 0 THEN 'partial'
                    ELSE 'pending'
                END AS account_status
             FROM bills b
             JOIN guests g ON g.id = b.guest_id
             LEFT JOIN bookings bk ON bk.id = b.booking_id
             LEFT JOIN rooms r ON r.id = bk.room_id
             WHERE b.status <> 'void'
             GROUP BY account_key, g.id, g.full_name
             HAVING (SUM(b.total_amount) > 0.009 OR SUM(b.paid_amount) > 0.009)
                AND (SUM(bk.status IN ('checked_in','checked_out')) > 0 OR SUM(bk.id IS NULL) > 0)
             ORDER BY MAX(bk.check_out) IS NULL, MAX(bk.check_out) DESC, MIN(b.id) DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function accountRecords(int $bookingId, int $billId): array
    {
        $guestId = 0;
        if ($bookingId > 0) {
            $bookingStmt = $this->conn->prepare('SELECT guest_id FROM bookings WHERE id = ?');
            $bookingStmt->bind_param('i', $bookingId);
            $bookingStmt->execute();
            $guestId = (int) ($bookingStmt->get_result()->fetch_column() ?: 0);
        }
        if ($billId > 0) {
            $billStmt = $this->conn->prepare("SELECT guest_id FROM bills WHERE id = ? AND status <> 'void'");
            $billStmt->bind_param('i', $billId);
            $billStmt->execute();
            $billGuestId = (int) ($billStmt->get_result()->fetch_column() ?: 0);
            if ($guestId > 0 && $billGuestId > 0 && $guestId !== $billGuestId) {
                return [];
            }
            $guestId = $guestId ?: $billGuestId;
        }
        if ($guestId <= 0) {
            return [];
        }

        $bookings = $this->conn->prepare(
            "SELECT b.id AS bill_id, bk.id AS booking_id, b.invoice_number, b.total_amount, b.paid_amount,
                    b.balance, b.status AS bill_status, b.created_at AS bill_created_at,
                    g.id AS guest_id, g.full_name, g.phone, g.email,
                    bk.status AS booking_status, bk.check_in, bk.check_in_time, bk.check_out, bk.check_out_time,
                    r.id AS room_id, r.room_number, r.room_type, r.rate_per_night
             FROM bookings bk
             JOIN guests g ON g.id = bk.guest_id
             LEFT JOIN rooms r ON r.id = bk.room_id
             LEFT JOIN bills b ON b.booking_id = bk.id AND b.status <> 'void'
             WHERE bk.guest_id = ? AND bk.status <> 'cancelled'
             ORDER BY bk.id DESC, b.id"
        );
        $bookings->bind_param('i', $guestId);
        $bookings->execute();
        $records = $bookings->get_result()->fetch_all(MYSQLI_ASSOC);

        $manualAccounts = $this->conn->prepare(
            "SELECT b.id AS bill_id, b.booking_id, b.invoice_number, b.total_amount, b.paid_amount,
                    b.balance, b.status AS bill_status, b.created_at AS bill_created_at,
                    g.id AS guest_id, g.full_name, g.phone, g.email,
                    NULL AS booking_status, NULL AS check_in, NULL AS check_in_time,
                    NULL AS check_out, NULL AS check_out_time,
                    NULL AS room_id, NULL AS room_number, NULL AS room_type, NULL AS rate_per_night
             FROM bills b
             JOIN guests g ON g.id = b.guest_id
             WHERE b.guest_id = ? AND b.booking_id IS NULL AND b.status <> 'void'
             ORDER BY b.id DESC"
        );
        $manualAccounts->bind_param('i', $guestId);
        $manualAccounts->execute();
        return array_merge($records, $manualAccounts->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    /** Returns an error message when the bill cannot take new charges, otherwise null. */
    public function chargeBlocker(int $billId): ?string
    {
        $bill = $this->findWithGuest($billId);
        if (!$bill) {
            return 'Guest account (bill) not found.';
        }
        if ($bill['status'] === 'void') {
            return 'This bill is void and cannot take new charges.';
        }
        if (!empty($bill['booking_id']) && in_array($bill['booking_status'], ['checked_out', 'cancelled'], true)) {
            return 'This stay is already ' . str_replace('_', ' ', (string) $bill['booking_status']) . '. Charges are locked.';
        }
        return null;
    }

    public function postRoomCharge(int $billId, array $room, float $nights, int $actorId, string $note = ''): void
    {
        $nights = max(1, $nights);
        $rate = (float) $room['rate_per_night'];
        $amount = round($nights * $rate, 2);
        $type = 'room';
        $roomId = (int) $room['id'];
        $label = rtrim(rtrim(number_format($nights, 2, '.', ''), '0'), '.');
        $desc = 'Room ' . $room['room_number'] . ' · ' . $room['room_type'] . " ({$label} night/s)"
            . ($note !== '' ? ' - ' . $note : '');
        $stmt = $this->conn->prepare(
            'INSERT INTO bill_items (bill_id, item_type, reference_id, description, quantity, unit_price, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisddd', $billId, $type, $roomId, $desc, $nights, $rate, $amount);
        $stmt->execute();
        recalculate_bill($this->conn, $billId);
        if ($actorId > 0) {
            audit($this->conn, $actorId, 'bill_add_room', "Bill #{$billId} · {$desc}");
        }
        flash('success', 'Room charge posted to the guest account.');
    }

    /** Posts the extra time of an extended stay, prorated per hour/minute against the nightly rate. */
    public function postExtensionCharge(int $billId, array $room, float $days, int $actorId, string $range): void
    {
        $qty = round($days, 2);
        if ($qty <= 0) {
            $qty = 0.01;
        }
        $rate = (float) $room['rate_per_night'];
        $amount = round($qty * $rate, 2);
        $type = 'room';
        $roomId = (int) $room['id'];
        $label = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        $desc = 'Stay extension · Room ' . $room['room_number'] . " ({$label} night/s) · {$range}";
        $stmt = $this->conn->prepare(
            'INSERT INTO bill_items (bill_id, item_type, reference_id, description, quantity, unit_price, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisddd', $billId, $type, $roomId, $desc, $qty, $rate, $amount);
        $stmt->execute();
        recalculate_bill($this->conn, $billId);
        if ($actorId > 0) {
            audit($this->conn, $actorId, 'bill_add_room', "Bill #{$billId} · {$desc}");
        }
    }

    public function postExtraNightCharge(
        int $billId,
        int $bookingId,
        string $roomNumber,
        float $nightlyRate,
        string $reason,
        string $date,
        int $actorId
    ): bool {
        $description = "{$reason} extra night · Booking #{$bookingId} · Room {$roomNumber} · {$date}";
        $existing = $this->conn->prepare(
            "SELECT id FROM bill_items
             WHERE bill_id = ? AND item_type = 'other' AND reference_id = ? AND description = ?
             LIMIT 1"
        );
        $existing->bind_param('iis', $billId, $bookingId, $description);
        $existing->execute();
        if ($existing->get_result()->fetch_assoc()) {
            return false;
        }

        $quantity = 1.0;
        $amount = round($nightlyRate, 2);
        $type = 'other';
        $item = $this->conn->prepare(
            'INSERT INTO bill_items (bill_id, item_type, reference_id, description, quantity, unit_price, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $item->bind_param('isisddd', $billId, $type, $bookingId, $description, $quantity, $nightlyRate, $amount);
        $item->execute();
        recalculate_bill($this->conn, $billId);
        if ($actorId > 0) {
            audit($this->conn, $actorId, 'late_stay_charge', "Bill #{$billId} · {$description} · " . money($amount));
        }
        return true;
    }

    public function reopenDue(int $billId, string $dueDate): void
    {
        $stmt = $this->conn->prepare('UPDATE bills SET due_date = GREATEST(due_date, ?) WHERE id = ?');
        $stmt->bind_param('si', $dueDate, $billId);
        $stmt->execute();
        recalculate_bill($this->conn, $billId);
    }

    /** Opens the stay's guest account and posts the room nights once; returns the bill id. */
    public function ensureStayBill(array $booking, array $room, int $actorId): int
    {
        $existing = $this->byBooking((int) $booking['id']);
        if ($existing) {
            $billId = (int) $existing['id'];
            $hasRoom = (int) $this->conn->query(
                "SELECT COUNT(*) c FROM bill_items WHERE bill_id={$billId} AND item_type='room'"
            )->fetch_assoc()['c'];
            if ($hasRoom > 0) {
                return $billId;
            }
        } else {
            $due = max((string) $booking['check_out'], date('Y-m-d'));
            $billId = $this->create((int) $booking['guest_id'], (int) $booking['id'], $due, $actorId);
        }
        $nights = (float) nights_between((string) $booking['check_in'], (string) $booking['check_out']);
        $this->postRoomCharge($billId, $room, $nights, $actorId);
        return $billId;
    }

    public function findForGuest(int $id, int $guestId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bills WHERE id = ? AND guest_id = ?');
        $stmt->bind_param('ii', $id, $guestId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function latestForGuest(int $guestId): ?array
    {
        return $this->conn->query(
            "SELECT b.* FROM bills b WHERE b.guest_id={$guestId}
             ORDER BY CASE WHEN EXISTS (SELECT 1 FROM bill_items bi WHERE bi.bill_id=b.id) THEN 0 ELSE 1 END,
                      b.id DESC LIMIT 1"
        )->fetch_assoc() ?: null;
    }

        public function activeForGuest(int $guestId): ?array
    {
        return $this->conn->query(
                        "SELECT b.* FROM bills b
                         LEFT JOIN bookings bk ON bk.id = b.booking_id
                         WHERE b.guest_id={$guestId} AND b.status IN ('pending','partial','overdue','paid')
                         ORDER BY
                             CASE WHEN bk.status IN ('reserved','checked_in') THEN 0 ELSE 1 END,
                             CASE WHEN EXISTS (SELECT 1 FROM bill_items bi WHERE bi.bill_id = b.id) THEN 0 ELSE 1 END,
                             FIELD(b.status,'overdue','partial','pending','paid'), b.id DESC
                         LIMIT 1"
        )->fetch_assoc() ?: null;
    }

    public function allForGuest(int $guestId): array
    {
        return $this->conn->query(
                        "SELECT b.id, b.invoice_number, b.status, b.balance, b.total_amount, b.created_at
                         FROM bills b
                         WHERE b.guest_id={$guestId}
                             AND NOT (
                                 b.booking_id IS NOT NULL
                                 AND NOT EXISTS (SELECT 1 FROM bill_items own_items WHERE own_items.bill_id=b.id)
                                 AND EXISTS (
                                     SELECT 1 FROM bills populated
                                     WHERE populated.booking_id=b.booking_id AND populated.id<>b.id
                                         AND EXISTS (SELECT 1 FROM bill_items posted_items WHERE posted_items.bill_id=populated.id)
                                 )
                             )
                         ORDER BY b.id DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

        public function byBooking(int $bookingId): ?array
    {
        return $this->conn->query(
                        "SELECT b.id FROM bills b WHERE b.booking_id={$bookingId}
                         ORDER BY
                             CASE WHEN EXISTS (SELECT 1 FROM bill_items bi WHERE bi.bill_id = b.id) THEN 0 ELSE 1 END,
                             CASE WHEN b.status = 'void' THEN 1 ELSE 0 END, b.id DESC
                         LIMIT 1"
        )->fetch_assoc() ?: null;
    }

    public function activeByBooking(int $bookingId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT id FROM bills
             WHERE booking_id = ? AND status <> 'void'
             ORDER BY CASE WHEN EXISTS (
                 SELECT 1 FROM bill_items WHERE bill_items.bill_id = bills.id
             ) THEN 0 ELSE 1 END, id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function create(int $guestId, ?int $bookingId, string $dueDate, int $actorId): int
    {
        $invoice = next_invoice_number($this->conn);
        if ($bookingId) {
            $stmt = $this->conn->prepare(
                'INSERT INTO bills (booking_id, guest_id, invoice_number, due_date, created_by) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('iissi', $bookingId, $guestId, $invoice, $dueDate, $actorId);
        } else {
            $stmt = $this->conn->prepare(
                'INSERT INTO bills (booking_id, guest_id, invoice_number, due_date, created_by) VALUES (NULL, ?, ?, ?, ?)'
            );
            $stmt->bind_param('issi', $guestId, $invoice, $dueDate, $actorId);
        }
        $stmt->execute();
        $billId = (int) $this->conn->insert_id;
        audit($this->conn, $actorId, 'bill_create', $invoice);
        flash('success', "Bill {$invoice} created.");
        return $billId;
    }

    public function items(int $billId): array
    {
        return $this->conn->query("SELECT * FROM bill_items WHERE bill_id={$billId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }

    public function addService(
        int $billId,
        array $service,
        float $qty,
        int $actorId,
        ?int $deliveryRoomId = null,
        ?string $deliveryRoomNumber = null
    ): int
    {
        $amount = round($qty * (float) $service['price'], 2);
        $type = 'service';
        $serviceId = (int) $service['id'];
        $price = (float) $service['price'];
        $desc = $service['name'] . ' (' . $service['category'] . ')';
        $stmt = $this->conn->prepare(
            'INSERT INTO bill_items
             (bill_id, item_type, reference_id, description, quantity, unit_price, amount, delivery_room_id, delivery_room_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisdddis', $billId, $type, $serviceId, $desc, $qty, $price, $amount, $deliveryRoomId, $deliveryRoomNumber);
        $stmt->execute();
        $itemId = (int) $this->conn->insert_id;
        recalculate_bill($this->conn, $billId);
        audit($this->conn, $actorId, 'bill_add_service', "Bill #{$billId} · {$desc}");
        flash('success', 'Service charge added.');
        return $itemId;
    }

    public function addExtra(
        int $billId,
        string $desc,
        float $qty,
        float $price,
        int $actorId = 0,
        int $referenceId = 0,
        ?int $deliveryRoomId = null,
        ?string $deliveryRoomNumber = null
    ): int
    {
        $amount = round($qty * $price, 2);
        $type = 'extra';
        $stmt = $this->conn->prepare(
            'INSERT INTO bill_items
             (bill_id, item_type, reference_id, description, quantity, unit_price, amount, delivery_room_id, delivery_room_number)
             VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisdddis', $billId, $type, $referenceId, $desc, $qty, $price, $amount, $deliveryRoomId, $deliveryRoomNumber);
        $stmt->execute();
        $itemId = (int) $this->conn->insert_id;
        recalculate_bill($this->conn, $billId);
        if ($actorId > 0) {
            audit($this->conn, $actorId, 'bill_add_extra', "Bill #{$billId} · {$desc}");
        }
        flash('success', 'Extra charge added.');
        return $itemId;
    }

    public function applyDiscount(int $billId, array $discount, int $actorId): void
    {
        $bill = $this->find($billId);
        if (!$bill) {
            return;
        }
        $subtotal = (float) $bill['subtotal'];
        $amount = $discount['discount_type'] === 'percent'
            ? round($subtotal * ((float) $discount['value'] / 100), 2)
            : (float) $discount['value'];
        $amount = min($amount, $subtotal);
        $upd = $this->conn->prepare('UPDATE bills SET discount_amount=? WHERE id=?');
        $upd->bind_param('di', $amount, $billId);
        $upd->execute();
        recalculate_bill($this->conn, $billId);
        audit($this->conn, $actorId, 'bill_discount', "Applied {$discount['name']} to bill #{$billId}");
        flash('success', 'Authorized discount applied.');
    }

    public function removeItem(int $billId, int $itemId): void
    {
        $this->conn->query("DELETE FROM bill_items WHERE id={$itemId} AND bill_id={$billId}");
        recalculate_bill($this->conn, $billId);
        flash('success', 'Line item removed.');
    }

    public function addPaidAmount(int $billId, float $amount): void
    {
        $bill = $this->find($billId);
        if (!$bill) {
            return;
        }
        $paid = (float) $bill['paid_amount'] + $amount;
        $upd = $this->conn->prepare('UPDATE bills SET paid_amount=? WHERE id=?');
        $upd->bind_param('di', $paid, $billId);
        $upd->execute();
        recalculate_bill($this->conn, $billId);
    }

    public function invoiceList(int $limit = 60): array
    {
        return $this->conn->query(
            "SELECT b.id, b.invoice_number, b.total_amount, b.status, b.created_at, g.full_name
             FROM bills b JOIN guests g ON g.id=b.guest_id ORDER BY b.id DESC LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function paymentSummary(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT b.id, b.invoice_number, b.balance, b.status, g.full_name
             FROM bills b JOIN guests g ON g.id=b.guest_id WHERE b.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}
