<?php
declare(strict_types=1);

const HOTEL_CHECK_IN_TIME = '15:00:00';
const HOTEL_CHECK_OUT_TIME = '14:00:00';

function is_hotel_check_in_time(mixed $time): bool
{
    return is_string($time) && preg_match('/^15:[0-5]\d(?::00)?$/', trim($time)) === 1;
}

function is_hotel_check_out_time(mixed $time): bool
{
    return is_string($time) && in_array(trim($time), ['14:00', '14:00:00'], true);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function split_person_name(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return [
        'first_name' => array_shift($parts) ?? '',
        'last_name' => implode(' ', $parts),
    ];
}

function valid_appointment_name(string $name): bool
{
    return preg_match(
        '/^(?=.{1,120}$)[\p{L}\p{M}]+(?:[- .\'][\p{L}\p{M}]+)*$/us',
        $name
    ) === 1;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function money(float|int|string $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/** Fixed hotel config (no settings table / admin settings page). */
function hotel_config(?string $key = null): array|string
{
    static $config = [
        'hotel_name' => 'Aurora Grand Hotel',
        'hotel_address' => 'Aurora Boulevard, Metro City',
        'hotel_phone' => '02-8888-1000',
        'hotel_email' => 'front@auroragrand.hotel',
        'tax_rate' => '0',
        'currency' => 'PHP',
        'invoice_prefix' => 'AGH',
    ];
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? '';
}

function payment_method_label(string $method): string
{
    return match ($method) {
        'online', 'gcash' => 'GCash',
        'bank', 'bank_transfer' => 'Bank Transfer',
        default => 'Cash (counter)',
    };
}

function hotel_info(): array
{
    $c = hotel_config();
    return [
        'name' => $c['hotel_name'],
        'hotel_name' => $c['hotel_name'],
        'address' => $c['hotel_address'],
        'phone' => $c['hotel_phone'],
        'email' => $c['hotel_email'],
    ];
}

function audit(mysqli $conn, ?int $userId, string $action, string $details = ''): void
{
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $action, $details);
    $stmt->execute();
}

function next_invoice_number(mysqli $conn): string
{
    $prefix = (string) hotel_config('invoice_prefix');
    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';
    $stmt = $conn->prepare(
        "SELECT invoice_number FROM bills WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $seq = 1;
    if ($last) {
        $parts = explode('-', $last['invoice_number']);
        $seq = ((int) end($parts)) + 1;
    }
    return sprintf('%s-%s-%04d', $prefix, $year, $seq);
}

function recalculate_bill(mysqli $conn, int $billId): void
{
    $taxRate = (float) hotel_config('tax_rate');

    $sumStmt = $conn->prepare('SELECT COALESCE(SUM(amount),0) AS subtotal FROM bill_items WHERE bill_id = ?');
    $sumStmt->bind_param('i', $billId);
    $sumStmt->execute();
    $subtotal = (float) ($sumStmt->get_result()->fetch_assoc()['subtotal'] ?? 0);

    $billStmt = $conn->prepare('SELECT discount_amount, paid_amount, due_date FROM bills WHERE id = ?');
    $billStmt->bind_param('i', $billId);
    $billStmt->execute();
    $bill = $billStmt->get_result()->fetch_assoc();
    if (!$bill) {
        return;
    }

    $discount = (float) $bill['discount_amount'];
    $taxable = max(0, $subtotal - $discount);
    $tax = round($taxable * ($taxRate / 100), 2);
    $total = round($taxable + $tax, 2);
    $paid = (float) $bill['paid_amount'];
    $balance = max(0, round($total - $paid, 2));

    $status = 'pending';
    if ($total <= 0) {
        $status = 'paid';
    } elseif ($paid <= 0 && !empty($bill['due_date']) && $bill['due_date'] < date('Y-m-d')) {
        $status = 'overdue';
    } elseif ($paid <= 0) {
        $status = 'pending';
    } elseif ($balance <= 0.009) {
        $status = 'paid';
        $balance = 0;
    } else {
        $status = 'partial';
        if (!empty($bill['due_date']) && $bill['due_date'] < date('Y-m-d')) {
            $status = 'overdue';
        }
    }

    $upd = $conn->prepare(
        'UPDATE bills SET subtotal=?, tax_amount=?, total_amount=?, balance=?, status=? WHERE id=?'
    );
    $upd->bind_param('ddddsi', $subtotal, $tax, $total, $balance, $status, $billId);
    $upd->execute();
}

function normalize_time(mixed $value, string $default): string
{
    if (is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m)) {
        return sprintf('%02d:%s:%s', (int) $m[1], $m[2], $m[3] ?? '00');
    }
    return $default;
}

function nights_between(string $checkIn, string $checkOut): int
{
    $in = new DateTime($checkIn);
    $out = new DateTime($checkOut);
    $nights = (int) $in->diff($out)->days;
    return max(1, $nights);
}

function status_badge(string $status): string
{
    $map = [
        'active' => 'ok',
        'available' => 'ok',
        'paid' => 'ok',
        'answered' => 'ok',
        'checked_in' => 'info',
        'reserved' => 'info',
        'partial' => 'warn',
        'pending' => 'warn',
        'open' => 'warn',
        'occupied' => 'warn',
        'overdue' => 'danger',
        'inactive' => 'danger',
        'maintenance' => 'danger',
        'cancelled' => 'muted',
        'checked_out' => 'muted',
        'void' => 'muted',
        'closed' => 'muted',
    ];
    $class = $map[$status] ?? 'muted';
    return '<span class="badge badge-' . $class . '">' . e(str_replace('_', ' ', ucfirst($status))) . '</span>';
}

function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Climb out of role folders when needed
    if (preg_match('#/(admin|frontdesk|guest)$#', $script)) {
        $script = dirname($script);
    }
    $base = rtrim($script, '/');
    if ($base === '' || $base === '.') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }
}

