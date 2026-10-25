<?php
/**
 * Helper script to replace all absolute links with url() function calls
 * Run this once to update all files
 */

$files = [
    'public/admin/events.php',
    'public/admin/events_create.php',
    'public/admin/events_edit.php',
    'public/admin/events_dates.php',
    'public/admin/seatplan.php',
    'public/admin/seatplan_categories.php',
    'public/presales/index.php',
    'public/index.php',
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    
    $content = file_get_contents($file);
    
    // Replace href="/admin/ with href="<?= url('admin/
    $content = preg_replace('/href=["\']\/(admin\/[^"\']+)["\']/', 'href="<?= url(\'$1\') ?>"', $content);
    $content = preg_replace('/href=["\']\/(presales\/[^"\']+)["\']/', 'href="<?= url(\'$1\') ?>"', $content);
    $content = preg_replace('/href=["\']\/(login\.php)["\']/', 'href="<?= url(\'$1\') ?>"', $content);
    $content = preg_replace('/href=["\']\/(logout\.php)["\']/', 'href="<?= url(\'$1\') ?>"', $content);
    $content = preg_replace('/href=["\']\/["\']/', 'href="<?= url(\'\') ?>"', $content);
    
    // Replace Location: /admin/ with Location: ' . url('admin/
    $content = preg_replace('/header\(["\']Location: \/(admin\/[^"\']+)["\']\)/', "header('Location: ' . url('$1'))", $content);
    $content = preg_replace('/header\(["\']Location: \/(presales\/[^"\']+)["\']\)/', "header('Location: ' . url('$1'))", $content);
    $content = preg_replace('/header\(["\']Location: \/(login\.php)["\']\)/', "header('Location: ' . url('$1'))", $content);
    $content = preg_replace('/header\(["\']Location: \/["\']\)/', "header('Location: ' . url(''))", $content);
    
    file_put_contents($file, $content);
    echo "Updated: $file\n";
}

echo "Done!\n";

