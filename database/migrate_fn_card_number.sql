-- --------------------------------------------------------
-- FN-Card-Nummer an der Reservierung speichern
--
-- Bisher wurde nur is_fn_card (ja/nein) gespeichert. Ohne Nummer ließ sich das
-- Limit "max. Tickets pro FN-Card und Veranstaltung" nicht prüfen, und die Karte
-- war in Bestelldetails und auf dem Ticket nicht sichtbar.
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- --------------------------------------------------------

ALTER TABLE `reservations`
    ADD COLUMN IF NOT EXISTS `fn_card_number` varchar(20) DEFAULT NULL AFTER `is_fn_card`,
    ADD INDEX IF NOT EXISTS `idx_fn_card_event` (`event_id`, `fn_card_number`);

-- Einstellungen für Rabatt und Limit (falls noch nicht vorhanden)
INSERT IGNORE INTO `configurations` (`key`, `value`, `type`, `description`) VALUES
    ('fn_card_factor', '0.1', 'string', 'FN Card discount factor (0.1 = 10% discount)'),
    ('max_fn_card', '2', 'integer', 'Maximum number of tickets per FN Card per event');