/** HTML attributes for numeric-only phone inputs. */
function phone_field_attrs(bool $required = false): string
{
    $attrs = 'type="tel" inputmode="numeric" pattern="[0-9]{7,15}" maxlength="15" '
        . 'class="phone-input" autocomplete="tel" placeholder="09123456789" title="Numbers only, 7 to 15 digits"';
    return $required ? $attrs . ' required' : $attrs;
}

/** @return array{ok:bool,message:string,phone:string} */
function validate_phone(?string $phone, bool $required = false): array
{
    $phone = trim((string) $phone);
    if ($phone === '') {
        if ($required) {
            return ['ok' => false, 'message' => 'Phone number is required.', 'phone' => ''];
        }
        return ['ok' => true, 'message' => '', 'phone' => ''];
    }
    if (!preg_match('/^\d+$/', $phone)) {
        return ['ok' => false, 'message' => 'Phone number must contain numbers only.', 'phone' => $phone];
    }
    $len = strlen($phone);
    if ($len < 7 || $len > 15) {
        return ['ok' => false, 'message' => 'Phone number must be 7 to 15 digits.', 'phone' => $phone];
    }
    return ['ok' => true, 'message' => '', 'phone' => $phone];
}

function app_root(): string
{
    return dirname(__DIR__);
}

function room_photo_url(?string $photo, ?string $roomType = null): string
{
    if ($photo !== null && $photo !== '' && is_file(app_root() . '/' . $photo)) {
        return base_path($photo);
    }
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $roomType) ?? '', '-'));
    if ($slug !== '') {
        foreach ([
            "systemphotos/room-{$slug}.jpg",
            "systemphotos/room-{$slug}.jpeg",
            "systemphotos/room-{$slug}.png",
            "systemphotos/room-{$slug}.webp",
            "assets/img/room-{$slug}.svg",
        ] as $candidate) {
            if (is_file(app_root() . '/' . $candidate)) {
                return base_path($candidate);
            }
        }
    }
    return base_path('assets/img/room-placeholder.svg');
}

/** @return array{ok:bool,message:string,path:?string} */
function store_room_photo(array $file, ?string $oldPhoto = null): array
{
    return store_image_upload($file, $oldPhoto, 'rooms', 'room', 'Room photo');
}

function store_image_upload(array $file, ?string $oldPhoto, string $folder, string $prefix, string $label): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'message' => '', 'path' => $oldPhoto];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => $label . ' upload failed.', 'path' => $oldPhoto];
    }

    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'message' => $label . ' must be 5MB or smaller.', 'path' => $oldPhoto];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name'] ?? '') ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'message' => $label . ' must be JPG, PNG, WEBP, or GIF.', 'path' => $oldPhoto];
    }

    $uploadDir = app_root() . '/uploads/' . $folder;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return ['ok' => false, 'message' => 'Could not create upload folder.', 'path' => $oldPhoto];
    }

    $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $relative = 'uploads/' . $folder . '/' . $filename;
    $target = app_root() . '/' . $relative;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'message' => 'Could not save ' . strtolower($label) . '.', 'path' => $oldPhoto];
    }

    if ($oldPhoto && is_file(app_root() . '/' . $oldPhoto)) {
        @unlink(app_root() . '/' . $oldPhoto);
    }

    return ['ok' => true, 'message' => '', 'path' => $relative];
}

function store_service_photo(array $file, ?string $oldPhoto = null): array
{
    return store_image_upload($file, $oldPhoto, 'services', 'svc', 'Photo');
}

function delete_stored_photo(?string $photo): void
{
    if ($photo && str_starts_with($photo, 'uploads/') && is_file(app_root() . '/' . $photo)) {
        @unlink(app_root() . '/' . $photo);
    }
}

/** Adds photo_url (relative to the site root, or null) to each service / item row. */
function with_photo_urls(array $rows): array
{
    foreach ($rows as &$row) {
        $photo = $row['photo'] ?? null;
        $row['photo_url'] = $photo && is_file(app_root() . '/' . $photo) ? base_path($photo) : null;
    }
    return $rows;
}
