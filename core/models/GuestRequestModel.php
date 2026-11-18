<?php
declare(strict_types=1);

final class GuestRequestModel
{
    public const STATUSES = ['pending', 'preparing', 'ready', 'to_be_delivered', 'delivered', 'not_delivered', 'cancelled'];

    public function __construct(private mysqli $conn) {}

    public function all(string $status = '', array $types = [], ?int $assignedTo = null, bool $excludeUnavailable = false): array
    {
        $sql = "SELECT gr.*, g.full_name AS guest_name, g.phone AS guest_phone,
                       r.room_number, b.check_in, b.check_out, b.check_in_time, b.check_out_time, b.status AS booking_status,
                       u.full_name AS handled_by_name,
                       prepared.full_name AS prepared_by_name,
                       assigned.full_name AS assigned_to_name,
                       assigning.full_name AS assigned_by_name,
                       confirmed.full_name AS confirmed_by_name,
                       unavailable_by.full_name AS item_unavailable_by_name
                FROM guest_requests gr
                JOIN guests g ON g.id = gr.guest_id
                LEFT JOIN bookings b ON b.id = gr.booking_id
                LEFT JOIN rooms r ON r.id = b.room_id
                LEFT JOIN users u ON u.id = gr.handled_by
                LEFT JOIN users prepared ON prepared.id = gr.prepared_by
                LEFT JOIN users assigned ON assigned.id = gr.assigned_to
                LEFT JOIN users assigning ON assigning.id = gr.assigned_by
                LEFT JOIN users confirmed ON confirmed.id = gr.confirmed_by
                LEFT JOIN users unavailable_by ON unavailable_by.id = gr.item_unavailable_by";
        $filters = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $filters[] = "gr.status = '" . $this->conn->real_escape_string($status) . "'";
        }
        $types = array_values(array_intersect($types, ['extra', 'food', 'laundry']));
        if ($types) {
            $typeList = implode(',', array_map(
                fn (string $type): string => "'" . $this->conn->real_escape_string($type) . "'",
                $types
            ));
            $filters[] = "gr.request_type IN ({$typeList})";
        }
        if ($assignedTo !== null) {
            $filters[] = 'gr.assigned_to = ' . max(0, $assignedTo);
        }
        if ($excludeUnavailable) {
            $filters[] = 'gr.item_unavailable_at IS NULL';
        }
        if ($filters) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }
        $sql .= ' ORDER BY FIELD(gr.status, "pending","preparing","ready","to_be_delivered","delivered","cancelled"), gr.id DESC';
        return with_photo_urls($this->conn->query($sql)->fetch_all(MYSQLI_ASSOC));
    }

    public function forGuest(int $guestId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT gr.*, r.room_number, prepared.full_name AS prepared_by_name,
                    assigned.full_name AS assigned_to_name,
                    confirmed.full_name AS confirmed_by_name, handled.full_name AS handled_by_name,
                    unavailable_by.full_name AS item_unavailable_by_name
             FROM guest_requests gr
             LEFT JOIN bookings b ON b.id = gr.booking_id
             LEFT JOIN rooms r ON r.id = b.room_id
             LEFT JOIN users prepared ON prepared.id = gr.prepared_by
             LEFT JOIN users assigned ON assigned.id = gr.assigned_to
             LEFT JOIN users confirmed ON confirmed.id = gr.confirmed_by
             LEFT JOIN users handled ON handled.id = gr.handled_by
             LEFT JOIN users unavailable_by ON unavailable_by.id = gr.item_unavailable_by
             WHERE gr.guest_id = ?
             ORDER BY gr.id DESC"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        return with_photo_urls($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public function forBooking(int $bookingId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT gr.*, prepared.full_name AS prepared_by_name,
                    assigned.full_name AS assigned_to_name,
                    confirmed.full_name AS confirmed_by_name,
                    unavailable_by.full_name AS item_unavailable_by_name
             FROM guest_requests gr
             LEFT JOIN users prepared ON prepared.id = gr.prepared_by
             LEFT JOIN users assigned ON assigned.id = gr.assigned_to
             LEFT JOIN users confirmed ON confirmed.id = gr.confirmed_by
             LEFT JOIN users unavailable_by ON unavailable_by.id = gr.item_unavailable_by
             WHERE gr.booking_id = ?
             ORDER BY gr.id DESC"
        );
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        return with_photo_urls($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM guest_requests WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function create(array $data, int $guestId, int $actorId): bool
    {
        $type = (string) ($data['request_type'] ?? '');
        $qty = max(1, (float) ($data['quantity'] ?? 1));
        $notes = trim((string) ($data['notes'] ?? ''));

        $bookingId = (int) ($data['booking_id'] ?? 0);
        if ($bookingId > 0) {
            $bookingStmt = $this->conn->prepare(
                "SELECT id, status FROM bookings
                 WHERE id = ? AND guest_id = ? AND status = 'checked_in'"
            );
            $bookingStmt->bind_param('ii', $bookingId, $guestId);
            $bookingStmt->execute();
            $booking = $bookingStmt->get_result()->fetch_assoc() ?: null;
        } else {
            $booking = (new BookingModel($this->conn))->checkedInForGuest($guestId);
            $bookingId = (int) ($booking['id'] ?? 0);
        }
        if (!$booking || $booking['status'] !== 'checked_in') {
            flash('error', 'You need a checked-in stay to request items, food, or laundry.');
            return false;
        }

        $itemName = '';
        $unitPrice = 0.0;
        $extraId = 0;
        $serviceId = 0;
        $photo = null;

        if ($type === 'extra') {
            $extraId = (int) ($data['additional_item_id'] ?? 0);
            $item = (new AdditionalItemModel($this->conn))->find($extraId);
            if (!$item || $item['status'] !== 'active') {
                flash('error', 'Select a valid extra item (e.g. Extra Pillow).');
                return false;
            }
            $itemName = (string) $item['item_name'];
            $unitPrice = (float) $item['unit_price'];
            $photo = $item['photo'] ?? null;
        } elseif ($type === 'food' || $type === 'laundry') {
            $serviceId = (int) ($data['service_id'] ?? 0);
            $service = (new ServiceModel($this->conn))->findActive($serviceId);
            $validService = $type === 'food'
                ? ($service && $this->isFoodService($service))
                : ($service && $this->isLaundryService($service));
            if (!$validService) {
                flash('error', $type === 'food' ? 'Select a valid food or room-service menu item.' : 'Select a valid laundry service.');
                return false;
            }
            $itemName = (string) $service['name'];
            $unitPrice = (float) $service['price'];
            $photo = $service['photo'] ?? null;
        } else {
            flash('error', 'Request type must be extra, food, or laundry.');
            return false;
        }

        $this->conn->begin_transaction();
        try {
        $stmt = $this->conn->prepare(
            'INSERT INTO guest_requests
             (guest_id, booking_id, request_type, additional_item_id, service_id, item_name, quantity, unit_price, notes, photo, status)
             VALUES (?, ?, ?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?, "pending")'
        );
        $stmt->bind_param(
            'iisiisddss',
            $guestId,
            $bookingId,
            $type,
            $extraId,
            $serviceId,
            $itemName,
            $qty,
            $unitPrice,
            $notes,
            $photo
        );
        $stmt->execute();
        $requestId = (int) $this->conn->insert_id;
        $request = [
            'id' => $requestId,
            'guest_id' => $guestId,
            'booking_id' => $bookingId,
            'request_type' => $type,
            'additional_item_id' => $extraId,
            'service_id' => $serviceId,
            'item_name' => $itemName,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
        ];
        $billItemId = $this->chargeToBill($request, $actorId);
        $billStmt = $this->conn->prepare(
            'UPDATE guest_requests SET billed = 1, bill_item_id = ? WHERE id = ?'
        );
        $billStmt->bind_param('ii', $billItemId, $requestId);
        $billStmt->execute();
        audit($this->conn, $actorId, 'guest_request_create', "{$type}: {$itemName} x{$qty}");
        $this->conn->commit();
        flash('success', 'Request submitted and added to your guest account balance.');
        return true;
        } catch (Throwable $error) {
            $this->conn->rollback();
            flash('error', 'The request could not be submitted or charged to your guest account.');
            return false;
        }
    }

    public function assign(int $id, int $staffId, int $actorId): bool
    {
        $this->conn->begin_transaction();
        try {
            $rowStmt = $this->conn->prepare('SELECT * FROM guest_requests WHERE id = ? FOR UPDATE');
            $rowStmt->bind_param('i', $id);
            $rowStmt->execute();
            $row = $rowStmt->get_result()->fetch_assoc() ?: null;
            if (!$row) {
                $this->conn->rollback();
                flash('error', 'Request not found.');
                return false;
            }
            if ($this->catalogItemArchived($row)) {
                $this->conn->rollback();
                flash('error', 'This requested catalog item is inactive or archived. The request remains in history, but the team cannot fulfill it.');
                return false;
            }
            if (!in_array($row['request_type'], ['extra', 'laundry'], true) || $row['status'] !== 'pending') {
                $this->conn->rollback();
                flash('error', 'Only pending amenities and laundry requests can be assigned.');
                return false;
            }

            $staffStmt = $this->conn->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'laundry' AND status = 'active' LIMIT 1"
            );
            $staffStmt->bind_param('i', $staffId);
            $staffStmt->execute();
            if (!$staffStmt->get_result()->fetch_assoc()) {
                $this->conn->rollback();
                flash('error', 'Choose an active Amenities & Laundry Staff account.');
                return false;
            }

            $stmt = $this->conn->prepare(
                'UPDATE guest_requests SET assigned_to = ?, assigned_by = ?, assigned_at = NOW() WHERE id = ?'
            );
            $stmt->bind_param('iii', $staffId, $actorId, $id);
            $stmt->execute();
            audit($this->conn, $actorId, 'guest_request_assigned', "Request #{$id} assigned to user #{$staffId}");
            $this->conn->commit();
            flash('success', 'Request assigned to Amenities & Laundry Staff.');
            return true;
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function updateStatus(int $id, string $status, int $actorId, string $role): bool
    {
        if (!in_array($status, ['preparing', 'ready', 'to_be_delivered'], true)) {
            flash('error', 'Invalid request status.');
            return false;
        }

        $this->conn->begin_transaction();
        try {
            $rowStmt = $this->conn->prepare('SELECT * FROM guest_requests WHERE id = ? FOR UPDATE');
            $rowStmt->bind_param('i', $id);
            $rowStmt->execute();
            $row = $rowStmt->get_result()->fetch_assoc() ?: null;
            if (!$row) {
                $this->conn->rollback();
                flash('error', 'Request not found.');
                return false;
            }
            if ($this->catalogItemArchived($row)) {
                $this->conn->rollback();
                flash('error', 'This requested catalog item is inactive or archived. The request remains in history, but the team cannot fulfill it.');
                return false;
            }

            $nextStatus = match ($row['status']) {
                'pending' => 'preparing',
                'preparing' => 'ready',
                'ready' => 'to_be_delivered',
                'not_delivered' => 'to_be_delivered',
                default => null,
            };
            $teamCanHandle = match ($role) {
                'kitchen' => $row['request_type'] === 'food',
                'laundry' => in_array($row['request_type'], ['extra', 'laundry'], true)
                    && (int) ($row['assigned_to'] ?? 0) === $actorId,
                default => false,
            };
            if (!$teamCanHandle || $nextStatus !== $status) {
                $this->conn->rollback();
                flash('error', 'This request is not assigned to your team or is not ready for that status update.');
                return false;
            }

            $billItemId = (int) ($row['bill_item_id'] ?? 0);
            if (!(int) $row['billed']) {
                $billItemId = $this->chargeToBill($row, $actorId);
            }
            $stmt = $this->conn->prepare(
                "UPDATE guest_requests
                 SET status = ?, handled_by = ?, handled_at = NOW(),
                     billed = 1, bill_item_id = ?,
                     prepared_by = IF(? = 'preparing' AND prepared_by IS NULL, ?, prepared_by),
                     confirmed_by = IF(? = 'to_be_delivered', NULL, confirmed_by),
                     confirmed_at = IF(? = 'to_be_delivered', NULL, confirmed_at)
                 WHERE id = ?"
            );
            $stmt->bind_param('siisissi', $status, $actorId, $billItemId, $status, $actorId, $status, $status, $id);
            $stmt->execute();
            audit($this->conn, $actorId, 'guest_request_status', "Request #{$id} to {$status}");
            $this->conn->commit();
            flash('success', 'Request status updated to ' . str_replace('_', ' ', $status) . '.');
            return true;
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function confirmDelivered(int $id, int $guestId, int $userId): bool
    {
        return $this->updateDeliveryOutcome($id, $guestId, $userId, true);
    }

    public function reportNotDelivered(int $id, int $guestId, int $userId): bool
    {
        return $this->updateDeliveryOutcome($id, $guestId, $userId, false);
    }

    private function updateDeliveryOutcome(int $id, int $guestId, int $userId, bool $received): bool
    {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('SELECT guest_id, status FROM guest_requests WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc() ?: null;
            if (!$request || (int) $request['guest_id'] !== $guestId) {
                $this->conn->rollback();
                flash('error', 'Request not found.');
                return false;
            }
            if ($request['status'] !== 'to_be_delivered') {
                $this->conn->rollback();
                flash('error', 'You can confirm delivery only after the item is marked To be delivered.');
                return false;
            }

            $status = $received ? 'delivered' : 'not_delivered';
            $update = $this->conn->prepare(
                'UPDATE guest_requests SET status = ?, confirmed_by = ?, confirmed_at = NOW() WHERE id = ?'
            );
            $update->bind_param('sii', $status, $userId, $id);
            $update->execute();
            $auditAction = $received ? 'guest_request_delivery_confirmed' : 'guest_request_delivery_reported_missing';
            $auditMessage = $received
                ? "Guest confirmed delivery of request #{$id}"
                : "Guest reported request #{$id} was not delivered";
            audit($this->conn, $userId, $auditAction, $auditMessage);
            $this->conn->commit();
            flash('success', $received ? 'Delivery confirmed. Thank you.' : 'Your report was sent to staff for follow-up.');
            return true;
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function cancelByGuest(int $id, int $guestId, int $userId): bool
    {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('SELECT * FROM guest_requests WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            if (!$row || (int) $row['guest_id'] !== $guestId) {
                $this->conn->rollback();
                flash('error', 'Request not found.');
                return false;
            }
            if ($row['status'] !== 'pending') {
                $this->conn->rollback();
                flash('error', 'Only a request still waiting for its team can be cancelled.');
                return false;
            }
            if ((int) ($row['bill_item_id'] ?? 0) > 0) {
                $itemStmt = $this->conn->prepare('SELECT bill_id FROM bill_items WHERE id = ?');
                $itemId = (int) $row['bill_item_id'];
                $itemStmt->bind_param('i', $itemId);
                $itemStmt->execute();
                $item = $itemStmt->get_result()->fetch_assoc();
                if ($item) {
                    (new BillModel($this->conn))->removeItem((int) $item['bill_id'], $itemId);
                }
            }
            $status = 'cancelled';
            $update = $this->conn->prepare(
                'UPDATE guest_requests SET status = ?, billed = 0, bill_item_id = NULL, handled_by = ?, handled_at = NOW() WHERE id = ?'
            );
            $update->bind_param('sii', $status, $userId, $id);
            $update->execute();
            audit($this->conn, $userId, 'guest_request_cancel', "Request #{$id}; its pending charge was reversed.");
            $this->conn->commit();
            flash('success', 'Request cancelled and its charge was removed from your balance.');
            return true;
        } catch (Throwable $error) {
            $this->conn->rollback();
            flash('error', 'Could not cancel the request and reverse its charge.');
            return false;
        }
    }

    private function isFoodService(array $service): bool
    {
        $cat = strtolower((string) ($service['category'] ?? ''));
        return in_array($cat, ['food', 'restaurant', 'room service'], true);
    }

    private function catalogItemArchived(array $request): bool
    {
        if (!empty($request['item_unavailable_at'])) {
            return true;
        }
        if ((int) ($request['service_id'] ?? 0) > 0) {
            $service = (new ServiceModel($this->conn))->find((int) $request['service_id']);
            return !$service || $service['status'] !== 'active';
        }
        if ((int) ($request['additional_item_id'] ?? 0) > 0) {
            $item = (new AdditionalItemModel($this->conn))->find((int) $request['additional_item_id']);
            return !$item || $item['status'] !== 'active';
        }
        return false;
    }

    private function isLaundryService(array $service): bool
    {
        $cat = strtolower((string) ($service['category'] ?? ''));
        return $cat === 'laundry';
    }

    private function chargeToBill(array $row, int $actorId): int
    {
        $bills = new BillModel($this->conn);
        $bill = $bills->byBooking((int) ($row['booking_id'] ?? 0));
        if (!$bill) {
            $bill = $bills->latestForGuest((int) $row['guest_id']);
        }
        if (!$bill) {
            $due = date('Y-m-d', strtotime('+1 day'));
            $billId = $bills->create(
                (int) $row['guest_id'],
                (int) ($row['booking_id'] ?? 0) ?: null,
                $due,
                $actorId
            );
        } else {
            $billId = (int) $bill['id'];
        }

        $qty = (float) $row['quantity'];
        $price = (float) $row['unit_price'];
        $name = (string) $row['item_name'];

        if (in_array($row['request_type'], ['food', 'laundry'], true) && (int) ($row['service_id'] ?? 0) > 0) {
            $service = (new ServiceModel($this->conn))->findActive((int) $row['service_id']);
            if ($service) {
                return $bills->addService($billId, $service, $qty, $actorId);
            } else {
                return $bills->addExtra($billId, "Guest request: {$name}", $qty, $price, $actorId, (int) ($row['service_id'] ?? 0));
            }
        }

        $ref = (int) ($row['additional_item_id'] ?? 0);
        return $bills->addExtra($billId, "Guest request: {$name}", $qty, $price, $actorId, $ref);
    }

    public function catalogForGuest(): array
    {
        return [
            'extras' => (new AdditionalItemModel($this->conn))->active(),
            'food' => (new ServiceModel($this->conn))->foodActive(),
            'laundry' => (new ServiceModel($this->conn))->laundryActive(),
        ];
    }
}
