<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class AuditApi
{
    public function getSystemLogs(array $data = []): void
    {
        api_require_role('admin');
        $limit = max(1, min(500, (int) ($data['limit'] ?? 200)));
        api_ok((new AuditModel(api_conn()))->recentSystem($limit));
    }

    public function getStaffLogs(array $data = []): void
    {
        api_require_role('frontdesk', 'admin');
        $limit = max(1, min(500, (int) ($data['limit'] ?? 200)));
        api_ok((new AuditModel(api_conn()))->recentStaff($limit));
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new AuditApi(), $operation, $data);
