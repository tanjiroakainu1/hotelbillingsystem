<?php
declare(strict_types=1);

/**
 * Admin and Front Desk report file export (Excel / PDF).
 * Usage: reports_export.php?format=excel|pdf&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/auth.php';
require_once APP_ROOT . '/core/PdfDocument.php';

spl_autoload_register(static function (string $class): void {
    $path = APP_ROOT . '/core/models/' . $class . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$user = current_user();
if (!$user || !in_array(($user['role'] ?? ''), ['admin', 'frontdesk'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Admin or Front Desk access required. Please sign in with an authorized account.';
    exit;
}

$format = strtolower((string) ($_GET['format'] ?? 'excel'));
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
$toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
if (!$fromDate || $fromDate->format('Y-m-d') !== $from
    || !$toDate || $toDate->format('Y-m-d') !== $to
    || $fromDate > $toDate) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid date range.';
    exit;
}

/** @var mysqli $conn */
$report = (new ReportModel($conn))->buildReport($from, $to);
$stamp = date('Ymd_His');

if (in_array($format, ['excel', 'xls', 'csv'], true)) {
    export_report_excel($report, $stamp);
}

if ($format === 'pdf') {
    export_report_pdf($report, $stamp);
}

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');
echo 'Unsupported format. Use format=excel or format=pdf.';
exit;

function export_report_excel(array $r, string $stamp): void
{
    $filename = "hotel_report_{$stamp}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $esc = static function ($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $money = static function ($v): string {
        return number_format((float) $v, 2, '.', '');
    };

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
        . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Styles>'
        . '<Style ss:ID="header"><Font ss:Bold="1"/></Style>'
        . '<Style ss:ID="title"><Font ss:Bold="1" ss:Size="14"/></Style>'
        . '</Styles>';

    echo '<Worksheet ss:Name="Summary"><Table>';
    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">'
        . $esc($r['hotel_name'] . ' — Billing Report')
        . '</Data></Cell></Row>';
    echo '<Row><Cell><Data ss:Type="String">Period</Data></Cell>'
        . '<Cell><Data ss:Type="String">' . $esc($r['from'] . ' to ' . $r['to']) . '</Data></Cell></Row>';
    echo '<Row><Cell><Data ss:Type="String">Generated</Data></Cell>'
        . '<Cell><Data ss:Type="String">' . $esc($r['generated_at']) . '</Data></Cell></Row>';
    echo '<Row></Row>';
    echo '<Row><Cell ss:StyleID="header"><Data ss:Type="String">Metric</Data></Cell>'
        . '<Cell ss:StyleID="header"><Data ss:Type="String">Value</Data></Cell></Row>';

    $summaryRows = [
        ['Bills created', (string) ($r['summary']['bill_count'] ?? 0)],
        ['Total billed', $money($r['summary']['billed'] ?? 0)],
        ['Total paid', $money($r['summary']['paid'] ?? 0)],
        ['Outstanding', $money($r['summary']['outstanding'] ?? 0)],
        ['Payments collected', $money($r['collected']['collected'] ?? 0)],
        ['Payment count', (string) ($r['collected']['payment_count'] ?? 0)],
    ];
    foreach ($summaryRows as [$label, $value]) {
        echo '<Row><Cell><Data ss:Type="String">' . $esc($label) . '</Data></Cell>'
            . '<Cell><Data ss:Type="String">' . $esc($value) . '</Data></Cell></Row>';
    }
    echo '</Table></Worksheet>';

    echo '<Worksheet ss:Name="Bills by Status"><Table>';
    echo '<Row><Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell>'
        . '<Cell ss:StyleID="header"><Data ss:Type="String">Count</Data></Cell>'
        . '<Cell ss:StyleID="header"><Data ss:Type="String">Total</Data></Cell></Row>';
    foreach ($r['by_status'] as $row) {
        echo '<Row><Cell><Data ss:Type="String">' . $esc($row['status']) . '</Data></Cell>'
            . '<Cell><Data ss:Type="Number">' . (int) $row['cnt'] . '</Data></Cell>'
            . '<Cell><Data ss:Type="Number">' . $money($row['total']) . '</Data></Cell></Row>';
    }
    if (!$r['by_status']) {
        echo '<Row><Cell><Data ss:Type="String">No bills in range</Data></Cell></Row>';
    }
    echo '</Table></Worksheet>';

    echo '<Worksheet ss:Name="Daily Collections"><Table>';
    echo '<Row><Cell ss:StyleID="header"><Data ss:Type="String">Date</Data></Cell>'
        . '<Cell ss:StyleID="header"><Data ss:Type="String">Amount</Data></Cell></Row>';
    foreach ($r['daily'] as $row) {
        echo '<Row><Cell><Data ss:Type="String">' . $esc($row['d']) . '</Data></Cell>'
            . '<Cell><Data ss:Type="Number">' . $money($row['total']) . '</Data></Cell></Row>';
    }
    if (!$r['daily']) {
        echo '<Row><Cell><Data ss:Type="String">No payments in range</Data></Cell></Row>';
    }
    echo '</Table></Worksheet>';

    echo '<Worksheet ss:Name="Service Revenue"><Table>';
    echo '<Row><Cell ss:StyleID="header"><Data ss:Type="String">Service</Data></Cell>'
        . '<Cell ss:StyleID="header"><Data ss:Type="String">Revenue</Data></Cell></Row>';
    foreach ($r['services'] as $row) {
        echo '<Row><Cell><Data ss:Type="String">' . $esc($row['description']) . '</Data></Cell>'
            . '<Cell><Data ss:Type="Number">' . $money($row['total']) . '</Data></Cell></Row>';
    }
    if (!$r['services']) {
        echo '<Row><Cell><Data ss:Type="String">No service revenue in range</Data></Cell></Row>';
    }
    echo '</Table></Worksheet>';

    echo '</Workbook>';
    exit;
}

