-- --------------------------------------------------------
-- QR-Code / Strichcode auf dem Ticket (Platzhalter {ticket_code})
--
-- Pro Veranstaltung: Art des Codes und Inhalt als Muster mit Platzhaltern,
-- z. B. "{ticket_number}" oder "FREISCHUETZ-{reservation_id}-{check}".
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- --------------------------------------------------------

ALTER TABLE `events`
    ADD COLUMN IF NOT EXISTS `ticket_code_type` varchar(20) DEFAULT NULL AFTER `alt_location_name`,
    ADD COLUMN IF NOT EXISTS `ticket_code_content` varchar(255) DEFAULT NULL AFTER `ticket_code_type`;
