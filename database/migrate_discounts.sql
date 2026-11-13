-- --------------------------------------------------------
-- Prozent-Rabatte im Verkauf (z. B. 50 %, Gruppe 10 %, Gruppe 15 %)
--
-- Die Rabattarten werden in den Einstellungen gepflegt (Funktionen → Rabatte). Beim Verkauf
-- wird Bezeichnung und Prozentsatz an der Reservierung gespeichert, damit Preis und Ticketdruck
-- unverändert bleiben, auch wenn die Einstellung später geändert wird.
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- --------------------------------------------------------

ALTER TABLE `reservations`
    ADD COLUMN IF NOT EXISTS `discount_label` varchar(50) DEFAULT NULL AFTER `is_fn_card`,
    ADD COLUMN IF NOT EXISTS `discount_percent` decimal(5,2) DEFAULT NULL AFTER `discount_label`;
