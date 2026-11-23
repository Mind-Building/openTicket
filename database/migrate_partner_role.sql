-- --------------------------------------------------------
-- Rolle "Externe Partner"
--
-- Externe Verkaufsstellen verkaufen/reservieren Karten gegen Bargeld über die
-- Vorverkaufsseiten. Welche Funktionen sie haben, wird je Installation
-- (Einstellungen) und je Partner-Benutzer (users.partner_permissions) festgelegt.
-- Jede Reservierung merkt sich den Verkäufer (sold_by_user_id); Partner-Barverkäufe
-- (Status 10) werden im Admin-Bericht "Abrechnung Partner" abgerechnet
-- (partner_settled_at).
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- --------------------------------------------------------

ALTER TABLE `users`
    MODIFY `role` enum('admin','presales','partner') NOT NULL DEFAULT 'presales';

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `partner_permissions` text DEFAULT NULL AFTER `role`;

ALTER TABLE `reservations`
    ADD COLUMN IF NOT EXISTS `sold_by_user_id` int(11) DEFAULT NULL AFTER `voucher_id`,
    ADD COLUMN IF NOT EXISTS `partner_settled_at` datetime DEFAULT NULL AFTER `sold_by_user_id`,
    ADD INDEX IF NOT EXISTS `idx_sold_by_user` (`sold_by_user_id`);

-- Status 10 (früher "FN Card presales") = Barverkauf durch einen externen Partner.
-- Die ID bleibt, damit importierte FN-Card-Altdaten weiterhin passen.
INSERT INTO `reservation_statuses` (`id`, `name`, `description`) VALUES
(10, 'partner_cash', 'Cash sale by external partner (settlement via partner report)')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);
