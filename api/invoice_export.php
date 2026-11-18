<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once APP_ROOT . '/core/PdfDocument.php';

$user = api_require_role('admin', 'frontdesk', 'guest');
$billId = filter_input(INPUT_GET, 'bill_id', FILTER_VALIDATE_INT);
if (!$billId || $billId < 1) {
    api_error('A valid invoice ID is required.');
}

$conn = api_conn();
$bill = (new BillModel($conn))->findWithGuest($billId);
if (!$bill) {
    api_error('Invoice not found.', 404);
}
if ($user['role'] === 'guest') {
    $guestId = (new GuestModel($conn))->profileId((int) $user['id']);
    if (!$guestId || (int) $bill['guest_id'] !== $guestId) {
        api_error('Invoice not found.', 404);
    }
}
if ((float) $bill['total_amount'] < 0.01) {
    api_error('This invoice cannot be exported until its charges total at least 0.01.');
}

$items = (new BillModel($conn))->items($billId);
$payments = array_values(array_filter(
    (new PaymentModel($conn))->forBill($billId),
    static fn(array $payment): bool => (float) $payment['amount'] >= 0.01
));
$hotel = hotel_info();
$navy = [0.10, 0.17, 0.27];
$teal = [0.08, 0.58, 0.56];
$muted = [0.40, 0.47, 0.55];
$light = [0.95, 0.97, 0.98];
$rule = [0.86, 0.89, 0.92];
$streams = [];
$stream = '';
$cursor = 0.0;

$startPage = static function (bool $cover = false) use (
    &$streams,
    &$stream,
    &$cursor,
    $bill,
    $hotel,
    $navy,
    $teal
): void {
    if ($stream !== '') {
        $streams[] = $stream;
    }
    $stream = '';
    if ($cover) {
        $stream .= PdfDocument::rectangle(0, 632, 612, 160, $navy);
        $stream .= PdfDocument::rectangle(0, 632, 612, 5, $teal);
        $stream .= PdfDocument::text($hotel['hotel_name'], 44, 754, 20, true, [1, 1, 1]);
        $stream .= PdfDocument::text('GUEST INVOICE', 44, 724, 12, true, [0.69, 0.91, 0.91]);
        $stream .= PdfDocument::text('Invoice  ' . $bill['invoice_number'], 44, 700, 9, false, [1, 1, 1]);
        $stream .= PdfDocument::text($hotel['address'] . '  ·  ' . $hotel['phone'], 44, 680, 8, false, [0.82, 0.87, 0.92]);
        $cursor = 614;
        return;
    }
    $stream .= PdfDocument::rectangle(0, 704, 612, 88, $navy);
    $stream .= PdfDocument::rectangle(0, 704, 612, 4, $teal);
    $stream .= PdfDocument::text($hotel['hotel_name'], 44, 762, 14, true, [1, 1, 1]);
    $stream .= PdfDocument::text('GUEST INVOICE · ' . $bill['invoice_number'] . ' · CONTINUED', 44, 740, 9, true, [0.69, 0.91, 0.91]);
    $stream .= PdfDocument::text('Guest  ' . $bill['full_name'], 44, 720, 8, false, [1, 1, 1]);
    $cursor = 678;
};

$startPage(true);
$stream .= PdfDocument::rectangle(40, 531, 532, 68, $light);
$stream .= PdfDocument::text('BILLED TO', 54, 578, 7, true, $muted);
$stream .= PdfDocument::text(mb_substr((string) $bill['full_name'], 0, 48), 54, 558, 10, true, $navy);
$stream .= PdfDocument::text(mb_substr((string) ($bill['email'] ?: $bill['phone'] ?: ''), 0, 52), 54, 542, 8, false, $muted);
$stream .= PdfDocument::text('ROOM / STAY', 350, 578, 7, true, $muted);
$room = !empty($bill['room_number']) ? 'Room ' . $bill['room_number'] : 'Guest account';
$stream .= PdfDocument::text(mb_substr($room, 0, 34), 350, 558, 9, true, $navy);
$stayStart = $bill['stay_check_in'] ?: $bill['created_at'];
$stayEnd = $bill['stay_check_out'] ?: '';
$stream .= PdfDocument::text(mb_substr($stayStart . ($stayEnd ? ' to ' . $stayEnd : ''), 0, 40), 350, 542, 7, false, $muted);

