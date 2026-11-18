<?php
declare(strict_types=1);

ob_start();

/**
 * CLI system test — run: php test_system.php
 * Architecture: HTML frontends + PHP APIs (operation/json params)
 */

$errors = [];
$passed = 0;

function ok(string $msg): void
{
    global $passed;
    $passed++;
    echo "[PASS] $msg\n";
}

function fail(string $msg): void
{
    global $errors;
    $errors[] = $msg;
    echo "[FAIL] $msg\n";
}

echo "=== Hotel Billing System Test (HTML + API) ===\n\n";

$root = __DIR__;

$required = [
    'index.html', 'home.html', 'login.html',
    'frontdesk/register-guest.html',
    'config/database.php', 'config/auth.php', 'config/helpers.php',
    'includes/profile_handler.php',
    'assets/css/style.css', 'assets/js/script.js',
    'sql/schema.sql',
    'api/_init.php', 'api/auth.php', 'api/dashboard.php', 'api/users.php',
    'api/rooms.php', 'api/services.php', 'api/discounts.php', 'api/guests.php',
    'api/bookings.php', 'api/bills.php', 'api/payments.php',
    'api/audit.php', 'api/reports.php', 'api/reports_export.php', 'api/payment_receipt_export.php', 'api/invoice_export.php', 'api/profile.php', 'api/housekeeping.php',
    'api/categories.php', 'api/room_types.php', 'api/additional_items.php', 'api/transfers.php', 'api/requests.php',
    'core/models/RoomTypeModel.php', 'core/models/GuestRequestModel.php', 'core/models/PaymentRequestModel.php',
    'core/PdfDocument.php', 'core/models/HousekeepingModel.php',

    'admin/index.html', 'admin/users.html', 'admin/room-types.html', 'admin/categories.html', 'admin/additional-items.html', 'admin/requested-items.html',
    'admin/rooms.html', 'admin/manage-hotel-services.html', 'admin/services.html',
    'admin/discounts.html', 'admin/billing.html', 'admin/reports.html',
    'admin/audit.html', 'admin/profile.html', 'admin/guests.html', 'admin/housekeeping.html',
    'admin/checkin.html', 'admin/checkout.html', 'admin/transfers.html', 'admin/room-management.html',
    'frontdesk/index.html', 'frontdesk/guests.html', 'frontdesk/register-guest.html', 'frontdesk/checkin.html', 'frontdesk/checkout.html',
    'frontdesk/transfers.html', 'frontdesk/additional-items.html', 'frontdesk/requested-items.html',
    'frontdesk/billing.html', 'frontdesk/guest-account.html', 'frontdesk/extend-stay.html', 'admin/extend-stay.html', 'frontdesk/payments.html', 'frontdesk/invoices.html',
    'frontdesk/reports.html', 'frontdesk/rooms.html', 'frontdesk/manage-hotel-services.html', 'frontdesk/services.html',
    'frontdesk/audit.html', 'frontdesk/profile.html', 'frontdesk/room-management.html', 'frontdesk/housekeeping.html', 'frontdesk/operations.html',
    'kitchen/index.html', 'kitchen/profile.html', 'laundry/index.html', 'laundry/profile.html',
    'housekeeping/index.html', 'housekeeping/profile.html',
    'guest/index.html', 'guest/bill.html', 'guest/requests.html', 'guest/payments.html',
    'guest/invoices.html', 'guest/booking.html', 'guest/transfers.html', 'guest/profile.html',
];

foreach ($required as $f) {
    if (is_file("$root/$f")) {
        ok("File exists: $f");
    } else {
        fail("Missing file: $f");
    }
}

$forbidden = [
    'index.php', 'home.php', 'login.php', 'register.php', 'logout.php',
    'admin/index.php', 'admin/rooms.php', 'admin/services.php', 'admin/settings.php',
    'admin/clarifications.html',
    'guest/clarification.html',
    'api/clarifications.php',
    'core/models/ClarificationModel.php', 'core/models/SettingModel.php',
    'frontdesk/index.php', 'guest/index.php',
    'frontdesk/balances.html',
    'frontdesk/partial-bill.html',
    'frontdesk/categories.html',
    'assets/js/app.js', 'includes/layout.php',
    '_patch_ui.py', '_patch_room_types_ui.js',
];
foreach ($forbidden as $f) {
    if (!is_file("$root/$f")) {
        ok("Removed obsolete: $f");
    } else {
        fail("Obsolete file still present: $f");
    }
}

$dbContent = file_get_contents("$root/config/database.php");
if (str_contains($dbContent, 'hotelbillingsystem') && !str_contains($dbContent, 'hotel_billing_system')) {
    ok('Database name aligned: hotelbillingsystem');
} else {
    fail('Database name mismatch in config/database.php');
}

$schema = file_get_contents("$root/sql/schema.sql");
$loginPage = file_get_contents("$root/login.html");
$loginScript = file_get_contents("$root/assets/js/script.js");
$guestRegistrationPage = file_get_contents("$root/frontdesk/register-guest.html");
if (!str_contains($loginPage, 'guest123')
    && str_contains($loginPage, 'script.js?v=130')
    && !str_contains($loginScript, 'If Front Desk kept the default password')
    && !str_contains($loginPage, 'appointment code as the initial password')
    && !str_contains(file_get_contents("$root/frontdesk/guests.html"), 'Default: guest123')
    && !str_contains(file_get_contents("$root/admin/guests.html"), 'Default: guest123')
    && str_contains(file_get_contents("$root/core/models/GuestModel.php"), 'Choose a unique portal password with at least 12 characters.')
    && str_contains(file_get_contents("$root/core/models/GuestModel.php"), 'str_contains($normalizedPassword, $lastName)')
    && !str_contains(file_get_contents("$root/core/models/GuestModel.php"), "?? 'guest123'")) {
    ok('Login and guest registration do not expose or set a shared default password');
} else {
    fail('A guest default password remains exposed or accepted during registration');
}
$guestModelSource = file_get_contents("$root/core/models/GuestModel.php");
if (str_contains($guestModelSource, 'public function registerQuick')
    && str_contains($guestModelSource, "\$data['password'] = \$nameParts['last_name'];")
    && str_contains($guestModelSource, '$this->register($data, $actorId, true)')
    && str_contains($guestModelSource, '!$quickRegister')
    && str_contains(file_get_contents("$root/api/guests.php"), 'function createQuickGuest')
    && str_contains(file_get_contents("$root/frontdesk/guests.html"), 'id="quick-guest-default-password"')
    && str_contains(file_get_contents("$root/admin/guests.html"), 'id="quick-guest-default-password"')
    && str_contains($loginScript, "'createQuickGuest', payload")
    && str_contains($loginScript, "quickName?.addEventListener('input', syncQuickPassword)")) {
    ok('Quick guest registration derives the initial portal password from the guest last name');
} else {
    fail('Quick guest registration does not derive and display the guest last name as the initial password');
}
if (str_contains($loginScript, "label: 'Guest', email: 'guest@gmail.com', password: 'guest123'")
    && str_contains($loginScript, 'await loginWithAccount(btn.getAttribute(\'data-email\'), password)')
    && str_contains($loginScript, "if (auto && prefillDemo?.password)")
    && str_contains($schema, "('Sample Guest', 'Sample', 'Guest', 'guest@gmail.com'")
    && str_contains($schema, "restore_seeded_guest_demo_login")
    && str_contains(file_get_contents("$root/config/database.php"), "password_hash('guest123', PASSWORD_DEFAULT)")) {
    ok('Seeded guest account and automatic demo login are aligned');
} else {
    fail('Seeded guest account or automatic demo login is missing');
}
$bookingModelSource = file_get_contents("$root/core/models/BookingModel.php");
$billingApiSource = file_get_contents("$root/api/bills.php");
$billingMarkup = file_get_contents("$root/frontdesk/billing.html") . file_get_contents("$root/admin/billing.html");
$frontdeskBillingPage = file_get_contents("$root/frontdesk/billing.html");
$frontdeskBillingTemplate = substr(
    $frontdeskBillingPage,
    strpos($frontdeskBillingPage, '<template id="page-billing">'),
    strpos($frontdeskBillingPage, '</template>') - strpos($frontdeskBillingPage, '<template id="page-billing">')
);
if (!str_contains($bookingModelSource, 'It is too early to check in')
    && str_contains($bookingModelSource, "This booking is not scheduled for check-in today.")
    && str_contains($billingMarkup, 'bill-guest-cards')
    && str_contains($billingMarkup, 'bill-account-rooms')
    && str_contains($billingMarkup, 'bill-service-cards')
    && str_contains($billingMarkup, 'bill-item-cards')
    && str_contains($billingMarkup, 'bill-delivery-rooms')
    && str_contains($billingMarkup, 'data-bill-filter="food"')
    && str_contains($billingMarkup, 'bill-catalog-search')
    && str_contains($billingMarkup, 'bill-back-to-accounts')
    && str_contains($loginScript, 'billing-focused')
    && str_contains($loginScript, 'data-bill-category')
    && !str_contains($billingMarkup, 'bill-guest-select')
    && !str_contains($billingMarkup, 'bill-booking-select')
    && !str_contains($frontdeskBillingTemplate, '<select')
    && str_contains($billingApiSource, 'Choose a room currently occupied by this guest.')
    && str_contains($billingApiSource, 'JOIN bookings booking ON booking.id = b.booking_id')
    && str_contains(file_get_contents("$root/core/models/RoomModel.php"), "b.status IN ('reserved','checked_in')")
    && str_contains($schema, 'delivery_room_number VARCHAR(20) DEFAULT NULL')) {
    ok('Staff check-in override and card-based guest, room, and billing workflow are aligned');
} else {
    fail('Staff check-in or shared visual billing workflow is incomplete');
}
$guestDashboardPage = file_get_contents("$root/guest/index.html");
$dashboardApiSource = file_get_contents("$root/api/dashboard.php");
if (str_contains($guestDashboardPage, 'id="guest-stay-rooms"')
    && str_contains($guestDashboardPage, 'My Rooms')
    && str_contains($dashboardApiSource, 'historyForGuest($guestId)')
    && str_contains($dashboardApiSource, "'stays' => \$stays")
    && str_contains($loginScript, 'guest-stay-room-card')
    && str_contains($loginScript, 'Request service for this room')
    && str_contains($loginScript, 'requestedBookingId')
    && str_contains(file_get_contents("$root/guest/requests.html"), 'id="req-room-select"')
    && str_contains(file_get_contents("$root/index.html"), 'href="appointment.html"')
    && str_contains(file_get_contents("$root/index.html"), 'script.js?v=135')
    && str_contains(file_get_contents("$root/appointment.html"), 'script.js?v=142')
    && str_contains(file_get_contents("$root/guest/index.html"), 'script.js?v=135')
    && str_contains(file_get_contents("$root/guest/requests.html"), 'script.js?v=135')
    && str_contains($loginScript, "apiPost('requests.php', 'createRequest', payload)")
    && str_contains($loginScript, "apiGet('requests.php', 'getGuestPage')")
    && str_contains($loginScript, 'renderRequestRows(refreshed.data.requests || [])')) {
    ok('Guest My Rooms shows account stays and opens room-specific service requests');
} else {
    fail('Guest room cards or room-specific service request selection is incomplete');
}
if (!str_contains($guestRegistrationPage, 'name="id_type"')
    && !str_contains($guestRegistrationPage, 'name="id_number"')
    && !str_contains($guestRegistrationPage, 'ID Type')
    && !str_contains($guestRegistrationPage, 'ID Number')) {
    ok('Front Desk guest registration does not request ID type or ID number');
} else {
    fail('Front Desk guest registration still includes ID type or ID number fields');
}
if (str_contains($schema, 'hotelbillingsystem') && !str_contains($schema, 'hotel_billing_system')) {
    ok('Schema uses hotelbillingsystem');
} else {
    fail('Schema database name mismatch');
}
if (!str_contains($schema, 'CREATE TABLE clarifications') && !str_contains($schema, 'CREATE TABLE settings')) {
    ok('Schema has no clarifications/settings tables');
} else {
    fail('Schema still defines clarifications or settings');
}
if (str_contains($schema, 'CREATE TABLE room_types') && str_contains($schema, 'CREATE TABLE categories') && str_contains($schema, 'CREATE TABLE room_transfers')) {
    ok('Schema includes room_types, categories, and room_transfers');
} else {
    fail('Schema missing room_types/categories/room_transfers');
}
$roomInsert = [];
if (preg_match('/INSERT INTO rooms\b.*?VALUES(.*?);/s', $schema, $roomInsert)) {
    preg_match_all("/^\\('([^']+)',\\s*(\\d+),\\s*'([^']+)'/m", $roomInsert[1], $seedRooms, PREG_SET_ORDER);
    $roomNumbers = array_column($seedRooms, 1);
    $roomTypes = array_count_values(array_column($seedRooms, 3));
    if (count($roomNumbers) === 30
        && count(array_unique($roomNumbers)) === 30
        && ($roomTypes['Standard'] ?? 0) === 10
        && ($roomTypes['Deluxe'] ?? 0) === 10
        && ($roomTypes['Suite'] ?? 0) === 10) {
        ok('Schema seeds 30 uniquely numbered rooms across 10 Standard, 10 Deluxe, and 10 Suite rooms');
    } else {
        fail('Schema room inventory must contain 30 unique rooms, evenly split across room types');
    }
} else {
    fail('Schema room inventory seed statement is missing');
}
if (!str_contains($schema, 'Operational demo records') && !str_contains($schema, 'Demo flow A:') && !str_contains($schema, 'Ana Reyes')) {
    ok('Schema excludes legacy operational test data');
} else {
    fail('Schema contains legacy operational test data');
}

