-- Migration: Device-Tokens werden ab jetzt gehasht gespeichert
-- ("sha256:<hex>", 71 Zeichen) statt im Klartext, damit ein DB-Leak
-- keine verwendbaren Tokens preisgibt.
--
-- Bestehende Klartext-Tokens funktionieren weiter: findByToken() prüft
-- zuerst die gehashte Form, fällt dann auf Klartext zurück und schreibt
-- die Zeile bei der ersten Verwendung automatisch auf das Hash-Format um.
--
-- Anwenden mit:
--   mysql -u root -p <datenbankname> < database/migrate_device_token_hash.sql

ALTER TABLE `device_tokens` MODIFY `token` VARCHAR(100) NOT NULL;
