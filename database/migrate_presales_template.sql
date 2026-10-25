-- Migration: Presales-Ticket-Template (eigenes Template für den Vorverkauf)
-- Fügt zwei Spalten hinzu, die das Customer-Template spiegeln.
-- Bestandsdaten funktionieren unverändert: Ist NULL, greift der Fallback auf ticket_pdf_template.

ALTER TABLE `events`
    ADD COLUMN IF NOT EXISTS `presales_ticket_pdf_template` VARCHAR(255) DEFAULT NULL AFTER `ticket_template_config`,
    ADD COLUMN IF NOT EXISTS `presales_ticket_template_config` TEXT DEFAULT NULL AFTER `presales_ticket_pdf_template`;