try {
    require "$root/config/database.php";
    ok('Database connection successful');
} catch (Throwable $e) {
    fail('Database connection: ' . $e->getMessage());
    echo "\nImport sql/schema.sql in phpMyAdmin first.\n";
    exit(1);
}

$tables = [
    'users', 'guests', 'rooms', 'room_types', 'categories', 'services', 'discounts',
    'additional_item_types', 'guest_requests', 'bookings', 'room_transfers', 'bills', 'bill_items',
    'payments', 'payment_requests', 'audit_logs', 'housekeeping_amenities', 'room_amenities',
    'housekeeping_tasks', 'housekeeping_task_items',
];
foreach ($tables as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    if ($r && $r->num_rows > 0) {
        ok("Table exists: $t");
    } else {
        fail("Missing table: $t");
    }
}

foreach (['clarifications', 'settings'] as $gone) {
    $r = $conn->query("SHOW TABLES LIKE '$gone'");
    if (!$r || $r->num_rows === 0) {
        ok("Table removed: $gone");
    } else {
        fail("Obsolete table still exists: $gone (re-import sql/schema.sql)");
    }
}

require_once "$root/core/models/RoomModel.php";
require_once "$root/core/models/BookingModel.php";
require_once "$root/core/models/BillModel.php";
require_once "$root/core/models/PaymentModel.php";
require_once "$root/core/models/PaymentRequestModel.php";
require_once "$root/core/models/RoomTransferModel.php";
require_once "$root/core/models/HousekeepingModel.php";
require_once "$root/core/models/ServiceModel.php";
require_once "$root/core/models/AdditionalItemModel.php";
require_once "$root/core/models/GuestRequestModel.php";
require_once "$root/config/helpers.php";
$appointmentNamesValid = valid_appointment_name('Jordan-Mae')
    && valid_appointment_name("O'Neil")
    && valid_appointment_name('Élodie')
    && valid_appointment_name('Santos de la Cruz');
$appointmentNamesInvalid = !valid_appointment_name('')
    && !valid_appointment_name('Jordan123')
    && !valid_appointment_name('Jordan<script>')
    && !valid_appointment_name(str_repeat('A', 121));
if ($appointmentNamesValid && $appointmentNamesInvalid) {
    ok('Appointment name validation accepts alternate Unicode names and rejects invalid characters/length');
} else {
    fail('Appointment name validation accepted invalid values or rejected valid names');
}
$archiveSchemaReady = str_contains($schema, 'item_unavailable_at DATETIME DEFAULT NULL')
    && str_contains($schema, "item_unavailable_reason ENUM('inactive','deleted')")
    && str_contains($schema, 'item_unavailable_by INT DEFAULT NULL')
    && str_contains($dbContent, "'item_unavailable_at' => 'DATETIME DEFAULT NULL'")
    && str_contains($dbContent, "'item_unavailable_by' => 'INT DEFAULT NULL'");
if ($archiveSchemaReady) {
    ok('Archive history columns are present in fresh and upgraded database schemas');
} else {
    fail('Archive history columns are missing from the fresh or upgraded database schema');
}
$archiveUiScript = file_get_contents("$root/assets/js/script.js");
$archiveUiReady = str_contains($archiveUiScript, 'This item was ${r.item_unavailable_reason')
    && str_contains($archiveUiScript, 'This item was ${r.item_unavailable_reason')
    && str_contains($archiveUiScript, 'by ${e(r.item_unavailable_by_name)}')
    && str_contains($archiveUiScript, 'Archive ${entry.name}?')
    && str_contains($archiveUiScript, 'Archive ${e(it.item_name)}?')
    && str_contains($archiveUiScript, 'data-permanent-delete-item=')
    && str_contains($archiveUiScript, 'Permanently delete ${entry.name}')
    && str_contains($archiveUiScript, 'if (request.item_unavailable_at) return false;');
if ($archiveUiReady) {
    ok('Guest, staff, and Admin catalog screens explain archived items and suppress fulfillment actions');
} else {
    fail('Archive notices or controls are missing from guest, staff, or Admin screens');
}
$adminRoomsPage = file_get_contents("$root/admin/rooms.html");
$frontdeskRoomsPage = file_get_contents("$root/frontdesk/rooms.html");
$servicesApi = file_get_contents("$root/api/services.php");
$additionalItemsApi = file_get_contents("$root/api/additional_items.php");
$archiveWorkspaceReady = str_contains($adminRoomsPage, 'id="page-archive"')
    && str_contains($frontdeskRoomsPage, 'id="page-archive"')
    && str_contains($adminRoomsPage, 'permanently remove them from the catalog')
    && str_contains($archiveUiScript, "id: 'archive', label: 'Archive Management', template: 'archive'")
    && str_contains($archiveUiScript, 'async function renderArchiveManagement(')
    && str_contains($archiveUiScript, 'data-restore-item=')
    && str_contains($servicesApi, 'public function permanentlyDeleteService(')
    && str_contains($servicesApi, "api_require_role('admin')")
    && str_contains($servicesApi, 'public function restoreService(')
    && str_contains($additionalItemsApi, 'public function permanentlyDeleteItem(')
    && str_contains($additionalItemsApi, "api_require_role('admin')")
    && str_contains($additionalItemsApi, 'public function restoreItem(');
if ($archiveWorkspaceReady) {
    ok('Archive Management is integrated into Admin and Front Desk Rooms pages; restore endpoints are Admin-only');
} else {
    fail('Rooms pages or Admin-only archive restore workflow is incomplete');
}

