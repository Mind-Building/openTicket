-- Migration: Add VIP ticket status
-- Run once against the existing database

INSERT IGNORE INTO `reservation_statuses` (`id`, `name`, `description`) VALUES
(11, 'vip', 'VIP ticket – free of charge, issued by admin with download link');
