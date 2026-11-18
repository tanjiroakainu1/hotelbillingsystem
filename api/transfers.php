<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class TransfersApi
{
    public function getTransferPage(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        $conn = api_conn();
        $transfers = new RoomTransferModel($conn);
        api_ok([
            'bookings' => $transfers->checkedInBookings(),
            'rooms' => (new RoomModel($conn))->available(),
            'transfers' => $transfers->recent(50),
        ]);
    }

    public function getRecentTransfers(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new RoomTransferModel(api_conn()))->recent(50));
    }

    public function getBookingTransfers(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        api_ok((new RoomTransferModel(api_conn()))->forBooking((int) ($data['booking_id'] ?? 0)));
    }

    public function getGuestTransfers(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        api_ok([
            'transfers' => (new RoomTransferModel($conn))->forGuest($guestId),
            'booking' => (new BookingModel($conn))->activeForGuest($guestId),
        ]);
    }

    public function transferRoom(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        (new RoomTransferModel(api_conn()))->transfer($data, (int) $user['id']);
        api_from_flash('Room transferred.');
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new TransfersApi(), $operation, $data);
