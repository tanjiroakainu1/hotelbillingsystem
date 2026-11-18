<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class BillsApi
{
    public function getAdminBills(array $data = []): void
    {
        api_require_role('admin');
        $status = (string) ($data['status'] ?? '');
        api_ok((new BillModel(api_conn()))->listFiltered($status !== '' ? $status : null));
    }

    public function getBillingPage(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $bills = new BillModel($conn);
        $billId = (int) ($data['id'] ?? 0);
        $bookingId = (int) ($data['booking_id'] ?? 0);

        if ($bookingId && !$billId) {
            $existing = $bills->byBooking($bookingId);
            if ($existing) {
                $billId = (int) $existing['id'];
            }
        }

        $bill = $billId ? $bills->findWithGuest($billId) : null;
        $items = $bill ? $bills->items((int) $bill['id']) : [];
        $deliveryRooms = $bill
            ? (new RoomModel($conn))->activeForGuest((int) $bill['guest_id'])
            : [];

        api_ok([
            'selected_id' => $billId,
            'bill' => $bill,
            'items' => $items,
            'delivery_rooms' => $deliveryRooms,
            'account_history' => $bills->accountHistory(),
            'guests' => (new GuestModel($conn))->listForBooking(),
            'bookings' => (new BookingModel($conn))->activeSimple(),
            'services' => (new ServiceModel($conn))->active(),
            'discounts' => (new DiscountModel($conn))->active(),
            'additional_items' => (new AdditionalItemModel($conn))->active(),
        ]);
    }

    public function getGuestAccount(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $bills = new BillModel($conn);
        $bookingId = (int) ($data['booking_id'] ?? 0);
        $billId = (int) ($data['bill_id'] ?? 0);
        $records = $bills->accountRecords($bookingId, $billId);
        if (!$records) {
            api_error('Guest account not found.', 404);
        }

        $rooms = [];
        $total = 0.0;
        $paid = 0.0;
        $balance = 0.0;
        $bookingIds = [];
        $roomIds = [];
        $billIds = [];
        $guestRequests = new GuestRequestModel($conn);
        foreach ($records as $record) {
            $record['items'] = !empty($record['bill_id']) ? $bills->items((int) $record['bill_id']) : [];
            $record['requests'] = !empty($record['booking_id'])
                ? $guestRequests->forBooking((int) $record['booking_id'])
                : [];
            $rooms[] = $record;
            if (!empty($record['booking_id'])) {
                $bookingIds[] = (int) $record['booking_id'];
            }
            if (!empty($record['room_id'])) {
                $roomIds[] = (int) $record['room_id'];
            }
            if (!empty($record['bill_id'])) {
                $billIds[] = (int) $record['bill_id'];
            }
            $total += (float) ($record['total_amount'] ?? 0);
            $paid += (float) ($record['paid_amount'] ?? 0);
            $balance += (float) ($record['balance'] ?? 0);
        }
        $bookingIds = array_values(array_unique($bookingIds));
        $first = $records[0];
        api_ok([
            'guest' => [
                'id' => (int) $first['guest_id'],
                'full_name' => $first['full_name'],
                'phone' => $first['phone'],
                'email' => $first['email'],
            ],
            'booking_id' => (int) ($first['booking_id'] ?? 0),
            'bill_id' => (int) ($first['bill_id'] ?? 0),
            'room_count' => count(array_unique($roomIds)),
            'stay_count' => count(array_unique($bookingIds)),
            'total_amount' => round($total, 2),
            'paid_amount' => round($paid, 2),
            'balance' => round($balance, 2),
            'rooms' => $rooms,
            'extension_history' => (new BookingModel($conn))->extensionHistory($bookingIds),
            'transfer_history' => (new RoomTransferModel($conn))->forBookings($bookingIds),
            'payment_history' => (new PaymentModel($conn))->forBills($billIds),
            'hotel' => hotel_info(),
        ]);
    }

    public function createBill(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $guestId = (int) ($data['guest_id'] ?? 0);
        $booking = (int) ($data['booking_id'] ?? 0);
        $due = (string) ($data['due_date'] ?? date('Y-m-d', strtotime('+1 day')));
        if (!$guestId) {
            api_error('Select a guest.');
        }
        $bills = new BillModel(api_conn());
        if ($booking > 0) {
            $bookingStmt = api_conn()->prepare('SELECT guest_id FROM bookings WHERE id = ?');
            $bookingStmt->bind_param('i', $booking);
            $bookingStmt->execute();
            $bookingGuestId = $bookingStmt->get_result()->fetch_column();
            if ((int) $bookingGuestId !== $guestId) {
                api_error('The selected stay does not belong to this guest.');
            }
            $existing = $bills->byBooking($booking);
            if ($existing) {
                api_ok(['bill_id' => (int) $existing['id']], 'This stay already has a guest account.');
            }
        }
        $newId = $bills->create(
            $guestId,
            $booking > 0 ? $booking : null,
            $due,
            (int) $user['id']
        );
        api_ok(['bill_id' => $newId], 'Bill created.');
    }

    private function chargeableBill(BillModel $bills, int $billId): void
    {
        $blocker = $bills->chargeBlocker($billId);
        if ($blocker !== null) {
            api_error($blocker);
        }
    }

    private function deliveryRoom(mysqli $conn, int $billId, int $roomId): ?array
    {
        $billStmt = $conn->prepare(
            "SELECT booking.status AS booking_status
             FROM bills b LEFT JOIN bookings booking ON booking.id = b.booking_id
             WHERE b.id = ?"
        );
        $billStmt->bind_param('i', $billId);
        $billStmt->execute();
        $bill = $billStmt->get_result()->fetch_assoc();
        if (!$bill) {
            api_error('Guest account not found.', 404);
        }
        if ($roomId < 1) {
            if (($bill['booking_status'] ?? '') === 'checked_in') {
                api_error('Select a delivery room for this in-house guest.');
            }
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT r.id, r.room_number
             FROM bills b
             JOIN bookings booking ON booking.id = b.booking_id
             JOIN rooms r ON r.id = booking.room_id
             WHERE b.id = ? AND r.id = ? AND booking.status = 'checked_in'
             LIMIT 1"
        );
        $stmt->bind_param('ii', $billId, $roomId);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();
        if (!$room) {
            api_error('Choose a room currently occupied by this guest.');
        }
        return $room;
    }

    private function accountSnapshot(BillModel $bills, int $billId): callable
    {
        return static function () use ($bills, $billId): array {
            $bill = $bills->find($billId);
            return [
                'bill_id' => $billId,
                'total_amount' => $bill['total_amount'] ?? 0,
                'balance' => $bill['balance'] ?? 0,
                'status' => $bill['status'] ?? '',
            ];
        };
    }

    public function addService(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $bills = new BillModel($conn);
        $billId = (int) ($data['bill_id'] ?? 0);
        $serviceId = (int) ($data['service_id'] ?? 0);
        $qty = max(1, (float) ($data['quantity'] ?? 1));
        $service = (new ServiceModel($conn))->findActive($serviceId);
        if (!$billId || !$service) {
            api_error('Invalid bill or service.');
        }
        $this->chargeableBill($bills, $billId);
        $room = $this->deliveryRoom($conn, $billId, (int) ($data['delivery_room_id'] ?? 0));
        $bills->addService(
            $billId,
            $service,
            $qty,
            (int) $user['id'],
            $room ? (int) $room['id'] : null,
            $room ? (string) $room['room_number'] : null
        );
        api_from_flash('Service added.', $this->accountSnapshot($bills, $billId));
    }

    public function addExtra(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $bills = new BillModel($conn);
        $billId = (int) ($data['bill_id'] ?? 0);
        $desc = trim((string) ($data['description'] ?? ''));
        $qty = max(1, (float) ($data['quantity'] ?? 1));
        $price = (float) ($data['unit_price'] ?? 0);
        $referenceId = (int) ($data['additional_item_id'] ?? 0);
        if (!$billId || $desc === '' || $price < 0) {
            api_error('Invalid extra charge.');
        }
        if ($referenceId > 0) {
            $catalogItem = (new AdditionalItemModel($conn))->find($referenceId);
            if (!$catalogItem || $catalogItem['status'] !== 'active') {
                api_error('This catalog item is no longer available.');
            }
            $desc = (string) $catalogItem['item_name'];
            $price = (float) $catalogItem['unit_price'];
        }
        $this->chargeableBill($bills, $billId);
        $room = $this->deliveryRoom($conn, $billId, (int) ($data['delivery_room_id'] ?? 0));
        $bills->addExtra(
            $billId,
            $desc,
            $qty,
            $price,
            (int) $user['id'],
            $referenceId,
            $room ? (int) $room['id'] : null,
            $room ? (string) $room['room_number'] : null
        );
        api_from_flash('Extra charge added.', $this->accountSnapshot($bills, $billId));
    }

    public function applyDiscount(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $billId = (int) ($data['bill_id'] ?? 0);
        $discountId = (int) ($data['discount_id'] ?? 0);
        $bill = (new BillModel($conn))->find($billId);
        $discount = (new DiscountModel($conn))->findActive($discountId);
        if (!$bill || !$discount) {
            api_error('Invalid bill or discount.');
        }
        (new BillModel($conn))->applyDiscount($billId, $discount, (int) $user['id']);
        api_from_flash('Discount applied.');
    }

    public function removeItem(array $data): void
    {
        api_require_role('frontdesk', 'admin');
        $bills = new BillModel(api_conn());
        $billId = (int) ($data['bill_id'] ?? 0);
        $this->chargeableBill($bills, $billId);
        $bills->removeItem($billId, (int) ($data['item_id'] ?? 0));
        api_from_flash('Item removed.');
    }

    public function getInvoices(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $id = (int) ($data['id'] ?? 0);
        $bills = new BillModel($conn);
        $bill = $id ? $bills->findWithGuest($id) : null;
        $accountRooms = $bill
            ? $bills->accountRecords((int) ($bill['booking_id'] ?? 0), (int) $bill['id'])
            : [];
        $bookingIds = array_values(array_unique(array_filter(
            array_map('intval', array_column($accountRooms, 'booking_id')),
            static fn(int $bookingId): bool => $bookingId > 0
        )));
        $accountBillIds = array_column($accountRooms, 'bill_id');
        api_ok([
            'account_history' => $bills->accountHistory(),
            'bill' => $bill,
            'items' => $bill ? $bills->items((int) $bill['id']) : [],
            'payments' => $bill ? (new PaymentModel($conn))->forBills($accountBillIds) : [],
            'hotel' => hotel_info(),
        ]);
    }

    public function getGuestBill(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        $bills = new BillModel($conn);
        $billId = (int) ($data['id'] ?? 0);
        $bill = $billId ? $bills->findForGuest($billId, $guestId) : $bills->activeForGuest($guestId);
        $accountRooms = $bill
            ? $bills->accountRecords((int) ($bill['booking_id'] ?? 0), (int) $bill['id'])
            : [];
        $guestRequests = new GuestRequestModel($conn);
        foreach ($accountRooms as &$room) {
            $room['requests'] = !empty($room['booking_id'])
                ? $guestRequests->forBooking((int) $room['booking_id'])
                : [];
        }
        unset($room);
        $roomItems = [];
        $serviceItems = [];
        if ($bill) {
            foreach ($bills->items((int) $bill['id']) as $item) {
                if ($item['item_type'] === 'room') {
                    $roomItems[] = $item;
                } else {
                    $serviceItems[] = $item;
                }
            }
        }
        $bookingIds = array_values(array_unique(array_filter(array_map(
            static fn(array $room): int => (int) ($room['booking_id'] ?? 0),
            $accountRooms
        ))));
        api_ok([
            'bill' => $bill,
            'room_items' => $roomItems,
            'service_items' => $serviceItems,
            'all_bills' => $bills->allForGuest($guestId),
            'account_rooms' => $accountRooms,
            'extension_history' => (new BookingModel($conn))->extensionHistory($bookingIds),
            'transfer_history' => (new RoomTransferModel($conn))->forBookings($bookingIds),
            'account_total' => round(array_sum(array_map(
                static fn(array $room): float => (float) ($room['total_amount'] ?? 0),
                $accountRooms
            )), 2),
            'account_paid' => round(array_sum(array_map(
                static fn(array $room): float => (float) ($room['paid_amount'] ?? 0),
                $accountRooms
            )), 2),
            'account_balance' => round(array_sum(array_map(
                static fn(array $room): float => (float) ($room['balance'] ?? 0),
                $accountRooms
            )), 2),
            'account_payments' => (new PaymentModel($conn))->forBills(array_column($accountRooms, 'bill_id')),
            'payment_requests' => $bill
                ? (new PaymentRequestModel($conn))->forGuest($guestId, (int) $bill['id'])
                : [],
            'hotel' => hotel_info(),
        ]);
    }

    public function getGuestInvoices(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        $bills = new BillModel($conn);
        $id = (int) ($data['id'] ?? 0);
        $bill = $id ? $bills->findForGuest($id, $guestId) : null;
        $accountRooms = $bill
            ? $bills->accountRecords((int) ($bill['booking_id'] ?? 0), (int) $bill['id'])
            : [];
        $accountBillIds = array_column($accountRooms, 'bill_id');
        $guest = (new GuestModel($conn))->find($guestId);
        api_ok([
            'list' => $bills->allForGuest($guestId),
            'bill' => $bill,
            'guest_name' => (string) ($guest['full_name'] ?? ''),
            'items' => $bill ? $bills->items((int) $bill['id']) : [],
            'payments' => $bill ? (new PaymentModel($conn))->forBills($accountBillIds) : [],
            'hotel' => hotel_info(),
        ]);
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new BillsApi(), $operation, $data);
