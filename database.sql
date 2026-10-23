-- =====================================================
-- Elegance Salon Management System
-- Complete Database Schema — Aptech eProject Submission
-- Version verification: tested locally on MariaDB 10.4.32; MySQL compatibility is untested.
-- Feedback rating validation is also enforced in PHP for servers that ignore CHECK constraints.
-- Character set: utf8mb4  |  Engine: InnoDB
--
-- Import order matters — create tables with no FK first.
-- This file contains the full fresh-install schema and email defaults.
-- Use the separate additive migrations for an existing database; this file
-- includes demo seed inserts and must not be imported over live application data.
-- =====================================================

CREATE DATABASE IF NOT EXISTS elegance_salon
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE elegance_salon;

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────
-- 1. USERS  (admins, receptionists, stylists, clients)
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id          INT          NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    email       VARCHAR(254) NOT NULL,
    phone       VARCHAR(20)  DEFAULT NULL,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','receptionist','stylist','client') DEFAULT 'client',
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 2. CLIENTS  (walk-in and online customers)
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clients (
    id          INT          NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    email       VARCHAR(254) DEFAULT NULL,
    phone       VARCHAR(20)  NOT NULL,
    gender      ENUM('male','female','other') DEFAULT NULL,
    address     TEXT         DEFAULT NULL,
    preferences TEXT         DEFAULT NULL,
    notes       TEXT         DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 3. SERVICES
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS services (
    id          INT           NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100)  NOT NULL,
    description TEXT          DEFAULT NULL,
    duration    INT           NOT NULL COMMENT 'in minutes',
    price       DECIMAL(10,2) NOT NULL,
    category    VARCHAR(50)   DEFAULT NULL,
    image       VARCHAR(255)  DEFAULT NULL,   -- optional upload path
    status      ENUM('active','inactive') DEFAULT 'active',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 4. STAFF  (linked to users with role=stylist)
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff (
    id              INT           NOT NULL AUTO_INCREMENT,
    user_id         INT           DEFAULT NULL,
    specialization  VARCHAR(100)  DEFAULT NULL,
    commission_rate DECIMAL(5,2)  DEFAULT 10.00,
    schedule        TEXT          DEFAULT NULL,
    bio             TEXT          DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_staff_user_id (user_id),
    CONSTRAINT fk_staff_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 5. APPOINTMENTS
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS appointments (
    id               INT  NOT NULL AUTO_INCREMENT,
    client_id        INT  NOT NULL,
    service_id       INT  NOT NULL,
    staff_id         INT  DEFAULT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status           ENUM('pending','confirmed','completed','cancelled','no-show') DEFAULT 'pending',
    notes            TEXT DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_appt_client  (client_id),
    KEY idx_appt_service (service_id),
    KEY idx_appt_staff   (staff_id),
    CONSTRAINT fk_appt_client  FOREIGN KEY (client_id)  REFERENCES clients(id),
    CONSTRAINT fk_appt_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_appt_staff   FOREIGN KEY (staff_id)   REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 6. INVENTORY
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inventory (
    id                  INT           NOT NULL AUTO_INCREMENT,
    product_name        VARCHAR(100)  NOT NULL,
    category            VARCHAR(50)   DEFAULT NULL,
    quantity            INT           DEFAULT 0,
    unit_price          DECIMAL(10,2) DEFAULT NULL,
    supplier            VARCHAR(100)  DEFAULT NULL,
    low_stock_threshold INT           DEFAULT 10,
    last_updated        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 7. PAYMENTS
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
    id             INT           NOT NULL AUTO_INCREMENT,
    appointment_id INT           DEFAULT NULL,
    client_id      INT           NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','card','online','upi') DEFAULT 'cash',
    payment_status ENUM('pending','paid','refunded')  DEFAULT 'pending',
    invoice_number VARCHAR(50)   DEFAULT NULL,
    paid_at        TIMESTAMP     NULL DEFAULT NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pay_appointment (appointment_id),
    KEY idx_pay_client      (client_id),
    CONSTRAINT fk_pay_appointment FOREIGN KEY (appointment_id)
        REFERENCES appointments(id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_client FOREIGN KEY (client_id)
        REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 8. FEEDBACK
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS feedback (
    id          INT  NOT NULL AUTO_INCREMENT,
    client_name VARCHAR(150) DEFAULT NULL,
    email       VARCHAR(254) DEFAULT NULL,
    rating      INT          DEFAULT NULL CHECK (rating BETWEEN 1 AND 5),
    message     TEXT         DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 9. NOTIFICATIONS  (includes 'link' column)
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT          NOT NULL AUTO_INCREMENT,
    user_id    INT          DEFAULT NULL,
    title      VARCHAR(150) DEFAULT NULL,
    message    TEXT         DEFAULT NULL,
    type       ENUM('appointment','inventory','payment','system') DEFAULT 'system',
    link       VARCHAR(255) DEFAULT NULL,
    is_read    TINYINT(1)   DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 10. CONTACT MESSAGES
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS contact_messages (
    id         INT          NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    email      VARCHAR(254) NOT NULL,
    phone      VARCHAR(20)  DEFAULT NULL,
    subject    VARCHAR(100) DEFAULT NULL,
    message    TEXT         NOT NULL,
    status     ENUM('new','read','replied') DEFAULT 'new',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 11. GALLERY
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gallery (
    id          INT          NOT NULL AUTO_INCREMENT,
    title       VARCHAR(150) NOT NULL,
    category    VARCHAR(60)  NOT NULL DEFAULT 'Hair',
    image_path  VARCHAR(255) NOT NULL,
    description TEXT         DEFAULT NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 12. SUPPLIERS
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS suppliers (
    id             INT          NOT NULL AUTO_INCREMENT,
    name           VARCHAR(120) NOT NULL,
    contact_person VARCHAR(100) DEFAULT NULL,
    email          VARCHAR(100) DEFAULT NULL,
    phone          VARCHAR(30)  DEFAULT NULL,
    address        TEXT         DEFAULT NULL,
    city           VARCHAR(80)  DEFAULT NULL,
    notes          TEXT         DEFAULT NULL,
    status         ENUM('active','inactive') DEFAULT 'active',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 13. PURCHASE ORDERS
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_orders (
    id           INT           NOT NULL AUTO_INCREMENT,
    po_number    VARCHAR(30)   NOT NULL,
    supplier_id  INT           DEFAULT NULL,
    status       ENUM('pending','approved','received','cancelled') DEFAULT 'pending',
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    notes        TEXT          DEFAULT NULL,
    expected_at  DATE          DEFAULT NULL,
    received_at  TIMESTAMP     NULL DEFAULT NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_number (po_number),
    KEY idx_po_supplier (supplier_id),
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 14. PURCHASE ORDER ITEMS
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id           INT           NOT NULL AUTO_INCREMENT,
    po_id        INT           NOT NULL,
    product_id   INT           DEFAULT NULL,
    product_name VARCHAR(150)  DEFAULT NULL,
    quantity     INT           NOT NULL,
    unit_cost    DECIMAL(10,2) NOT NULL,
    line_total   DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_poi_po      (po_id),
    KEY idx_poi_product (product_id),
    CONSTRAINT fk_poi_po      FOREIGN KEY (po_id)       REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id)  REFERENCES inventory(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────
-- 15. APPOINTMENT REMINDERS  (duplicate-prevention log)
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS appointment_reminders (
    id              INT         NOT NULL AUTO_INCREMENT,
    appointment_id  INT         NOT NULL,
    reminder_type   ENUM('24h','2h') NOT NULL,
    channel         VARCHAR(50) DEFAULT 'in_app',
    sent_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appt_reminder (appointment_id, reminder_type, channel),
    CONSTRAINT fk_reminder_appt FOREIGN KEY (appointment_id)
        REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────
-- 16. EMAIL NOTIFICATION SETTINGS AND DELIVERY LOG
-- ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_notification_settings (
    setting_key VARCHAR(64) NOT NULL,
    enabled     TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_notification_log (
    event_key   VARCHAR(191) NOT NULL,
    event_type  VARCHAR(64) NOT NULL,
    status      ENUM('sending','sent','failed') NOT NULL DEFAULT 'sending',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (event_key),
    KEY idx_email_notification_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Registration and event-deduplication schema alignment ──
-- Keep older existing tables compatible if their CREATE TABLE was skipped.
ALTER TABLE users
    MODIFY COLUMN name VARCHAR(150) NOT NULL,
    MODIFY COLUMN email VARCHAR(254) NOT NULL;

ALTER TABLE clients
    MODIFY COLUMN name VARCHAR(150) NOT NULL,
    MODIFY COLUMN email VARCHAR(254) DEFAULT NULL;

-- MySQL does not support ADD COLUMN IF NOT EXISTS on all targeted versions.
SET @add_appointment_updated_at = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE appointments ADD COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at',
        'SET @appointment_updated_at_migration_noop = 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointments'
      AND COLUMN_NAME = 'updated_at'
);
PREPARE add_appointment_updated_at_stmt FROM @add_appointment_updated_at;
EXECUTE add_appointment_updated_at_stmt;
DEALLOCATE PREPARE add_appointment_updated_at_stmt;

-- Insert default preferences without overwriting administrator choices.
INSERT IGNORE INTO email_notification_settings (setting_key, enabled) VALUES
    ('master_enabled', 1),
    ('appointment_booked', 1),
    ('appointment_confirmed', 1),
    ('appointment_cancelled', 1),
    ('appointment_rescheduled', 1),
    ('appointment_completed', 1),
    ('reminder_24h', 0),
    ('reminder_2h', 0),
    ('low_stock', 0),
    ('payment_alerts', 0);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- SEED DATA  —  safe demo records for admin, receptionist, and stylist roles
-- All passwords below are bcrypt hashes of the shown
-- plaintext.  Change them after import in production.
--
-- admin@elegancesalon.com         → admin123
-- receptionist@elegancesalon.com  → reception123
-- manal@gmail.com                 → stylist123
-- sophia@elegancesalon.com        → stylist123
-- isabella@elegancesalon.com      → stylist123
-- liam@elegancesalon.com          → stylist123
-- =====================================================

-- ── Users ──────────────────────────────────────────
INSERT INTO users (id, name, email, phone, password, role, status) VALUES
(1,  'Admin User',      'admin@elegancesalon.com',        '03001234567',
     '$2y$10$TDiICzE.BJk8AnzDwYzCv.YuEZOKCnGIKYOEV/lQLmz3i8rhpDbrK', 'admin',        'active'),
(16, 'Rachel Monroe',   'receptionist@elegancesalon.com', '03011234568',
     '$2y$10$w51qOUD5iXmpk6/0xE9R8OglaeZ3pgrBLKtzmXNNQ4uYf1vH/NowC', 'receptionist', 'active'),
(8,  'Miss MANAL',      'manal@gmail.com',                '03021234569',
     '$2y$10$AiekqviqWKYGxsVJUMiGKewarwha8tYlJjJqh/fAsW6SDzxpnB.yi',  'stylist',      'active'),
(17, 'Sophia Laurent',  'sophia@elegancesalon.com',       '03031234570',
     '$2y$10$KCbB0zgBeL4LYhb3GeAJlO2iKZeCXNWBSyNr7cT2A7FSuECp.THQq',  'stylist',      'active'),
(18, 'Isabella Grace',  'isabella@elegancesalon.com',     '03041234571',
     '$2y$10$wEJBl0ktbHbFf6h2zgeyQ.juj92D6mtkrxh.vVBQnsmh/EQvYYoFS',  'stylist',      'active'),
(19, 'Liam Brooks',     'liam@elegancesalon.com',         '03051234572',
     '$2y$10$e9T90Zu2cKzjudh7AlVFTuuLxkh1B8QLYtqzgOOIYgYWGB38Ax8dK',   'stylist',      'active');

-- ── Clients ────────────────────────────────────────
INSERT INTO clients (id, name, email, phone) VALUES
(1, 'Aazaf Yousuf', 'aazafyousuf32a@gmail.com', '03309066929'),
(2, 'Sara Ahmed',   'sara.ahmed@example.com',    '03001112222'),
(3, 'Fatima Khan',  'fatima.khan@example.com',   '03003334444');

-- ── Services ───────────────────────────────────────
INSERT INTO services (id, name, description, duration, price, category, status) VALUES
(1,  'Signature Haircut & Style',  'Precision cut and professional styling',         60,  85.00,  'Hair',     'active'),
(2,  'Balayage Color & Blowout',   'Hand-painted highlights with blow dry',          120, 165.00, 'Hair',     'active'),
(3,  'Relaxing Aromatherapy Facial','Deep cleansing facial with essential oils',     75,  120.00, 'Skin',     'active'),
(4,  'Ultimate Relaxation Massage','Full body massage for complete relaxation',      90,  130.00, 'Massage',  'active'),
(5,  'Manicure & Pedicure',        'Complete nail care package',                     60,  70.00,  'Nails',    'active'),
(6,  'Bridal Makeup Package',      'Full bridal makeup and hair styling',            180, 350.00, 'Makeup',   'active'),
(7,  'Blowout & Styling',          'Professional blowout with finishing',            40,  55.00,  'Hair',     'active'),
(8,  'Microdermabrasion',          'Exfoliating skin renewal treatment',             45,  125.00, 'Skin',     'active'),
(9,  'Balayage & Highlights',      'Sun-kissed dimensional colour',                 120, 165.00, 'Hair',     'active'),
(10, 'LED Light Therapy',          'Anti-aging and acne-clearing light treatment',  30,  70.00,  'Skin',     'active'),
(11, 'Gel Manicure',               'Long-lasting gel polish application',            45,  45.00,  'Beauty',   'active'),
(12, 'Signature Facial',           'Customised deep-cleanse and hydration facial',  60,  95.00,  'Skin',     'active'),
(13, 'Hot Stone Therapy',          'Heated basalt stone relaxation massage',         75,  145.00, 'Wellness', 'active'),
(14, 'Bridal Hair Styling',        'Updo and finishing for weddings and events',    90,  180.00, 'Hair',     'active');

-- ── Staff profiles ─────────────────────────────────
INSERT INTO staff (id, user_id, specialization, commission_rate, schedule, bio) VALUES
(4,  8,  'Precision Cutting and Balayage',     18.50, 'Mon - Fri: 10:00 AM - 7:00 PM',
     'Expert cutter with a passion for transformative colour.'),
(9,  17, 'Color Mastery & Dimensional Balayage', 15.00, 'Mon - Fri: 10:00 AM - 7:00 PM',
     'Senior colourist with 8 years of luxury editorial experience.'),
(10, 18, 'Bridal Artistry & Event Styling',    15.00, 'Tue - Sat: 10:00 AM - 6:00 PM',
     'Master artist specialising in haute couture wedding looks.'),
(11, 19, 'Precision Cuts & Men''s Grooming',   12.00, 'Mon - Sat: 11:00 AM - 8:00 PM',
     'Barbering specialist renowned for structural shears and fades.');

-- ── Inventory ──────────────────────────────────────
INSERT INTO inventory (id, product_name, category, quantity, unit_price, supplier, low_stock_threshold) VALUES
(1, 'Shampoo - Premium 1L',    'Hair Care', 25, 15.00, 'BeautySupply Co',    10),
(2, 'Hair Color - Ash Blonde', 'Color',      8, 22.00, 'LOreal Distributor', 10),
(3, 'Facial Cream - Hydrating','Skin Care', 18, 28.00, 'SkinLab',             8),
(4, 'Massage Oil - Lavender',  'Wellness',  12, 18.00, 'AromaEssentials',     5),
(5, 'Nail Polish Set',         'Nails',     30, 12.00, 'NailPro',            15);

-- ── Suppliers ──────────────────────────────────────
INSERT INTO suppliers (id, name, contact_person, email, phone, city, status) VALUES
(1, 'BeautySupply Pro',          'Ahmed Ali',  'ahmed@beautysupply.com', '03001239988', 'Karachi',   'active'),
(2, 'L''Oreal Elite Distributor','Sara Noor',  'sara@lorealdist.com',    '03214567890', 'Lahore',    'active'),
(3, 'SkinLab Cosmetics',         'Raza Khan',  'raza@skinlab.pk',        '03337891234', 'Islamabad', 'active');

-- ── Gallery ────────────────────────────────────────
INSERT INTO gallery (id, title, category, image_path, status) VALUES
(1, 'Precision Balayage & Wave',      'Hair',     'assets/img/hair1.jfif',      'active'),
(2, 'Glossy Brunette Blowout',        'Hair',     'assets/img/hair2.jfif',      'active'),
(3, 'Editorial Bridal Makeup',        'Makeup',   'assets/img/beauty1.jfif',    'active'),
(4, 'Hydra-Glow Facial Therapy',      'Skin',     'assets/img/skin1.jfif',      'active'),
(5, 'Aromatherapy Hot Stone Massage', 'Wellness', 'assets/img/wellness1.jfif',  'active'),
(6, 'Haute Couture Evening Glam',     'Makeup',   'assets/img/beauty2.jfif',    'active'),
(7, 'Deep Detox Clay Ritual',         'Skin',     'assets/img/skin2.jfif',      'active'),
(8, 'Relaxation Spa Suite',           'Interior', 'assets/img/hero.jpeg',       'active');

-- ── Sample appointments (demonstrates full lifecycle) ──
INSERT INTO appointments (id, client_id, service_id, staff_id, appointment_date, appointment_time, status, notes) VALUES
(1,  1, 3,  4,  '2026-10-09', '15:00:00', 'completed', 'Regular client follow-up'),
(2,  2, 13, 9,  '2026-10-20', '16:00:00', 'completed', NULL),
(3,  2, 8,  10, '2026-10-20', '18:00:00', 'completed', NULL),
(4,  2, 11, 11, '2026-10-20', '13:00:00', 'completed', NULL),
(5,  3, 12, NULL,'2026-10-31','19:02:00', 'confirmed', 'Online booking — needs stylist assignment');

-- ── Sample payments (full service price paid) ──────
INSERT INTO payments (id, appointment_id, client_id, amount, payment_method, payment_status, invoice_number, paid_at) VALUES
(1, 2, 2, 145.00, 'cash',   'paid', 'INV-2026-0001', '2026-10-20 17:00:00'),
(2, 3, 2, 125.00, 'card',   'paid', 'INV-2026-0002', '2026-10-20 19:30:00'),
(3, 4, 2, 45.00,  'upi',    'paid', 'INV-2026-0003', '2026-10-20 14:00:00');

ALTER TABLE users
    MODIFY COLUMN name VARCHAR(150) NOT NULL,
    MODIFY COLUMN email VARCHAR(254) NOT NULL;

ALTER TABLE clients
    MODIFY COLUMN name VARCHAR(150) NOT NULL,
    MODIFY COLUMN email VARCHAR(254) DEFAULT NULL;

SET @add_appointment_updated_at = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE appointments ADD COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at',
        'SET @appointment_updated_at_migration_noop = 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointments'
      AND COLUMN_NAME = 'updated_at'
);
PREPARE add_appointment_updated_at_stmt FROM @add_appointment_updated_at;
EXECUTE add_appointment_updated_at_stmt;
DEALLOCATE PREPARE add_appointment_updated_at_stmt;
-- Additive migration for saved email preferences and email de-duplication.
-- Safe to run more than once; existing administrator choices are preserved.

CREATE TABLE IF NOT EXISTS email_notification_settings (
    setting_key VARCHAR(64) NOT NULL,
    enabled     TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_notification_log (
    event_key   VARCHAR(191) NOT NULL,
    event_type  VARCHAR(64) NOT NULL,
    status      ENUM('sending','sent','failed') NOT NULL DEFAULT 'sending',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (event_key),
    KEY idx_email_notification_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO email_notification_settings (setting_key, enabled) VALUES
    ('master_enabled', 1),
    ('appointment_booked', 1),
    ('appointment_confirmed', 1),
    ('appointment_cancelled', 1),
    ('appointment_rescheduled', 1),
    ('appointment_completed', 1),
    ('reminder_24h', 0),
    ('reminder_2h', 0),
    ('low_stock', 0),
    ('payment_alerts', 0)
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