function export_report_pdf(array $r, string $stamp): void
{
    $filename = "hotel_report_{$stamp}.pdf";
    $navy = [0.10, 0.17, 0.27];
    $teal = [0.08, 0.58, 0.56];
    $muted = [0.40, 0.47, 0.55];
    $light = [0.95, 0.97, 0.98];
    $rule = [0.86, 0.89, 0.92];
    $stream = '';
    $pages = [];
    $pageNumber = 0;
    $cursor = 0.0;

    $startPage = static function (bool $cover = false) use (
        &$pages,
        &$stream,
        &$pageNumber,
        &$cursor,
        $r,
        $navy,
        $teal,
        $muted,
        $light
    ): void {
        if ($pageNumber > 0) {
            $pages[] = $stream;
        }
        $pageNumber++;
        $stream = '';
        if ($cover) {
            $stream .= PdfDocument::rectangle(0, 642, 612, 150, $navy);
            $stream .= PdfDocument::rectangle(0, 642, 612, 5, $teal);
            $stream .= PdfDocument::text($r['hotel_name'], 42, 754, 20, true, [1, 1, 1]);
            $stream .= PdfDocument::text('BILLING & COLLECTIONS REPORT', 42, 726, 12, true, [0.69, 0.91, 0.91]);
            $stream .= PdfDocument::text('Reporting period  ' . $r['from'] . '  to  ' . $r['to'], 42, 701, 9, false, [1, 1, 1]);
            $stream .= PdfDocument::text('Generated  ' . $r['generated_at'], 42, 682, 8, false, [0.82, 0.87, 0.92]);
            $cursor = 618;
            return;
        }
        $stream .= PdfDocument::rectangle(0, 704, 612, 88, $navy);
        $stream .= PdfDocument::rectangle(0, 704, 612, 4, $teal);
        $stream .= PdfDocument::text($r['hotel_name'], 42, 762, 14, true, [1, 1, 1]);
        $stream .= PdfDocument::text('BILLING & COLLECTIONS REPORT · CONTINUED', 42, 740, 9, true, [0.69, 0.91, 0.91]);
        $stream .= PdfDocument::text($r['from'] . '  to  ' . $r['to'], 42, 720, 8, false, [1, 1, 1]);
        $cursor = 678;
    };

    $startPage(true);
    $cards = [
        ['BILLS CREATED', (string) (int) ($r['summary']['bill_count'] ?? 0)],
        ['TOTAL BILLED', 'PHP ' . number_format((float) ($r['summary']['billed'] ?? 0), 2)],
        ['COLLECTED', 'PHP ' . number_format((float) ($r['collected']['collected'] ?? 0), 2)],
        ['OUTSTANDING', 'PHP ' . number_format((float) ($r['summary']['outstanding'] ?? 0), 2)],
    ];
    $cardGap = 10;
    $cardWidth = (532 - 3 * $cardGap) / 4;
    foreach ($cards as $index => [$label, $value]) {
        $x = 40 + $index * ($cardWidth + $cardGap);
        $stream .= PdfDocument::rectangle($x, 538, $cardWidth, 66, $light);
        $stream .= PdfDocument::rectangle($x, 538, 3, 66, $teal);
        $stream .= PdfDocument::text($label, $x + 11, 584, 7, true, $muted);
        $stream .= PdfDocument::text($value, $x + 11, 561, 10, true, $navy);
    }
    $stream .= PdfDocument::text(
        'Paid on bills  PHP ' . number_format((float) ($r['summary']['paid'] ?? 0), 2)
        . '     Payment transactions  ' . (int) ($r['collected']['payment_count'] ?? 0),
        42,
        520,
        8,
        false,
        $muted
    );
    $cursor = 495;

    $drawSection = static function (
        string $title,
        array $headers,
        array $rows,
        array $widths
    ) use (
        &$stream,
        &$pages,
        &$pageNumber,
        &$cursor,
        $startPage,
        $navy,
        $teal,
        $light,
        $rule,
        $muted
    ): void {
        $drawTitle = static function (string $heading) use (&$stream, &$cursor, $navy): void {
            $stream .= PdfDocument::text($heading, 42, $cursor, 11, true, $navy);
            $cursor -= 19;
        };
        $drawHeader = static function () use (&$stream, &$cursor, $headers, $widths, $navy): void {
            $stream .= PdfDocument::rectangle(40, $cursor - 20, 532, 22, $navy);
            $x = 48;
            foreach ($headers as $index => $label) {
                $stream .= PdfDocument::text($label, $x, $cursor - 13, 8, true, [1, 1, 1]);
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
            $rows = [array_merge(['No data for this period'], array_fill(0, count($headers) - 1, ''))];
        }
        foreach ($rows as $rowIndex => $row) {
            if ($cursor - 24 < 52) {
                $startPage();
                $drawTitle($title . ' · CONTINUED');
                $drawHeader();
            }
            if ($rowIndex % 2 === 0) {
                $stream .= PdfDocument::rectangle(40, $cursor - 21, 532, 23, $light);
            }
            $x = 48;
            foreach ($row as $index => $value) {
                $width = $widths[$index];
                $text = (string) $value;
                $plain = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
                $plain = $plain === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $plain;
                $maxCharacters = max(3, (int) floor(($width - 14) / 4.5));
                if (strlen($plain) > $maxCharacters) {
                    $plain = substr($plain, 0, $maxCharacters - 3) . '...';
                }
                $stream .= PdfDocument::text($plain, $x, $cursor - 13, 8, false, $muted);
                $x += $width;
            }
            $stream .= PdfDocument::line(40, $cursor - 21, 572, $cursor - 21, $rule, 0.35);
            $cursor -= 23;
        }
        $cursor -= 17;
    };

    $drawSection(
        'Bills by status',
        ['STATUS', 'BILLS', 'TOTAL AMOUNT'],
        array_map(static fn(array $row): array => [
            ucfirst((string) $row['status']),
            (string) (int) $row['cnt'],
            'PHP ' . number_format((float) $row['total'], 2),
        ], $r['by_status'] ?? []),
        [285, 70, 161]
    );
    $drawSection(
        'Daily collections',
        ['DATE', 'AMOUNT COLLECTED'],
        array_map(static fn(array $row): array => [
            (string) $row['d'],
            'PHP ' . number_format((float) $row['total'], 2),
        ], $r['daily'] ?? []),
        [340, 176]
    );
    $drawSection(
        'Service revenue',
        ['SERVICE', 'REVENUE'],
        array_map(static fn(array $row): array => [
            (string) $row['description'],
            'PHP ' . number_format((float) $row['total'], 2),
        ], $r['services'] ?? []),
        [340, 176]
    );

    $pages[] = $stream;
    $totalPages = count($pages);
    foreach ($pages as $index => &$page) {
        $page .= PdfDocument::line(40, 42, 572, 42, $rule, 0.6);
        $page .= PdfDocument::text('Aurora Grand Hotel  ·  Internal financial summary', 42, 27, 7, false, $muted);
        $page .= PdfDocument::text('Page ' . ($index + 1) . ' of ' . $totalPages, 514, 27, 7, false, $muted);
    }
    unset($page);

    $pdf = PdfDocument::fromStreams($pages);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}
