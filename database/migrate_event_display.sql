-- --------------------------------------------------------
-- Pro Veranstaltung: Gutschein-Infotext, Gutschein-Mail-Motiv, Sitzplan-Farben
--
-- voucher_info_text        Hinweistext im Gutschein-Bereich (leer = Text aus den Einstellungen)
-- voucher_email_image      Motiv neben jedem Gutschein in der Mail „Ihr Gutschein“ (Pfad ab public/)
-- seatplan_distance_color  Farbe der Abstände im Sitzplan (leer = Standard grau)
-- seatplan_distance_border Abstände mit schwarzem Rahmen
-- seatplan_stairs_color    Farbe der Treppen (leer = Standard weiß)
-- seatplan_stairs_border   Treppen mit schwarzem Rahmen (Standard: ja)
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- --------------------------------------------------------

ALTER TABLE `events`
    ADD COLUMN IF NOT EXISTS `voucher_info_text` text DEFAULT NULL AFTER `voucher_template_config`,
    ADD COLUMN IF NOT EXISTS `voucher_email_image` varchar(255) DEFAULT NULL AFTER `voucher_info_text`,
    ADD COLUMN IF NOT EXISTS `seatplan_distance_color` varchar(20) DEFAULT NULL AFTER `voucher_email_image`,
    ADD COLUMN IF NOT EXISTS `seatplan_distance_border` tinyint(1) NOT NULL DEFAULT 0 AFTER `seatplan_distance_color`,
    ADD COLUMN IF NOT EXISTS `seatplan_stairs_color` varchar(20) DEFAULT NULL AFTER `seatplan_distance_border`,
    ADD COLUMN IF NOT EXISTS `seatplan_stairs_border` tinyint(1) NOT NULL DEFAULT 1 AFTER `seatplan_stairs_color`;
