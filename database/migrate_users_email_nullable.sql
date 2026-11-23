-- --------------------------------------------------------
-- Benutzer ohne E-Mail-Adresse
--
-- users.email war in älteren Installationen NOT NULL, das Formular erlaubt aber
-- Benutzer ohne E-Mail (z. B. externe Partner). NULL statt Leerstring, damit der
-- UNIQUE-Index mehrere Benutzer ohne E-Mail zulässt.
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- --------------------------------------------------------

ALTER TABLE `users`
    MODIFY `email` varchar(200) DEFAULT NULL;

UPDATE `users` SET `email` = NULL WHERE `email` = '';
