<?php
/**
 * Public entry point for the daily report cron script.
 * Accessible via HTTP (protected by REPORT_SECRET in .env) or triggered by cron.
 *
 * HTTP:         https://domain.de/ticket2.0/cron_report.php?secret=<REPORT_SECRET>
 * HTTP Preview: https://domain.de/ticket2.0/cron_report.php?secret=<REPORT_SECRET>&preview=1
 * CLI:          php public/cron_report.php
 *
 * Set REPORT_SECRET in .env. Leave empty to disable HTTP access entirely.
 */
require_once __DIR__ . '/../app/scripts/daily_report.php';
