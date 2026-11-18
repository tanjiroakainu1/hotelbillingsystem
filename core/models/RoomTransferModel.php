<?php
declare(strict_types=1);

final class RoomTransferModel
{
    public function __construct(private mysqli $conn) {}

    public static function isScheduledTransferTime(string $time): bool
    {
        return $time === HOTEL_CHECK_IN_TIME;
    }

    public static function isLateTransfer(string $date, string $time, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();
        $scheduled = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$date} {$time}");
        return $scheduled !== false
            && $date === $now->format('Y-m-d')
            && $now > $scheduled;
    }

    public static function calculateRateAdjustment(
        float $oldRate,
        float $newRate,
        string $transferDate,
        string $transferTime,
        string $checkOut
    ): array {
        $transferAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$transferDate} {$transferTime}");
        $transferErrors = DateTimeImmutable::getLastErrors();
        $checkoutDay = DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
        $checkoutErrors = DateTimeImmutable::getLastErrors();

        if (!$transferAt
            || ($transferErrors !== false && ($transferErrors['warning_count'] || $transferErrors['error_count']))
            || $transferAt->format('Y-m-d H:i:s') !== "{$transferDate} {$transferTime}"
            || !$checkoutDay
            || ($checkoutErrors !== false && ($checkoutErrors['warning_count'] || $checkoutErrors['error_count']))
            || $checkoutDay->format('Y-m-d') !== $checkOut) {
            throw new InvalidArgumentException('Choose a valid transfer date and time.');
        }

        $transferDay = $transferAt->setTime(0, 0, 0);
        if ($checkoutDay <= $transferDay) {
            throw new InvalidArgumentException('Transfer must start before the checkout date.');
        }

        $remainingNights = (int) $transferDay->diff($checkoutDay)->format('%a');
        $nextMidnight = $transferDay->modify('+1 day');
        $partialNight = ($nextMidnight->getTimestamp() - $transferAt->getTimestamp()) / 86400;
        $billableNights = max(0, $remainingNights - 1) + $partialNight;
        $rateDifference = round($newRate - $oldRate, 2);

        return [
            'remaining_nights' => $remainingNights,
            'billable_nights' => $billableNights,
            'rate_difference' => $rateDifference,
            'total_charge' => round($rateDifference * $billableNights, 2),
        ];
    }

    public function forBooking(int $bookingId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT t.*, fr.room_number AS from_room, tr.room_number AS to_room,
                    u.full_name AS transferred_by_name
             FROM room_transfers t
             JOIN rooms fr ON fr.id = t.from_room_id
             JOIN rooms tr ON tr.id = t.to_room_id
             LEFT JOIN users u ON u.id = t.transferred_by
             WHERE t.booking_id = ?
             ORDER BY t.id DESC"
        );
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function forBookings(array $bookingIds): array
    {
        $bookingIds = array_values(array_unique(array_filter(
            array_map('intval', $bookingIds),
            static fn(int $bookingId): bool => $bookingId > 0
        )));
        if (!$bookingIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT t.*, fr.room_number AS from_room, tr.room_number AS to_room,
                    fr.room_type AS from_type, tr.room_type AS to_type,
                    g.full_name AS guest_name, b.check_in, b.check_out, b.check_in_time, b.check_out_time,
                    b.status AS booking_status, u.full_name AS transferred_by_name
             FROM room_transfers t
             JOIN rooms fr ON fr.id = t.from_room_id
             JOIN rooms tr ON tr.id = t.to_room_id
             JOIN bookings b ON b.id = t.booking_id
             JOIN guests g ON g.id = b.guest_id
             LEFT JOIN users u ON u.id = t.transferred_by
             WHERE t.booking_id IN ({$placeholders})
             ORDER BY t.id DESC"
        );
        $params = [str_repeat('i', count($bookingIds))];
        foreach ($bookingIds as $index => &$bookingId) {
            $params[] = &$bookingId;
        }
        unset($bookingId);
        call_user_func_array([$stmt, 'bind_param'], $params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function recent(int $limit = 40): array
    {
        return $this->conn->query(
            "SELECT t.*, fr.room_number AS from_room, tr.room_number AS to_room,
                    fr.room_type AS from_type, tr.room_type AS to_type,
                    g.full_name AS guest_name, b.check_in, b.check_out, b.check_in_time, b.check_out_time, b.status AS booking_status,
                    u.full_name AS transferred_by_name
             FROM room_transfers t
             JOIN rooms fr ON fr.id = t.from_room_id
             JOIN rooms tr ON tr.id = t.to_room_id
             JOIN bookings b ON b.id = t.booking_id
             JOIN guests g ON g.id = b.guest_id
             LEFT JOIN users u ON u.id = t.transferred_by
             ORDER BY t.id DESC LIMIT {$limit}"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function forGuest(int $guestId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT t.*, fr.room_number AS from_room, tr.room_number AS to_room,
                    fr.room_type AS from_type, tr.room_type AS to_type,
                    b.check_in, b.check_out, b.check_in_time, b.check_out_time, b.status AS booking_status,
                    u.full_name AS transferred_by_name
             FROM room_transfers t
             JOIN rooms fr ON fr.id = t.from_room_id
             JOIN rooms tr ON tr.id = t.to_room_id
             JOIN bookings b ON b.id = t.booking_id
             LEFT JOIN users u ON u.id = t.transferred_by
             WHERE b.guest_id = ?
             ORDER BY t.id DESC"
        );
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function checkedInBookings(): array
    {
        return $this->conn->query(
            "SELECT b.id, b.guest_id, b.stay_group, b.room_id, b.check_in, b.check_out, b.check_in_time, b.check_out_time, g.full_name,
                    r.room_number, r.room_type, r.rate_per_night
             FROM bookings b
             JOIN guests g ON g.id = b.guest_id
             JOIN rooms r ON r.id = b.room_id
             WHERE b.status = 'checked_in'
             ORDER BY b.id DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Transfer guest to a new room, update booking/rooms, and post transfer charge to bill.
     */
    public function transfer(array $data, int $actorId): bool
    {
        $bookingId = (int) ($data['booking_id'] ?? 0);
        $toRoomId = (int) ($data['to_room_id'] ?? 0);
        $transferDate = (string) ($data['transfer_date'] ?? date('Y-m-d'));
        $transferTimeInput = $data['transfer_time'] ?? '';
        if (!is_string($transferTimeInput)
            || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', trim($transferTimeInput))) {
            flash('error', 'Choose a valid transfer time.');
            return false;
        }
        $transferTime = normalize_time($transferTimeInput, date('H:i:s'));
        if (!self::isScheduledTransferTime($transferTime)) {
            flash('error', 'Room transfers are scheduled for exactly 3:00 PM.');
            return false;
        }
        $reason = trim((string) ($data['reason'] ?? ''));

        $bookingStmt = $this->conn->prepare('SELECT * FROM bookings WHERE id = ?');
        $bookingStmt->bind_param('i', $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        if (!$booking || $booking['status'] !== 'checked_in') {
            flash('error', 'Only checked-in bookings can be transferred.');
            return false;
        }

        $checkOut = (string) $booking['check_out'];
        if ($transferDate < date('Y-m-d') || $transferDate < (string) $booking['check_in'] || $transferDate >= $checkOut) {
            flash('error', 'Choose a transfer date from today through the day before checkout.');
            return false;
        }
        if ($transferDate === (string) $booking['check_in'] && $transferTime < (string) $booking['check_in_time']) {
            flash('error', 'Transfers on arrival day cannot begin before the booking check-in time.');
            return false;
        }
        $transferAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$transferDate} {$transferTime}");
        $checkInAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $booking['check_in'] . ' ' . (string) $booking['check_in_time']);
        $checkOutAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $checkOut . ' ' . HOTEL_CHECK_OUT_TIME);
        if (!$transferAt || !$checkInAt || !$checkOutAt || $transferAt < $checkInAt || $transferAt >= $checkOutAt) {
            flash('error', 'Transfer time must be within the guest’s scheduled stay.');
            return false;
        }

        $fromRoomId = (int) $booking['room_id'];
        if ($toRoomId === $fromRoomId) {
            flash('error', 'Select a different room.');
            return false;
        }

        $rooms = new RoomModel($this->conn);
        $fromRoom = $rooms->find($fromRoomId);
        $toRoom = $rooms->find($toRoomId);
        if (!$fromRoom || !$toRoom) {
            flash('error', 'Room not found.');
            return false;
        }
        if ($toRoom['status'] === 'maintenance') {
            flash('error', 'Target room is under maintenance.');
            return false;
        }

        $oldRate = (float) $fromRoom['rate_per_night'];
        $newRate = (float) $toRoom['rate_per_night'];
        try {
            $adjustment = self::calculateRateAdjustment(
                $oldRate,
                $newRate,
                $transferDate,
                $transferTime,
                $checkOut
            );
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
            return false;
        }
        $remaining = $adjustment['remaining_nights'];
        $billableNights = $adjustment['billable_nights'];
        $diff = $adjustment['rate_difference'];
        $totalCharge = $adjustment['total_charge'];

        $this->conn->begin_transaction();
        try {
            $lockBooking = $this->conn->prepare('SELECT status, room_id FROM bookings WHERE id = ? FOR UPDATE');
            $lockBooking->bind_param('i', $bookingId);
            $lockBooking->execute();
            $lockedBooking = $lockBooking->get_result()->fetch_assoc();
            if (!$lockedBooking || $lockedBooking['status'] !== 'checked_in' || (int) $lockedBooking['room_id'] !== $fromRoomId) {
                $this->conn->rollback();
                flash('error', 'The booking changed before the transfer could be completed. Refresh and try again.');
                return false;
            }
            $lockTarget = $this->conn->prepare('SELECT status FROM rooms WHERE id=? FOR UPDATE');
            $lockTarget->bind_param('i', $toRoomId);
            $lockTarget->execute();
            $lockedRoom = $lockTarget->get_result()->fetch_assoc();
            if (!$lockedRoom || $lockedRoom['status'] === 'maintenance') {
                $this->conn->rollback();
                flash('error', 'Target room is unavailable.');
                return false;
            }
            $conflict = $this->conn->prepare(
                "SELECT id FROM bookings
                 WHERE room_id=? AND id<>? AND status IN ('reserved','checked_in')
                   AND check_in < ? AND check_out > ?
                 UNION ALL
                 SELECT appointment.id FROM room_appointment_rooms appointment_room
                 JOIN room_appointments appointment ON appointment.id = appointment_room.appointment_id
                 WHERE appointment_room.room_id=? AND appointment.status='confirmed'
                   AND appointment.check_in < ? AND appointment.check_out > ?
                 LIMIT 1"
            );
            $conflict->bind_param('iississ', $toRoomId, $bookingId, $checkOut, $transferDate, $toRoomId, $checkOut, $transferDate);
            $conflict->execute();
            if ($conflict->get_result()->fetch_assoc()) {
                $this->conn->rollback();
                flash('error', 'Target room has another reservation during the remaining stay.');
                return false;
            }
            if (self::isLateTransfer($transferDate, $transferTime)) {
                $bill = (new BillModel($this->conn))->activeByBooking($bookingId);
                if (!$bill) {
                    throw new RuntimeException('The booking has no guest bill for the late-transfer charge.');
                }
                (new BillModel($this->conn))->postExtraNightCharge(
                    (int) $bill['id'],
                    $bookingId,
                    (string) $fromRoom['room_number'],
                    $oldRate,
                    'Late transfer',
                    $transferDate,
                    $actorId
                );
            }
            $ins = $this->conn->prepare(
                'INSERT INTO room_transfers
                 (booking_id, from_room_id, to_room_id, transfer_date, transfer_time, old_nightly_rate, new_nightly_rate,
                  remaining_nights, rate_difference_per_night, total_additional_charge, reason, transferred_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->bind_param(
                'iiissddiddsi',
                $bookingId,
                $fromRoomId,
                $toRoomId,
                $transferDate,
                $transferTime,
                $oldRate,
                $newRate,
                $remaining,
                $diff,
                $totalCharge,
                $reason,
                $actorId
            );
            $ins->execute();
            $transferId = (int) $this->conn->insert_id;

            $upd = $this->conn->prepare('UPDATE bookings SET room_id=? WHERE id=?');
            $upd->bind_param('ii', $toRoomId, $bookingId);
            $upd->execute();

            $rooms->syncOperationalStatus($fromRoomId);
            $rooms->syncOperationalStatus($toRoomId);

            if ($totalCharge != 0.0) {
                $billRow = $this->conn->query(
                    "SELECT id FROM bills WHERE booking_id={$bookingId} ORDER BY id DESC LIMIT 1"
                )->fetch_assoc();
                if ($billRow) {
                    $billId = (int) $billRow['id'];
                    $desc = sprintf(
                        'Room Rate Adjustment (Room %s to %s from %s at %s) - %.6f Night-Equivalent(s)',
                        $fromRoom['room_number'],
                        $toRoom['room_number'],
                        $transferDate,
                        date('g:i:s A', strtotime($transferTime)),
                        $billableNights
                    );
                    $qty = $billableNights;
                    $type = 'transfer_charge';
                    $item = $this->conn->prepare(
                        'INSERT INTO bill_items (bill_id, item_type, reference_id, description, quantity, unit_price, amount)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $item->bind_param('isisidd', $billId, $type, $transferId, $desc, $qty, $diff, $totalCharge);
                    $item->execute();
                    recalculate_bill($this->conn, $billId);
                }
            }

            audit(
                $this->conn,
                $actorId,
                'room_transfer',
                "Booking #{$bookingId}: {$fromRoom['room_number']} → {$toRoom['room_number']}"
            );
            $this->conn->commit();
            flash('success', 'Room transferred successfully.');
            return true;
        } catch (Throwable $e) {
            $this->conn->rollback();
            flash('error', 'Room transfer failed.');
            return false;
        }
    }
}
