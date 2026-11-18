-- Hotel Billing System: clean starter schema and role demo accounts.
-- Importing this file resets the database. It seeds inventory/catalogs but no sample stays or billing history.
-- Demo logins: admin@gmail.com/admin123, frontdesk@gmail.com/frontdesk123,
-- kitchen@gmail.com/kitchen123, laundry@gmail.com/laundry123, housekeeping@gmail.com/housekeeping123.

CREATE DATABASE IF NOT EXISTS hotelbillingsystem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hotelbillingsystem;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS housekeeping_task_items;
DROP TABLE IF EXISTS housekeeping_tasks;
DROP TABLE IF EXISTS room_appointment_rooms;
DROP TABLE IF EXISTS room_appointment_changes;
DROP TABLE IF EXISTS room_appointments;
DROP TABLE IF EXISTS room_amenities;
DROP TABLE IF EXISTS housekeeping_amenities;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS payment_requests;
DROP TABLE IF EXISTS bill_items;
DROP TABLE IF EXISTS bills;
DROP TABLE IF EXISTS guest_requests;
DROP TABLE IF EXISTS kitchen_inventory;
DROP TABLE IF EXISTS guest_registrations;
DROP TABLE IF EXISTS room_transfers;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS guests;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS discounts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS room_types;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS additional_item_types;
DROP TABLE IF EXISTS clarifications;
DROP TABLE IF EXISTS settings;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. USERS TABLE
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  first_name VARCHAR(120) NOT NULL DEFAULT '',
  last_name VARCHAR(120) NOT NULL DEFAULT '',
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','frontdesk','guest','kitchen','laundry','housekeeping') NOT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  gender ENUM('male','female') DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. ROOM TYPES TABLE (Standard / Deluxe / Suite)
CREATE TABLE room_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description TEXT DEFAULT NULL,
  base_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  room_features TEXT COMMENT 'Default features, comma-separated (e.g. WiFi, TV, AC)',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. CATEGORIES TABLE (room types plus the fixed Food / Laundry service groups)
CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  category_type ENUM('room', 'service') NOT NULL,
  description TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. GUESTS TABLE
CREATE TABLE guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  full_name VARCHAR(120) NOT NULL,
  first_name VARCHAR(120) NOT NULL DEFAULT '',
  last_name VARCHAR(120) NOT NULL DEFAULT '',
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  gender ENUM('male','female') DEFAULT NULL,
  address TEXT,
  id_type VARCHAR(50) DEFAULT NULL,
  id_number VARCHAR(80) DEFAULT NULL,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4b. FRONT DESK GUEST REGISTRATIONS
CREATE TABLE guest_registrations (
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
) ENGINE=InnoDB;

-- 5. ROOMS TABLE
CREATE TABLE rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(20) NOT NULL UNIQUE,
  room_type_id INT DEFAULT NULL,
  category_id INT DEFAULT NULL,
  room_type VARCHAR(60) NOT NULL,
  rate_per_night DECIMAL(12,2) NOT NULL,
  floor INT DEFAULT 1,
  capacity INT DEFAULT 3 COMMENT 'Total guests = max_adults + max_children',
  max_adults INT NOT NULL DEFAULT 2,
  max_children INT NOT NULL DEFAULT 1,
  room_features TEXT COMMENT 'Comma-separated (e.g. WiFi, TV, AC, Mini Bar)',
  photo VARCHAR(255) DEFAULT NULL,
  status ENUM('available','occupied','maintenance','reserved','cleaning') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE SET NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5b. ROOM HOUSEKEEPING AMENITY CATALOG AND INVENTORY
