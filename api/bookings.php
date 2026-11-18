<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class BookingsApi
{
    public function getCheckinData(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        api_ok([
            'bookings' => (new BookingModel($conn))->list(),
            'in_house' => (new BookingModel($conn))->inHouse(),
            'rooms' => (new RoomModel($conn))->available(),
            'guests' => (new GuestModel($conn))->listForBooking(),
            'transfers' => (new RoomTransferModel($conn))->recent(20),
            'tax_rate' => (float) hotel_config('tax_rate'),
        ]);
    }

    public function createBooking(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();

        $plan = (string) ($data['billing_type'] ?? '');
        if (!in_array($plan, ['full', 'half', 'partial'], true)) {
            api_error('Select a billing type: full, half or partial payment.');
        }
        $appointmentId = (int) ($data['appointment_id'] ?? 0);
        $checkInDate = (string) ($data['check_in'] ?? '');
        $checkOutDate = (string) ($data['check_out'] ?? '');
        $checkInDay = DateTimeImmutable::createFromFormat('!Y-m-d', $checkInDate);
        $checkOutDay = DateTimeImmutable::createFromFormat('!Y-m-d', $checkOutDate);
        if (!$checkInDay || $checkInDay->format('Y-m-d') !== $checkInDate
            || !$checkOutDay || $checkOutDay->format('Y-m-d') !== $checkOutDate
            || $checkOutDay <= $checkInDay) {
            api_error('Enter valid check-in and check-out dates before creating an invoice.');
        }
        $checkInTimeRaw = (string) ($data['check_in_time'] ?? '');
        $checkOutTimeRaw = (string) ($data['check_out_time'] ?? '');
        if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $checkInTimeRaw)
            || !preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $checkOutTimeRaw)) {
            api_error('Choose valid AM/PM check-in and check-out times.');
        }
        $checkInTime = normalize_time($checkInTimeRaw, HOTEL_CHECK_IN_TIME);
        $checkOutTime = normalize_time($checkOutTimeRaw, HOTEL_CHECK_OUT_TIME);
        if (!is_hotel_check_in_time($checkInTime) || !is_hotel_check_out_time($checkOutTime)) {
            api_error('Check-in must be between 3:00 and 3:59 PM, and check-out is fixed at 2:00 PM.');
        }
        $checkInAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $checkInDate . ' ' . $checkInTime);
        $checkOutAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $checkOutDate . ' ' . $checkOutTime);
        $appointmentCheckInToday = $appointmentId > 0 && $checkInDate === date('Y-m-d');
        if (!$checkInAt || !$checkOutAt
            || (!$appointmentCheckInToday && $checkInAt <= new DateTimeImmutable())
            || $checkOutAt <= $checkInAt) {
            api_error('Check-in must be in the future unless it is today’s confirmed appointment; check-out must be later than check-in.');
        }
        $stayNights = max(1, (int) $checkInDay->diff($checkOutDay)->days);
        $method = strtolower((string) ($data['method'] ?? 'cash'));
        if (!in_array($method, ['cash', 'gcash', 'bank_transfer'], true)) {
            api_error('Choose Cash, GCash or Bank Transfer as the payment method.');
        }
        if ($method !== 'cash' && trim((string) ($data['reference_no'] ?? '')) === '') {
            api_error('Enter the transfer reference number.');
        }
        if ($method === 'gcash' && strlen(preg_replace('/\D+/', '', (string) ($data['reference_no'] ?? ''))) < 8) {
            api_error('Enter the GCash reference number from the payment receipt.');
        }
        if ($method === 'bank_transfer' && strlen(preg_replace('/[^A-Za-z0-9]/', '', (string) ($data['reference_no'] ?? ''))) < 6) {
            api_error('Enter the bank transfer reference number.');
        }
        if ($plan === 'partial' && round((float) ($data['partial_amount'] ?? 0), 2) < 0.01) {
            api_error('Partial payment must be at least 0.01.');
        }

        $rooms = $data['rooms'] ?? null;
        if (is_string($rooms)) {
            $rooms = json_decode($rooms, true);
        }
        if (!is_array($rooms) || !$rooms) {
            $rooms = [[
                'room_id' => $data['room_id'] ?? 0,
                'adults' => $data['adults'] ?? 1,
                'children' => $data['children'] ?? 0,
            ]];
        }
        $appointment = null;
        if ($appointmentId > 0) {
            $appointmentStmt = $conn->prepare(
                "SELECT * FROM room_appointments
                 WHERE id = ? AND status = 'confirmed' AND converted_at IS NULL"
            );
            $appointmentStmt->bind_param('i', $appointmentId);
            $appointmentStmt->execute();
            $appointment = $appointmentStmt->get_result()->fetch_assoc();
            if (!$appointment) {
                api_error('Only a confirmed online appointment that has not already been checked in can be used.');
            }
            if (!in_array($appointment['payment_status'], ['verified', 'not_required'], true)
                || $plan !== 'partial'
                || round((float) ($data['partial_amount'] ?? 0), 2) !== round((float) $appointment['deposit_amount'], 2)
                || $method !== (string) $appointment['payment_method']
                || trim((string) ($data['reference_no'] ?? '')) !== (string) $appointment['payment_reference']) {
                api_error('Use the verified appointment deposit, method and transfer reference as the check-in payment.');
            }
            if ((int) ($appointment['account_user_id'] ?? 0) <= 0
                || trim((string) ($data['check_in'] ?? '')) !== (string) $appointment['check_in']
                || trim((string) ($data['check_out'] ?? '')) !== (string) $appointment['check_out']
                || $checkInTime !== (string) $appointment['check_in_time']
                || $checkOutTime !== (string) $appointment['check_out_time']
                || (int) ($data['adults'] ?? 0) !== (int) $appointment['adults']
                || (int) ($data['children'] ?? 0) !== (int) $appointment['children']) {
                api_error('Appointment guest and stay details must match the approved request.');
            }
            $guestStmt = $conn->prepare('SELECT id FROM guests WHERE user_id = ? LIMIT 1');
            $accountUserId = (int) $appointment['account_user_id'];
            $guestStmt->bind_param('i', $accountUserId);
            $guestStmt->execute();
            $linkedGuest = $guestStmt->get_result()->fetch_assoc();
            if (!$linkedGuest || (int) ($data['guest_id'] ?? 0) !== (int) $linkedGuest['id']) {
                api_error('The selected guest account is not linked to this appointment.');
            }
            $appointmentRoomStmt = $conn->prepare(
                'SELECT room_id FROM room_appointment_rooms WHERE appointment_id = ? ORDER BY room_id'
            );
            $appointmentRoomStmt->bind_param('i', $appointmentId);
            $appointmentRoomStmt->execute();
            $appointmentRoomIds = array_map(
                'intval',
                array_column($appointmentRoomStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'room_id')
            );
            $bookingRoomIds = array_map(static fn(array $slot): int => (int) ($slot['room_id'] ?? 0), $rooms);
            sort($appointmentRoomIds);
            sort($bookingRoomIds);
            if (!$appointmentRoomIds || $bookingRoomIds !== $appointmentRoomIds) {
                api_error('Use exactly the room or rooms reserved by this appointment.');
            }
        }
        $seen = [];
        $limits = $conn->prepare('SELECT room_number, max_adults, max_children, rate_per_night FROM rooms WHERE id = ?');
        $roomRates = [];
        foreach ($rooms as $slot) {
            $rid = (int) ($slot['room_id'] ?? 0);
            if ($rid <= 0) {
                api_error('Select a room for every room slot.');
            }
            if (isset($seen[$rid])) {
                api_error('The same room was selected twice. Pick a different room for each slot.');
            }
            $seen[$rid] = true;
            $limits->bind_param('i', $rid);
            $limits->execute();
            $room = $limits->get_result()->fetch_assoc();
            if (!$room) {
                api_error('Room not found.');
            }
            $ad = max(1, (int) ($slot['adults'] ?? 1));
            $ch = max(0, (int) ($slot['children'] ?? 0));
            if ($ad > (int) $room['max_adults'] || $ch > (int) $room['max_children']) {
                api_error("Room {$room['room_number']} allows up to {$room['max_adults']} adult(s) and {$room['max_children']} child(ren). Exceeds available capacity - add another room.");
            }
            $roomRates[$rid] = (float) $room['rate_per_night'];
        }

        $discount = null;
        $discountId = (int) ($data['discount_id'] ?? 0);
        if ($discountId > 0) {
            $discount = (new DiscountModel($conn))->findActive($discountId);
            if (!$discount) {
                api_error('The selected discount is not available.');
            }
            if (!in_array($data['discount_eligibility_confirmed'] ?? null, ['1', 1, true], true)) {
                api_error('Confirm that the guest meets the selected discount requirements before applying it.');
            }
        }

        $projectedTotals = [];
        foreach ($rooms as $index => $slot) {
            $subtotal = round($roomRates[(int) $slot['room_id']] * $stayNights, 2);
            $discountAmount = 0.0;
            if ($discount) {
                $discountAmount = $discount['discount_type'] === 'percent'
                    ? round($subtotal * ((float) $discount['value'] / 100), 2)
                    : ($index === 0 ? min((float) $discount['value'], $subtotal) : 0.0);
            }
            $taxable = max(0.0, $subtotal - $discountAmount);
            $tax = round($taxable * ((float) hotel_config('tax_rate') / 100), 2);
            $projectedTotal = round($taxable + $tax, 2);
            if ($projectedTotal < 0.01) {
                api_error('Each room must have a charge of at least 0.01 after discounts; zero-amount invoices are not allowed.');
            }
            $projectedTotals[] = $projectedTotal;
        }
        $projectedGrand = array_sum($projectedTotals);
        $projectedPayment = match ($plan) {
            'full' => $projectedGrand,
            'half' => round($projectedGrand / 2, 2),
            default => min(round((float) ($data['partial_amount'] ?? 0), 2), $projectedGrand),
        };
        if ($projectedPayment < 0.01) {
            api_error('Check-in payment must be at least 0.01. Zero-amount payments and receipts are not allowed.');
        }

        $bookings = new BookingModel($conn);
        $bills = new BillModel($conn);
        $created = [];
        foreach ($rooms as $slot) {
            $row = $data;
            $row['room_id'] = (int) $slot['room_id'];
            $row['adults'] = (int) ($slot['adults'] ?? 1);
            $row['children'] = (int) ($slot['children'] ?? 0);
            if (!$bookings->create($row, (int) $user['id'], new RoomModel($conn), $bills)) {
                api_from_flash('Booking failed.');
            }
            if ($discount && ($discount['discount_type'] === 'percent' || !$created)) {
                $bills->applyDiscount((int) $bookings->lastBillId, $discount, (int) $user['id']);
            }
            $created[] = [
                'booking_id' => $bookings->lastBookingId,
                'bill_id' => (int) $bookings->lastBillId,
                'total' => (float) ($bills->find((int) $bookings->lastBillId)['total_amount'] ?? 0),
            ];
        }

        if (count($created) > 1) {
            $groupId = (int) $created[0]['booking_id'];
            $ids = implode(',', array_map(static fn ($row) => (int) $row['booking_id'], $created));
            $conn->query("UPDATE bookings SET stay_group = {$groupId} WHERE id IN ({$ids})");
        }

        $grand = array_sum(array_column($created, 'total'));
        $target = match ($plan) {
            'full' => $grand,
            'half' => round($grand / 2, 2),
            default => min(round((float) $data['partial_amount'], 2), $grand),
        };
        $paid = true;
        $amount = 0.0;
        $payments = new PaymentModel($conn);
        $paymentIds = [];
        foreach ($created as $i => $row) {
            $share = $grand > 0 ? round($target * $row['total'] / $grand, 2) : 0;
            if ($i === count($created) - 1) {
                $share = round($target - $amount, 2);
            }
            if ($share <= 0) {
                continue;
            }
            $ok = $payments->record([
                'bill_id' => $row['bill_id'],
                'amount' => min($share, $row['total']),
                'method' => $method,
                'reference_no' => $data['reference_no'] ?? '',
                'notes' => ucfirst($plan) . ' payment at check-in',
            ], $bills, (int) $user['id']);
            $paid = $paid && $ok;
            $paymentIds = array_merge($paymentIds, $payments->lastPaymentIds);
            $amount += $share;
        }
        if (!$paid) {
            api_from_flash('Stay saved, but the payment could not be recorded. Record it from Billing.');
        }
        if ($appointmentId > 0) {
            $converted = $conn->prepare(
                'UPDATE room_appointments SET converted_at = NOW() WHERE id = ? AND converted_at IS NULL'
            );
            $converted->bind_param('i', $appointmentId);
            $converted->execute();
            if ($converted->affected_rows !== 1) {
                api_error('The appointment was already checked in. Review the guest bookings before retrying.');
            }
            audit($conn, (int) $user['id'], 'appointment_checked_in', "Appointment #{$appointmentId}");
        }
        if ($discount) {
            audit(
                $conn,
                (int) $user['id'],
                'discount_eligibility_confirmed',
                "Discount #{$discountId} eligibility verified for guest #" . (int) ($data['guest_id'] ?? 0)
            );
        }
        $count = count($created);
        flash('success', ($bookings->lastStatus === 'checked_in' ? 'Guest checked in' : 'Stay booked')
            . ($count > 1 ? " · {$count} rooms" : '')
            . ' · ' . ucfirst($plan) . ' payment of ' . money($target) . ' recorded.');
        api_from_flash('Booking created.', static fn () => [
            'booking_id' => $created[0]['booking_id'],
            'bill_id' => $created[0]['bill_id'],
            'bookings' => $created,
            'payment_ids' => $paymentIds,
            'status' => $bookings->lastStatus,
        ]);
    }
    public function updateStayDates(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        (new BookingModel(api_conn()))->updateStayDates($data, (int) $user['id']);
        api_from_flash('Stay dates updated.');
    }

    public function getExtendData(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $bookings = new BookingModel(api_conn());
        $stays = $bookings->extendable();
        $bookingIds = array_column($stays, 'id');
        api_ok([
            'stays' => $stays,
            'extension_history' => $bookings->extensionHistory($bookingIds),
        ]);
    }

    public function extendStay(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $id = (int) ($data['id'] ?? 0);
        (new BookingModel($conn))->extendStay($data, new RoomModel($conn), new BillModel($conn), (int) $user['id']);
        api_from_flash('Stay extended.', static function () use ($conn, $id) {
            $bill = (new BillModel($conn))->byBooking($id);
            return ['booking_id' => $id, 'bill_id' => $bill['id'] ?? null];
        });
    }

    public function checkIn(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $id = (int) ($data['id'] ?? 0);
        $billId = (new BookingModel($conn))->checkIn($id, new RoomModel($conn), new BillModel($conn), (int) $user['id']);
        api_from_flash('Checked in.', static fn () => ['booking_id' => $id, 'bill_id' => $billId]);
    }

    public function checkOut(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        (new BookingModel($conn))->checkOut(
            (int) ($data['id'] ?? 0),
            new RoomModel($conn),
            (int) $user['id']
        );
        api_from_flash('Checked out.');
    }

    public function getGuestBookings(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        $bookings = new BookingModel($conn);
        api_ok([
            'active' => $bookings->activeForGuest($guestId),
            'history' => $bookings->historyForGuest($guestId),
        ]);
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new BookingsApi(), $operation, $data);
