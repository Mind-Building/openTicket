-- --------------------------------------------------------
-- Umbuchungs-Historie (Audit-Trail)
--
-- Eine Umbuchung ueberschreibt event_date/seat_identifier in der
-- reservations-Zeile. Damit das Vorher->Nachher dauerhaft und abfragbar
-- bleibt (Uebersicht je Veranstaltung, Risiko alter PDF-Ausdrucke),
-- wird jede Umbuchung zusaetzlich hier protokolliert.
--
-- Anwendung:
--   mysql -u root -p ticket < database/migrate_rebookings.sql
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