-- Starter charge amounts are estimates. Bedding references: IKEA Philippines listings.
-- Blanket references: IKEA Philippines blanket/throw listings; pillows: Mandaue Foam;
-- towels: MUJI Philippines. Appliance/accessory amounts are budget allowances; reconcile
-- every default with the hotel's actual purchase or repair costs before billing guests.
CREATE TABLE housekeeping_amenities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_name VARCHAR(120) NOT NULL UNIQUE,
  stained_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  damaged_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  missing_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE room_amenities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  amenity_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_room_amenity (room_id, amenity_id),
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  FOREIGN KEY (amenity_id) REFERENCES housekeeping_amenities(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6. SERVICES TABLE
CREATE TABLE services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  category_id INT DEFAULT NULL,
  category VARCHAR(60) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  min_room_type VARCHAR(60) DEFAULT NULL COMMENT 'Tier restriction (e.g., Standard, Deluxe, Suite)',
  description TEXT,
  photo VARCHAR(255) DEFAULT NULL COMMENT 'Uploaded image shown to guests (uploads/services/...)',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 6. ADDITIONAL ITEM TYPES TABLE
CREATE TABLE additional_item_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_name VARCHAR(120) NOT NULL UNIQUE,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  description TEXT DEFAULT NULL,
  photo VARCHAR(255) DEFAULT NULL COMMENT 'Uploaded image shown to guests (uploads/services/...)',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 7. DISCOUNTS TABLE
CREATE TABLE discounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(40) DEFAULT NULL UNIQUE,
  discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value DECIMAL(12,2) NOT NULL,
  description TEXT,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 8. BOOKINGS TABLE
CREATE TABLE bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  guest_id INT NOT NULL,
  room_id INT NOT NULL,
  check_in DATE NOT NULL,
  check_out DATE NOT NULL,
  check_in_time TIME NOT NULL DEFAULT '15:00:00',
  check_out_time TIME NOT NULL DEFAULT '14:00:00',
  adults INT NOT NULL DEFAULT 1,
  children INT NOT NULL DEFAULT 0,
  status ENUM('reserved','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'reserved',
  stay_group INT DEFAULT NULL COMMENT 'Shared id (first booking id) for rooms booked together',
  notes TEXT,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8a. PUBLIC ROOM APPOINTMENT REQUESTS AND CODE-BASED CHANGE HISTORY
CREATE TABLE room_appointments (
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
  FOREIGN KEY (payment_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (account_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE room_appointment_changes (
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
) ENGINE=InnoDB;

CREATE TABLE room_appointment_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT NOT NULL,
  room_id INT NOT NULL,
  UNIQUE KEY uq_room_appointment_assignment (appointment_id, room_id),
  INDEX idx_room_appointment_assigned_room (room_id),
  FOREIGN KEY (appointment_id) REFERENCES room_appointments(id) ON DELETE CASCADE,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 8b. HOUSEKEEPING CHECK-OUT TASKS AND ROOM CHECKLIST
CREATE TABLE housekeeping_tasks (
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
) ENGINE=InnoDB;

CREATE TABLE housekeeping_task_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  room_amenity_id INT DEFAULT NULL,
  item_name VARCHAR(120) NOT NULL,
  condition_status ENUM('pending','ok','damaged','stained','missing') NOT NULL DEFAULT 'pending',
  charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes TEXT DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_task_item_name (task_id, item_name),
  FOREIGN KEY (task_id) REFERENCES housekeeping_tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (room_amenity_id) REFERENCES room_amenities(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 9. ROOM TRANSFERS TABLE
CREATE TABLE room_transfers (
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (from_room_id) REFERENCES rooms(id),
  FOREIGN KEY (to_room_id) REFERENCES rooms(id),
  FOREIGN KEY (transferred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 10. BILLS TABLE
CREATE TABLE bills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT DEFAULT NULL,
  guest_id INT NOT NULL,
  invoice_number VARCHAR(40) NOT NULL UNIQUE,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','partial','paid','overdue','void') NOT NULL DEFAULT 'pending',
  due_date DATE DEFAULT NULL,
  notes TEXT,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 11. BILL ITEMS TABLE
CREATE TABLE bill_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  item_type ENUM('room','service','extra','transfer_charge','other') NOT NULL DEFAULT 'other',
  reference_id INT DEFAULT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,6) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  delivery_room_id INT DEFAULT NULL,
  delivery_room_number VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (delivery_room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 12. GUEST PAYMENT REQUESTS
CREATE TABLE payment_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  guest_id INT NOT NULL,
  booking_id INT DEFAULT NULL,
  bill_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash','online','bank') NOT NULL,
  reference_no VARCHAR(80) DEFAULT NULL,
  notes TEXT,
  status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_by INT DEFAULT NULL,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_payment_requests_account (guest_id, bill_id, status),
  FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 13. PAYMENTS TABLE
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  payment_request_id INT DEFAULT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash','online','bank') NOT NULL DEFAULT 'cash',
  reference_no VARCHAR(80) NOT NULL,
  notes TEXT,
  received_by INT DEFAULT NULL,
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_request_id) REFERENCES payment_requests(id) ON DELETE SET NULL,
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 13. GUEST REQUESTS (extras like pillows + food / room service)
CREATE TABLE guest_requests (
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
  photo VARCHAR(255) DEFAULT NULL COMMENT 'Catalog photo captured when request was submitted',
  status ENUM('pending','preparing','ready','to_be_delivered','delivered','not_delivered','cancelled') NOT NULL DEFAULT 'pending',
  billed TINYINT(1) NOT NULL DEFAULT 0,
  bill_item_id INT DEFAULT NULL,
  item_unavailable_at DATETIME DEFAULT NULL,
  item_unavailable_reason ENUM('inactive','deleted') DEFAULT NULL,
  item_unavailable_by INT DEFAULT NULL,
  prepared_by INT DEFAULT NULL,
  delivered_by INT DEFAULT NULL,
  assigned_to INT DEFAULT NULL,
  assigned_by INT DEFAULT NULL,
  assigned_at DATETIME DEFAULT NULL,
  confirmed_by INT DEFAULT NULL,
  confirmed_at DATETIME DEFAULT NULL,
  handled_by INT DEFAULT NULL,
  handled_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  FOREIGN KEY (additional_item_id) REFERENCES additional_item_types(id) ON DELETE SET NULL,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (item_unavailable_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (bill_item_id) REFERENCES bill_items(id) ON DELETE SET NULL,
  FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (delivered_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 14. AUDIT LOGS TABLE
CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  action VARCHAR(120) NOT NULL,
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =================================================================
-- BASE SEED DATA (starter accounts and catalog; operational records start empty)
-- Logins: admin@gmail.com/admin123 · frontdesk@gmail.com/frontdesk123
-- Housekeeping: housekeeping@gmail.com/housekeeping123
-- =================================================================

CREATE TABLE system_migrations (
  migration_key VARCHAR(120) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO users (full_name, first_name, last_name, email, password, role, phone, gender, status) VALUES
('System Administrator', 'System', 'Administrator', 'admin@gmail.com', '$2y$10$cWKHY.4inG2duoWxxzaeFugvRVowIDEJor5MZxI6BlEWCd7Hd4mca', 'admin', '09000000001', 'male', 'active'),
('Front Desk Officer', 'Front', 'Desk Officer', 'frontdesk@gmail.com', '$2y$10$nNt9S9OIz3mihgUtMzUpZenwx/aQPAIBMXkgkPSfn3paFKCbs5d7O', 'frontdesk', '09000000002', 'female', 'active'),
('Sample Guest', 'Sample', 'Guest', 'guest@gmail.com', '$2y$10$kKX8I/XLPw1AZcJYm4Ob0ut40K/5coyTiV3rw/jVPpcnZ1LoHJTIe', 'guest', '09000000003', 'male', 'active'),
('Kitchen Staff', 'Kitchen', 'Staff', 'kitchen@gmail.com', '$2y$10$b2c36Tjno6g84GOLx61BceYr9qYqC072rpTOw9AzlbqP1H4ZdxZ52', 'kitchen', '09000000004', 'male', 'active'),
('Laundry Staff', 'Laundry', 'Staff', 'laundry@gmail.com', '$2y$10$tWMtkTn7pp0ChDiXKxWeR.pLbIkSTbED59eduTGtiZ7WU.YlqUVsi', 'laundry', '09000000006', 'female', 'active'),
('Housekeeping Staff', 'Housekeeping', 'Staff', 'housekeeping@gmail.com', '$2y$10$lO5lilQRQsIhti6eL8XalOlE.wKRJCa8c1bNIFnCqxw9X9yd8fcvu', 'housekeeping', '09000000007', 'female', 'active');

INSERT INTO system_migrations (migration_key) VALUES ('restore_seeded_guest_demo_login');

INSERT INTO housekeeping_amenities (item_name, stained_charge, damaged_charge, missing_charge) VALUES
('Bedsheet', 250.00, 900.00, 1850.00),
('Pillowcases', 100.00, 175.00, 250.00),
('Blanket', 150.00, 500.00, 999.00),
('Towels', 100.00, 300.00, 590.00),
('Pillows', 100.00, 200.00, 320.00),
('TV / Remote', 100.00, 750.00, 1200.00),
('Air Conditioner / Remote', 100.00, 400.00, 650.00),
('Kettle', 100.00, 600.00, 1200.00),
('Toiletries', 50.00, 75.00, 100.00),
('Room Key', 50.00, 150.00, 250.00);

INSERT INTO room_types (name, description, base_rate, room_features, status) VALUES
('Standard', 'Standard basic rooms with single/double bed options', 2500.00, 'WiFi, TV, AC', 'active'),
('Deluxe', 'Upgraded rooms with better views and mini-bar facilities', 4200.00, 'WiFi, TV, AC, Mini Bar', 'active'),
('Suite', 'Premium luxury suites with jacuzzi and dedicated living rooms', 7800.00, 'WiFi, TV, AC, Jacuzzi, Living Area', 'active');

INSERT INTO categories (name, category_type, description) VALUES
('Food', 'service', 'Meals, drinks, and in-room food service'),
('Laundry', 'service', 'Washing, ironing, and dry cleaning');

INSERT INTO rooms (room_number, room_type_id, room_type, rate_per_night, floor, capacity, max_adults, max_children, room_features, photo, status) VALUES
('101', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('102', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'maintenance'),
('201', 2, 'Deluxe', 4200.00, 2, 3, 2, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('202', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar, Sofa', 'systemphotos/room-deluxe.jpg', 'available'),
('301', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('302', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('103', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('104', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('105', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('106', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('107', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('108', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('109', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('110', 1, 'Standard', 2500.00, 1, 3, 2, 1, 'WiFi, TV, AC', 'systemphotos/room-standard.jpg', 'available'),
('203', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('204', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('205', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('206', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('207', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('208', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('209', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('210', 2, 'Deluxe', 4200.00, 2, 4, 3, 1, 'WiFi, TV, AC, Mini Bar', 'systemphotos/room-deluxe.jpg', 'available'),
('303', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('304', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('305', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('306', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('307', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('308', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('309', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available'),
('310', 3, 'Suite', 7800.00, 3, 6, 4, 2, 'WiFi, TV, AC, Jacuzzi, Living Area', 'systemphotos/room-suite.jpg', 'available');

INSERT INTO room_amenities (room_id, amenity_id)
SELECT r.id, a.id FROM rooms r CROSS JOIN housekeeping_amenities a WHERE a.status = 'active';

INSERT INTO services (name, category_id, category, price, min_room_type, description, status) VALUES
('Room Service Meal', 1, 'Food', 520.00, NULL, 'In-room meal delivery', 'active'),
('Breakfast Buffet', 1, 'Food', 450.00, NULL, 'Daily breakfast buffet per person', 'active'),
('Club Sandwich', 1, 'Food', 280.00, NULL, 'Triple-decker sandwich with fries', 'active'),
('Chicken Adobo Meal', 1, 'Food', 320.00, NULL, 'Filipino classic with garlic rice', 'active'),
('Fresh Fruit Platter', 1, 'Food', 350.00, NULL, 'Seasonal fruits delivered to the room', 'active'),
('Steamed Rice', 1, 'Food', 60.00, NULL, 'Steamed white rice, one serving', 'active'),
('Pancake Stack', 1, 'Food', 220.00, NULL, 'Pancakes with syrup and butter', 'active'),
('Garden Salad', 1, 'Food', 240.00, NULL, 'Fresh garden salad with house dressing', 'active'),
('Hot Coffee', 1, 'Food', 90.00, NULL, 'Freshly brewed coffee', 'active'),
('Hot Tea', 1, 'Food', 70.00, NULL, 'Selection of hot tea', 'active'),
('Bottled Water', 1, 'Food', 45.00, NULL, 'Chilled bottled drinking water', 'active'),
('Vegetable Soup', 1, 'Food', 180.00, NULL, 'Warm seasonal vegetable soup', 'active'),
('Wash & Fold', 2, 'Laundry', 350.00, NULL, 'Wash and fold per load', 'active'),
('Laundry Press', 2, 'Laundry', 180.00, NULL, 'Pressing per piece', 'active'),
('Dry Cleaning', 2, 'Laundry', 250.00, NULL, 'Dry cleaning per piece', 'active'),
('Ironing Only', 2, 'Laundry', 80.00, NULL, 'Ironing per piece', 'active'),
('Express Laundry', 2, 'Laundry', 500.00, NULL, 'Priority wash, dry and fold service', 'active'),
('Delicate Garment Wash', 2, 'Laundry', 300.00, NULL, 'Gentle wash for delicate garments', 'active');

UPDATE services s
JOIN categories c
  ON c.category_type = 'service'
 AND LOWER(c.name) = CASE
       WHEN LOWER(s.category) IN ('food', 'restaurant', 'room service') THEN 'food'
       WHEN LOWER(s.category) = 'laundry' THEN 'laundry'
     END
SET s.category_id = c.id,
    s.category = c.name
WHERE s.category_id IS NULL
   OR s.category_id <> c.id
   OR s.category <> c.name;

INSERT INTO additional_item_types (item_name, unit_price, description, status) VALUES
('Extra Pillow', 150.00, 'Additional standard soft pillow', 'active'),
('Extra Bath Towel', 100.00, 'Fresh bath towel requested by guest', 'active'),
('Extra Bedsheet', 250.00, 'Replacement or additional bedsheet set', 'active'),
('Extra Folding Bed / Mattress', 800.00, 'Rollaway single bed with complete linens', 'active'),
('Extra Blanket', 200.00, 'Additional thermal blanket', 'active'),
('Keycard Replacement', 300.00, 'Fee for lost or damaged room keycard', 'active'),
('Dental Care Kit', 100.00, 'Toothbrush and toothpaste set', 'active'),
('Shower Cap', 40.00, 'Disposable shower cap', 'active'),
('Bedroom Slippers', 100.00, 'Comfortable disposable guest slippers', 'active'),
('Sewing Kit', 50.00, 'Small travel sewing kit', 'active'),
('Sanitary Kit', 120.00, 'Guest personal care kit', 'active');

INSERT INTO discounts (name, code, discount_type, value, description, status) VALUES
('Senior Citizen', 'senior_citizen', 'percent', 10.00, 'Senior citizen discount', 'active'),
('PWD', 'pwd', 'percent', 10.00, 'Persons with disability discount', 'active'),
('Employee', 'employee', 'percent', 10.00, 'Hotel employee discount', 'active');

INSERT INTO guests (user_id, full_name, first_name, last_name, email, phone) VALUES
  (3, 'Sample Guest', 'Sample', 'Guest', 'guest@gmail.com', '09000000003');