$guestRow = $conn->query('SELECT id FROM guests ORDER BY id LIMIT 1')->fetch_assoc();
$adminRow = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch_assoc();
if ($guestRow && $adminRow) {
    $archiveSuffix = bin2hex(random_bytes(6));
    $guestId = (int) $guestRow['id'];
    $adminId = (int) $adminRow['id'];
    $serviceId = 0;
    $extraId = 0;
    $invoice = '';
    $serviceName = '';
    $extraName = '';
    $serviceRequestId = 0;
    $extraRequestId = 0;
    try {
        $serviceName = "Archive Test {$archiveSuffix}";
        $category = 'Food';
        $price = 12.50;
        $serviceInsert = $conn->prepare(
            "INSERT INTO services (name, category, price, status) VALUES (?, ?, ?, 'active')"
        );
        $serviceInsert->bind_param('ssd', $serviceName, $category, $price);
        $serviceInsert->execute();
        $serviceId = (int) $conn->insert_id;

        $extraName = "Archive Extra {$archiveSuffix}";
        $extraInsert = $conn->prepare(
            "INSERT INTO additional_item_types (item_name, unit_price, status) VALUES (?, ?, 'active')"
        );
        $extraInsert->bind_param('sd', $extraName, $price);
        $extraInsert->execute();
        $extraId = (int) $conn->insert_id;

        $invoice = "TEST-ARCHIVE-{$archiveSuffix}";
        $billInsert = $conn->prepare(
            'INSERT INTO bills (guest_id, invoice_number, subtotal, total_amount, balance) VALUES (?, ?, 25.00, 25.00, 25.00)'
        );
        $billInsert->bind_param('is', $guestId, $invoice);
        $billInsert->execute();
        $billId = (int) $conn->insert_id;

        $serviceDescription = "Guest request: {$serviceName}";
        $extraDescription = "Guest request: {$extraName}";
        $serviceBillItem = $conn->prepare(
            "INSERT INTO bill_items (bill_id, item_type, reference_id, description, quantity, unit_price, amount)
             VALUES (?, 'service', ?, ?, 1, ?, ?)"
        );
        $serviceBillItem->bind_param('iisdd', $billId, $serviceId, $serviceDescription, $price, $price);
        $serviceBillItem->execute();
        $serviceBillItemId = (int) $conn->insert_id;
        $extraBillItem = $conn->prepare(
            "INSERT INTO bill_items (bill_id, item_type, reference_id, description, quantity, unit_price, amount)
             VALUES (?, 'extra', ?, ?, 1, ?, ?)"
        );
        $extraBillItem->bind_param('iisdd', $billId, $extraId, $extraDescription, $price, $price);
        $extraBillItem->execute();
        $extraBillItemId = (int) $conn->insert_id;

        $serviceType = 'food';
        $extraType = 'extra';
        $serviceRequest = $conn->prepare(
            'INSERT INTO guest_requests (guest_id, request_type, service_id, item_name, unit_price, billed, bill_item_id)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $serviceRequest->bind_param('isisdi', $guestId, $serviceType, $serviceId, $serviceName, $price, $serviceBillItemId);
        $serviceRequest->execute();
        $serviceRequestId = (int) $conn->insert_id;
        $extraRequest = $conn->prepare(
            'INSERT INTO guest_requests (guest_id, request_type, additional_item_id, item_name, unit_price, billed, bill_item_id)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $extraRequest->bind_param('isisdi', $guestId, $extraType, $extraId, $extraName, $price, $extraBillItemId);
        $extraRequest->execute();
        $extraRequestId = (int) $conn->insert_id;

        (new ServiceModel($conn))->delete($serviceId, $adminId);
        (new AdditionalItemModel($conn))->delete($extraId, $adminId);
        $serviceState = $conn->query(
            "SELECT s.status, gr.item_unavailable_at, gr.item_unavailable_reason, gr.item_unavailable_by, bi.description, bi.amount, b.total_amount
             FROM services s
             JOIN guest_requests gr ON gr.service_id = s.id
             JOIN bill_items bi ON bi.id = gr.bill_item_id
             JOIN bills b ON b.id = bi.bill_id
             WHERE s.id = {$serviceId}"
        )->fetch_assoc();
        $extraState = $conn->query(
            "SELECT item.status, gr.item_unavailable_at, gr.item_unavailable_reason, gr.item_unavailable_by, bi.description, bi.amount, b.total_amount
             FROM additional_item_types item
             JOIN guest_requests gr ON gr.additional_item_id = item.id
             JOIN bill_items bi ON bi.id = gr.bill_item_id
             JOIN bills b ON b.id = bi.bill_id
             WHERE item.id = {$extraId}"
        )->fetch_assoc();
        $serviceUnavailableForNewRequests = (new ServiceModel($conn))->findActive($serviceId) === null;
        $extraCatalog = (new AdditionalItemModel($conn))->active();
        $extraUnavailableForNewRequests = !in_array($extraId, array_map(
            static fn (array $item): int => (int) $item['id'],
            $extraCatalog
        ), true);
        $staffFulfillmentGuard = str_contains(
            file_get_contents("$root/core/models/GuestRequestModel.php"),
            '$this->catalogItemArchived($row)'
        );
        $serviceStateValid = $serviceState
            && $serviceState['status'] === 'inactive'
            && $serviceState['item_unavailable_reason'] === 'deleted'
            && $serviceState['item_unavailable_at'] !== null
            && (int) $serviceState['item_unavailable_by'] === $adminId
            && str_contains($serviceState['description'], '[Catalog item archived by ')
            && (float) $serviceState['amount'] === $price
            && (float) $serviceState['total_amount'] === 25.0;
        $extraStateValid = $extraState
            && $extraState['status'] === 'inactive'
            && $extraState['item_unavailable_reason'] === 'deleted'
            && $extraState['item_unavailable_at'] !== null
            && (int) $extraState['item_unavailable_by'] === $adminId
            && str_contains($extraState['description'], '[Catalog item archived by ')
            && (float) $extraState['amount'] === $price
            && (float) $extraState['total_amount'] === 25.0;
        if ($serviceStateValid && $extraStateValid && $serviceUnavailableForNewRequests
            && $extraUnavailableForNewRequests && $staffFulfillmentGuard) {
            ok('Archiving food and extras preserves guest requests and bill totals, marks history, and blocks fulfillment');
        } else {
            fail('Catalog archive did not preserve and correctly mark request and bill history');
        }

        (new ServiceModel($conn))->restore($serviceId, $adminId);
        (new AdditionalItemModel($conn))->restore($extraId, $adminId);
        $restoredServiceStatus = $conn->query("SELECT status FROM services WHERE id = {$serviceId}")->fetch_row()[0] ?? '';
        $restoredExtraStatus = $conn->query("SELECT status FROM additional_item_types WHERE id = {$extraId}")->fetch_row()[0] ?? '';
        $preservedArchiveFlag = (bool) $conn->query(
            "SELECT id FROM guest_requests
             WHERE additional_item_id = {$extraId}
               AND item_unavailable_at IS NOT NULL
               AND item_unavailable_reason = 'deleted'"
        )->fetch_assoc();
        if ($restoredServiceStatus === 'active' && $restoredExtraStatus === 'active' && $preservedArchiveFlag) {
            ok('Admin restore reactivates catalog entries without rewriting archived request history');
        } else {
            fail('Catalog restore did not reactivate entries or changed their archived request history');
        }
        (new ServiceModel($conn))->delete($serviceId, $adminId);
        (new AdditionalItemModel($conn))->delete($extraId, $adminId);
        (new ServiceModel($conn))->permanentlyDelete($serviceId, $adminId);
        (new AdditionalItemModel($conn))->permanentlyDelete($extraId, $adminId);
        $permanentServiceHistory = $conn->query(
            "SELECT gr.service_id, gr.item_unavailable_by, gr.item_unavailable_reason, bi.description, bi.amount
             FROM guest_requests gr
             JOIN bill_items bi ON bi.id = gr.bill_item_id
             WHERE gr.id = {$serviceRequestId}"
        )->fetch_assoc();
        $permanentExtraHistory = $conn->query(
            "SELECT gr.additional_item_id, gr.item_unavailable_by, gr.item_unavailable_reason, bi.description, bi.amount
             FROM guest_requests gr
             JOIN bill_items bi ON bi.id = gr.bill_item_id
             WHERE gr.id = {$extraRequestId}"
        )->fetch_assoc();
        $catalogRowsRemain = (int) $conn->query(
            "SELECT
                (SELECT COUNT(*) FROM services WHERE id = {$serviceId})
                + (SELECT COUNT(*) FROM additional_item_types WHERE id = {$extraId})"
        )->fetch_row()[0];
        if ($catalogRowsRemain === 0
            && $permanentServiceHistory
            && $permanentServiceHistory['service_id'] === null
            && (int) $permanentServiceHistory['item_unavailable_by'] === $adminId
            && $permanentServiceHistory['item_unavailable_reason'] === 'deleted'
            && str_contains($permanentServiceHistory['description'], '[Catalog item permanently removed by ')
            && (float) $permanentServiceHistory['amount'] === $price
            && $permanentExtraHistory
            && $permanentExtraHistory['additional_item_id'] === null
            && (int) $permanentExtraHistory['item_unavailable_by'] === $adminId
            && $permanentExtraHistory['item_unavailable_reason'] === 'deleted'
            && str_contains($permanentExtraHistory['description'], '[Catalog item permanently removed by ')
            && (float) $permanentExtraHistory['amount'] === $price) {
            ok('Permanent catalog removal retains guest requests and billed transaction lines with staff attribution');
        } else {
            fail('Permanent catalog deletion removed or failed to annotate guest billing history');
        }
    } catch (Throwable $error) {
        fail('Catalog archive lifecycle test: ' . $error->getMessage());
    } finally {
        $requests = $conn->prepare('DELETE FROM guest_requests WHERE id IN (?, ?)');
        $requests->bind_param('ii', $serviceRequestId, $extraRequestId);
        $requests->execute();
        if ($invoice !== '') {
            $billItems = $conn->prepare(
                'DELETE bi FROM bill_items bi JOIN bills b ON b.id = bi.bill_id WHERE b.invoice_number = ?'
            );
            $billItems->bind_param('s', $invoice);
            $billItems->execute();
            $bills = $conn->prepare('DELETE FROM bills WHERE invoice_number = ?');
            $bills->bind_param('s', $invoice);
            $bills->execute();
        }
        if ($serviceId > 0) {
            $service = $conn->prepare('DELETE FROM services WHERE id = ?');
            $service->bind_param('i', $serviceId);
            $service->execute();
        }
        if ($extraId > 0) {
            $extra = $conn->prepare('DELETE FROM additional_item_types WHERE id = ?');
            $extra->bind_param('i', $extraId);
            $extra->execute();
        }
        if ($serviceName !== '') {
            $serviceAudit = "%{$serviceName}%";
            $audit = $conn->prepare('DELETE FROM audit_logs WHERE details LIKE ?');
            $audit->bind_param('s', $serviceAudit);
            $audit->execute();
        }
        if ($extraName !== '') {
            $extraAudit = "%{$extraName}%";
            $audit = $conn->prepare('DELETE FROM audit_logs WHERE details LIKE ?');
            $audit->bind_param('s', $extraAudit);
            $audit->execute();
        }
    }
} else {
    fail('Catalog archive lifecycle test requires a seeded guest and administrator');
}
if (!BookingModel::hasOutstandingBalance(0.0)
    && !BookingModel::hasOutstandingBalance(0.009)
    && BookingModel::hasOutstandingBalance(0.01)) {
    ok('Check-out balance rule allows a cleared account and blocks a remaining cent');
} else {
    fail('Check-out balance threshold does not match cent-based bill balances');
}
if (is_hotel_check_in_time('15:00:00')
    && is_hotel_check_in_time('15:59:00')
    && !is_hotel_check_in_time('16:00:00')
    && !is_hotel_check_in_time('14:59:00')
    && is_hotel_check_out_time('14:00:00')
    && !is_hotel_check_out_time('12:00:00')
    && RoomTransferModel::isScheduledTransferTime('15:00:00')
    && !RoomTransferModel::isScheduledTransferTime('15:01:00')
    && !RoomTransferModel::isLateTransfer('2026-10-21', '15:00:00', new DateTimeImmutable('2026-10-21 14:59:59'))
    && !RoomTransferModel::isLateTransfer('2026-10-21', '15:00:00', new DateTimeImmutable('2026-10-21 15:00:00'))
    && RoomTransferModel::isLateTransfer('2026-10-21', '15:00:00', new DateTimeImmutable('2026-10-21 15:00:01'))
    && !RoomTransferModel::isLateTransfer('2026-10-21', '15:00:00', new DateTimeImmutable('2026-10-22 16:00:00'))) {
    ok('Hotel timing policy accepts the full 3 PM check-in hour and fixes checkout/transfers');
} else {
    fail('Hotel timing policy validation is incorrect');
}
$appointmentPolicyApi = file_get_contents("$root/api/appointments.php");
if (str_contains($appointmentPolicyApi, 'is_hotel_check_in_time($checkInTime)')
    && str_contains($appointmentPolicyApi, 'is_hotel_check_out_time($checkOutTime)')
    && str_contains(file_get_contents("$root/admin/checkin.html"), 'data-time-policy="check-in"')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'data-time-policy="check-out"')
    && str_contains(file_get_contents("$root/appointment.html"), 'data-time-policy="check-in"')
    && str_contains(file_get_contents("$root/manage-appointment.html"), 'fixed at 2:00 PM')) {
    ok('Booking and appointment entry points expose and enforce the hotel time policy');
} else {
    fail('Booking or appointment timing policy is not aligned across UI and API');
}
$transferAtSix = RoomTransferModel::calculateRateAdjustment(2500, 4200, '2026-10-21', '18:00:00', '2026-10-23');
$transferAtFiveThirty = RoomTransferModel::calculateRateAdjustment(2500, 4200, '2026-10-21', '17:30:00', '2026-10-23');
$transferDownAtSix = RoomTransferModel::calculateRateAdjustment(4200, 2500, '2026-10-21', '18:00:00', '2026-10-23');
$transferAtNoon = RoomTransferModel::calculateRateAdjustment(2500, 4200, '2026-10-21', '12:00:00', '2026-10-23');
if ($transferAtSix['remaining_nights'] === 2
    && abs($transferAtSix['billable_nights'] - 1.25) < 0.000001
    && $transferAtSix['total_charge'] === 2125.0
    && $transferAtFiveThirty['total_charge'] === 2160.42
    && $transferAtNoon['total_charge'] === 2550.0
    && $transferDownAtSix['total_charge'] === -2125.0) {
    ok('Room transfer rate adjustments prorate by selected time and support both higher and lower room rates');
} else {
    fail('Room transfer time-based rate adjustment calculations are incorrect');
}
$billItemQuantity = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'quantity'")->fetch_assoc();
if ($billItemQuantity && strtolower((string) $billItemQuantity['Type']) === 'decimal(10,6)') {
    ok('Bill item quantities preserve time-prorated room-rate precision');
} else {
    fail('Bill item quantity precision is insufficient for time-prorated room-rate adjustments');
}
$deliveryRoomIdColumn = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'delivery_room_id'")->fetch_assoc();
$deliveryRoomNumberColumn = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'delivery_room_number'")->fetch_assoc();
if ($deliveryRoomIdColumn && $deliveryRoomNumberColumn) {
    ok('Bill items retain delivery room IDs and room-number snapshots');
} else {
    fail('Bill item delivery room fields are missing from the active database');
}
$roomTransferSource = file_get_contents("$root/core/models/RoomTransferModel.php");
$transferUi = file_get_contents("$root/assets/js/script.js");
if (str_contains($roomTransferSource, 'self::calculateRateAdjustment(')
    && str_contains($roomTransferSource, 'at %s) - %.6f Night-Equivalent(s)')
    && str_contains($transferUi, 'const transferStamp = `${dateInput.value}T15:00:00`')
    && str_contains($roomTransferSource, 'postExtraNightCharge(')
    && str_contains($roomTransferSource, "'Late transfer'")
    && str_contains($transferUi, 'const billableNights = Math.max(0, nights - 1) + transferDayFraction')
    && str_contains(file_get_contents("$root/config/database.php"), 'MODIFY quantity DECIMAL(10,6)')
    && str_contains($schema, 'quantity DECIMAL(10,6) NOT NULL DEFAULT 1')) {
    ok('Room transfer preview, fixed schedule, late-night fee, and invoice adjustment are aligned');
} else {
    fail('Room transfer schedule or billing is not aligned across preview, invoice, and database schema');
}
$roomModel = new RoomModel($conn);
$bookingModel = new BookingModel($conn);
$billModel = new BillModel($conn);
$paymentModel = new PaymentModel($conn);
$paymentRequestModel = new PaymentRequestModel($conn);
$paymentCountBeforeZeroTests = (int) $conn->query('SELECT COUNT(*) FROM payments')->fetch_row()[0];
$paymentRequestCountBeforeZeroTests = (int) $conn->query('SELECT COUNT(*) FROM payment_requests')->fetch_row()[0];
$zeroPaymentRejected = !$paymentModel->record(
    ['bill_id' => 1, 'amount' => 0, 'method' => 'cash'],
    $billModel,
    1
);
$subCentPaymentRejected = !$paymentModel->record(
    ['bill_id' => 1, 'amount' => 0.001, 'method' => 'cash'],
    $billModel,
    1
);
$zeroAccountPaymentRejected = !$paymentModel->recordAccountPayment(
    ['booking_id' => 1, 'amount' => 0, 'method' => 'cash'],
    $billModel,
    1
);
$zeroGuestPaymentRequestRejected = $paymentRequestModel->createForGuest(
    ['bill_id' => 1, 'amount' => 0, 'method' => 'cash'],
    1,
    1,
    $billModel
) === false;
$paymentCountAfterZeroTests = (int) $conn->query('SELECT COUNT(*) FROM payments')->fetch_row()[0];
$paymentRequestCountAfterZeroTests = (int) $conn->query('SELECT COUNT(*) FROM payment_requests')->fetch_row()[0];
if ($zeroPaymentRejected && $subCentPaymentRejected && $zeroAccountPaymentRejected
    && $zeroGuestPaymentRequestRejected
    && $paymentCountBeforeZeroTests === $paymentCountAfterZeroTests
    && $paymentRequestCountBeforeZeroTests === $paymentRequestCountAfterZeroTests) {
    ok('Zero and sub-cent payments/payment requests are rejected without creating records');
} else {
    fail('A zero or sub-cent payment/payment request was accepted or persisted');
}
$managedRooms = $roomModel->managementList(date('Y-m-d'));
$roomTotal = $roomModel->count();
if (count($managedRooms) === $roomTotal) {
    ok('Room management query returns every room');
} else {
    fail('Room management query count does not match room inventory');
}
$conn->begin_transaction();
try {
    $testRoomNumbers = [];
    $testRoomIds = [];
    $insertRoom = $conn->prepare(
        "INSERT INTO rooms (room_number, room_type, rate_per_night, floor, status)
         VALUES (?, 'Transfer Availability Test', 1000.00, 99, 'available')"
    );
    for ($i = 1; $i <= 30; $i++) {
        $roomNumber = sprintf('ZZ-XFER-TEST-%02d', $i);
        $insertRoom->bind_param('s', $roomNumber);
        $insertRoom->execute();
        $testRoomNumbers[] = $roomNumber;
        $testRoomIds[] = (int) $conn->insert_id;
    }
    $testDate = date('Y-m-d');
    $checkoutDate = date('Y-m-d', strtotime('+1 day'));
    $availableRooms = $roomModel->availableForDates($testDate, $checkoutDate);
    $availableTestRoomNumbers = array_values(array_intersect(
        $testRoomNumbers,
        array_column($availableRooms, 'room_number')
    ));
    if (count($availableTestRoomNumbers) === count($testRoomNumbers)) {
        ok('Transfer availability returns all 30 available rooms without a result limit');
    } else {
        fail('Transfer availability returned ' . count($availableTestRoomNumbers) . ' of 30 available test rooms');
    }
    $guest = $conn->query('SELECT id FROM guests ORDER BY id LIMIT 1')->fetch_assoc();
    $guestId = (int) ($guest['id'] ?? 0);
    if ($guestId > 0) {
        $insertBooking = $conn->prepare(
            'INSERT INTO bookings (guest_id, room_id, check_in, check_out, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (['reserved', 'checked_in'] as $index => $bookingStatus) {
            $roomId = $testRoomIds[$index];
            $insertBooking->bind_param('iisss', $guestId, $roomId, $testDate, $checkoutDate, $bookingStatus);
            $insertBooking->execute();
        }
        $overviewRooms = $roomModel->managementList($testDate);
        $overviewTestRooms = array_values(array_filter(
            $overviewRooms,
            static fn(array $room): bool => in_array((int) $room['id'], $testRoomIds, true)
        ));
        $overviewStatuses = [];
        foreach ($overviewTestRooms as $room) {
            if ($room['booking_status']) {
                $overviewStatuses[(int) $room['id']] = $room['booking_status'];
            }
        }
        if (count($overviewTestRooms) === 30
            && ($overviewStatuses[$testRoomIds[0]] ?? '') === 'reserved'
            && ($overviewStatuses[$testRoomIds[1]] ?? '') === 'checked_in') {
            ok('Room overview lists all rooms and distinguishes reserved from checked-in stays');
        } else {
            fail('Room overview did not return all rooms with the expected reserved/checked-in statuses');
        }
    } else {
        fail('Room overview status test requires at least one guest record');
    }
} catch (Throwable $e) {
    fail('Room availability test failed: ' . $e->getMessage());
} finally {
    $conn->rollback();
}
$checkoutRoomIds = [];
$checkoutBookingIds = [];
$checkoutBillIds = [];
$checkoutFixtureReady = false;
try {
    $guestRow = $conn->query('SELECT id FROM guests ORDER BY id LIMIT 1')->fetch_assoc();
    $actorRow = $conn->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch_assoc();
    if (!$guestRow || !$actorRow) {
        throw new RuntimeException('Checkout integration test requires guest and staff records.');
    }
    $checkoutGuestId = (int) $guestRow['id'];
    $checkoutActorId = (int) $actorRow['id'];
    $stayGroup = random_int(100000000, 2000000000);
    $insertTestRoom = $conn->prepare(
        "INSERT INTO rooms (room_number, room_type, rate_per_night, floor, status)
         VALUES (?, 'Checkout Balance Test', 1000.00, 98, 'occupied')"
    );
    $insertTestBooking = $conn->prepare(
        "INSERT INTO bookings (guest_id, stay_group, room_id, check_in, check_out, status)
         VALUES (?, ?, ?, ?, ?, 'checked_in')"
    );
    $insertTestBill = $conn->prepare(
        "INSERT INTO bills (booking_id, guest_id, invoice_number, total_amount, paid_amount, balance, status)
         VALUES (?, ?, ?, 100.00, ?, ?, ?)"
    );
    $checkoutDate = date('Y-m-d', strtotime('-1 day'));
    $checkInDate = date('Y-m-d', strtotime('-2 days'));
    $conn->begin_transaction();
    try {
        for ($index = 1; $index <= 2; $index++) {
            $roomNumber = 'ZZ-CO-' . strtoupper(bin2hex(random_bytes(4))) . $index;
            $insertTestRoom->bind_param('s', $roomNumber);
            $insertTestRoom->execute();
            $checkoutRoomIds[] = (int) $conn->insert_id;

            $roomId = $checkoutRoomIds[$index - 1];
            $insertTestBooking->bind_param('iiiss', $checkoutGuestId, $stayGroup, $roomId, $checkInDate, $checkoutDate);
            $insertTestBooking->execute();
            $checkoutBookingIds[] = (int) $conn->insert_id;

            $paidAmount = $index === 1 ? 100.0 : 75.0;
            $balance = 100.0 - $paidAmount;
            $billStatus = $balance > 0 ? 'partial' : 'paid';
            $invoiceNumber = 'CHK-TEST-' . strtoupper(bin2hex(random_bytes(6)));
            $bookingId = $checkoutBookingIds[$index - 1];
            $insertTestBill->bind_param('iisdds', $bookingId, $checkoutGuestId, $invoiceNumber, $paidAmount, $balance, $billStatus);
            $insertTestBill->execute();
            $checkoutBillIds[] = (int) $conn->insert_id;
        }
        $conn->commit();
        $checkoutFixtureReady = true;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $listedCheckout = array_values(array_filter(
        $bookingModel->list(),
        static fn(array $booking): bool => (int) $booking['id'] === $checkoutBookingIds[0]
    ));
    $groupBalanceVisible = $listedCheckout
        && abs((float) $listedCheckout[0]['account_balance'] - 25.0) < 0.001;
    $bookingModel->checkOut($checkoutBookingIds[0], $roomModel, $checkoutActorId);
    $stillCheckedIn = (string) ($bookingModel->find($checkoutBookingIds[0])['status'] ?? '') === 'checked_in';
    $lateCharge = $conn->prepare(
        "SELECT COUNT(*) AS charge_count
         FROM bill_items
         WHERE bill_id = ? AND item_type = 'other' AND description LIKE 'Late checkout extra night%'"
    );
    $lateCharge->bind_param('i', $checkoutBillIds[0]);
    $lateCharge->execute();
    $lateChargeCount = (int) $lateCharge->get_result()->fetch_assoc()['charge_count'];
    $lateAccountBalance = (float) $conn->query(
        'SELECT SUM(balance) AS balance FROM bills WHERE id IN (' . implode(',', array_map('intval', $checkoutBillIds)) . ')'
    )->fetch_assoc()['balance'];

    $paidBill = $conn->prepare("UPDATE bills SET paid_amount=total_amount, balance=0.00, status='paid' WHERE id=?");
    foreach ($checkoutBillIds as $billId) {
        $paidBill->bind_param('i', $billId);
        $paidBill->execute();
    }
    $bookingModel->checkOut($checkoutBookingIds[0], $roomModel, $checkoutActorId);
    $checkedOutWhenCleared = (string) ($bookingModel->find($checkoutBookingIds[0])['status'] ?? '') === 'checked_out';
    if ($groupBalanceVisible && $stillCheckedIn && $lateChargeCount === 1
        && abs($lateAccountBalance - 925.0) < 0.001 && $checkedOutWhenCleared) {
        ok('Late checkout adds one idempotent extra night, blocks until paid, then allows checkout');
    } else {
        fail('Late checkout did not consistently bill once and enforce the full linked stay-account balance'
            . ' (balance=' . ($groupBalanceVisible ? 'visible' : 'mismatch')
            . ', unpaid=' . ($stillCheckedIn ? 'blocked' : 'checked out')
            . ', late charge count=' . $lateChargeCount
            . ', post-charge balance=' . money($lateAccountBalance)
            . ', paid=' . ($checkedOutWhenCleared ? 'completed' : 'still checked in') . ')');
    }
} catch (Throwable $error) {
    fail('Checkout balance integration test failed: ' . $error->getMessage());
} finally {
    if ($checkoutFixtureReady) {
        try {
            $conn->begin_transaction();
            $deleteAudit = $conn->prepare("DELETE FROM audit_logs WHERE action='check_out' AND details=?");
            foreach ($checkoutBookingIds as $bookingId) {
                $details = "Booking #{$bookingId}";
                $deleteAudit->bind_param('s', $details);
                $deleteAudit->execute();
                $lateDetails = '%Booking #' . $bookingId . '%';
                $deleteLateAudit = $conn->prepare("DELETE FROM audit_logs WHERE action='late_stay_charge' AND details LIKE ?");
                $deleteLateAudit->bind_param('s', $lateDetails);
                $deleteLateAudit->execute();
            }
            $deleteBills = $conn->prepare('DELETE FROM bills WHERE id=?');
            foreach ($checkoutBillIds as $billId) {
                $deleteBills->bind_param('i', $billId);
                $deleteBills->execute();
            }
            $deleteBookings = $conn->prepare('DELETE FROM bookings WHERE id=?');
            foreach ($checkoutBookingIds as $bookingId) {
                $deleteBookings->bind_param('i', $bookingId);
                $deleteBookings->execute();
            }
            $deleteRooms = $conn->prepare('DELETE FROM rooms WHERE id=?');
            foreach ($checkoutRoomIds as $roomId) {
                $deleteRooms->bind_param('i', $roomId);
                $deleteRooms->execute();
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            fail('Checkout test fixture cleanup failed: ' . $error->getMessage());
        }
    }
}
echo 'Dashboard data: rooms=' . $roomTotal
    . ', occupied=' . $roomModel->occupiedCount()
    . ', checked_in=' . $bookingModel->checkedInCount()
    . ', reserved=' . $bookingModel->reservedCount() . "\n";

require "$root/config/auth.php";
$conn->begin_transaction();
$logins = [
    ['admin@gmail.com', 'admin123', 'admin', 'admin/index.html'],
    ['frontdesk@gmail.com', 'frontdesk123', 'frontdesk', 'frontdesk/index.html'],
    ['kitchen@gmail.com', 'kitchen123', 'kitchen', 'kitchen/index.html'],
    ['laundry@gmail.com', 'laundry123', 'laundry', 'laundry/index.html'],
];
foreach ($logins as [$email, $pass, $role, $homeSuffix]) {
    $_SESSION = [];
    if (attempt_login($conn, $email, $pass) && current_user()['role'] === $role) {
        $home = role_home($role);
        if (str_ends_with($home, $homeSuffix) || str_contains($home, $homeSuffix)) {
            ok("Login OK: $role → $home");
        } else {
            fail("Login home mismatch for $role: $home");
        }
    } else {
        fail("Login failed for $email");
    }
}
$conn->rollback();

if (function_exists('hotel_config') && hotel_config('hotel_name') === 'Aurora Grand Hotel') {
    ok('hotel_config() available without settings table');
} else {
    fail('hotel_config() missing or incorrect');
}

$js = file_get_contents("$root/assets/js/script.js");
$css = file_get_contents("$root/assets/css/style.css");
$mobilePages = [
    'index.html', 'login.html', 'admin/checkin.html', 'frontdesk/checkin.html',
    'frontdesk/payments.html', 'guest/bill.html', 'kitchen/index.html',
    'laundry/index.html',
];
if (str_contains($css, '@media (max-width: 640px)')
    && str_contains($css, '.table-wrap td[data-label]::before')
    && str_contains($css, 'min-height: 44px')
    && str_contains($css, 'grid-template-columns: minmax(0, 1fr)')
    && str_contains($js, 'function labelTableCells(root)')
    && !array_filter($mobilePages, static fn(string $page): bool => !str_contains(
        file_get_contents("$root/$page"),
        '<meta name="viewport"'
    ))) {
    ok('Shared mobile layout, labeled responsive tables, touch targets, and viewport metadata are present');
} else {
    fail('Shared mobile responsive layout or page viewport support is incomplete');
}
foreach (['admin/checkin.html', 'frontdesk/checkin.html'] as $checkinPage) {
    $page = file_get_contents("$root/$checkinPage");
    $expectedScriptVersion = $checkinPage === 'admin/checkin.html' ? 'script.js?v=142' : 'script.js?v=135';
    if (str_contains($page, '<h2>Guest Check-In</h2>')
        && str_contains($page, 'id="page-checkout"')
        && str_contains($page, 'id="page-appointments"')
        && str_contains($page, 'id="appointment-requests-body"')
        && str_contains($page, 'id="appointment-changes-body"')
        && str_contains($page, 'data-page="stay-management"')
        && !str_contains($page, 'booking-submit-button')
        && str_contains($page, $expectedScriptVersion)
        && str_contains($page, 'Staff may check in an arriving guest earlier for role-play and system testing.')) {
        ok("Combined Check-In / Check-Out / Appointment page is complete: $checkinPage");
    } else {
        fail("Combined Check-In / Check-Out page is incomplete: $checkinPage");
    }
}
if (str_contains($js, 'function renderWorkflowTabs(')
    && str_contains($js, "'admin:stay-management'")
    && str_contains($js, "'frontdesk:stay-management'")
    && str_contains($js, "label: 'Online Appointments', template: 'appointments', render: renderStaffAppointments")
    && str_contains($js, 'class="appointment-description"')
    && str_contains(file_get_contents("$root/admin/checkout.html"), "set('tab', 'checkout')")
    && str_contains(file_get_contents("$root/frontdesk/checkout.html"), "set('tab', 'checkout')")) {
    ok('Admin and Front Desk use a tabbed check-in/out page and old check-out URLs redirect');
} else {
    fail('Combined check-in/out routes or compatibility redirects are incomplete');
}
if (str_contains(file_get_contents("$root/frontdesk/operations.html"), 'id="requested-items-tbody"')
    && str_contains(file_get_contents("$root/frontdesk/operations.html"), 'id="housekeeping-management-tbody"')
    && str_contains(file_get_contents("$root/frontdesk/operations.html"), 'id="req-filter-status"')
    && str_contains($js, "'frontdesk:operations'")
    && str_contains($js, "'Food / Laundry / Amenities Assignments'")
    && str_contains(file_get_contents("$root/frontdesk/requested-items.html"), "new URL('operations.html'")
    && str_contains(file_get_contents("$root/frontdesk/housekeeping.html"), "new URL('operations.html'")
    && str_contains(file_get_contents("$root/admin/requested-items.html"), 'id="item-create"')
    && str_contains($js, 'function renderCombinedRequests(')
    && str_contains($js, "'admin:requested-items'")
    && !str_contains($js, "'admin:additional-items'")
    && str_contains(file_get_contents("$root/frontdesk/additional-items.html"), "new URL('operations.html'")
    && str_contains(file_get_contents("$root/admin/additional-items.html"), "new URL('requested-items.html'")) {
    ok('Front Desk request assignment and checkout inspection workflows share one page; old URLs redirect');
} else {
    fail('Combined Front Desk request/inspection route or compatibility redirects are incomplete');
}
$adminHousekeeping = file_get_contents("$root/admin/housekeeping.html");
$frontDeskOperations = file_get_contents("$root/frontdesk/operations.html");
if (str_contains($adminHousekeeping, 'id="page-requested-items"')
    && str_contains($adminHousekeeping, 'id="requested-items-tbody"')
    && str_contains($adminHousekeeping, 'id="page-housekeeping"')
    && str_contains($adminHousekeeping, 'id="housekeeping-management-tbody"')
    && str_contains($adminHousekeeping, 'id="req-filter-status"')
    && str_contains($frontDeskOperations, 'id="page-requested-items"')
    && str_contains($frontDeskOperations, 'id="page-housekeeping"')
    && str_contains($adminHousekeeping, 'Checkout Room Inspections')
    && str_contains($adminHousekeeping, 'Damage, Stains &amp; Checklist')
    && str_contains($frontDeskOperations, 'Damage, Stains &amp; Checklist')
    && str_contains($js, "Number(task.assigned_to) === Number(staff.id) ? 'selected' : ''")
    && str_contains($js, "task.assigned_to ? '' : 'selected'")
    && str_contains($js, "'admin:housekeeping': ['Request Assignments & Room Inspections', ['admin'], 'housekeeping', renderFrontdeskOperations]")
    && str_contains($js, "'Food / Laundry / Amenities Assignments'")) {
    ok('Admin housekeeping combines request assignments and checkout inspections like Front Desk');
} else {
    fail('Admin housekeeping assignment/inspection workflow is not aligned with Front Desk');
}
if (str_contains(file_get_contents("$root/admin/room-management.html"), 'id="page-extend-stay"')
    && str_contains(file_get_contents("$root/frontdesk/room-management.html"), 'id="page-extend-stay"')
    && str_contains($js, 'function renderRoomStayManagement(')
    && str_contains(file_get_contents("$root/admin/extend-stay.html"), "set('tab', 'extend-stay')")
    && str_contains(file_get_contents("$root/frontdesk/extend-stay.html"), "set('tab', 'extend-stay')")) {
    ok('Admin and Front Desk use a tabbed room-availability/extension page and preserve old extension URLs');
} else {
    fail('Combined room-management/extension routes or redirects are incomplete');
}
if (str_contains(file_get_contents("$root/admin/room-management.html"), 'id="page-transfers"')
    && str_contains(file_get_contents("$root/frontdesk/room-management.html"), 'id="page-transfers"')
    && str_contains(file_get_contents("$root/admin/transfers.html"), "searchParams.set('tab', 'transfers')")
    && str_contains(file_get_contents("$root/frontdesk/transfers.html"), "searchParams.set('tab', 'transfers')")) {
    ok('Transfers are integrated into Admin and Front Desk room management with compatible old URLs');
} else {
    fail('Combined room-management/transfer routes or redirects are incomplete');
}
if (str_contains(file_get_contents("$root/admin/guests.html"), 'id="page-register-guest"')
    && str_contains(file_get_contents("$root/frontdesk/guests.html"), 'id="page-register-guest"')
    && str_contains(file_get_contents("$root/admin/guests.html"), '<th>First Name</th><th>Last Name</th>')
    && str_contains(file_get_contents("$root/frontdesk/guests.html"), '<th>First Name</th>')
    && str_contains($js, 'lastNameParts.join')
    && str_contains($schema, "first_name VARCHAR(120) NOT NULL DEFAULT ''")
    && str_contains($js, 'colspan="7" class="empty">No guests found.')
    && !str_contains(substr(file_get_contents("$root/frontdesk/guests.html"), 0, strpos(file_get_contents("$root/frontdesk/guests.html"), '<template id="page-register-guest">')), '<th>Gender</th>')
    && str_contains(file_get_contents("$root/frontdesk/guests.html"), 'id="guest-appointment-lookup"')
    && str_contains($js, "claimGuestAccount', {")
    && str_contains($js, 'data-guest-reset')
    && str_contains($js, 'guest-password-reset-dialog')
    && str_contains($js, "passwordDialog.showModal()")
    && !str_contains($js, "window.prompt('Enter a unique new password")
    && str_contains($js, 'data-guest-status')
    && str_contains(file_get_contents("$root/admin/guests.html"), 'script.js?v=137')
    && str_contains(file_get_contents("$root/frontdesk/guests.html"), 'script.js?v=137')
    && str_contains(file_get_contents("$root/admin/guests.html"), 'style.css?v=62')
    && str_contains(file_get_contents("$root/frontdesk/guests.html"), 'style.css?v=62')
    && str_contains(file_get_contents("$root/api/guests.php"), 'resetPortalPassword')
    && str_contains(file_get_contents("$root/api/guests.php"), 'setPortalStatus')
    && str_contains(file_get_contents("$root/core/models/GuestModel.php"), 'SET u.status = ?')
    && str_contains(file_get_contents("$root/core/models/GuestModel.php"), 'guest_portal_status')
    && str_contains(file_get_contents("$root/config/auth.php"), 'WHERE email = ? AND status = "active"')
    && str_contains($js, 'Delete account')
    && str_contains($js, 'Restore account')
    && str_contains($js, 'Guest portal account deleted. Sign-in is disabled; guest and transaction history were retained.')
    && str_contains(file_get_contents("$root/frontdesk/guests.html"), 'Delete account disables portal sign-in only.')
    && str_contains($js, "'admin:guests'")
    && str_contains($js, "'frontdesk:guests'")
    && str_contains(file_get_contents("$root/frontdesk/register-guest.html"), "searchParams.set('tab', 'register')")) {
    ok('Guest management shows full, first, and last names for Admin and Front Desk');
} else {
    fail('Guest name columns or combined Guest Management routes are incomplete');
}
$userPage = file_get_contents("$root/admin/users.html");
if (str_contains($userPage, '<th>First Name</th>')
    && str_contains($userPage, '<th>Last Name</th>')
    && str_contains($js, "e(u.first_name || '—')")
    && str_contains($js, "e(u.last_name || '—')")
    && str_contains(file_get_contents("$root/core/models/UserModel.php"), 'first_name, last_name')
    && str_contains($schema, "('Housekeeping Staff', 'Housekeeping', 'Staff'")) {
    ok('All role accounts persist first/last names and Admin user management displays them');
} else {
    fail('Role account name fields, display, or SQL seed data are incomplete');
}
$calendarCss = file_get_contents("$root/assets/css/style.css");
if (str_contains($js, 'function enhanceCalendarInputs(')
    && str_contains($js, 'if (dt.min !== min) dt.min = min;')
    && str_contains($js, 'if (dt.max !== max) dt.max = max;')
    && str_contains($js, 'className = \'calendar-popover\'')
    && str_contains($js, 'data-calendar-day')
    && str_contains($js, 'calendarValidate')
    && str_contains($js, 'panel.offsetHeight')
    && str_contains($js, "document.querySelectorAll('.calendar-control.is-open')")
    && str_contains($calendarCss, '.calendar-popover')
    && str_contains($calendarCss, 'max-height: calc(100vh - 24px)')
    && str_contains($calendarCss, '@media (max-width: 380px)')
    && str_contains($calendarCss, '.calendar-day.is-selected')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'script.js?v=135')
    && str_contains(file_get_contents("$root/guest/booking.html"), 'script.js?v=130')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'style.css?v=61')) {
    ok('Shared calendar picker is styled and loaded across role pages');
} else {
    fail('Shared calendar picker or its page cache references are incomplete');
}
$appointmentApi = file_get_contents("$root/api/appointments.php");
$appointmentHome = file_get_contents("$root/index.html");
$appointmentSchema = str_contains($schema, 'CREATE TABLE room_appointments')
    && str_contains($schema, 'CREATE TABLE room_appointment_changes')
    && str_contains($schema, 'CREATE TABLE room_appointment_rooms')
    && str_contains($schema, 'DROP TABLE IF EXISTS room_appointments')
    && str_contains($schema, 'DROP TABLE IF EXISTS room_appointment_changes')
    && str_contains($schema, 'DROP TABLE IF EXISTS room_appointment_rooms');
if ($appointmentSchema
    && str_contains(file_get_contents("$root/config/database.php"), 'CREATE TABLE IF NOT EXISTS room_appointments')
    && str_contains($appointmentApi, 'getPublicAvailability')
    && str_contains($appointmentApi, 'createPublicAppointment')
    && str_contains($appointmentApi, 'requestPublicChange')
    && str_contains($appointmentApi, 'reviewChangeRequest')
    && str_contains($appointmentApi, 'assignAppointmentRooms')
    && str_contains($appointmentApi, 'claimGuestAccount')
    && str_contains($appointmentApi, 'password_hash($code, PASSWORD_DEFAULT)')
    && str_contains($appointmentApi, '@gmail\\.com')
    && str_contains($appointmentApi, 'check_in_time')
    && str_contains($appointmentApi, 'check_out_time')
    && str_contains($appointmentApi, 'reviewAppointmentPayment')
    && str_contains($appointmentApi, '$depositAmount = round($estimatedTotal * 0.25, 2)')
    && str_contains($appointmentApi, "'payment_status' => 'pending'")
    && str_contains($schema, 'payment_status ENUM(\'not_required\',\'pending\',\'verified\',\'rejected\')')
    && !str_contains(file_get_contents("$root/appointment.html"), 'cdn.jsdelivr.net/npm/axios')
    && str_contains(file_get_contents("$root/appointment.html"), 'script.js?v=142')
    && str_contains(file_get_contents("$root/appointment.html"), 'style.css?v=62')
    && str_contains(file_get_contents("$root/manage-appointment.html"), 'style.css?v=61')
    && str_contains(file_get_contents("$root/assets/css/style.css"), '.appointment-page-head .home-brand-mark { color: var(--primary-dark); text-shadow: none; }')
    && str_contains($js, 'async function requestJson')
    && str_contains($js, "dateIn.closest('#booking-create, #transfer-form, #public-appointment-form, #public-appointment-change-form')")
    && !str_contains($js, 'axios.')
    && str_contains(file_get_contents("$root/core/models/RoomModel.php"), 'room_appointment_rooms')
    && str_contains(file_get_contents("$root/core/models/BookingModel.php"), 'room_appointment_rooms')
    && str_contains($appointmentHome, 'href="appointment.html"')
    && str_contains(file_get_contents("$root/appointment.html"), 'id="public-appointment-form"')
    && str_contains(file_get_contents("$root/appointment.html"), 'name="payment_method"')
    && str_contains(file_get_contents("$root/appointment.html"), 'name="payment_reference"')
    && str_contains(file_get_contents("$root/appointment.html"), 'Letters, numbers, and symbols are accepted.')
    && str_contains($appointmentApi, "\$paymentReference === '' || strlen(\$paymentReference) > 80")
    && !str_contains($appointmentApi, 'at least 8 digits')
    && !str_contains($js, "paymentReference.replace(/\\D/g, '').length < 8")
    && str_contains($js, "!/@gmail\\.com$/i.test(field('email')?.value.trim() || '')")
    && str_contains(file_get_contents("$root/appointment.html"), 'data-appointment-room-gallery')
    && str_contains(file_get_contents("$root/appointment.html"), 'data-check-appointment-availability')
    && str_contains($js, 'renderAppointmentRoomGallery(form, []);')
    && str_contains($js, 'form.querySelector(\'[data-check-appointment-availability]\')')
    && str_contains($js, "roomGallery?.addEventListener('change', (event) =>")
    && str_contains($js, 'input.checked = selectedRoom;')
    && str_contains($js, 'card?.classList.toggle(\'is-selected\', selectedRoom);')
    && str_contains($js, 'function updateAppointmentSelection(form)')
    && !str_contains($js, 'updateAppointmentSelection(form, true)')
    && str_contains(file_get_contents("$root/appointment.html"), 'name="room_ids"')
    && str_contains(file_get_contents("$root/appointment.html"), 'data-time-select')
    && str_contains(file_get_contents("$root/appointment.html"), 'data-appointment-name')
    && str_contains($appointmentApi, 'valid_appointment_name($firstName)')
    && str_contains($appointmentApi, 'valid_appointment_name($lastName)')
    && str_contains($appointmentApi, 'appointmentNameExists($conn, $firstName, $lastName, $fullName)')
    && str_contains($appointmentApi, 'Please use another name for your appointment.')
    && str_contains($js, 'data-appointment-name-error')
    && str_contains($js, '/^[\p{L}\p{M}]+(?:[ .\'-][\p{L}\p{M}]+)*$/u')
    && str_contains(file_get_contents("$root/appointment.html"), 'appointment-cooldown-status')
    && str_contains($js, 'public-appointment-draft-v1')
    && str_contains($js, 'public-appointment-result-v1')
    && str_contains($js, 'public-appointment-cooldown-until-v1')
    && str_contains($js, 'Your saved check-in date has passed. Choose a future check-in date to check room availability.')
    && str_contains($js, 'Choose new future dates to check room availability.')
    && str_contains($appointmentApi, 'INTERVAL 5 MINUTE')
    && str_contains(file_get_contents("$root/manage-appointment.html"), 'id="appointment-lookup-form"')
    && str_contains(file_get_contents("$root/manage-appointment.html"), 'appointment-code')
    && str_contains(file_get_contents("$root/manage-appointment.html"), 'Give the code to Front Desk')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'id="appointment-checkin-lookup"')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'name="appointment_id"')
    && str_contains(file_get_contents("$root/admin/checkin.html"), 'id="appointment-checkin-lookup"')
    && str_contains(file_get_contents("$root/admin/checkin.html"), 'name="appointment_id"')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'id="discount-eligibility-confirmed"')
    && str_contains(file_get_contents("$root/admin/checkin.html"), 'id="discount-eligibility-confirmed"')
    && str_contains($js, 'appointmentRoomIds.includes(Number(room.id))')
    && str_contains($js, 'Rooms are fixed to this appointment.')
    && str_contains($js, 'appointmentScheduledForToday')
    && str_contains(file_get_contents("$root/api/bookings.php"), 'eligibility verified for guest')
    && str_contains(file_get_contents("$root/api/bookings.php"), 'discount_eligibility_confirmed')
    && str_contains($appointmentApi, "'rooms' => \$roomDetails")
    && str_contains(file_get_contents("$root/admin/checkin.html"), 'script.js?v=142')
    && str_contains(file_get_contents("$root/admin/appointments.html"), 'script.js?v=142')
    && str_contains(file_get_contents("$root/manage-appointment.html"), 'script.js?v=142')
    && str_contains(file_get_contents("$root/admin/appointment-view.html"), 'data-page="appointment-view"')
    && str_contains(file_get_contents("$root/admin/appointment-view.html"), 'Back to Appointment Requests')
    && str_contains($js, "'admin:appointment-view'")
    && str_contains($js, 'data-review-appointment="${e(item.id)}"')
    && str_contains($js, 'appointment-view.html?id=${encodeURIComponent(item.id)}')
    && str_contains($appointmentApi, 'AS room_numbers')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'Load appointment code to view data')
    && str_contains(file_get_contents("$root/admin/checkin.html"), 'Load appointment code to view data')
    && !str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'Open public request form')
    && !str_contains(file_get_contents("$root/frontdesk/appointments.html"), 'Open public request form')
    && str_contains($appointmentApi, "api_require_role('admin', 'frontdesk')")
    && str_contains($appointmentApi, 'proposed_room_ids')
    && str_contains(file_get_contents("$root/config/database.php"), "'converted_at' => 'DATETIME DEFAULT NULL'")
    && str_contains($js, "claimGuestAccount', { appointment_code: code }")
    && str_contains($js, "appointment_id: appointmentIdInput?.value || ''")
    && str_contains(file_get_contents("$root/api/bookings.php"), 'verified appointment deposit')
    && str_contains(file_get_contents("$root/admin/appointments.html"), 'id="appointment-requests-body"')
    && str_contains(file_get_contents("$root/frontdesk/appointments.html"), 'id="appointment-changes-body"')
    && str_contains($js, "'admin:appointments'")
    && str_contains($js, "'frontdesk:appointments'")) {
    ok('Public room appointments, code-based changes, Admin and Front Desk review are wired');
} else {
    fail('Appointment request, database, or staff review workflow is incomplete');
}
$adminBillingPage = file_get_contents("$root/admin/billing.html");
$adminBillingTemplate = substr(
    $adminBillingPage,
    strpos($adminBillingPage, '<template id="page-billing">'),
    strpos($adminBillingPage, '</template>') - strpos($adminBillingPage, '<template id="page-billing">')
);
if (str_contains($adminBillingPage, 'id="page-discounts"')
    && str_contains($adminBillingPage, 'id="bill-guest-cards"')
    && str_contains($adminBillingPage, 'id="bill-account-rooms"')
    && str_contains($adminBillingPage, 'id="bill-service-cards"')
    && str_contains($adminBillingPage, 'id="bill-item-cards"')
    && !str_contains($adminBillingTemplate, '<select')
    && str_contains($js, "'admin:billing'")
    && str_contains(file_get_contents("$root/admin/discounts.html"), "searchParams.set('tab', 'discounts')")) {
    ok('Admin Billing and Discounts share a page with card-based guest and room selection');
} else {
    fail('Combined Billing/Discounts route or card-based guest and room selection is incomplete');
}
if (str_contains($schema, "ENUM('admin','frontdesk','guest','kitchen','laundry','housekeeping')")
    && !str_contains($schema, 'staffdelivery')
    && !is_dir("$root/staffdelivery")
    && str_contains(file_get_contents("$root/config/database.php"), "DELETE FROM users WHERE role = 'staffdelivery'")
    && str_contains($schema, "ENUM('available','occupied','maintenance','reserved','cleaning')")
    && str_contains($schema, 'CREATE TABLE housekeeping_tasks')
    && str_contains($schema, 'CREATE TABLE housekeeping_task_items')
    && str_contains($schema, "('Housekeeping Staff', 'Housekeeping', 'Staff', 'housekeeping@gmail.com'")
    && is_file("$root/housekeeping/index.html")
    && is_file("$root/housekeeping/profile.html")
    && str_contains($js, "'housekeeping:dashboard'")
    && str_contains($js, "'admin:housekeeping'")
    && str_contains($js, "'frontdesk:operations'")
    && str_contains(file_get_contents("$root/core/models/BookingModel.php"), 'createForCheckout')
    && str_contains(file_get_contents("$root/core/models/HousekeepingModel.php"), "ORDER BY COUNT(t.id), u.id")
    && str_contains(file_get_contents("$root/core/models/HousekeepingModel.php"), "UPDATE rooms SET status = 'cleaning'")
    && str_contains(file_get_contents("$root/core/models/HousekeepingModel.php"), "condition_status <> 'ok'")) {
    ok('Housekeeping role, checkout inspections, least-loaded assignment, and room status transitions are wired');
} else {
    fail('Housekeeping role or checkout inspection workflow is incomplete');
}
$housekeepingModelSource = file_get_contents("$root/core/models/HousekeepingModel.php");
$databaseConfigSource = file_get_contents("$root/config/database.php");
if (str_contains($schema, 'charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00')
    && str_contains($databaseConfigSource, "ALTER TABLE housekeeping_task_items ADD COLUMN charge_amount DECIMAL(12,2)")
    && str_contains($housekeepingModelSource, "condition === 'ok' ? 0.0")
    && str_contains($housekeepingModelSource, 'Enter a charge amount for every damaged, stained, or missing item.')
    && str_contains($housekeepingModelSource, "VALUES (?, 'other', ?, ?, 1, ?, ?)")
    && str_contains($js, 'data-item-charge="${item.id}"')
    && str_contains($js, 'confirmed charges are added to the guest')) {
    ok('Housekeeping inspection findings require interactive charges and post them to the checked-out stay bill');
} else {
    fail('Housekeeping damage charges or schema migration are incomplete');
}
if (str_contains($schema, 'stained_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00')
    && str_contains($schema, "('Pillowcases', 100.00, 175.00, 250.00)")
    && str_contains($databaseConfigSource, 'WHEN \'Blanket\' THEN 999.00')
    && str_contains($housekeepingModelSource, 'AS damaged_charge')
    && str_contains($js, 'data-charge-stained=')
    && str_contains($js, 'suggestedCharge > 0 ? String(suggestedCharge) :')
    && str_contains(file_get_contents("$root/housekeeping/index.html"), 'script.js?v=131')) {
    ok('Housekeeping checklist auto-fills condition-specific, database-seeded estimate charges');
} else {
    fail('Housekeeping condition pricing data or interactive charge defaults are incomplete');
}
$bookingModelSource = file_get_contents("$root/core/models/BookingModel.php");
if (str_contains($bookingModelSource, 'function checkedInForGuest(')
    && str_contains($bookingModelSource, "b.status = 'checked_in'")
    && str_contains($bookingModelSource, 'function checkedInStaysForGuest(')
    && str_contains(file_get_contents("$root/api/requests.php"), 'checkedInStaysForGuest($guestId)')
    && str_contains(file_get_contents("$root/core/models/GuestRequestModel.php"), "id = ? AND guest_id = ? AND status = 'checked_in'")
    && str_contains($js, "const canRequest = booking?.status === 'checked_in'")) {
    ok('Guests can request services only from checked-in rooms while retaining account history');
} else {
    fail('Service request eligibility is not restricted to checked-in guest stays');
}
$roomModelSource = file_get_contents("$root/core/models/RoomModel.php");
if (str_contains($roomModelSource, "pending_task.status <> 'completed'")
    && str_contains($roomModelSource, 'AS housekeeping_pending')
    && str_contains($js, 'Number(room.housekeeping_pending) === 1')
    && str_contains($js, 'Complete the outstanding housekeeping inspection before marking this room available.')
    && str_contains($js, "table.querySelectorAll('[data-save-room-status]').forEach((button) => {")) {
    ok('Room availability controls explain pending housekeeping inspections and save handlers bind once');
} else {
    fail('Room availability controls do not align with housekeeping inspection restrictions');
}
foreach (['renderRoomTypes', 'renderAdditionalItems', 'renderGuestRequests', 'renderRoomManagement', 'getRoomManagement', 'form-stack'] as $needle) {
    if (str_contains($js, $needle)) {
        ok("script.js includes: $needle");
    } else {
        fail("script.js missing: $needle");
    }
}
if (str_contains(file_get_contents("$root/admin/categories.html"), "manage-hotel-services.html")
    && !str_contains($js, "'frontdesk:categories'")
    && !str_contains($js, "'admin:categories'")
    && str_contains($schema, "('Food', 'service'")
    && str_contains($schema, "('Laundry', 'service'")
    && !is_file("$root/sql/ensure_service_categories.sql")
    && !is_file("$root/sql/ensure_housekeeping.sql")
    && count(glob("$root/sql/*.sql")) === 1
    && !str_contains($schema, "('Restaurant', 'service'")
    && !str_contains($schema, "('Spa & Wellness', 'service'")
    && str_contains(file_get_contents("$root/frontdesk/rooms.html"), 'id="service-category-cards"')
    && str_contains(file_get_contents("$root/admin/rooms.html"), 'id="service-category-cards"')
    && str_contains($js, "food: entries.filter((entry) => entry.kind === 'food').length")
    && str_contains($js, "laundry: entries.filter((entry) => entry.kind === 'laundry').length")
    && str_contains($js, "extra: entries.filter((entry) => entry.kind === 'extra').length")
    && str_contains(file_get_contents("$root/admin/rooms.html"), 'value="food"')
    && str_contains(file_get_contents("$root/admin/rooms.html"), 'value="laundry"')
    && str_contains(file_get_contents("$root/admin/rooms.html"), 'value="extra"')
    && str_contains(file_get_contents("$root/admin/manage-hotel-services.html"), "searchParams.set('tab', 'services')")
    && str_contains(file_get_contents("$root/frontdesk/manage-hotel-services.html"), "searchParams.set('tab', 'services')")
    && str_contains($js, "isCurrent: () => currentVersion === renderVersion")
    && str_contains($js, "document.body.dataset.role === 'admin'")) {
    ok('Service categories are consolidated into the single SQL file and merged Admin/Front Desk catalog pages');
} else {
    fail('Integrated category summary or fixed three-category catalog is incomplete');
}
if (str_contains($js, "name: 'Items'")
    && str_contains($js, "extra: 'Items'")) {
    ok('Catalog category labels are normalized across the combined page');
} else {
    fail('Combined service catalog category labels are incomplete');
}
if (str_contains(file_get_contents("$root/core/models/PaymentRequestModel.php"), 'function createForGuest(')
    && str_contains(file_get_contents("$root/core/models/PaymentRequestModel.php"), 'function complete(')
    && str_contains(file_get_contents("$root/core/models/PaymentRequestModel.php"), 'function cancel(')
    && str_contains(file_get_contents("$root/api/payments.php"), 'function createGuestPaymentRequest(')
    && str_contains(file_get_contents("$root/api/payments.php"), 'function completePaymentRequest(')
    && !str_contains(file_get_contents("$root/guest/bill.html"), 'guest-payment-request-form')
    && !str_contains(file_get_contents("$root/guest/bill.html"), 'Payment Requests')
    && !str_contains(file_get_contents("$root/frontdesk/payments.html"), 'Guest Payment Requests')
    && !str_contains(file_get_contents("$root/frontdesk/payments.html"), 'payment-requests-tbody')
    && !str_contains($js, 'payment-requests-tbody')
    && str_contains($schema, 'CREATE TABLE payment_requests')
    && str_contains($schema, 'payment_request_id INT DEFAULT NULL')) {
    ok('Guest payment requests persist while the Front Desk payment page omits the separate request queue');
} else {
    fail('Guest payment request storage or Front Desk payment page cleanup is incomplete');
}
$paymentPage = file_get_contents("$root/frontdesk/payments.html");
$paymentApi = file_get_contents("$root/api/payments.php");
$paymentModelSource = file_get_contents("$root/core/models/PaymentModel.php");
if (str_contains($paymentPage, 'id="payment-account-select"')
    && str_contains($paymentPage, 'id="bill-breakdown"')
    && !str_contains($paymentPage, 'payment-bill-select')
    && !str_contains($paymentPage, 'payment-distribute-account')
    && str_contains($paymentPage, 'id="payment-ref-wrap" hidden')
    && str_contains($paymentPage, 'name="reference_no"')
    && str_contains($paymentApi, 'function getAccountPaymentDetails(')
    && str_contains($paymentApi, '$bills->accountRecords($bookingId, $billId)')
    && str_contains($paymentModelSource, 'recordAccountPayment(')
    && str_contains($paymentModelSource, "if (\$method !== 'cash' && \$reference === '')")
    && str_contains($paymentModelSource, 'setCashReceiptReference(')
    && str_contains($paymentModelSource, 'OR-CASH-%s-%06d')
    && str_contains($schema, 'reference_no VARCHAR(80) NOT NULL')
    && str_contains($js, "'getAccountPaymentDetails'")
    && str_contains($js, 'All charges and balances')
    && str_contains($js, 'refInput.required = requiresReference')
    && str_contains($js, 'refWrap.hidden = !requiresReference')
    && str_contains($js, "payload.method !== 'cash' && !String(payload.reference_no || '').trim()")
    && str_contains(file_get_contents("$root/api/bookings.php"), "\$method !== 'cash' && trim((string) (\$data['reference_no'] ?? '')) === ''")
    && str_contains($js, "apiPost('payments.php', 'recordAccountPayment', payload)")) {
    ok('Payments list all account charges; cash receipts auto-generate and transfer references are required');
} else {
    fail('Account payment distribution or method-specific receipt/reference handling is incomplete');
}
if (str_contains($schema, "photo VARCHAR(255) DEFAULT NULL COMMENT 'Catalog photo")
    && str_contains(file_get_contents("$root/core/models/GuestRequestModel.php"), 'with_photo_urls')
    && str_contains($js, 'request.photo_url')
    && str_contains(file_get_contents("$root/admin/rooms.html"), 'type="file" name="photo"')) {
    ok('Catalog and guest-request images persist and are shown across request pages');
} else {
    fail('Catalog image upload or request photo persistence/display is incomplete');
}
$guestRequestModel = file_get_contents("$root/core/models/GuestRequestModel.php");
$requestApi = file_get_contents("$root/api/requests.php");
if (str_contains($schema, "ENUM('pending','preparing','ready','to_be_delivered','delivered','not_delivered','cancelled')")
    && str_contains($schema, 'bill_item_id INT DEFAULT NULL')
    && str_contains($guestRequestModel, '$this->chargeToBill($request, $actorId)')
    && str_contains($guestRequestModel, "['preparing', 'ready', 'to_be_delivered']")
    && str_contains($guestRequestModel, 'function confirmDelivered(')
    && str_contains($guestRequestModel, 'function reportNotDelivered(')
    && str_contains($requestApi, "'kitchen' => ['food']")
    && str_contains($requestApi, "'laundry' => ['extra', 'laundry']")
    && str_contains($requestApi, 'function assignRequest(')
    && str_contains($requestApi, 'function confirmDelivered(')
    && str_contains($requestApi, 'function reportNotDelivered(')
    && str_contains(file_get_contents("$root/config/database.php"), "UPDATE guest_requests SET status = 'to_be_delivered' WHERE status = 'ready_for_delivery'")) {
    ok('Guest requests charge immediately, route to Kitchen or assigned staff, and support customer delivery confirmation');
} else {
    fail('Guest request billing, assignment, status lifecycle, or delivery confirmation is incomplete');
}
$bookingApi = file_get_contents("$root/api/bookings.php");
$bookingModelSource = file_get_contents("$root/core/models/BookingModel.php");
$paymentModelSource = file_get_contents("$root/core/models/PaymentModel.php");
if (str_contains($bookingApi, '$projectedTotal < 0.01')
    && str_contains($bookingApi, '$projectedPayment < 0.01')
    && str_contains($bookingModelSource, '$roomCharge < 0.01')
    && str_contains($bookingModelSource, 'Cannot check in or issue an invoice')
    && str_contains($paymentModelSource, '$amount = round((float) ($data[\'amount\'] ?? 0), 2)')
    && str_contains($paymentModelSource, '$amount < 0.01')
    && str_contains($js, 'Payment amount must be at least 0.01.')
    && str_contains($js, 'Zero-amount invoices and payments are not allowed.')
    && str_contains($js, 'This invoice cannot be exported until its charges total at least 0.01.')) {
    ok('Check-in, payment, receipts, and invoice exports enforce a minimum amount of 0.01');
} else {
    fail('Zero-value check-in, payment, receipt, or invoice safeguards are incomplete');
}
if (str_contains($schema, "('Bottled Water', 1, 'Food'")
    && str_contains($schema, "('Express Laundry', 2, 'Laundry'")
    && str_contains($schema, "('Dental Care Kit', 100.00")
    && str_contains($js, "['Bottled Water', 45")
    && str_contains($js, "['Delicate Garment Wash', 300")) {
    ok('Food, laundry, and guest comfort supplies are seeded and available as quick picks');
} else {
    fail('Food, laundry, and guest comfort catalog entries are incomplete');
}
$frontdeskReports = file_get_contents("$root/frontdesk/reports.html");
$reportsApi = file_get_contents("$root/api/reports.php");
$reportsExportApi = file_get_contents("$root/api/reports_export.php");
if (str_contains($frontdeskReports, 'data-role="frontdesk" data-page="reports"')
    && str_contains($frontdeskReports, 'id="export-excel"')
    && str_contains($frontdeskReports, 'id="export-pdf"')
    && str_contains($js, "'frontdesk:reports':")
    && str_contains($js, "['reports', 'Reports', 'reports.html']")
    && str_contains($reportsApi, "api_require_role('admin', 'frontdesk')")
    && str_contains($reportsExportApi, "['admin', 'frontdesk']")
    && str_contains($reportsExportApi, "export_report_excel(\$report, \$stamp)")
    && str_contains($reportsExportApi, "export_report_pdf(\$report, \$stamp)")
    && str_contains($reportsExportApi, 'application/vnd.ms-excel')
    && str_contains($reportsExportApi, "header('Content-Type: application/pdf')")
    && str_contains($reportsExportApi, 'BILLING & COLLECTIONS REPORT')
    && str_contains($reportsExportApi, 'Bills by status')
    && str_contains($reportsExportApi, 'Daily collections')
    && str_contains($reportsExportApi, 'Service revenue')
    && str_contains($reportsExportApi, 'PdfDocument::fromStreams($pages)')
    && str_contains(file_get_contents("$root/core/PdfDocument.php"), '/Helvetica-Bold')
    && str_contains(file_get_contents("$root/frontdesk/index.html"), 'href="reports.html"')) {
    ok('Front Desk has reports, authorized exports, and a branded paginated PDF layout');
} else {
    fail('Front Desk report page or export authorization is incomplete');
}
if (!is_file("$root/frontdesk/balances.html")
    && !str_contains($js, "'frontdesk:balances':")
    && !str_contains(file_get_contents("$root/frontdesk/payments.html"), 'balances.html')
    && !str_contains($js, 'function renderBalances(')
    && !str_contains(file_get_contents("$root/api/bills.php"), 'function getOutstanding(')) {
    ok('Obsolete Front Desk Balances page and links are removed');
} else {
    fail('Front Desk Balances page is still present or linked');
}
$roomStatusPages = [
    file_get_contents("$root/admin/room-management.html"),
    file_get_contents("$root/frontdesk/room-management.html"),
];
if (count(array_filter($roomStatusPages, static fn(string $page): bool =>
    str_contains($page, 'id="room-status-summary"')
    && str_contains($page, 'id="room-status-filters"')
)) === 2
    && str_contains($js, 'localStamp().slice(0, 10)')
    && str_contains($js, "'checked_in'")
    && str_contains($js, 'data-room-status-filter="${key}"')) {
    ok('Admin and Front Desk room overview shows date-based status counts and filters');
} else {
    fail('Admin or Front Desk room overview is missing status summary/filter support');
}
if (str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'id="booking-create"')) {
    ok('Check-in page includes the stay booking form');
} else {
    fail('Check-in page is missing the stay booking form');
}
$billing = file_get_contents("$root/frontdesk/billing.html");
if (str_contains($js, 'function fmtBillingDateTime(value)')
    && str_contains($js, 'Charged: ${fmtBillingDateTime(it.created_at)}')
    && str_contains($billing, 'id="bill-created-at"')) {
    ok('Guest Billing displays readable account and charge timestamps');
} else {
    fail('Guest Billing timestamp display is incomplete');
}
if (str_contains(file_get_contents("$root/core/models/BillModel.php"), 'function accountHistory()')
    && str_contains(file_get_contents("$root/api/bills.php"), 'function getGuestAccount(')
    && str_contains($js, 'async function renderGuestAccount(main)')
    && str_contains($billing, 'Total charges')
    && str_contains($billing, '>Paid<')
    && str_contains($billing, '>Balance<')) {
    ok('Guest Billing History groups stay bills and displays account totals');
} else {
    fail('Guest Billing account grouping / payment totals are incomplete');
}
if (str_contains(file_get_contents("$root/api/payments.php"), 'function recordAccountPayment(')
    && str_contains(file_get_contents("$root/core/models/PaymentModel.php"), 'function recordAccountPayment(')
    && str_contains(file_get_contents("$root/frontdesk/guest-account.html"), 'id="account-pay-full"')
    && str_contains($js, "'recordAccountPayment'")) {
    ok('Guest Account supports full or distributed payments');
} else {
    fail('Guest Account distributed payment flow is incomplete');
}
if (str_contains(file_get_contents("$root/core/models/PaymentModel.php"), 'function forBills(')
    && str_contains(file_get_contents("$root/api/bills.php"), "'payment_history' =>")
    && str_contains(file_get_contents("$root/frontdesk/guest-account.html"), 'id="account-payment-history-tbody"')
    && str_contains(file_get_contents("$root/frontdesk/guest-account.html"), '<th>Receipt</th>')
    && str_contains($js, 'account.payment_history')
    && str_contains($js, 'paymentReceiptExportUrl')) {
    ok('Guest Account displays payment history and exports receipts across all room bills');
} else {
    fail('Guest Account payment history or PDF receipt export is incomplete');
}
if (str_contains(file_get_contents("$root/core/models/BookingModel.php"), 'function extensionHistory(')
    && str_contains(file_get_contents("$root/api/bookings.php"), "'extension_history' =>")
    && str_contains(file_get_contents("$root/frontdesk/room-management.html"), 'id="extend-history-tbody"')
    && str_contains(file_get_contents("$root/admin/room-management.html"), 'id="extend-history-tbody"')
    && str_contains($js, 'extensionHistory.filter')
    && str_contains($js, 'type="date" class="extend-at"')
    && str_contains(file_get_contents("$root/core/models/BookingModel.php"), "DateTime::createFromFormat('!Y-m-d H:i:s'")
    && str_contains(file_get_contents("$root/core/models/BookingModel.php"), 'The new check-out cannot be in the past.')
    && str_contains(file_get_contents("$root/frontdesk/room-management.html"), 'type="date">')
    && str_contains(file_get_contents("$root/frontdesk/extend-stay.html"), "set('tab', 'extend-stay')")) {
    ok('Merged room-management pages include stay extension history and preserve old URLs');
} else {
    fail('Merged Extend Stay pages or compatibility redirects are incomplete');
}
$guestBillsApi = file_get_contents("$root/api/bills.php");
$guestBillMethodStart = strpos($guestBillsApi, 'public function getGuestBill');
$guestBillMethodEnd = strpos($guestBillsApi, 'public function getGuestInvoices', $guestBillMethodStart);
$guestBillMethod = substr($guestBillsApi, $guestBillMethodStart, $guestBillMethodEnd - $guestBillMethodStart);
if (str_contains($guestBillMethod, '$bookingIds = array_values(array_unique(array_filter(array_map(')
    && str_contains($guestBillMethod, "'extension_history' => (new BookingModel(\$conn))->extensionHistory(\$bookingIds)")
    && str_contains($guestBillMethod, "'transfer_history' => (new RoomTransferModel(\$conn))->forBookings(\$bookingIds)")
    && str_contains(file_get_contents("$root/core/models/RoomTransferModel.php"), 'function forBookings(')
    && str_contains(file_get_contents("$root/frontdesk/guest-account.html"), 'id="account-stay-changes-tbody"')
    && str_contains(file_get_contents("$root/guest/bill.html"), 'id="guest-account-stay-changes"')
    && str_contains($js, 'function renderStayChangeHistory(')
    && str_contains($js, 'room.check_out, room.check_out_time')) {
    ok('Front Desk and guest accounts show current stay dates plus room extension and transfer history');
} else {
    fail('Guest accounts are missing current stay dates or extension/transfer history');
}
if (str_contains(file_get_contents("$root/frontdesk/payments.html"), 'id="payment-account-select"')
    && str_contains(file_get_contents("$root/api/payments.php"), "'accounts' =>")
    && str_contains($js, "'recordAccountPayment'")) {
    ok('Payment entry is account-aware and supports distributed payments');
} else {
    fail('Payment account selection/distribution is incomplete');
}
$adminCheckout = file_get_contents("$root/admin/checkout.html");
$frontdeskCheckout = file_get_contents("$root/frontdesk/checkout.html");
if (str_contains(file_get_contents("$root/core/models/BookingModel.php"), 'AS account_balance')
    && str_contains(file_get_contents("$root/core/models/BookingModel.php"), 'FOR UPDATE')
    && str_contains(file_get_contents("$root/core/models/BookingModel.php"), 'guest account still has an outstanding balance')
    && str_contains(file_get_contents("$root/api/bookings.php"), "api_require_role('frontdesk', 'admin')")
    && str_contains($js, 'Account balance: <strong>${money(accountBalance)}</strong>')
    && str_contains($js, 'Clear the guest account balance before check-out.')
    && str_contains(file_get_contents("$root/admin/checkin.html"), 'Any outstanding balance across the guest')
    && str_contains(file_get_contents("$root/frontdesk/checkin.html"), 'Any outstanding balance across the guest')) {
    ok('Admin and Front Desk show account balances and block checkout until linked bills are paid');
} else {
    fail('Checkout balance restriction is not aligned across the server and role pages');
}
$checkinAdmin = file_get_contents("$root/admin/checkin.html");
$checkinFrontdesk = file_get_contents("$root/frontdesk/checkin.html");
$frontdeskPayments = file_get_contents("$root/frontdesk/payments.html");
$frontdeskGuestAccount = file_get_contents("$root/frontdesk/guest-account.html");
$guestBill = file_get_contents("$root/guest/bill.html");
if (str_contains($js, "const DEMO_GCASH_NUMBER = '0953 385 2421'")
    && str_contains($js, 'function paymentDestinationMarkup(method)')
    && str_contains($checkinAdmin, 'id="billing-payment-destination"')
    && str_contains($checkinFrontdesk, 'id="billing-payment-destination"')
    && str_contains($frontdeskPayments, 'id="payment-destination"')
    && str_contains($frontdeskGuestAccount, 'id="account-payment-destination"')
    && !str_contains($guestBill, 'id="guest-payment-request-destination"')
    && str_contains($js, 'Sample details for testing only. Verify the hotel')) {
    ok('Demo GCash and bank transfer destinations are shown consistently across payment flows');
} else {
    fail('Payment destination details are missing or inconsistent across check-in and account payment flows');
}
if (str_contains(file_get_contents("$root/frontdesk/payments.html"), 'id="page-invoices"')
    && str_contains(file_get_contents("$root/frontdesk/invoices.html"), "set('tab', 'invoices')")
    && str_contains(file_get_contents("$root/api/bills.php"), "'account_history' =>")
    && str_contains($js, 'paymentReceiptExportUrl')
    && str_contains($js, 'Export PDF')) {
    ok('Invoices show account billing history and exportable payment receipts');
} else {
    fail('Invoice account history or PDF receipt export is incomplete');
}
$paymentApi = file_get_contents("$root/api/payments.php");
$paymentModel = file_get_contents("$root/core/models/PaymentModel.php");
$bookingApi = file_get_contents("$root/api/bookings.php");
if (str_contains($paymentModel, 'public array $lastPaymentIds')
    && str_contains($paymentApi, "'payment_id' =>")
    && str_contains($paymentApi, "'payment_ids' =>")
    && str_contains($bookingApi, '\'payment_ids\' => $paymentIds')
    && str_contains($js, 'paymentReceiptExportUrl(payment.id)')
    && !str_contains($js, 'function consumePaymentReceiptIds()')
    && !str_contains($js, 'function exportPaymentReceipts(')
    && !str_contains($js, 'exportPaymentReceipts(receiptPayments.map')
    && !str_contains($js, 'receiptQuery')
    && !str_contains($js, 'payment_ids=${encodeURIComponent(paymentIds.join')
    && str_contains($js, 'function invoiceExportUrl(billId)')
    && str_contains($js, 'invoiceExportUrl(bill.id)')
    && str_contains($js, 'invoiceExportUrl(d.bill.id)')) {
    ok('Payment recording keeps receipts available for manual PDF export without automatic downloads');
} else {
    fail('Automatic receipt export remains or manual receipt export is unavailable');
}
if (str_contains($paymentModel, 'function forGuest(')
    && str_contains(file_get_contents("$root/api/bills.php"), '\'payments\' => $bill ? (new PaymentModel($conn))->forBills($accountBillIds) : []')
    && str_contains($js, 'View Invoice / Receipts')
    && str_contains(file_get_contents("$root/guest/payments.html"), '<th>Receipt</th>')
    && str_contains($js, 'paymentReceiptExportUrl')
    && str_contains($js, 'Export PDF')) {
    ok('Guest portal can view invoices and export payment receipt PDFs');
} else {
    fail('Guest invoice or payment receipt history is incomplete');
}
if (str_contains($paymentModel, 'function receipts(')
    && str_contains(file_get_contents("$root/api/payment_receipt_export.php"), "api_require_role('admin', 'frontdesk', 'guest')")
    && str_contains(file_get_contents("$root/api/payment_receipt_export.php"), 'Content-Disposition: attachment')
    && str_contains(file_get_contents("$root/api/payment_receipt_export.php"), 'PdfDocument::fromStreams($streams)')
    && str_contains(file_get_contents("$root/api/payment_receipt_export.php"), 'count($paymentIdParts) > 20')
    && str_contains(file_get_contents("$root/frontdesk/payments.html"), 'Downloads folder')
    && str_contains(file_get_contents("$root/guest/payments.html"), 'Downloads folder')) {
    ok('Front Desk and guest payment pages export authorized, designed PDF receipts');
} else {
    fail('Payment receipt PDF downloads or authorization are incomplete');
}
if (str_contains(file_get_contents("$root/api/invoice_export.php"), "api_require_role('admin', 'frontdesk', 'guest')")
    && str_contains(file_get_contents("$root/api/invoice_export.php"), 'GUEST INVOICE')
    && str_contains(file_get_contents("$root/api/invoice_export.php"), 'findWithGuest($billId)')
    && str_contains(file_get_contents("$root/api/invoice_export.php"), "Content-Disposition: attachment")
    && str_contains(file_get_contents("$root/api/invoice_export.php"), 'PdfDocument::fromStreams($streams)')
    && str_contains($js, 'invoiceExportUrl(bill.id)')
    && str_contains($js, 'invoiceExportUrl(d.bill.id)')) {
    ok('Admin, Front Desk, and guest invoice pages export branded, authorized PDF invoices');
} else {
    fail('Invoice PDF export action, layout, or access control is incomplete');
}
if (str_contains($js, 'function quantityLabel(value)')
    && str_contains($js, 'maximumFractionDigits: 6')
    && substr_count($js, 'e(quantityLabel(') >= 6
    && str_contains(file_get_contents("$root/api/invoice_export.php"), "number_format((float) \$item['quantity'], 6, '.', '')")) {
    ok('Whole quantities are displayed without trailing zeros while fractional quantities retain precision');
} else {
    fail('Quantity formatting is inconsistent across the UI and invoice PDF');
}
if (str_contains($js, "not_delivered: 'danger'")
    && str_contains($js, 'data-report-not-delivered')
    && str_contains($js, 'roomSel.innerHTML = activeBookings.map')
    && str_contains(file_get_contents("$root/api/requests.php"), 'public function reportNotDelivered(')
    && str_contains(file_get_contents("$root/core/models/GuestRequestModel.php"), 'gr.item_unavailable_at IS NULL')
    && str_contains(file_get_contents("$root/sql/schema.sql"), "'not_delivered'")) {
    ok('Room-targeted requests, not-delivered feedback, and active fulfillment queues are wired');
} else {
    fail('Guest request room selection or delivery feedback is incomplete');
}
if (str_contains(file_get_contents("$root/core/models/BillModel.php"), "CONCAT('guest:', b.guest_id)")
    && str_contains(file_get_contents("$root/core/models/BillModel.php"), 'WHERE bk.guest_id = ? AND bk.status <>')
    && str_contains(file_get_contents("$root/core/models/BillModel.php"), 'COUNT(DISTINCT bk.id) AS stay_count')
    && !str_contains(file_get_contents("$root/guest/bill.html"), 'guest-payment-request-panel')
    && !str_contains(file_get_contents("$root/guest/bill.html"), 'guest-payment-requests')) {
    ok('Guest account history groups stays by guest and guest bill payment-request panels are removed');
} else {
    fail('Guest account consolidation or guest bill payment-request removal is incomplete');
}
if (str_contains(file_get_contents("$root/assets/css/style.css"), '.numeric-cell')
    && str_contains(file_get_contents("$root/assets/css/style.css"), '.billing-history-table')
    && str_contains(file_get_contents("$root/frontdesk/guest-account.html"), '<th>Requested Services</th>')
    && str_contains(file_get_contents("$root/guest/bill.html"), '<th>Requested Services</th>')) {
    ok('Account amounts stay legible and each room account lists its requested services');
} else {
    fail('Account number layout or per-room requested-service lists are incomplete');
}
if (str_contains(file_get_contents("$root/admin/billing.html"), 'id="page-discounts"')
    && str_contains(file_get_contents("$root/admin/discounts.html"), "set('tab', 'discounts')")
    && str_contains(file_get_contents("$root/admin/billing.html"), 'id="disc-create"')
    && str_contains(file_get_contents("$root/admin/billing.html"), 'id="disc-tbody"')
    && str_contains($js, 'function bindCreateDiscountForm(')
    && str_contains($js, 'bindCreateDiscountForm(flash)')
    && str_contains($js, "'createDiscount'")
    && str_contains($js, "'updateDiscount'")
    && str_contains($js, "'deleteDiscount'")
    && str_contains(file_get_contents("$root/core/models/DiscountModel.php"), 'function updateSystem(')) {
    ok('Discount page lists details and supports custom add/edit/delete with protected system discounts');
} else {
    fail('Discount details or CRUD controls are incomplete');
}
if (!str_contains($js, 'clarifications.php') && str_contains($js, 'room_types.php')) {
    ok('script.js uses room_types API and has no clarifications');
} else {
    fail('script.js room_types/clarifications alignment failed');
}

echo "\n=== Results: $passed passed, " . count($errors) . " failed ===\n";
exit(count($errors) > 0 ? 1 : 0);
