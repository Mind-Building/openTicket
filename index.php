<?php
/**
 * Root index.php - PHP fallback redirect to /public/
 *
 * This file is only reached when mod_rewrite is disabled or the .htaccess
 * RewriteRule for ^$ does not fire. In normal operation, .htaccess rewrites
 * the empty path directly to public/index.php (bypassing this file).
 *
 * When mod_rewrite is NOT available, Apache serves this file and it
 * performs a PHP redirect to the public/ subdirectory.
 */

// Calculate the base URL path (works in subdirectory deployments)
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$publicUrl = $scriptDir . '/public/';

header('Location: ' . $publicUrl, true, 302);
exit;
