<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class PaymentsApi
{
    public function getPaymentsPage(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $billId = (int) ($data['bill_id'] ?? 0);
        $bills = new BillModel($conn);
        $payments = new PaymentModel($conn);

        api_ok([
            'bill_id' => $billId,
            'bill' => $billId ? $bills->paymentSummary($billId) : null,
            'bills' => $bills->listFiltered(),
            'accounts' => $bills->accountHistory(),
            'history' => $payments->history(40),
        ]);
    }

    public function getBillItems(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $billId = (int) ($data['bill_id'] ?? 0);
        $bills = new BillModel($conn);
        if (!$bills->find($billId)) {
            api_error('Bill not found.');
        }
        api_ok(['items' => $bills->items($billId), 'bill' => $bills->paymentSummary($billId)]);
    }

    public function getAccountPaymentDetails(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $bookingId = (int) ($data['booking_id'] ?? 0);
        $billId = (int) ($data['bill_id'] ?? 0);
        if ($bookingId <= 0 && $billId <= 0) {
            api_error('Select a guest account before viewing its charges.');
        }

        $bills = new BillModel(api_conn());
        $records = $bills->accountRecords($bookingId, $billId);
        foreach ($records as &$record) {
            $record['items'] = !empty($record['bill_id'])
                ? $bills->items((int) $record['bill_id'])
                : [];
        }
        unset($record);

        api_ok(['bills' => $records]);
    }

    public function recordPayment(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $payments = new PaymentModel($conn);
        $ok = $payments->record($data, new BillModel($conn), (int) $user['id']);
        if (!$ok) {
            api_from_flash('Payment failed.');
        }
        api_from_flash('Payment recorded.', static fn () => [
            'bill_id' => (int) ($data['bill_id'] ?? 0),
            'payment_id' => $payments->lastPaymentIds[0] ?? null,
        ]);
    }

    public function recordAccountPayment(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $payments = new PaymentModel($conn);
        $ok = $payments->recordAccountPayment($data, new BillModel($conn), (int) $user['id']);
        if (!$ok) {
            api_from_flash('Payment failed.');
        }
        api_from_flash('Payment recorded and distributed.', static fn () => [
            'booking_id' => (int) ($data['booking_id'] ?? 0),
            'bill_id' => (int) ($data['bill_id'] ?? 0),
            'payment_ids' => $payments->lastPaymentIds,
        ]);
    }

    public function getGuestPayments(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        api_ok((new PaymentModel($conn))->forGuest($guestId));
    }

    public function completePaymentRequest(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        $payments = new PaymentModel($conn);
        $requestId = (int) ($data['request_id'] ?? 0);
        if (!(new PaymentRequestModel($conn))->complete(
            $requestId,
            (int) $user['id'],
            $payments,
            new BillModel($conn)
        )) {
            api_from_flash('Could not complete payment request.');
        }
        $billStmt = $conn->prepare('SELECT bill_id FROM payment_requests WHERE id = ?');
        $billStmt->bind_param('i', $requestId);
        $billStmt->execute();
        $billId = (int) ($billStmt->get_result()->fetch_assoc()['bill_id'] ?? 0);
        api_from_flash('Payment request completed.', static fn () => [
            'request_id' => $requestId,
            'bill_id' => $billId,
            'payment_ids' => $payments->lastPaymentIds,
        ]);
    }

    public function cancelPaymentRequest(array $data): void
    {
        $user = api_require_role('frontdesk', 'admin');
        $conn = api_conn();
        if (!(new PaymentRequestModel($conn))->cancel(
            (int) ($data['request_id'] ?? 0),
            (int) $user['id']
        )) {
            api_from_flash('Could not delete payment request.');
        }
        api_from_flash('Payment request deleted.');
    }

    public function createGuestPaymentRequest(array $data): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        $requestId = (new PaymentRequestModel($conn))->createForGuest(
            $data,
            $guestId,
            (int) $user['id'],
            new BillModel($conn)
        );
        if (!$requestId) {
            api_from_flash('Payment request failed.');
        }
        api_from_flash('Payment request submitted.', static fn () => ['request_id' => $requestId]);
    }

    public function cancelGuestPaymentRequest(array $data): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        if (!(new PaymentRequestModel($conn))->cancel(
            (int) ($data['request_id'] ?? 0),
            (int) $user['id'],
            (new GuestModel($conn))->profileId((int) $user['id'])
        )) {
            api_from_flash('Could not delete payment request.');
        }
        api_from_flash('Payment request deleted.');
    }

    public function getGuestPaymentRequests(array $data = []): void
    {
        $user = api_require_role('guest');
        $conn = api_conn();
        $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
        if (!$guestId) {
            api_error('Guest profile missing.');
        }
        api_ok((new PaymentRequestModel($conn))->forGuest(
            $guestId,
            (int) ($data['bill_id'] ?? 0)
        ));
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new PaymentsApi(), $operation, $data);
