<?php
/**
 * Backfill der Umbuchungs-Historie aus den Logdateien.
 *
 * Vor Einfuehrung der Tabelle `rebookings` wurde jede Umbuchung nur als
 * Log-Eintrag `reservation_rebooked` (Kanal admin/presales) festgehalten.
 * Dieses Skript liest diese Eintraege und schreibt sie in die Tabelle,
 * damit auch bereits erfolgte Umbuchungen in der Uebersicht erscheinen.
 *
 * Idempotent: dank UNIQUE(reservation_id, created_at) werden bereits
 * vorhandene Eintraege uebersprungen. Das Skript kann gefahrlos mehrfach
 * laufen.
 *
 * Die eigentliche Logik steckt in App\Models\Rebooking::backfillFromLogs(),
 * damit sie auch ueber die Admin-Oberflaeche aufrufbar ist.
 *
 * Aufruf:
 *   php app/scripts/backfill_rebookings.php
 *   php app/scripts/backfill_rebookings.php --dir=/pfad/zu/logging
 *   php app/scripts/backfill_rebookings.php --dry-run
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Models\Rebooking;
use App\Services\LoggerService;

$args   = array_slice($argv, 1);
$dir    = LoggerService::getLogPath();
$dryRun = false;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, "Unbekanntes Argument: $arg\n");
        exit(1);
    }
}

$res = (new Rebooking())->backfillFromLogs($dir, $dryRun);

foreach ($res['errors'] as $err) {
    fwrite(STDERR, $err . "\n");
}

if ($dryRun) {
    foreach ($res['entries'] as $e) {
        fwrite(STDOUT, sprintf(
            "[%s] %s  Res #%d: %s / %s  ->  %s / %s\n",
            $e['created_at'], $e['channel'], $e['reservation_id'],
            $e['old_date'], $e['old_seat'], $e['new_date'], $e['new_seat']
        ));
    }
    fwrite(STDOUT, "\nDry-Run: {$res['scanned']} Log-Eintraege, {$res['items']} Umbuchungen gefunden. Nichts geschrieben.\n");
} else {
    fwrite(STDOUT, "Fertig: {$res['scanned']} Log-Eintraege, {$res['items']} Umbuchungen verarbeitet, "
        . "{$res['inserted']} neu, {$res['skipped']} bereits vorhanden.\n");
}

if (!empty($res['errors'])) {
    exit(1);
}
