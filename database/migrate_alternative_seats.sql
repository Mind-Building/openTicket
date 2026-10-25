-- --------------------------------------------------------
-- Alternativbelegung für Sitze (z. B. Tribüne -> Gebäude)
--
-- Jeder Sitz (normaler Sitz, Rollstuhlplatz, Tischplatz) kann zusätzlich
-- zu seiner originären Bezeichnung (Reihe/Platz bzw. Tisch/Platz) eine
-- alternative Reihe + Platznummer in einem anderen Veranstaltungsort haben.
-- --------------------------------------------------------

ALTER TABLE `seat_plan_elements`
    ADD COLUMN IF NOT EXISTS `alt_row` varchar(100) DEFAULT NULL AFTER `table_seat_number`,
    ADD COLUMN IF NOT EXISTS `alt_seat` varchar(50) DEFAULT NULL AFTER `alt_row`;

-- Name des alternativen Veranstaltungsortes (z. B. "Stadthalle"),
-- wird als Platzhalter auf dem Ticket-Layout verwendet.
ALTER TABLE `events`
    ADD COLUMN IF NOT EXISTS `alt_location_name` varchar(200) DEFAULT NULL AFTER `reservation_phone`;
