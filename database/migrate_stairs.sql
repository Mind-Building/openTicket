-- --------------------------------------------------------
-- Abstände im Sitzplan: normaler Abstand oder Treppe
--
-- Ein Abstand (element_type 'distance') kann als Treppe markiert werden; Treppen
-- werden in allen Sitzplan-Ansichten weiß mit schwarzem Rahmen gezeichnet.
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- --------------------------------------------------------

ALTER TABLE `seat_plan_elements`
    ADD COLUMN IF NOT EXISTS `is_stairs` tinyint(1) NOT NULL DEFAULT 0 AFTER `consumes_number`;
