<?php
declare(strict_types=1);

// Hotel local time (Philippines) so today's date, check-in times and transfers match the front desk clock.
date_default_timezone_set('Asia/Manila');

$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'hotelbillingsystem';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function align_database_schema(mysqli $conn): void
{
    static $aligned = false;
    if ($aligned) {
        return;
    }
    $aligned = true;

    if ($conn->query("SHOW TABLES LIKE 'users'")->fetch_assoc()) {
        $demoGuest = $conn->prepare(
            "SELECT id FROM users WHERE email = 'guest@gmail.com' AND role = 'guest' LIMIT 1"
        );
        $demoGuest->execute();
        $demoGuestRow = $demoGuest->get_result()->fetch_assoc();
        if ($demoGuestRow) {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS system_migrations (
                    migration_key VARCHAR(120) PRIMARY KEY,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB"
            );
            $migrationKey = 'restore_seeded_guest_demo_login';
            $migration = $conn->prepare('INSERT IGNORE INTO system_migrations (migration_key) VALUES (?)');
            $migration->bind_param('s', $migrationKey);
            $migration->execute();
            if ($migration->affected_rows === 1) {
                $guestPasswordHash = password_hash('guest123', PASSWORD_DEFAULT);
                $updateGuestPassword = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                $updateGuestPassword->bind_param('si', $guestPasswordHash, $demoGuestRow['id']);
                $updateGuestPassword->execute();
            }
        }
    }

    foreach (['users', 'guests'] as $nameTable) {
        if (!$conn->query("SHOW TABLES LIKE '{$nameTable}'")->fetch_assoc()) {
            continue;
        }
        foreach (['first_name', 'last_name'] as $nameColumn) {
            $column = $conn->query("SHOW COLUMNS FROM {$nameTable} LIKE '{$nameColumn}'")->fetch_assoc();
            if (!$column) {
                $conn->query("ALTER TABLE {$nameTable} ADD COLUMN {$nameColumn} VARCHAR(120) NOT NULL DEFAULT '' AFTER full_name");
            } elseif (strtolower((string) $column['Type']) !== 'varchar(120)' || $column['Null'] !== 'NO') {
                $conn->query("ALTER TABLE {$nameTable} MODIFY COLUMN {$nameColumn} VARCHAR(120) NOT NULL DEFAULT ''");
            }
        }
        $conn->query(
            "UPDATE {$nameTable}
             SET first_name = TRIM(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)),
                 last_name = CASE
                     WHEN LOCATE(' ', TRIM(full_name)) > 0
                     THEN TRIM(SUBSTRING(TRIM(full_name), LOCATE(' ', TRIM(full_name)) + 1))
                     ELSE ''
                 END
             WHERE first_name = '' AND TRIM(full_name) <> ''"
        );
    }

    foreach (['check_in_time' => '15:00:00', 'check_out_time' => '14:00:00'] as $timeColumn => $timeDefault) {
        if (!$conn->query("SHOW COLUMNS FROM bookings LIKE '{$timeColumn}'")->fetch_assoc()) {
            $after = $timeColumn === 'check_in_time' ? 'check_out' : 'check_in_time';
            $conn->query("ALTER TABLE bookings ADD COLUMN {$timeColumn} TIME NOT NULL DEFAULT '{$timeDefault}' AFTER {$after}");
        }
        $conn->query("ALTER TABLE bookings ALTER COLUMN {$timeColumn} SET DEFAULT '{$timeDefault}'");
    }
    $conn->query(
        "UPDATE bookings
         SET check_in_time = CASE
                 WHEN check_in_time < '15:00:00' OR check_in_time >= '16:00:00' THEN '15:00:00'
                 ELSE MAKETIME(15, MINUTE(check_in_time), 0)
             END,
             check_out_time = '14:00:00'
         WHERE status IN ('reserved','checked_in') AND check_out >= CURDATE()
           AND (check_in_time < '15:00:00' OR check_in_time >= '16:00:00'
                OR SECOND(check_in_time) <> 0 OR check_out_time <> '14:00:00')"
    );

    if (!$conn->query("SHOW COLUMNS FROM bookings LIKE 'stay_group'")->fetch_assoc()) {
        $conn->query('ALTER TABLE bookings ADD COLUMN stay_group INT DEFAULT NULL AFTER status');
    }

    if ($conn->query("SHOW TABLES LIKE 'discounts'")->fetch_assoc()
        && (int) $conn->query('SELECT COUNT(*) FROM discounts')->fetch_row()[0] > 0
        && !$conn->query("SELECT id FROM discounts WHERE code = 'employee'")->fetch_assoc()) {
        $conn->query("INSERT INTO discounts (name, code, discount_type, value, description, status) VALUES ('Employee', 'employee', 'percent', 10.00, 'Hotel employee discount', 'active')");
        $conn->query("UPDATE discounts SET value = 10.00 WHERE code IN ('senior_citizen','pwd')");
    }
    if ($conn->query("SHOW TABLES LIKE 'discounts'")->fetch_assoc()) {
        $conn->query("DELETE FROM discounts WHERE code IS NULL AND name IN ('Promo Weekend','Corporate Flat')");
    }

    $payMethod = $conn->query("SHOW COLUMNS FROM payments LIKE 'method'")->fetch_assoc();
    if ($payMethod && !str_contains($payMethod['Type'], "'bank'")) {
        $conn->query("ALTER TABLE payments MODIFY method ENUM('cash','online','bank') NOT NULL DEFAULT 'cash'");
    }
    if ($conn->query("SHOW TABLES LIKE 'payments'")->fetch_assoc()) {
        $conn->query(
            "UPDATE payments SET reference_no = CONCAT('OR-LEGACY-', id)
             WHERE reference_no IS NULL OR TRIM(reference_no) = ''"
        );
        $referenceColumn = $conn->query("SHOW COLUMNS FROM payments LIKE 'reference_no'")->fetch_assoc();
        if ($referenceColumn && $referenceColumn['Null'] === 'YES') {
            $conn->query('ALTER TABLE payments MODIFY reference_no VARCHAR(80) NOT NULL');
        }
    }

    $roomStatus = $conn->query("SHOW COLUMNS FROM rooms LIKE 'status'")->fetch_assoc();
    if ($roomStatus && !str_contains((string) $roomStatus['Type'], "'cleaning'")) {
        $conn->query("ALTER TABLE rooms MODIFY status ENUM('available','occupied','maintenance','reserved','cleaning') NOT NULL DEFAULT 'available'");
    }

    if (!$conn->query("SHOW TABLES LIKE 'room_types'")->fetch_assoc()) {
        $conn->query(
            "CREATE TABLE room_types (
              id INT AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(100) NOT NULL UNIQUE,
              description TEXT DEFAULT NULL,
              base_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              room_features TEXT DEFAULT NULL,
              status ENUM('active','inactive') NOT NULL DEFAULT 'active',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
        $conn->query(
            "INSERT IGNORE INTO room_types (name, description, base_rate, room_features, status)
             SELECT name, description, 0, NULL, 'active'
             FROM categories WHERE category_type = 'room'"
        );
        if (!(int) $conn->query('SELECT COUNT(*) c FROM room_types')->fetch_assoc()['c']) {
            $conn->query(
                "INSERT INTO room_types (name, description, base_rate, room_features, status) VALUES
                 ('Standard', 'Standard basic rooms', 2500.00, 'WiFi, TV, AC', 'active'),
                 ('Deluxe', 'Upgraded rooms with mini-bar', 4200.00, 'WiFi, TV, AC, Mini Bar', 'active'),
                 ('Suite', 'Premium luxury suites', 7800.00, 'WiFi, TV, AC, Jacuzzi, Living Area', 'active')"
            );
        }
    }

    if (!$conn->query("SHOW COLUMNS FROM rooms LIKE 'room_type_id'")->fetch_assoc()) {
        $conn->query('ALTER TABLE rooms ADD COLUMN room_type_id INT DEFAULT NULL AFTER room_number');
        $conn->query(
            "UPDATE rooms r
             LEFT JOIN room_types rt ON rt.name = r.room_type
             SET r.room_type_id = rt.id
             WHERE r.room_type_id IS NULL"
        );
        $conn->query(
            "UPDATE rooms r
             LEFT JOIN categories c ON c.id = r.category_id AND c.category_type = 'room'
             LEFT JOIN room_types rt ON rt.name = c.name
             SET r.room_type_id = rt.id
             WHERE r.room_type_id IS NULL AND rt.id IS NOT NULL"
        );
    }

    if (!$conn->query("SHOW TABLES LIKE 'categories'")->fetch_assoc()) {
        $conn->query(
            "CREATE TABLE categories (
              id INT AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(100) NOT NULL UNIQUE,
              category_type ENUM('room', 'service') NOT NULL,
              description TEXT DEFAULT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
    }

    if (!$conn->query("SHOW TABLES LIKE 'additional_item_types'")->fetch_assoc()) {
        $conn->query(
            "CREATE TABLE additional_item_types (
              id INT AUTO_INCREMENT PRIMARY KEY,
              item_name VARCHAR(120) NOT NULL UNIQUE,
              unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              description TEXT DEFAULT NULL,
              status ENUM('active','inactive') NOT NULL DEFAULT 'active',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
    }

    if (!$conn->query("SHOW TABLES LIKE 'room_transfers'")->fetch_assoc()) {
        $conn->query(
            "CREATE TABLE room_transfers (
              id INT AUTO_INCREMENT PRIMARY KEY,
              booking_id INT NOT NULL,
              from_room_id INT NOT NULL,
              to_room_id INT NOT NULL,
              transfer_date DATE NOT NULL,
              transfer_time TIME NOT NULL DEFAULT '15:00:00',
              old_nightly_rate DECIMAL(12,2) NOT NULL,
              new_nightly_rate DECIMAL(12,2) NOT NULL,
              remaining_nights INT NOT NULL DEFAULT 1,
              rate_difference_per_night DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              total_additional_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              reason TEXT DEFAULT NULL,
              transferred_by INT DEFAULT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
    }

    if (!$conn->query("SHOW COLUMNS FROM room_transfers LIKE 'transfer_time'")->fetch_assoc()) {
        $conn->query("ALTER TABLE room_transfers ADD COLUMN transfer_time TIME NOT NULL DEFAULT '15:00:00' AFTER transfer_date");
    }
    $conn->query("ALTER TABLE room_transfers ALTER COLUMN transfer_time SET DEFAULT '15:00:00'");

    if ($conn->query("SHOW TABLES LIKE 'bill_items'")->fetch_assoc()) {
        $quantityColumn = $conn->query("SHOW COLUMNS FROM bill_items LIKE 'quantity'")->fetch_assoc();
        if ($quantityColumn && strtolower((string) $quantityColumn['Type']) !== 'decimal(10,6)') {
            $conn->query('ALTER TABLE bill_items MODIFY quantity DECIMAL(10,6) NOT NULL DEFAULT 1');
        }
        if (!$conn->query("SHOW COLUMNS FROM bill_items LIKE 'delivery_room_id'")->fetch_assoc()) {
            $conn->query('ALTER TABLE bill_items ADD COLUMN delivery_room_id INT DEFAULT NULL AFTER amount');
        }
        if (!$conn->query("SHOW COLUMNS FROM bill_items LIKE 'delivery_room_number'")->fetch_assoc()) {
            $conn->query('ALTER TABLE bill_items ADD COLUMN delivery_room_number VARCHAR(20) DEFAULT NULL AFTER delivery_room_id');
        }
        if ($conn->query("SHOW TABLES LIKE 'rooms'")->fetch_assoc()
            && !$conn->query("SHOW KEYS FROM bill_items WHERE Key_name = 'fk_bill_items_delivery_room'")->fetch_assoc()) {
            $conn->query('ALTER TABLE bill_items ADD CONSTRAINT fk_bill_items_delivery_room FOREIGN KEY (delivery_room_id) REFERENCES rooms(id) ON DELETE SET NULL');
        }
    }

    if ($conn->query("SHOW COLUMNS FROM rooms LIKE 'amenities'")->fetch_assoc()
        && !$conn->query("SHOW COLUMNS FROM rooms LIKE 'room_features'")->fetch_assoc()) {
        $conn->query('ALTER TABLE rooms CHANGE COLUMN amenities room_features TEXT COMMENT \'Comma-separated (e.g. WiFi, TV, AC, Mini Bar)\'');
    }

    if (!$conn->query("SHOW COLUMNS FROM rooms LIKE 'room_features'")->fetch_assoc()) {
        $conn->query('ALTER TABLE rooms ADD COLUMN room_features TEXT COMMENT \'Comma-separated (e.g. WiFi, TV, AC, Mini Bar)\' AFTER capacity');
    }

    if (!$conn->query("SHOW COLUMNS FROM rooms LIKE 'max_adults'")->fetch_assoc()) {
        $conn->query('ALTER TABLE rooms ADD COLUMN max_adults INT NOT NULL DEFAULT 2 AFTER capacity, ADD COLUMN max_children INT NOT NULL DEFAULT 1 AFTER max_adults');
        $conn->query('UPDATE rooms SET max_adults = GREATEST(capacity, 1), max_children = 1, capacity = GREATEST(capacity, 1) + 1');
    }

    if (!$conn->query("SHOW COLUMNS FROM rooms LIKE 'photo'")->fetch_assoc()) {
        $after = $conn->query("SHOW COLUMNS FROM rooms LIKE 'room_features'")->fetch_assoc()
            ? 'room_features'
            : 'capacity';
        $conn->query("ALTER TABLE rooms ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER {$after}");
    }

    if (!$conn->query("SHOW COLUMNS FROM rooms LIKE 'category_id'")->fetch_assoc()) {
        $conn->query('ALTER TABLE rooms ADD COLUMN category_id INT DEFAULT NULL AFTER room_number');
    }

    if (!$conn->query("SHOW TABLES LIKE 'guest_requests'")->fetch_assoc()) {
        $conn->query(
            "CREATE TABLE guest_requests (
              id INT AUTO_INCREMENT PRIMARY KEY,
              guest_id INT NOT NULL,
              booking_id INT DEFAULT NULL,
              request_type ENUM('extra','food','laundry') NOT NULL,
              additional_item_id INT DEFAULT NULL,
              service_id INT DEFAULT NULL,
              item_name VARCHAR(120) NOT NULL,
              quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
              unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
              notes TEXT,
              photo VARCHAR(255) DEFAULT NULL,
              status ENUM('pending','preparing','ready','to_be_delivered','delivered','not_delivered','cancelled') NOT NULL DEFAULT 'pending',
              billed TINYINT(1) NOT NULL DEFAULT 0,
              bill_item_id INT DEFAULT NULL,
              item_unavailable_at DATETIME DEFAULT NULL,
              item_unavailable_reason ENUM('inactive','deleted') DEFAULT NULL,
              item_unavailable_by INT DEFAULT NULL,
              prepared_by INT DEFAULT NULL,
              assigned_to INT DEFAULT NULL,
              assigned_by INT DEFAULT NULL,
              assigned_at DATETIME DEFAULT NULL,
              confirmed_by INT DEFAULT NULL,
              confirmed_at DATETIME DEFAULT NULL,
              handled_by INT DEFAULT NULL,
              handled_at TIMESTAMP NULL DEFAULT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
    }

    $requestTypeColumn = $conn->query("SHOW COLUMNS FROM guest_requests LIKE 'request_type'")->fetch_assoc();
    if ($requestTypeColumn && !str_contains($requestTypeColumn['Type'], "'laundry'")) {
        $conn->query("ALTER TABLE guest_requests MODIFY request_type ENUM('extra','food','laundry') NOT NULL");
    }

    $requestArchiveMigrationNeeded = !$conn->query("SHOW COLUMNS FROM guest_requests LIKE 'item_unavailable_at'")->fetch_assoc()
        || !$conn->query("SHOW COLUMNS FROM guest_requests LIKE 'item_unavailable_reason'")->fetch_assoc()
        || !$conn->query("SHOW COLUMNS FROM guest_requests LIKE 'item_unavailable_by'")->fetch_assoc();

    foreach ([
        'photo' => 'VARCHAR(255) DEFAULT NULL',
        'bill_item_id' => 'INT DEFAULT NULL',
        'item_unavailable_at' => 'DATETIME DEFAULT NULL',
        'item_unavailable_reason' => "ENUM('inactive','deleted') DEFAULT NULL",
        'item_unavailable_by' => 'INT DEFAULT NULL',
        'prepared_by' => 'INT DEFAULT NULL',
        'assigned_to' => 'INT DEFAULT NULL',
        'assigned_by' => 'INT DEFAULT NULL',
        'assigned_at' => 'DATETIME DEFAULT NULL',
        'confirmed_by' => 'INT DEFAULT NULL',
        'confirmed_at' => 'DATETIME DEFAULT NULL',
        'handled_by' => 'INT DEFAULT NULL',
        'handled_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ] as $column => $definition) {
        if (!$conn->query("SHOW COLUMNS FROM guest_requests LIKE '{$column}'")->fetch_assoc()) {
            $conn->query("ALTER TABLE guest_requests ADD COLUMN {$column} {$definition}");
        }
    }
    if ($requestArchiveMigrationNeeded) {
        $conn->query(
            "UPDATE guest_requests gr
             LEFT JOIN services s ON s.id = gr.service_id
             LEFT JOIN additional_item_types item ON item.id = gr.additional_item_id
             SET gr.item_unavailable_at = COALESCE(gr.created_at, NOW()),
                 gr.item_unavailable_reason = 'inactive'
             WHERE gr.item_unavailable_at IS NULL
               AND ((gr.service_id IS NOT NULL AND COALESCE(s.status, 'inactive') <> 'active')
                 OR (gr.additional_item_id IS NOT NULL AND COALESCE(item.status, 'inactive') <> 'active'))"
        );
        $conn->query(
            "UPDATE bill_items bi
             JOIN guest_requests gr ON gr.bill_item_id = bi.id
             SET bi.description = CONCAT(
                 LEFT(bi.description, 255 - CHAR_LENGTH(' [Catalog item inactive/removed by staff]')),
                 ' [Catalog item inactive/removed by staff]'
             )
             WHERE gr.item_unavailable_at IS NOT NULL
               AND bi.description NOT LIKE '%[Catalog item inactive/removed by staff]%'"
        );
    }

    $requestStatusColumn = $conn->query("SHOW COLUMNS FROM guest_requests LIKE 'status'")->fetch_assoc();
    $requestStatusType = (string) ($requestStatusColumn['Type'] ?? '');
    if ($requestStatusColumn && (
        !str_contains($requestStatusType, "'preparing'")
        || !str_contains($requestStatusType, "'ready'")
        || !str_contains($requestStatusType, "'to_be_delivered'")
        || !str_contains($requestStatusType, "'delivered'")
        || !str_contains($requestStatusType, "'not_delivered'")
        || str_contains($requestStatusType, "'ready_for_delivery'")
        || str_contains($requestStatusType, "'completed'")
        || str_contains($requestStatusType, "'approved'")
        || str_contains($requestStatusType, "'in_progress'")
        || str_contains($requestStatusType, "'rejected'")
    )) {
        $conn->query("ALTER TABLE guest_requests MODIFY status ENUM('pending','approved','delivered','not_delivered','in_progress','completed','rejected','ready_for_delivery','preparing','ready','to_be_delivered','cancelled') NOT NULL DEFAULT 'pending'");
        $conn->query("UPDATE guest_requests SET status = 'pending' WHERE status IN ('approved','in_progress')");
        $conn->query("UPDATE guest_requests SET status = 'cancelled' WHERE status = 'rejected'");
        $conn->query("UPDATE guest_requests SET status = 'to_be_delivered' WHERE status = 'ready_for_delivery'");
        $conn->query("UPDATE guest_requests SET status = 'delivered' WHERE status = 'completed'");
        $conn->query("ALTER TABLE guest_requests MODIFY status ENUM('pending','preparing','ready','to_be_delivered','delivered','not_delivered','cancelled') NOT NULL DEFAULT 'pending'");
    }
    foreach (['services', 'additional_item_types'] as $catalogTable) {
        if (!$conn->query("SHOW COLUMNS FROM {$catalogTable} LIKE 'photo'")->fetch_assoc()) {
            $conn->query("ALTER TABLE {$catalogTable} ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER description");
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS housekeeping_amenities (
              id INT AUTO_INCREMENT PRIMARY KEY,
              item_name VARCHAR(120) NOT NULL UNIQUE,
              stained_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              damaged_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              missing_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              status ENUM('active','inactive') NOT NULL DEFAULT 'active',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
    );
    foreach ([
        'stained_charge' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'damaged_charge' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'missing_charge' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    ] as $columnName => $definition) {
        if (!$conn->query("SHOW COLUMNS FROM housekeeping_amenities LIKE '{$columnName}'")->fetch_assoc()) {
            $conn->query("ALTER TABLE housekeeping_amenities ADD COLUMN {$columnName} {$definition}");
        }
    }
    $conn->query(
        "INSERT IGNORE INTO housekeeping_amenities (item_name) VALUES
             ('Bedsheet'), ('Pillowcases'), ('Blanket'), ('Towels'), ('Pillows'),
             ('TV / Remote'), ('Air Conditioner / Remote'), ('Kettle'), ('Toiletries'), ('Room Key')"
    );
    $conn->query(
        "UPDATE housekeeping_amenities
         SET stained_charge = CASE item_name
                 WHEN 'Bedsheet' THEN 250.00 WHEN 'Pillowcases' THEN 100.00 WHEN 'Blanket' THEN 150.00
                 WHEN 'Towels' THEN 100.00 WHEN 'Pillows' THEN 100.00 WHEN 'TV / Remote' THEN 100.00
                 WHEN 'Air Conditioner / Remote' THEN 100.00 WHEN 'Kettle' THEN 100.00
                 WHEN 'Toiletries' THEN 50.00 WHEN 'Room Key' THEN 50.00 ELSE stained_charge END,
             damaged_charge = CASE item_name
                 WHEN 'Bedsheet' THEN 900.00 WHEN 'Pillowcases' THEN 175.00 WHEN 'Blanket' THEN 500.00
                 WHEN 'Towels' THEN 300.00 WHEN 'Pillows' THEN 200.00 WHEN 'TV / Remote' THEN 750.00
                 WHEN 'Air Conditioner / Remote' THEN 400.00 WHEN 'Kettle' THEN 600.00
                 WHEN 'Toiletries' THEN 75.00 WHEN 'Room Key' THEN 150.00 ELSE damaged_charge END,
             missing_charge = CASE item_name
                 WHEN 'Bedsheet' THEN 1850.00 WHEN 'Pillowcases' THEN 250.00 WHEN 'Blanket' THEN 999.00
                 WHEN 'Towels' THEN 590.00 WHEN 'Pillows' THEN 320.00 WHEN 'TV / Remote' THEN 1200.00
                 WHEN 'Air Conditioner / Remote' THEN 650.00 WHEN 'Kettle' THEN 1200.00
                 WHEN 'Toiletries' THEN 100.00 WHEN 'Room Key' THEN 250.00 ELSE missing_charge END
         WHERE stained_charge = 0.00 AND damaged_charge = 0.00 AND missing_charge = 0.00"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS room_amenities (
              id INT AUTO_INCREMENT PRIMARY KEY,
              room_id INT NOT NULL,
              amenity_id INT NOT NULL,
              quantity INT NOT NULL DEFAULT 1,
              status ENUM('active','inactive') NOT NULL DEFAULT 'active',
              UNIQUE KEY uq_room_amenity (room_id, amenity_id),
              FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
              FOREIGN KEY (amenity_id) REFERENCES housekeeping_amenities(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB"
    );
    $conn->query(
        "INSERT IGNORE INTO room_amenities (room_id, amenity_id)
             SELECT r.id, a.id FROM rooms r CROSS JOIN housekeeping_amenities a WHERE a.status = 'active'"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS housekeeping_tasks (
              id INT AUTO_INCREMENT PRIMARY KEY,
              booking_id INT NOT NULL UNIQUE,
              room_id INT NOT NULL,
              assigned_to INT DEFAULT NULL,
              assigned_by INT DEFAULT NULL,
              status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              completed_at TIMESTAMP NULL DEFAULT NULL,
              FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
              FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
              FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
              FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS housekeeping_task_items (
              id INT AUTO_INCREMENT PRIMARY KEY,
              task_id INT NOT NULL,
              room_amenity_id INT DEFAULT NULL,
              item_name VARCHAR(120) NOT NULL,
              condition_status ENUM('pending','ok','damaged','stained','missing') NOT NULL DEFAULT 'pending',
              notes TEXT DEFAULT NULL,
              updated_at TIMESTAMP NULL DEFAULT NULL,
              UNIQUE KEY uq_task_item_name (task_id, item_name),
              FOREIGN KEY (task_id) REFERENCES housekeeping_tasks(id) ON DELETE CASCADE,
              FOREIGN KEY (room_amenity_id) REFERENCES room_amenities(id) ON DELETE SET NULL
            ) ENGINE=InnoDB"
    );
    if (!$conn->query("SHOW COLUMNS FROM housekeeping_task_items LIKE 'charge_amount'")->fetch_assoc()) {
        $conn->query('ALTER TABLE housekeeping_task_items ADD COLUMN charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER condition_status');
    }
    $conn->query(
        "INSERT IGNORE INTO users (full_name, email, password, role, phone, gender, status)
             VALUES ('Housekeeping Staff', 'housekeeping@gmail.com',
                     '\$2y\$10\$lO5lilQRQsIhti6eL8XalOlE.wKRJCa8c1bNIFnCqxw9X9yd8fcvu',
                     'housekeeping', '09000000007', 'female', 'active')"
    );
    $conn->query(
        "UPDATE users SET first_name = 'Housekeeping', last_name = 'Staff'
         WHERE email = 'housekeeping@gmail.com' AND first_name = ''"
    );
    $conn->query('DROP TABLE IF EXISTS kitchen_inventory');

    if (!$conn->query("SHOW TABLES LIKE 'guest_registrations'")->fetch_assoc()) {
        $conn->query(
            "CREATE TABLE guest_registrations (
              id INT AUTO_INCREMENT PRIMARY KEY,
              guest_id INT NOT NULL,
              registered_by INT DEFAULT NULL,
              full_name VARCHAR(120) NOT NULL,
              email VARCHAR(150) DEFAULT NULL,
              phone VARCHAR(40) DEFAULT NULL,
              gender ENUM('male','female') DEFAULT NULL,
              address TEXT,
              id_type VARCHAR(50) DEFAULT NULL,
              id_number VARCHAR(80) DEFAULT NULL,
              notes TEXT,
              portal_created TINYINT(1) NOT NULL DEFAULT 0,
              registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
              FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB"
        );
        // Backfill existing guests as front-desk registrations when possible
        $fd = $conn->query("SELECT id FROM users WHERE role IN ('frontdesk','admin') ORDER BY id LIMIT 1")->fetch_assoc();
        $staffId = (int) ($fd['id'] ?? 0);
        if ($staffId > 0) {
            $conn->query(
                "INSERT INTO guest_registrations
                   (guest_id, registered_by, full_name, email, phone, gender, address, id_type, id_number, notes, portal_created, registered_at)
                 SELECT g.id, {$staffId}, g.full_name, g.email, g.phone, g.gender, g.address, g.id_type, g.id_number, g.notes,
                        CASE WHEN g.user_id IS NOT NULL THEN 1 ELSE 0 END, g.created_at
                 FROM guests g
                 WHERE NOT EXISTS (SELECT 1 FROM guest_registrations gr WHERE gr.guest_id = g.id)"
            );
        }
    }

    $roleColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc();
    if ($roleColumn && str_contains((string) $roleColumn['Type'], "'staffdelivery'")) {
        if ($conn->query("SHOW TABLES LIKE 'guest_registrations'")->fetch_assoc()) {
            $registrationActor = $conn->query("SHOW COLUMNS FROM guest_registrations LIKE 'registered_by'")->fetch_assoc();
            if ($registrationActor && $registrationActor['Null'] === 'NO') {
                $conn->query('ALTER TABLE guest_registrations MODIFY registered_by INT DEFAULT NULL');
            }
            $conn->query(
                "UPDATE guest_registrations SET registered_by = NULL
                 WHERE registered_by IN (SELECT id FROM users WHERE role = 'staffdelivery')"
            );
        }
        if ($conn->query("SHOW TABLES LIKE 'guest_requests'")->fetch_assoc()) {
            foreach (['delivered_by', 'prepared_by', 'handled_by', 'assigned_to', 'assigned_by', 'confirmed_by'] as $actorColumn) {
                if ($conn->query("SHOW COLUMNS FROM guest_requests LIKE '{$actorColumn}'")->fetch_assoc()) {
                    $conn->query(
                        "UPDATE guest_requests SET {$actorColumn} = NULL
                         WHERE {$actorColumn} IN (SELECT id FROM users WHERE role = 'staffdelivery')"
                    );
                }
            }
        }
        if ($conn->query("SHOW TABLES LIKE 'audit_logs'")->fetch_assoc()) {
            $conn->query(
                "UPDATE audit_logs SET user_id = NULL
                 WHERE user_id IN (SELECT id FROM users WHERE role = 'staffdelivery')"
            );
        }
        $conn->query("DELETE FROM users WHERE role = 'staffdelivery'");
    }
    if ($roleColumn && (!str_contains((string) $roleColumn['Type'], "'housekeeping'")
        || str_contains((string) $roleColumn['Type'], "'staffdelivery'"))) {
        $conn->query("ALTER TABLE users MODIFY role ENUM('admin','frontdesk','guest','kitchen','laundry','housekeeping') NOT NULL");
    }

    if (!$conn->query("SHOW COLUMNS FROM discounts LIKE 'code'")->fetch_assoc()) {
        $conn->query('ALTER TABLE discounts ADD COLUMN code VARCHAR(40) DEFAULT NULL UNIQUE AFTER name');
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS room_appointments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          appointment_code CHAR(16) NOT NULL UNIQUE,
          first_name VARCHAR(120) NOT NULL,
          last_name VARCHAR(120) NOT NULL,
          full_name VARCHAR(241) NOT NULL,
          email VARCHAR(150) NOT NULL,
          phone VARCHAR(40) NOT NULL,
          room_type_id INT DEFAULT NULL,
          room_type_name VARCHAR(100) NOT NULL,
          room_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
          adults SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          children SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          check_in DATE NOT NULL,
          check_in_time TIME NOT NULL DEFAULT '15:00:00',
          check_out DATE NOT NULL,
          check_out_time TIME NOT NULL DEFAULT '14:00:00',
          notes TEXT DEFAULT NULL,
          estimated_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          payment_method ENUM('gcash','bank_transfer') DEFAULT NULL,
          payment_reference VARCHAR(80) DEFAULT NULL,
          payment_status ENUM('not_required','pending','verified','rejected') NOT NULL DEFAULT 'not_required',
          payment_review_note VARCHAR(1000) DEFAULT NULL,
          payment_reviewed_by INT DEFAULT NULL,
          payment_reviewed_at DATETIME DEFAULT NULL,
          account_user_id INT DEFAULT NULL,
          converted_at DATETIME DEFAULT NULL,
          status ENUM('pending','confirmed','rejected','cancelled') NOT NULL DEFAULT 'pending',
          review_note VARCHAR(1000) DEFAULT NULL,
          reviewed_by INT DEFAULT NULL,
          reviewed_at DATETIME DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_room_appointments_status_date (status, check_in, check_out),
          FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE SET NULL,
          FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
          FOREIGN KEY (payment_reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $appointmentColumns = [
        'check_in_time' => "TIME NOT NULL DEFAULT '15:00:00'",
        'check_out_time' => "TIME NOT NULL DEFAULT '14:00:00'",
        'estimated_total' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'deposit_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'payment_method' => "ENUM('gcash','bank_transfer') DEFAULT NULL",
        'payment_reference' => 'VARCHAR(80) DEFAULT NULL',
        'payment_status' => "ENUM('not_required','pending','verified','rejected') NOT NULL DEFAULT 'not_required'",
        'payment_review_note' => 'VARCHAR(1000) DEFAULT NULL',
        'payment_reviewed_by' => 'INT DEFAULT NULL',
        'payment_reviewed_at' => 'DATETIME DEFAULT NULL',
        'account_user_id' => 'INT DEFAULT NULL',
        'converted_at' => 'DATETIME DEFAULT NULL',
    ];
    foreach ($appointmentColumns as $columnName => $definition) {
        if (!$conn->query("SHOW COLUMNS FROM room_appointments LIKE '{$columnName}'")->fetch_assoc()) {
            $conn->query("ALTER TABLE room_appointments ADD COLUMN {$columnName} {$definition}");
        }
    }
    $appointmentPaymentReviewerForeignKey = $conn->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_appointments'
           AND COLUMN_NAME = 'payment_reviewed_by' AND REFERENCED_TABLE_NAME = 'users'
         LIMIT 1"
    )->fetch_assoc();
    if (!$appointmentPaymentReviewerForeignKey) {
        $conn->query(
            'ALTER TABLE room_appointments
             ADD CONSTRAINT fk_room_appointments_payment_reviewed_by
             FOREIGN KEY (payment_reviewed_by) REFERENCES users(id) ON DELETE SET NULL'
        );
    }
    $conn->query("ALTER TABLE room_appointments ALTER COLUMN check_in_time SET DEFAULT '15:00:00'");
    $conn->query("ALTER TABLE room_appointments ALTER COLUMN check_out_time SET DEFAULT '14:00:00'");
    $conn->query(
        "UPDATE room_appointments
         SET check_in_time = CASE
                 WHEN check_in_time < '15:00:00' OR check_in_time >= '16:00:00' THEN '15:00:00'
                 ELSE MAKETIME(15, MINUTE(check_in_time), 0)
             END,
             check_out_time = '14:00:00'
         WHERE status IN ('pending','confirmed') AND check_out >= CURDATE()
           AND (check_in_time < '15:00:00' OR check_in_time >= '16:00:00'
                OR SECOND(check_in_time) <> 0 OR check_out_time <> '14:00:00')"
    );
    $appointmentForeignKeys = $conn->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_appointments'
           AND COLUMN_NAME = 'account_user_id' AND REFERENCED_TABLE_NAME = 'users'"
    )->fetch_assoc();
    if (!$appointmentForeignKeys) {
        $conn->query(
            'ALTER TABLE room_appointments ADD CONSTRAINT fk_room_appointments_account_user
             FOREIGN KEY (account_user_id) REFERENCES users(id) ON DELETE SET NULL'
        );
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS room_appointment_changes (
          id INT AUTO_INCREMENT PRIMARY KEY,
          appointment_id INT NOT NULL,
          change_type ENUM('cancel','reschedule') NOT NULL,
          current_room_type_name VARCHAR(100) DEFAULT NULL,
          current_room_count TINYINT UNSIGNED DEFAULT NULL,
          current_adults SMALLINT UNSIGNED DEFAULT NULL,
          current_children SMALLINT UNSIGNED DEFAULT NULL,
          current_check_in DATE DEFAULT NULL,
          current_check_in_time TIME DEFAULT NULL,
          current_check_out DATE DEFAULT NULL,
          current_check_out_time TIME DEFAULT NULL,
          proposed_room_type_id INT DEFAULT NULL,
          proposed_room_type_name VARCHAR(100) DEFAULT NULL,
          proposed_room_count TINYINT UNSIGNED DEFAULT NULL,
          proposed_room_ids TEXT DEFAULT NULL,
          proposed_adults SMALLINT UNSIGNED DEFAULT NULL,
          proposed_children SMALLINT UNSIGNED DEFAULT NULL,
          proposed_check_in DATE DEFAULT NULL,
          proposed_check_in_time TIME DEFAULT NULL,
          proposed_check_out DATE DEFAULT NULL,
          proposed_check_out_time TIME DEFAULT NULL,
          reason VARCHAR(1000) DEFAULT NULL,
          status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
          review_note VARCHAR(1000) DEFAULT NULL,
          reviewed_by INT DEFAULT NULL,
          reviewed_at DATETIME DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_appointment_changes_status (status, appointment_id),
          FOREIGN KEY (appointment_id) REFERENCES room_appointments(id) ON DELETE CASCADE,
          FOREIGN KEY (proposed_room_type_id) REFERENCES room_types(id) ON DELETE SET NULL,
          FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS room_appointment_rooms (
          id INT AUTO_INCREMENT PRIMARY KEY,
          appointment_id INT NOT NULL,
          room_id INT NOT NULL,
          UNIQUE KEY uq_room_appointment_assignment (appointment_id, room_id),
          INDEX idx_room_appointment_assigned_room (room_id),
          FOREIGN KEY (appointment_id) REFERENCES room_appointments(id) ON DELETE CASCADE,
          FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );
    $appointmentCode = $conn->query("SHOW COLUMNS FROM room_appointments LIKE 'appointment_code'")->fetch_assoc();
    if ($appointmentCode && !str_contains(strtolower((string) $appointmentCode['Type']), 'char(16)')) {
        $conn->query('ALTER TABLE room_appointments MODIFY appointment_code CHAR(16) NOT NULL');
    }
    $appointmentChangeColumns = [
        'current_room_type_name' => 'VARCHAR(100) DEFAULT NULL',
        'current_room_count' => 'TINYINT UNSIGNED DEFAULT NULL',
        'current_adults' => 'SMALLINT UNSIGNED DEFAULT NULL',
        'current_children' => 'SMALLINT UNSIGNED DEFAULT NULL',
        'current_check_in' => 'DATE DEFAULT NULL',
        'current_check_in_time' => 'TIME DEFAULT NULL',
        'current_check_out' => 'DATE DEFAULT NULL',
        'current_check_out_time' => 'TIME DEFAULT NULL',
        'proposed_check_in_time' => 'TIME DEFAULT NULL',
        'proposed_check_out_time' => 'TIME DEFAULT NULL',
        'proposed_room_ids' => 'TEXT DEFAULT NULL',
    ];
    foreach ($appointmentChangeColumns as $columnName => $definition) {
        if (!$conn->query("SHOW COLUMNS FROM room_appointment_changes LIKE '{$columnName}'")->fetch_assoc()) {
            $conn->query("ALTER TABLE room_appointment_changes ADD COLUMN {$columnName} {$definition}");
        }
    }
    $conn->query(
        "UPDATE room_appointment_changes c
         JOIN room_appointments a ON a.id = c.appointment_id
         SET c.current_room_type_name = COALESCE(c.current_room_type_name, a.room_type_name),
             c.current_room_count = COALESCE(c.current_room_count, a.room_count),
             c.current_adults = COALESCE(c.current_adults, a.adults),
             c.current_children = COALESCE(c.current_children, a.children),
             c.current_check_in = COALESCE(c.current_check_in, a.check_in),
             c.current_check_in_time = COALESCE(c.current_check_in_time, a.check_in_time),
             c.current_check_out = COALESCE(c.current_check_out, a.check_out),
             c.current_check_out_time = COALESCE(c.current_check_out_time, a.check_out_time)"
    );

    $uploadDir = dirname(__DIR__) . '/uploads/rooms';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
}

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");
    align_database_schema($conn);
} catch (Throwable $e) {
    http_response_code(500);
    die('Database connection failed. Import sql/schema.sql in phpMyAdmin first.');
}
