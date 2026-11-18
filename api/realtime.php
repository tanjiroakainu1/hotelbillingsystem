<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

class RealtimeApi
{
    /** Public on purpose: it only exposes a change counter, never data. */
    public function getVersion(array $data = []): void
    {
        api_ok(['version' => realtime_version(), 'ws_port' => REALTIME_WS_PORT]);
    }
}

[$operation, $data] = array_values(api_input());
api_dispatch(new RealtimeApi(), $operation, $data);
