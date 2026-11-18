<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class ReportsApi
{
    public function getReport(array $data = []): void
    {
        api_require_role('admin', 'frontdesk');
        $from = (string) ($data['from'] ?? date('Y-m-01'));
        $to = (string) ($data['to'] ?? date('Y-m-d'));
        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (!$fromDate || $fromDate->format('Y-m-d') !== $from
            || !$toDate || $toDate->format('Y-m-d') !== $to
            || $fromDate > $toDate) {
            api_error('Choose a valid report date range.');
        }
        api_ok((new ReportModel(api_conn()))->buildReport($from, $to));
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new ReportsApi(), $operation, $data);