$summaryCards = [
    ['TOTAL CHARGES', 'PHP ' . number_format((float) $bill['total_amount'], 2)],
    ['TOTAL PAID', 'PHP ' . number_format((float) $bill['paid_amount'], 2)],
    ['BALANCE DUE', 'PHP ' . number_format((float) $bill['balance'], 2)],
];
$cardWidth = 170;
foreach ($summaryCards as $index => [$label, $value]) {
    $x = 40 + $index * 181;
    $stream .= PdfDocument::rectangle($x, 449, $cardWidth, 62, $light);
    $stream .= PdfDocument::rectangle($x, 449, 3, 62, $teal);
    $stream .= PdfDocument::text($label, $x + 12, 489, 7, true, $muted);
    $stream .= PdfDocument::text($value, $x + 12, 466, 10, true, $navy);
}
$stream .= PdfDocument::text('PAYMENT STATUS  ' . strtoupper((string) $bill['status']), 44, 432, 8, true, $muted);
$cursor = 411;

$drawTable = static function (string $title, array $headers, array $rows, array $widths) use (
    &$stream,
    &$streams,
    &$cursor,
    $startPage,
    $bill,
    $navy,
    $light,
    $rule,
    $muted
): void {
    $drawTitle = static function (string $heading) use (&$stream, &$cursor, $navy): void {
        $stream .= PdfDocument::text($heading, 42, $cursor, 10, true, $navy);
        $cursor -= 18;
    };
    $drawHeader = static function () use (&$stream, &$cursor, $headers, $widths, $navy): void {
        $stream .= PdfDocument::rectangle(40, $cursor - 20, 532, 22, $navy);
        $x = 48;
        foreach ($headers as $index => $header) {
            $stream .= PdfDocument::text($header, $x, $cursor - 13, 7, true, [1, 1, 1]);
            $x += $widths[$index];
        }
        $cursor -= 25;
    };
    $drawTitle($title);
    if ($cursor - 48 < 52) {
        $startPage();
        $drawTitle($title . ' · CONTINUED');
    }
    $drawHeader();
    if (!$rows) {
        $rows = [array_merge(['No records'], array_fill(0, count($headers) - 1, ''))];
    }
    foreach ($rows as $index => $row) {
        if ($cursor - 24 < 52) {
            $startPage();
            $drawTitle($title . ' · CONTINUED');
            $drawHeader();
        }
        if ($index % 2 === 0) {
            $stream .= PdfDocument::rectangle(40, $cursor - 21, 532, 23, $light);
        }
        $x = 48;
        foreach ($row as $column => $value) {
            $width = $widths[$column];
            $text = (string) $value;
            $plain = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            $plain = $plain === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $plain;
            $maxCharacters = max(3, (int) floor(($width - 14) / 4));
            if (strlen($plain) > $maxCharacters) {
                $plain = substr($plain, 0, $maxCharacters - 3) . '...';
            }
            $stream .= PdfDocument::text($plain, $x, $cursor - 13, 8, false, $muted);
            $x += $width;
        }
        $stream .= PdfDocument::line(40, $cursor - 21, 572, $cursor - 21, $rule, 0.35);
        $cursor -= 23;
    }
    $cursor -= 15;
};

$itemRows = array_map(static fn(array $item): array => [
    (string) $item['description'],
    rtrim(rtrim(number_format((float) $item['quantity'], 6, '.', ''), '0'), '.'),
    'PHP ' . number_format((float) $item['amount'], 2),
], $items);
$drawTable('CHARGES', ['DESCRIPTION', 'QTY', 'AMOUNT'], $itemRows, [338, 60, 118]);

$paymentRows = array_map(static fn(array $payment): array => [
    date('Y-m-d H:i', strtotime($payment['paid_at'])),
    payment_method_label((string) $payment['method']),
    'PHP ' . number_format((float) $payment['amount'], 2),
], $payments);
$drawTable('PAYMENT HISTORY', ['DATE AND TIME', 'METHOD', 'AMOUNT PAID'], $paymentRows, [180, 180, 156]);

$streams[] = $stream;
$totalPages = count($streams);
foreach ($streams as $index => &$page) {
    $page .= PdfDocument::line(40, 42, 572, 42, $rule, 0.6);
    $page .= PdfDocument::text('Aurora Grand Hotel  ·  Guest invoice', 42, 27, 7, false, $muted);
    $page .= PdfDocument::text('Page ' . ($index + 1) . ' of ' . $totalPages, 514, 27, 7, false, $muted);
}
unset($page);

$pdf = PdfDocument::fromStreams($streams);
$filename = 'invoice_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $bill['invoice_number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
