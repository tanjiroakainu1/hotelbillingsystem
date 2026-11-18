<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once APP_ROOT . '/core/PdfDocument.php';

$user = api_require_role('admin', 'frontdesk', 'guest');
$rawPaymentIds = trim((string) ($_GET['payment_ids'] ?? $_GET['payment_id'] ?? ''));
$paymentIdParts = $rawPaymentIds === '' ? [] : explode(',', $rawPaymentIds);
if (!$paymentIdParts || count($paymentIdParts) > 20) {
    api_error('Select between 1 and 20 payment receipts to export.');
}
foreach ($paymentIdParts as $part) {
    if (!preg_match('/^[1-9]\d*$/D', $part)) {
        api_error('A valid payment ID is required.');
    }
}
$paymentIds = array_values(array_unique(array_map('intval', $paymentIdParts)));

$guestId = null;
if ($user['role'] === 'guest') {
    $guestId = (new GuestModel(api_conn()))->profileId((int) $user['id']);
    if (!$guestId) {
        api_error('Guest profile missing.');
    }
}

$payments = (new PaymentModel(api_conn()))->receipts($paymentIds, $guestId);
$paymentsById = [];
foreach ($payments as $payment) {
    $paymentsById[(int) $payment['id']] = $payment;
}
if (count($paymentsById) !== count($paymentIds)) {
    api_error('Payment receipt not found.', 404);
}

$hotel = hotel_info();
$navy = [0.10, 0.17, 0.27];
$teal = [0.08, 0.58, 0.56];
$muted = [0.40, 0.47, 0.55];
$light = [0.95, 0.97, 0.98];
$streams = [];
foreach ($paymentIds as $paymentId) {
    $payment = $paymentsById[$paymentId];
    if ((float) $payment['amount'] < 0.01) {
        api_error('Payment receipt not found.', 404);
    }
    $stream = PdfDocument::rectangle(0, 632, 612, 160, $navy);
    $stream .= PdfDocument::rectangle(0, 632, 612, 5, $teal);
    $stream .= PdfDocument::text($hotel['hotel_name'], 44, 754, 20, true, [1, 1, 1]);
    $stream .= PdfDocument::text('PAYMENT RECEIPT', 44, 724, 12, true, [0.69, 0.91, 0.91]);
    $stream .= PdfDocument::text($hotel['address'] . '  ·  ' . $hotel['phone'], 44, 700, 9, false, [1, 1, 1]);
    $stream .= PdfDocument::rectangle(40, 539, 532, 72, $light);
    $stream .= PdfDocument::rectangle(40, 539, 4, 72, $teal);
    $stream .= PdfDocument::text('AMOUNT RECEIVED', 58, 585, 8, true, $muted);
    $stream .= PdfDocument::text('PHP ' . number_format((float) $payment['amount'], 2), 58, 557, 17, true, $navy);
    $receiptNumber = 'PR-' . str_pad((string) $payment['id'], 8, '0', STR_PAD_LEFT);
    $stream .= PdfDocument::text('RECEIPT NUMBER', 410, 585, 7, true, $muted);
    $stream .= PdfDocument::text($receiptNumber, 410, 562, 10, true, $navy);
    $stream .= PdfDocument::text('PAYMENT DETAILS', 44, 505, 10, true, $navy);
    $detailRows = [
        ['Guest', (string) $payment['full_name']],
        ['Invoice', (string) ($payment['invoice_number'] ?: 'Guest account')],
        ['Room', (string) ($payment['room_number'] ?: 'Guest account')],
        ['Date and time', date('F j, Y g:i A', strtotime($payment['paid_at']))],
        ['Payment method', payment_method_label((string) $payment['method'])],
        ['Reference', (string) ($payment['reference_no'] ?: '—')],
        ['Received by', (string) ($payment['cashier'] ?: 'Staff unavailable')],
    ];
    $y = 476;
    foreach ($detailRows as $index => [$label, $value]) {
        if ($index % 2 === 0) {
            $stream .= PdfDocument::rectangle(40, $y - 9, 532, 30, $light);
        }
        $stream .= PdfDocument::text($label, 52, $y, 8, true, $muted);
        $stream .= PdfDocument::text(mb_substr($value, 0, 75), 190, $y, 9, false, $navy);
        $y -= 30;
    }
    if (!empty($payment['notes'])) {
        $stream .= PdfDocument::text('Note', 52, $y, 8, true, $muted);
        $stream .= PdfDocument::text(mb_substr((string) $payment['notes'], 0, 75), 190, $y, 9, false, $navy);
    }
    $stream .= PdfDocument::line(40, 60, 572, 60);
    $stream .= PdfDocument::text('Thank you for your payment.', 44, 42, 8, false, $muted);
    $streams[] = $stream;
}
$pdf = PdfDocument::fromStreams($streams);
$firstReceipt = 'PR-' . str_pad((string) $paymentIds[0], 8, '0', STR_PAD_LEFT);
$filename = (count($paymentIds) === 1 ? 'payment_receipt_' : 'payment_receipts_') . $firstReceipt . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
