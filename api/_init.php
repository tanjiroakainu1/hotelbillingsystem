<?php
declare(strict_types=1);

if (!defined('API_INIT_LOADED')) {
    define('API_INIT_LOADED', true);

    header('Content-Type: application/json; charset=utf-8');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (!defined('APP_ROOT')) {
        define('APP_ROOT', dirname(__DIR__));
    }

    require_once APP_ROOT . '/config/auth.php';
    require_once APP_ROOT . '/config/realtime.php';

    spl_autoload_register(static function (string $class): void {
        $path = APP_ROOT . '/core/models/' . $class . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

if (!function_exists('api_input')) {
    function api_input(): array
    {
        $operation = '';
        $jsonRaw = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $operation = (string) ($_GET['operation'] ?? '');
            $jsonRaw = (string) ($_GET['json'] ?? '');
        } else {
            $operation = (string) ($_POST['operation'] ?? '');
            $jsonRaw = (string) ($_POST['json'] ?? '');
            $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if ($operation === '' && str_contains($contentType, 'application/json')) {
                $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
                $operation = (string) ($body['operation'] ?? '');
                $jsonRaw = isset($body['json'])
                    ? (is_string($body['json']) ? $body['json'] : json_encode($body['json']))
                    : json_encode($body);
            }
        }

        $data = [];
        if ($jsonRaw !== '') {
            $decoded = json_decode($jsonRaw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $source = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? $_GET : $_POST;
        foreach ($source as $key => $value) {
            if ($key === 'operation' || $key === 'json') {
                continue;
            }
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        return ['operation' => $operation, 'data' => $data];
    }

    function api_ok(mixed $data = null, string $message = 'OK'): void
    {
        // Every successful write (POST) tells connected browsers to refresh their data.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'auth.php') {
            realtime_notify();
        }
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    function api_error(string $message, int $code = 400, mixed $data = null): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    function api_require_login(): array
    {
        $user = current_user();
        if (!$user) {
            api_error('Please sign in to continue.', 401);
        }
        return $user;
    }

    function api_require_role(string ...$roles): array
    {
        $user = api_require_login();
        if (!in_array($user['role'], $roles, true)) {
            api_error('You do not have access to that area.', 403);
        }
        return $user;
    }

    function api_conn(): mysqli
    {
        global $conn;
        return $conn;
    }

    function api_dispatch(object $handler, string $operation, array $data): void
    {
        if ($operation === '' || !method_exists($handler, $operation)) {
            api_error('Unknown operation: ' . $operation, 404);
        }
        $handler->{$operation}($data);
    }

    function api_from_flash(string $defaultSuccess = 'Done.', ?callable $onSuccess = null): void
    {
        $flash = get_flash();
        if ($flash) {
            if (($flash['type'] ?? '') === 'success') {
                $extra = $onSuccess ? $onSuccess() : null;
                api_ok($extra, (string) $flash['message']);
            }
            api_error((string) $flash['message']);
        }
        $extra = $onSuccess ? $onSuccess() : null;
        api_ok($extra, $defaultSuccess);
    }
}
