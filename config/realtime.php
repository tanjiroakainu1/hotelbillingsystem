<?php
declare(strict_types=1);

const REALTIME_WS_PORT = 8081;

function realtime_version_file(): string
{
    return dirname(__DIR__) . '/uploads/.realtime_version';
}

function realtime_version(): string
{
    $file = realtime_version_file();
    return is_file($file) ? trim((string) @file_get_contents($file)) : '0';
}

/** Mark data as changed: bump the version (polling fallback) and ping the WebSocket server. */
function realtime_notify(): void
{
    @file_put_contents(realtime_version_file(), (string) (int) (microtime(true) * 1000), LOCK_EX);

    $socket = @fsockopen('127.0.0.1', REALTIME_WS_PORT, $errno, $errstr, 0.2);
    if (!$socket) {
        return;
    }
    stream_set_timeout($socket, 0, 200000);
    fwrite($socket, "POST /notify HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($socket);
}
