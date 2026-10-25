-- Ticket System 2.0 - New Database Schema
-- All terms in English
-- PHP 8.1+ compatible

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Users table (for admin and presales office)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','presales') NOT NULL DEFAULT 'presales',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role` (`role`),
  KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Configurations table (replaces config files)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `configurations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `type` enum('string','integer','boolean','json') NOT NULL DEFAULT 'string',
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Rate limits (Brute-Force-/API-Schutz, App\Core\RateLimiter)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `key` varchar(190) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `first_attempt` datetime NOT NULL,
  `last_attempt` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`key`),
  KEY `last_attempt` (`last_attempt`),
  KEY `blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Events (formerly: veranstaltungen)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `sell_tickets` tinyint(1) NOT NULL DEFAULT 0,
  `sell_vouchers` tinyint(1) NOT NULL DEFAULT 0,
  `allow_reduced_prices` tinyint(1) NOT NULL DEFAULT 0,
  `allow_fn_card` tinyint(1) NOT NULL DEFAULT 0,
  `use_seat_plan` tinyint(1) NOT NULL DEFAULT 1,
  `show_start_time` time NOT NULL DEFAULT '20:30:00',
  `max_reservation_time` time NOT NULL DEFAULT '12:00:00',
  `reservation_phone` varchar(50) DEFAULT NULL,
  `alt_location_name` varchar(200) DEFAULT NULL,
  `ticket_code_type` varchar(20) DEFAULT NULL,
  `ticket_code_content` varchar(255) DEFAULT NULL,
  `max_seats` int(11) DEFAULT NULL,
  `seat_category_id` int(11) DEFAULT NULL,
  `email_ticket_subject` varchar(255) DEFAULT NULL,
  `email_ticket_body` text DEFAULT NULL,
  `ticket_pdf_template` varchar(255) DEFAULT NULL,
  `ticket_template_config` text DEFAULT NULL,
  `presales_ticket_pdf_template` varchar(255) DEFAULT NULL,
  `presales_ticket_template_config` text DEFAULT NULL,
  `voucher_use_template` tinyint(1) NOT NULL DEFAULT 0,
  `voucher_template` varchar(255) DEFAULT NULL,
  `voucher_template_config` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Event dates (formerly: veranstaltungstermine)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `event_dates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `show_start_time` time DEFAULT NULL,
  `is_general_rehearsal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `date` (`date`),
  CONSTRAINT `fk_event_dates_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Seat categories (formerly: sitzkategorien)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `seat_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `sign` varchar(10) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `reduced_price` decimal(10,2) DEFAULT NULL,
  `general_rehearsal_price` decimal(10,2) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `is_general_rehearsal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `fk_seat_categories_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Seat plan rows
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `seat_plan_rows` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `row_number` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `row_name` varchar(100) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `fk_seat_plan_rows_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_seat_plan_rows_category` FOREIGN KEY (`category_id`) REFERENCES `seat_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Seat plan elements (seats, tables, distances, etc.)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `seat_plan_elements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `row_id` int(11) NOT NULL,
  `element_type` enum('seat','table','distance','spacer','wheelchair') NOT NULL,
  `element_number` int(11) DEFAULT NULL,
  `table_number` int(11) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `table_seat_area` enum('left','right','back','front') DEFAULT NULL,
  `table_seat_number` int(11) DEFAULT NULL,
  `alt_row` varchar(100) DEFAULT NULL,
  `alt_seat` varchar(50) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `consumes_number` tinyint(1) NOT NULL DEFAULT 0,
  `is_stairs` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `row_id` (`row_id`),
  KEY `category_id` (`category_id`),
  KEY `element_type` (`element_type`),
  CONSTRAINT `fk_seat_plan_elements_row` FOREIGN KEY (`row_id`) REFERENCES `seat_plan_rows` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_seat_plan_elements_category` FOREIGN KEY (`category_id`) REFERENCES `seat_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Customers (formerly: kunden)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salutation` varchar(20) DEFAULT NULL,
  `first_name` varchar(200) NOT NULL,
  `last_name` varchar(200) NOT NULL,
  `street` varchar(200) NOT NULL,
  `house_number` varchar(10) NOT NULL,
  `postal_code` varchar(10) NOT NULL,
  `city` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `phone_prefix` varchar(10) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `session_id` varchar(255) NOT NULL,
  `item_type` enum('ticket','voucher') NOT NULL DEFAULT 'ticket',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `item_type` (`item_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Vouchers (MUST be created BEFORE reservations due to FK)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_code` varchar(100) NOT NULL,
  `hash` varchar(255) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `session_id` varchar(255) NOT NULL,
  `event_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `status_id` int(2) NOT NULL DEFAULT 0,
  `is_reduced` tinyint(1) NOT NULL DEFAULT 0,
  `is_fn_card` tinyint(1) NOT NULL DEFAULT 0,
  `transformed_session_id` varchar(255) DEFAULT NULL,
  `transformed_status_id` int(2) DEFAULT NULL,
  `purchase_intent` enum('buy','redeem') NOT NULL DEFAULT 'buy',
  `mail_sent` tinyint(1) NOT NULL DEFAULT 0,
  `mail_sent_date` date DEFAULT NULL,
  `order_date` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`),
  UNIQUE KEY `voucher_code` (`voucher_code`),
  KEY `session_id` (`session_id`),
  KEY `customer_id` (`customer_id`),
  KEY `event_id` (`event_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `fk_vouchers_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_vouchers_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vouchers_category` FOREIGN KEY (`category_id`) REFERENCES `seat_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Reservations (formerly: reservierungen)
-- NOTE: Created AFTER vouchers because of FK dependency
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `event_id` int(11) NOT NULL,
  `event_date` date NOT NULL,
  `seat_identifier` varchar(100) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `status_id` int(2) NOT NULL DEFAULT 1,
  `is_reduced` tinyint(1) NOT NULL DEFAULT 0,
  `is_fn_card` tinyint(1) NOT NULL DEFAULT 0,
  `fn_card_number` varchar(20) DEFAULT NULL,
  `is_honor_card` tinyint(1) NOT NULL DEFAULT 0,
  `is_companion` tinyint(1) NOT NULL DEFAULT 0,
  `voucher_id` int(11) DEFAULT NULL,
  `order_date` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `customer_id` (`customer_id`),
  KEY `event_id` (`event_id`),
  KEY `event_date` (`event_date`),
  KEY `status_id` (`status_id`),
  KEY `category_id` (`category_id`),
  KEY `voucher_id` (`voucher_id`),
  KEY `idx_fn_card_event` (`event_id`, `fn_card_number`),
  CONSTRAINT `fk_reservations_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_reservations_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_reservations_category` FOREIGN KEY (`category_id`) REFERENCES `seat_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_reservations_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Reservation statuses
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `reservation_statuses` (
  `id` int(2) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default statuses
INSERT IGNORE INTO `reservation_statuses` (`id`, `name`, `description`) VALUES
(0, 'not_redeemed', 'Not redeemed'),
(1, 'in_cart', 'In shopping cart, not purchased yet'),
(2, 'paid_paypal', 'Paid via PayPal'),
(3, 'reserved_presales', 'Reserved via presales, not paid yet'),
(4, 'paid_presales', 'Reserved via presales, paid and collected'),
(5, 'cash_payment', 'Cash payment'),
(6, 'reserved_online', 'Reserved online, not paid yet'),
(7, 'printed_box_office', 'Printed for box office'),
(8, 'paid_voucher', 'Paid with voucher'),
(9, 'voucher_redeemed', 'Voucher redeemed'),
(10, 'fn_card_presales', 'FN Card presales'),
(11, 'vip', 'VIP ticket – free of charge, issued by admin with download link'),
(20, 'paypal_started', 'PayPal started but not completed'),
(91, 'paypal_error', 'ERROR: Paid via PayPal but without customer data');

-- --------------------------------------------------------
-- Device tokens (for presales terminals without login)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(100) NOT NULL,
  `user_id` int(11) NOT NULL,
  `label` varchar(200) NOT NULL DEFAULT '',
  `expires_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_device_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- General rehearsals (formerly: generalproben)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `general_rehearsals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `fk_general_rehearsals_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Payment requests (Admin sends payment link to customer)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `payment_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `customer_email` varchar(255) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `paypal_order_id` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `status` (`status`),
  KEY `customer_email` (`customer_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Umbuchungs-Historie (Audit-Trail fuer Sitz-/Datumswechsel)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rebookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `old_event_date` date NOT NULL,
  `old_seat_identifier` varchar(100) NOT NULL,
  `new_event_date` date NOT NULL,
  `new_seat_identifier` varchar(100) NOT NULL,
  `channel` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `reservation_id` (`reservation_id`),
  UNIQUE KEY `uniq_rebooking` (`reservation_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

