-- Migration: rate_limits table
-- Stützt die App\Core\RateLimiter-Klasse (Login-Brute-Force-Schutz + API-Rate-Limit).
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `key` varchar(190) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `first_attempt` datetime NOT NULL,
  `last_attempt` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`key`),
  KEY `last_attempt` (`last_attempt`),
  KEY `blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
