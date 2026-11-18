<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class DashboardApi
{
    public function adminStats(array $data = []): void
    {
        api_require_role('admin');
        $conn = api_conn();
        $rooms = new RoomModel($conn);
        $bills = new BillModel($conn);
        $payments = new PaymentModel($conn);
        $users = new UserModel($conn);

        api_ok([
            'rooms' => $rooms->count(),
            'occupied' => $rooms->occupiedCount(),
            'users' => $users->count(),
            'revenue' => $bills->revenueTotal(),
            'outstanding' => $bills->outstandingTotal(),
            'pending_bills' => $bills->pendingCount(),
            'today_pay' => $payments->todayTotal(),
            'tax_rate' => (string) hotel_config('tax_rate'),
            'hotel_name' => (string) hotel_config('hotel_name'),
            'recent_bills' => $bills->recent(8),
        ]);
    }

    public function frontdeskStats(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $bookings = new BookingModel($conn);
        $bills = new BillModel($conn);
        $payments = new PaymentModel($conn);

        api_ok([
            'checked_in' => $bookings->checkedInCount(),
            'reserved' => $bookings->reservedCount(),
            'pending_bills' => $bills->pendingCount(),
            'today_pay' => $payments->todayTotal(),
            'stays' => $bookings->activeStays(),
        ]);
    }

    public function guestStats(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guests = new GuestModel($conn);
        $guestId = $guests->ensureProfile(
            (int) $user['id'],
            $user['full_name'],
            $user['email'],
            $user['phone'] ?? ''
        );
        $bills = new BillModel($conn);
        $bookings = new BookingModel($conn);
        $guestRequests = new GuestRequestModel($conn);
        $stays = $bookings->historyForGuest($guestId);
        foreach ($stays as &$stay) {
            $stay['requests'] = $guestRequests->forBooking((int) $stay['id']);
        }
        unset($stay);

        api_ok([
            'guest_id' => $guestId,
            'active_bill' => $bills->activeForGuest($guestId),
            'booking' => $bookings->activeForGuest($guestId),
            'stays' => $stays,
        ]);
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new DashboardApi(), $operation, $data);
