<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;

Auth::requireAdmin();

$logDir = __DIR__ . '/../../logging';

// --- Discover available log files ---
$logFiles = glob($logDir . '/*.log') ?: [];
$availableChannels = ['admin', 'customer', 'presales'];
$dateSet = [];
foreach ($logFiles as $file) {
    if (preg_match('/-((\d{4}-\d{2}-\d{2}))\.log$/', basename($file), $m)) {
        $dateSet[$m[1]] = true;
    }
}
krsort($dateSet);
$availableDates = array_keys($dateSet);

// --- Mode + filter params ---
// mode: 'normal' (single day) | 'week' (7-day warnings) | 'session' | 'voucher'
//       | 'reservation' (reservation-ID search) | 'ip' (IP search)
$searchSession     = trim($_GET['session'] ?? '');
$searchVoucher     = trim($_GET['voucher'] ?? '');
$searchReservation = trim($_GET['reservation'] ?? '');
$searchIp          = trim($_GET['ip'] ?? '');
$mode              = $searchVoucher !== ''     ? 'voucher'
                   : ($searchReservation !== '' ? 'reservation'
                   : ($searchIp !== ''          ? 'ip'
                   : ($searchSession !== ''     ? 'session'
                   : ($_GET['mode'] ?? 'normal'))));
$selectedDate    = $_GET['date']    ?? ($availableDates[0] ?? date('Y-m-d'));
$selectedChannel = $_GET['channel'] ?? 'all';
$selectedLevel   = $_GET['level']   ?? 'warnings';

// Reservierungs-IDs aus dem Suchfeld (Komma/Leerzeichen-getrennt) extrahieren
$reservationIds = [];
if ($searchReservation !== '') {
    foreach (preg_split('/[\s,]+/', $searchReservation) as $part) {
        if (ctype_digit($part)) {
            $reservationIds[] = (int)$part;
        }
    }
    $reservationIds = array_values(array_unique($reservationIds));
}

$alertLevels = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
$cutoff7days = date('Y-m-d', strtotime('-6 days'));

// --- Load acknowledged hashes ---
$acknowledgedFile = __DIR__ . '/../../logging/acknowledged.json';
$acknowledged = [];
if (is_readable($acknowledgedFile)) {
    $acknowledged = json_decode(file_get_contents($acknowledgedFile), true) ?: [];
}

// --- Parse a single log line ---
function parseLogLine(string $line): ?array
{
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.+?) (\{.*\}) \[\]\s*$/', $line, $m)) {
        return null;
    }
    $context = json_decode($m[5], true) ?: [];
    $entry = [
        'datetime' => $m[1],
        'channel'  => strtolower($m[2]),
        'level'    => strtoupper($m[3]),
        'message'  => trim($m[4]),
        'context'  => $context,
    ];
    $entry['hash'] = md5($entry['datetime'] . '|' . $entry['channel'] . '|' . $entry['level'] . '|' . $entry['message']);
    return $entry;
}

// --- Match a log entry against reservation IDs ---
// Prefers structured context fields (reservation_ids, failed_id, …) so that a
// search for "711" matches the reservation but not "17110" or part of a timestamp.
// Falls back to a word-boundary match on the raw line when no field is present.
function logMatchesReservationIds(array $context, string $rawLine, array $ids): bool
{
    $idFields = ['reservation_ids', 'failed_id', 'reservation_id', 'rid', 'id'];
    $haystack = [];
    foreach ($idFields as $f) {
        if (isset($context[$f])) {
            foreach (explode(',', (string)$context[$f]) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $haystack[] = (int)$part;
                }
            }
        }
    }
    if ($haystack) {
        foreach ($ids as $id) {
            if (in_array($id, $haystack, true)) {
                return true;
            }
        }
        return false;
    }
    // No structured ID field -> word-boundary match on the raw line
    foreach ($ids as $id) {
        if (preg_match('/\b' . $id . '\b/', $rawLine)) {
            return true;
        }
    }
    return false;
}

// Reasons in a seats_released entry that mark an automatic, unattended release.
// A PayPal error followed by one of these left no stale reservation behind.
const AUTO_RELEASE_REASONS = ['cron_cleanup', 'presales_cleanup', 'timeout'];

// --- A logged PayPal client error is benign if the session was settled afterwards ---
// PayPal client-side errors (declined cards, cancellations, SDK errors) are logged
// as warnings. They are not a real problem when the same session afterwards either
// completed the payment (transient retry) or had its seats released automatically
// by the cron / presales cleanup (abandoned checkout, cleaned up as designed).
// Returns 'completed', 'released' or null.
function paypalErrorResolution(array $entry, array $completedSessions, array $releasedSessions): ?string
{
    // Log messages are prefixed by the logger (e.g. "Action: paypal_client_error").
    if (!str_ends_with($entry['message'], 'paypal_client_error')) {
        return null;
    }
    $sid = substr((string)($entry['context']['session_id'] ?? ''), 0, 32);
    if ($sid === '') {
        return null;
    }
    // Resolution must have happened at or after the error (same session, "danach").
    if (isset($completedSessions[$sid]) && $completedSessions[$sid] >= $entry['datetime']) {
        return 'completed';
    }
    if (isset($releasedSessions[$sid]) && $releasedSessions[$sid] >= $entry['datetime']) {
        return 'released';
    }
    return null;
}

// --- Collect sessions that were settled after an error ---
// $completedSessions: session reached a successful payment completion.
// $releasedSessions:  session's seats were released by an automatic cleanup.
// Both map: normalised session-id (first 32 chars) => latest datetime.
$completedSessions = [];
$releasedSessions  = [];
foreach ($logFiles as $file) {
    if (!preg_match('/^customer-\d{4}-\d{2}-\d{2}$/', basename($file, '.log'))) continue;
    $raw = file_get_contents($file);
    if ($raw === false) continue;
    $hasCompleted = str_contains($raw, 'payment_completed');
    $hasReleased  = str_contains($raw, 'seats_released');
    if (!$hasCompleted && !$hasReleased) continue;
    foreach (explode("\n", $raw) as $line) {
        $isCompletedLine = $hasCompleted && str_contains($line, 'payment_completed');
        $isReleasedLine  = $hasReleased  && str_contains($line, 'seats_released');
        if (!$isCompletedLine && !$isReleasedLine) continue;
        $p = parseLogLine(trim($line));
        if (!$p) continue;
        $sid = substr((string)($p['context']['session_id'] ?? ''), 0, 32);
        if ($sid === '') continue;

        // Matches both 'payment_completed' and 'paypal_payment_completed'.
        if (str_contains($p['message'], 'payment_completed')) {
            if (!isset($completedSessions[$sid]) || $completedSessions[$sid] < $p['datetime']) {
                $completedSessions[$sid] = $p['datetime'];
            }
        } elseif (str_ends_with($p['message'], 'seats_released')
                  && in_array($p['context']['reason'] ?? '', AUTO_RELEASE_REASONS, true)) {
            if (!isset($releasedSessions[$sid]) || $releasedSessions[$sid] < $p['datetime']) {
                $releasedSessions[$sid] = $p['datetime'];
            }
        }
    }
}

// --- Load entries depending on mode ---
$entries = [];

if ($mode === 'normal') {
    // Single day, selected channel
    $channelsToLoad = $selectedChannel === 'all' ? $availableChannels : [$selectedChannel];
    foreach ($channelsToLoad as $ch) {
        $file = $logDir . '/' . $ch . '-' . $selectedDate . '.log';
        if (!is_readable($file)) continue;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) > 1000) $lines = array_slice($lines, -1000);
        foreach ($lines as $line) {
            $parsed = parseLogLine($line);
            if ($parsed) $entries[] = $parsed;
        }
    }
    if ($selectedLevel === 'warnings') {
        $entries = array_filter($entries, fn($e) => in_array($e['level'], $alertLevels));
    }

} elseif ($mode === 'week') {
    // Last 7 days, selected channel, warnings+ only
    foreach ($logFiles as $file) {
        $base = basename($file, '.log');
        if (!preg_match('/^(admin|customer|presales)-(\d{4}-\d{2}-\d{2})$/', $base, $m)) continue;
        if ($m[2] < $cutoff7days) continue;
        if ($selectedChannel !== 'all' && $m[1] !== $selectedChannel) continue;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $parsed = parseLogLine($line);
            if ($parsed && in_array($parsed['level'], $alertLevels)) {
                $entries[] = $parsed;
            }
        }
    }

} elseif ($mode === 'session') {
    // Search across all files for the session ID string
    foreach ($logFiles as $file) {
        // Quick file-level check before parsing every line
        $raw = file_get_contents($file);
        if ($raw === false || !str_contains($raw, $searchSession)) continue;
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if (!str_contains($line, $searchSession)) continue;
            $parsed = parseLogLine(trim($line));
            if ($parsed) $entries[] = $parsed;
        }
    }

} elseif ($mode === 'voucher') {
    // Search across all files for the voucher code
    $needle = strtoupper($searchVoucher);
    foreach ($logFiles as $file) {
        $raw = file_get_contents($file);
        if ($raw === false || !str_contains(strtoupper($raw), $needle)) continue;
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if (!str_contains(strtoupper($line), $needle)) continue;
            $parsed = parseLogLine(trim($line));
            if ($parsed) $entries[] = $parsed;
        }
    }

} elseif ($mode === 'reservation') {
    // Search across all files for one or more reservation IDs (field-aware)
    if (!empty($reservationIds)) {
        $idPattern = '/\b(' . implode('|', $reservationIds) . ')\b/';
        foreach ($logFiles as $file) {
            $raw = file_get_contents($file);
            if ($raw === false || !preg_match($idPattern, $raw)) continue;
            foreach (explode("\n", $raw) as $line) {
                if (!preg_match($idPattern, $line)) continue;
                $parsed = parseLogLine(trim($line));
                if (!$parsed) continue;
                if (logMatchesReservationIds($parsed['context'], $line, $reservationIds)) {
                    $entries[] = $parsed;
                }
            }
        }
    }

} elseif ($mode === 'ip') {
    // Search across all files for an exact IP (boundary-safe: 1.2.3.4 != 1.2.3.40)
    $ipPattern = '/(?<![\d.])' . preg_quote($searchIp, '/') . '(?![\d.])/';
    foreach ($logFiles as $file) {
        $raw = file_get_contents($file);
        if ($raw === false || !str_contains($raw, $searchIp)) continue;
        foreach (explode("\n", $raw) as $line) {
            if (!preg_match($ipPattern, $line)) continue;
            $parsed = parseLogLine(trim($line));
            if ($parsed) $entries[] = $parsed;
        }
    }
}

// Sort descending by datetime
usort($entries, fn($a, $b) => strcmp($b['datetime'], $a['datetime']));
$entryCount = count($entries);

// --- 7-day summary across all files ---
$summary = [];
foreach ($availableChannels as $ch) {
    $summary[$ch] = ['total' => 0, 'warnings' => 0, 'errors' => 0];
}
foreach ($logFiles as $file) {
    $base = basename($file, '.log');
    if (!preg_match('/^(admin|customer|presales)-(\d{4}-\d{2}-\d{2})$/', $base, $m)) continue;
    if ($m[2] < $cutoff7days) continue;
    $ch    = $m[1];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $parsed = parseLogLine($line);
        if (!$parsed) continue;
        // PayPal client errors whose session was settled afterwards are not real issues.
        $isAcknowledged = isset($acknowledged[$parsed['hash']])
            || paypalErrorResolution($parsed, $completedSessions, $releasedSessions) !== null;
        $summary[$ch]['total']++;
        if ($parsed['level'] === 'WARNING' && !$isAcknowledged) {
            $summary[$ch]['warnings']++;
        } elseif (in_array($parsed['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']) && !$isAcknowledged) {
            $summary[$ch]['errors']++;
        }
    }
}

// --- Conversion Funnel Analysis (last 7 days, customer channel) ---
$funnelSessions = [
    'reserved'  => [],  // seats_reserved = Sitz ausgewählt
    'checkout'  => [],  // payment_started = Checkout aufgerufen
    'completed' => [],  // payment_completed = Zahlung abgeschlossen
    'timeout'   => [],  // cart_cleared reason=timeout = abgelaufen
];
foreach ($logFiles as $file) {
    $base = basename($file, '.log');
    if (!preg_match('/^customer-(\d{4}-\d{2}-\d{2})$/', $base, $m)) continue;
    if ($m[1] < $cutoff7days) continue;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $p = parseLogLine($line);
        if (!$p) continue;
        $sid = $p['context']['session_id'] ?? '';
        if ($sid === '' || str_starts_with($sid, 'payment_request_')) continue;
        $msg = $p['message'];
        if (str_contains($msg, 'seats_reserved') || str_contains($msg, 'seats_added_to_cart')) {
            $funnelSessions['reserved'][$sid] = true;
        } elseif (str_contains($msg, 'payment_started')) {
            $funnelSessions['checkout'][$sid] = true;
        } elseif (str_contains($msg, 'payment_completed')) {
            $funnelSessions['completed'][$sid] = true;
        } elseif (str_contains($msg, 'cart_cleared') && ($p['context']['reason'] ?? '') === 'timeout') {
            $funnelSessions['timeout'][$sid] = true;
        }
    }
}
$fc = [
    'reserved'  => count($funnelSessions['reserved']),
    'checkout'  => count($funnelSessions['checkout']),
    'completed' => count($funnelSessions['completed']),
    'timeout'   => count($funnelSessions['timeout']),
];
$fc['abandoned']    = count(array_diff_key($funnelSessions['reserved'], $funnelSessions['completed']));
$completedKnown     = count(array_intersect_key($funnelSessions['reserved'], $funnelSessions['completed']));
// Use the highest stage as base when cart logging is missing (e.g. recently added)
$fc['funnelBase']   = max($fc['reserved'], $fc['checkout'], $fc['completed']);
$fc['abandonRate']  = $fc['funnelBase'] > 0 ? round($fc['abandoned']   / $fc['funnelBase'] * 100) : 0;
$fc['convertRate']  = $fc['funnelBase'] > 0 ? round($fc['completed']   / $fc['funnelBase'] * 100) : 0;

// --- Display helpers ---
function levelBadge(string $level): string
{
    $map = [
        'DEBUG' => 'secondary', 'INFO' => 'info', 'NOTICE' => 'primary',
        'WARNING' => 'warning', 'ERROR' => 'danger',
        'CRITICAL' => 'danger', 'ALERT' => 'danger', 'EMERGENCY' => 'danger',
    ];
    $cls  = $map[$level] ?? 'secondary';
    $text = $level === 'WARNING' ? 'text-dark' : 'text-white';
    return '<span class="badge bg-' . $cls . ' ' . $text . '">' . htmlspecialchars($level) . '</span>';
}

function rowClass(string $level): string
{
    return match ($level) {
        'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'table-danger',
        'WARNING'                                  => 'table-warning',
        default                                    => '',
    };
}

function channelLabel(string $ch): string
{
    return match ($ch) {
        'admin'    => '<span class="badge bg-dark">Admin</span>',
        'customer' => '<span class="badge bg-primary">Kunde</span>',
        'presales' => '<span class="badge bg-success">Vorverkauf</span>',
        default    => '<span class="badge bg-secondary">' . htmlspecialchars($ch) . '</span>',
    };
}

$baseUrl = url('admin/logs.php');

// --- Build summary cards ---
$channelLabels = ['admin' => 'Admin', 'customer' => 'Kunden', 'presales' => 'Vorverkauf'];
$summaryHtml = '';
foreach ($availableChannels as $ch) {
    $stat  = $summary[$ch];
    $label = $channelLabels[$ch];

    if ($stat['errors'] > 0)        { $cardCls = 'border-danger';  $iconCls = 'bi-exclamation-octagon-fill text-danger'; }
    elseif ($stat['warnings'] > 0)  { $cardCls = 'border-warning'; $iconCls = 'bi-exclamation-triangle-fill text-warning'; }
    else                            { $cardCls = 'border-success';  $iconCls = 'bi-check-circle-fill text-success'; }

    // Link to week view for this channel (only if there's something to show)
    $weekUrl     = $baseUrl . '?mode=week&channel=' . urlencode($ch);
    $weekUrlAll  = $baseUrl . '?mode=week&channel=all';
    $hasIssues   = $stat['errors'] > 0 || $stat['warnings'] > 0;

    $badgesHtml = '';
    if ($stat['errors'] > 0) {
        $badgesHtml .= '<a href="' . $weekUrl . '" class="badge bg-danger text-decoration-none me-1" title="7-Tage-Fehler anzeigen">'
            . $stat['errors'] . ' Fehler</a>';
    }
    if ($stat['warnings'] > 0) {
        $badgesHtml .= '<a href="' . $weekUrl . '" class="badge bg-warning text-dark text-decoration-none me-1" title="7-Tage-Warnungen anzeigen">'
            . $stat['warnings'] . ' Warnungen</a>';
    }
    if (!$hasIssues) {
        $badgesHtml = '<span class="badge bg-success">Keine Auffälligkeiten</span>';
    }

    $summaryHtml .= '
    <div class="col-md-4 mb-3">
        <div class="card h-100 border-2 ' . $cardCls . '">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi ' . $iconCls . ' fs-2"></i>
                <div>
                    <div class="fw-bold fs-5">' . htmlspecialchars($label) . '</div>
                    <div class="text-muted small">letzte 7 Tage · ' . $stat['total'] . ' Einträge gesamt</div>
                    <div class="mt-1">' . $badgesHtml . '</div>
                </div>
            </div>
        </div>
    </div>';
}

// --- Mode banner (week / session) ---
$modeBanner = '';
if ($mode === 'week') {
    $chLabel    = $selectedChannel === 'all' ? 'Alle Kanäle' : ($channelLabels[$selectedChannel] ?? $selectedChannel);
    $backHref   = $baseUrl . '?date=' . urlencode($selectedDate) . '&channel=' . urlencode($selectedChannel) . '&level=warnings';
    $modeBanner = '
<div class="alert alert-warning d-flex align-items-center justify-content-between py-2 mb-3">
    <span><i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>7-Tage-Ansicht:</strong> Alle Warnungen &amp; Fehler · ' . htmlspecialchars($chLabel) . '
        &nbsp;·&nbsp;' . date('d.m.Y', strtotime($cutoff7days)) . ' – heute
    </span>
    <a href="' . $backHref . '" class="btn btn-sm btn-outline-secondary ms-3">
        <i class="bi bi-x-lg"></i> Schließen
    </a>
</div>';
} elseif ($mode === 'session') {
    $backHref   = $baseUrl . '?date=' . urlencode($selectedDate) . '&channel=' . urlencode($selectedChannel) . '&level=' . urlencode($selectedLevel);
    $modeBanner = '
<div class="alert alert-info d-flex align-items-center justify-content-between py-2 mb-3">
    <span><i class="bi bi-search me-2"></i>
        <strong>Session-Suche:</strong> &ldquo;' . htmlspecialchars($searchSession) . '&rdquo; · ' . $entryCount . ' Treffer in allen Dateien
    </span>
    <a href="' . $backHref . '" class="btn btn-sm btn-outline-secondary ms-3">
        <i class="bi bi-x-lg"></i> Suche beenden
    </a>
</div>';
} elseif ($mode === 'voucher') {
    $backHref   = $baseUrl . '?date=' . urlencode($selectedDate) . '&channel=' . urlencode($selectedChannel) . '&level=' . urlencode($selectedLevel);
    $modeBanner = '
<div class="alert alert-info d-flex align-items-center justify-content-between py-2 mb-3">
    <span><i class="bi bi-gift me-2"></i>
        <strong>Gutschein-Suche:</strong> &ldquo;' . htmlspecialchars($searchVoucher) . '&rdquo; · ' . $entryCount . ' Treffer in allen Dateien
    </span>
    <a href="' . $backHref . '" class="btn btn-sm btn-outline-secondary ms-3">
        <i class="bi bi-x-lg"></i> Suche beenden
    </a>
</div>';
} elseif ($mode === 'reservation') {
    $idsLabel   = implode(', ', array_map(fn($i) => '#' . $i, $reservationIds));
    $backHref   = $baseUrl . '?date=' . urlencode($selectedDate) . '&channel=' . urlencode($selectedChannel) . '&level=' . urlencode($selectedLevel);
    $modeBanner = '
<div class="alert alert-info d-flex align-items-center justify-content-between py-2 mb-3">
    <span><i class="bi bi-ticket-detailed me-2"></i>
        <strong>Reservierungs-Suche:</strong> ' . htmlspecialchars($idsLabel) . ' · ' . $entryCount . ' Treffer in allen Dateien
    </span>
    <a href="' . $backHref . '" class="btn btn-sm btn-outline-secondary ms-3">
        <i class="bi bi-x-lg"></i> Suche beenden
    </a>
</div>';
} elseif ($mode === 'ip') {
    $backHref   = $baseUrl . '?date=' . urlencode($selectedDate) . '&channel=' . urlencode($selectedChannel) . '&level=' . urlencode($selectedLevel);
    $modeBanner = '
<div class="alert alert-info d-flex align-items-center justify-content-between py-2 mb-3">
    <span><i class="bi bi-hdd-network me-2"></i>
        <strong>IP-Suche:</strong> &ldquo;' . htmlspecialchars($searchIp) . '&rdquo; · ' . $entryCount . ' Treffer in allen Dateien
    </span>
    <a href="' . $backHref . '" class="btn btn-sm btn-outline-secondary ms-3">
        <i class="bi bi-x-lg"></i> Suche beenden
    </a>
</div>';
}

// --- Filter bar (only in normal mode) ---
$dateOptions = '';
foreach ($availableDates as $d) {
    $sel = $d === $selectedDate ? ' selected' : '';
    $dateOptions .= '<option value="' . htmlspecialchars($d) . '"' . $sel . '>'
        . htmlspecialchars(date('d.m.Y', strtotime($d))) . '</option>';
}

$channelTabHtml = function (string $ch, string $label) use ($selectedChannel, $selectedDate, $selectedLevel, $baseUrl): string {
    $active = $selectedChannel === $ch ? ' active' : '';
    $href   = $baseUrl . '?date=' . urlencode($selectedDate) . '&channel=' . urlencode($ch) . '&level=' . urlencode($selectedLevel);
    return '<a href="' . $href . '" class="btn btn-sm btn-outline-secondary' . $active . '">' . $label . '</a>';
};

$toggleLevel = $selectedLevel === 'warnings' ? 'all' : 'warnings';
$toggleLabel = $selectedLevel === 'warnings'
    ? '<i class="bi bi-list-ul"></i> Alle Einträge'
    : '<i class="bi bi-exclamation-triangle"></i> Nur Warnungen';
$toggleHref = $baseUrl . '?date=' . urlencode($selectedDate) . '&channel=' . urlencode($selectedChannel) . '&level=' . urlencode($toggleLevel);

$filterBar = $mode === 'normal' ? '
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <input type="hidden" name="channel" value="' . htmlspecialchars($selectedChannel) . '">
                <input type="hidden" name="level"   value="' . htmlspecialchars($selectedLevel) . '">
                <label class="mb-0 fw-semibold text-nowrap"><i class="bi bi-calendar3"></i> Datum:</label>
                <select name="date" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
                    ' . $dateOptions . '
                </select>
            </form>
            <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold text-nowrap">Kanal:</span>
                <div class="btn-group btn-group-sm">
                    ' . $channelTabHtml('all', 'Alle') . '
                    ' . $channelTabHtml('admin', 'Admin') . '
                    ' . $channelTabHtml('customer', 'Kunden') . '
                    ' . $channelTabHtml('presales', 'Vorverkauf') . '
                </div>
            </div>
            <a href="' . $toggleHref . '" class="btn btn-sm btn-outline-secondary">' . $toggleLabel . '</a>
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0 ms-auto">
                <input type="hidden" name="date"    value="' . htmlspecialchars($selectedDate) . '">
                <input type="hidden" name="channel" value="' . htmlspecialchars($selectedChannel) . '">
                <input type="hidden" name="level"   value="' . htmlspecialchars($selectedLevel) . '">
                <div class="input-group input-group-sm" style="width:240px">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="session" class="form-control" placeholder="Session-ID suchen…" value="' . htmlspecialchars($searchSession) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <input type="hidden" name="date"    value="' . htmlspecialchars($selectedDate) . '">
                <input type="hidden" name="channel" value="' . htmlspecialchars($selectedChannel) . '">
                <input type="hidden" name="level"   value="' . htmlspecialchars($selectedLevel) . '">
                <div class="input-group input-group-sm" style="width:240px">
                    <span class="input-group-text"><i class="bi bi-gift"></i></span>
                    <input type="text" name="voucher" class="form-control" placeholder="Gutscheincode suchen…" value="' . htmlspecialchars($searchVoucher) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <div class="input-group input-group-sm" style="width:240px">
                    <span class="input-group-text"><i class="bi bi-ticket-detailed"></i></span>
                    <input type="text" name="reservation" class="form-control" placeholder="Reservierungs-ID(s)…" value="' . htmlspecialchars($searchReservation) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <div class="input-group input-group-sm" style="width:200px">
                    <span class="input-group-text"><i class="bi bi-hdd-network"></i></span>
                    <input type="text" name="ip" class="form-control" placeholder="IP-Adresse…" value="' . htmlspecialchars($searchIp) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
        </div>
    </div>
</div>' : '
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <input type="hidden" name="date"    value="' . htmlspecialchars($selectedDate) . '">
                <input type="hidden" name="channel" value="' . htmlspecialchars($selectedChannel) . '">
                <input type="hidden" name="level"   value="' . htmlspecialchars($selectedLevel) . '">
                <div class="input-group input-group-sm" style="width:260px">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="session" class="form-control" placeholder="Session-ID suchen…" value="' . htmlspecialchars($searchSession) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <input type="hidden" name="date"    value="' . htmlspecialchars($selectedDate) . '">
                <input type="hidden" name="channel" value="' . htmlspecialchars($selectedChannel) . '">
                <input type="hidden" name="level"   value="' . htmlspecialchars($selectedLevel) . '">
                <div class="input-group input-group-sm" style="width:260px">
                    <span class="input-group-text"><i class="bi bi-gift"></i></span>
                    <input type="text" name="voucher" class="form-control" placeholder="Gutscheincode suchen…" value="' . htmlspecialchars($searchVoucher) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <div class="input-group input-group-sm" style="width:260px">
                    <span class="input-group-text"><i class="bi bi-ticket-detailed"></i></span>
                    <input type="text" name="reservation" class="form-control" placeholder="Reservierungs-ID(s)…" value="' . htmlspecialchars($searchReservation) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
            <form method="get" action="' . $baseUrl . '" class="d-flex align-items-center gap-2 mb-0">
                <div class="input-group input-group-sm" style="width:220px">
                    <span class="input-group-text"><i class="bi bi-hdd-network"></i></span>
                    <input type="text" name="ip" class="form-control" placeholder="IP-Adresse…" value="' . htmlspecialchars($searchIp) . '">
                    <button type="submit" class="btn btn-outline-secondary">Suchen</button>
                </div>
            </form>
        </div>
    </div>
</div>';

// --- Build entry rows ---
$rowsHtml = '';
$rowIndex  = 0;
$ackApiUrl = url('api/log_acknowledge.php');
foreach ($entries as $e) {
    $ctxJson    = $e['context'] ? json_encode($e['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
    $collapseId = 'ctx-' . $rowIndex++;
    $hasCtx     = $ctxJson !== '' && $ctxJson !== '[]';
    $isAcked    = isset($acknowledged[$e['hash']]);
    $ackedBy    = $isAcked ? ($acknowledged[$e['hash']]['by'] . ' am ' . $acknowledged[$e['hash']]['at']) : '';
    $paypalResolution = paypalErrorResolution($e, $completedSessions, $releasedSessions);

    $quick = [];
    if (!empty($e['context']['ip'])) {
        $ip     = $e['context']['ip'];
        $ipUrl  = $baseUrl . '?ip=' . urlencode($ip);
        $quick[] = '<small class="text-muted"><i class="bi bi-hdd-network"></i> <a href="' . $ipUrl . '" title="Alle Einträge dieser IP">' . htmlspecialchars($ip) . '</a></small>';
    }
    if (!empty($e['context']['username'])) {
        $uid       = $e['context']['user_id'] ?? null;
        $userLabel = htmlspecialchars($e['context']['username']);
        if ($uid) {
            $userUrl   = url('admin/users_edit.php?id=' . urlencode((string)$uid));
            $quick[]   = '<small class="text-muted"><i class="bi bi-person"></i> <a href="' . $userUrl . '" title="Benutzer bearbeiten">' . $userLabel . '</a></small>';
        } else {
            $quick[]   = '<small class="text-muted"><i class="bi bi-person"></i> ' . $userLabel . '</small>';
        }
    } elseif (!empty($e['context']['user_id'])) {
        $uid      = $e['context']['user_id'];
        $isCust   = $e['channel'] === 'customer';
        $userUrl  = url(($isCust ? 'admin/customers_edit.php' : 'admin/users_edit.php') . '?id=' . urlencode((string)$uid));
        $userTitle = $isCust ? 'Kunde anzeigen' : 'Benutzer bearbeiten';
        $quick[]  = '<small class="text-muted"><i class="bi bi-person"></i> <a href="' . $userUrl . '" title="' . $userTitle . '">ID ' . htmlspecialchars((string)$uid) . '</a></small>';
    }
    if (!empty($e['context']['session_id'])) {
        $sid      = $e['context']['session_id'];
        $sidShort = substr($sid, 0, 12) . '…';
        $sidUrl   = $baseUrl . '?session=' . urlencode($sid);
        $quick[]  = '<small class="text-muted"><i class="bi bi-key"></i> <a href="' . $sidUrl . '" title="Alle Einträge dieser Session">' . htmlspecialchars($sidShort) . '</a></small>';
    }
    if (!empty($e['context']['voucher_code'])) {
        $vc     = $e['context']['voucher_code'];
        $vcUrl  = $baseUrl . '?voucher=' . urlencode($vc);
        $quick[] = '<small class="text-muted"><i class="bi bi-gift"></i> <a href="' . $vcUrl . '" title="Alle Einträge dieses Gutscheins">' . htmlspecialchars($vc) . '</a></small>';
    }
    if (!empty($e['context']['reason'])) {
        $quick[] = '<small class="text-muted"><i class="bi bi-info-circle"></i> ' . htmlspecialchars($e['context']['reason']) . '</small>';
    }
    if (!empty($e['context']['customer_name'])) {
        $quick[] = '<small class="text-muted"><i class="bi bi-person-badge"></i> ' . htmlspecialchars($e['context']['customer_name']) . '</small>';
    }
    if (!empty($e['context']['reservation_ids'])) {
        $rawResIds = $e['context']['reservation_ids'];
        if (is_array($rawResIds)) {
            $resIds = array_filter(array_map('intval', $rawResIds));
        } else {
            $resIds = array_filter(array_map('trim', explode(',', (string)$rawResIds)));
        }
        $resLinks = [];
        foreach ($resIds as $rid) {
            $resUrl = url('admin/reservations.php?reservation_id=' . (int)$rid);
            $resLinks[] = '<a href="' . $resUrl . '" title="Reservierung ' . htmlspecialchars($rid) . ' anzeigen">#' . htmlspecialchars($rid) . '</a>';
        }
        $quick[] = '<small class="text-muted"><i class="bi bi-ticket"></i> ' . implode(', ', $resLinks) . '</small>';
    }
    $quickHtml = $quick ? (' <span class="ms-2 d-inline-flex gap-2 flex-wrap">' . implode('', $quick) . '</span>') : '';

    $ctxToggle = $hasCtx
        ? '<button class="btn btn-sm btn-link p-0 ms-1 text-muted" data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '" title="JSON-Kontext"><i class="bi bi-chevron-down"></i></button>'
        : '';
    $ctxBlock = $hasCtx
        ? '<tr class="collapse" id="' . $collapseId . '"><td colspan="5" class="p-0"><pre class="bg-light border-0 rounded-0 m-0 px-3 py-2 small text-secondary" style="max-height:200px;overflow-y:auto;">' . htmlspecialchars($ctxJson) . '</pre></td></tr>'
        : '';

    $isAlertLevel = in_array($e['level'], $alertLevels);
    $resolvedBadge = '';
    if ($paypalResolution !== null) {
        // Session was settled afterwards (paid or cleaned up) -> not a real error.
        $rowCls   = 'text-muted';
        $msgStyle = 'text-decoration:line-through;opacity:.55;';
        $ackBtn   = '';
        [$badgeTitle, $badgeText] = $paypalResolution === 'completed'
            ? ['Die gleiche Session hat danach erfolgreich bezahlt – kein echter Fehler', 'Abschluss erfolgt']
            : ['Die Sitzplätze dieser Session wurden danach automatisch freigegeben – keine Altlast', 'Plätze freigegeben'];
        $resolvedBadge = ' <span class="badge bg-success align-middle" title="' . htmlspecialchars($badgeTitle) . '"><i class="bi bi-check-circle-fill me-1"></i>' . $badgeText . '</span>';
    } elseif ($isAlertLevel && $isAcked) {
        $rowCls   = 'text-muted';
        $msgStyle = 'text-decoration:line-through;opacity:.55;';
        $ackBtn   = '<button class="btn btn-sm btn-link p-0 ms-2 text-success ack-btn" data-hash="' . htmlspecialchars($e['hash']) . '" data-action="unacknowledge" title="Behobener Fehler – klicken zum Zurücksetzen" style="font-size:1rem;line-height:1"><i class="bi bi-check-circle-fill"></i></button>';
    } elseif ($isAlertLevel) {
        $rowCls   = rowClass($e['level']);
        $msgStyle = '';
        $ackBtn   = '<button class="btn btn-sm btn-link p-0 ms-2 text-muted ack-btn" data-hash="' . htmlspecialchars($e['hash']) . '" data-action="acknowledge" title="Als behoben markieren" style="font-size:1rem;line-height:1"><i class="bi bi-circle"></i></button>';
    } else {
        $rowCls   = rowClass($e['level']);
        $msgStyle = '';
        $ackBtn   = '';
    }

    $rowsHtml .= '
        <tr class="' . $rowCls . '" id="row-' . htmlspecialchars($e['hash']) . '">
            <td class="text-nowrap small text-muted">' . htmlspecialchars($e['datetime']) . '</td>
            <td>' . channelLabel($e['channel']) . '</td>
            <td>' . levelBadge($e['level']) . '</td>
            <td><span style="' . $msgStyle . '">' . htmlspecialchars($e['message']) . '</span>' . $resolvedBadge . $quickHtml . $ctxToggle . '</td>
            <td class="text-center">' . $ackBtn . '</td>
        </tr>' . $ctxBlock;
}

if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="4" class="text-center text-muted py-4">Keine Einträge gefunden.</td></tr>';
}

// --- Status line above table ---
$statusLine = match ($mode) {
    'week'    => '<i class="bi bi-calendar-range"></i> Letzte 7 Tage · ' . $entryCount . ' Warnungen/Fehler',
    'session' => '<i class="bi bi-search"></i> ' . $entryCount . ' Treffer für Session &ldquo;' . htmlspecialchars($searchSession) . '&rdquo;',
    'voucher' => '<i class="bi bi-gift"></i> ' . $entryCount . ' Treffer für Gutschein &ldquo;' . htmlspecialchars($searchVoucher) . '&rdquo;',
    'reservation' => '<i class="bi bi-ticket-detailed"></i> ' . $entryCount . ' Treffer für Reservierung ' . htmlspecialchars(implode(', ', array_map(fn($i) => '#' . $i, $reservationIds))),
    'ip'      => '<i class="bi bi-hdd-network"></i> ' . $entryCount . ' Treffer für IP &ldquo;' . htmlspecialchars($searchIp) . '&rdquo;',
    default   => ($selectedLevel === 'warnings' ? '<i class="bi bi-funnel"></i> Nur Warnungen &amp; Fehler · ' : '') . $entryCount . ' Einträge',
};

$pageTitle   = 'System-Logs';
$showSidebar = true;
$showHeader  = true;

// --- Funnel card HTML ---
$funnelStepHtml = function(string $icon, string $label, int $count, int $total, string $colorClass) use ($fc): string {
    $pct    = $total > 0 ? round($count / $total * 100) : 0;
    $barPct = min(100, $pct);
    $barCls = match($colorClass) { 'text-success' => 'bg-success', 'text-danger' => 'bg-danger', 'text-warning' => 'bg-warning', default => 'bg-info' };
    $pctLabel = $pct > 100 ? '<span class="text-muted small" title="Mehr Abschlüsse als Cart-Starts — ältere Logs ohne Cart-Logging">~' . $pct . '%</span>' : '<span class="text-muted small">' . $pct . '%</span>';
    return '
    <div class="d-flex align-items-center gap-3 py-2 border-bottom">
        <i class="bi ' . $icon . ' fs-4 ' . $colorClass . '" style="width:28px;text-align:center"></i>
        <div class="flex-grow-1">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-semibold">' . htmlspecialchars($label) . '</span>
                <span class="fw-bold">' . $count . ' <small class="text-muted fw-normal">Sessions</small></span>
            </div>
            <div class="progress" style="height:6px">
                <div class="progress-bar ' . $barCls . '" style="width:' . $barPct . '%"></div>
            </div>
        </div>
        <div style="width:48px;text-align:right">' . $pctLabel . '</div>
    </div>';
};

$abandonBadge  = $fc['abandonRate'] >= 70 ? 'bg-danger' : ($fc['abandonRate'] >= 40 ? 'bg-warning text-dark' : 'bg-success');
$convertBadge  = $fc['convertRate'] >= 60 ? 'bg-success' : ($fc['convertRate'] >= 30 ? 'bg-warning text-dark' : 'bg-danger');

$funnelCardHtml = '
<div class="card mb-4 border-2">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
        <span class="fw-bold"><i class="bi bi-funnel-fill me-2 text-primary"></i>Bestellabbrecher &ndash; letzte 7 Tage</span>
        <div class="d-flex gap-2">
            <span class="badge ' . $convertBadge . '"><i class="bi bi-check2-circle me-1"></i>Conversion ' . $fc['convertRate'] . '%</span>
            <span class="badge ' . $abandonBadge . '"><i class="bi bi-x-circle me-1"></i>Abbruch ' . $fc['abandonRate'] . '%</span>
        </div>
    </div>
    <div class="card-body py-2 px-4">
        ' . $funnelStepHtml('bi-cart-plus', 'Warenkorb befüllt', $fc['reserved'], $fc['funnelBase'], 'text-info') . '
        ' . $funnelStepHtml('bi-credit-card', 'Checkout aufgerufen', $fc['checkout'], $fc['funnelBase'], 'text-primary') . '
        ' . $funnelStepHtml('bi-check-circle-fill', 'Zahlung abgeschlossen', $fc['completed'], $fc['funnelBase'], 'text-success') . '
        <div class="d-flex align-items-center gap-3 py-2 border-bottom">
            <i class="bi bi-hourglass-split fs-4 text-warning" style="width:28px;text-align:center"></i>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Timeout (Reservierung abgelaufen)</span>
                    <span class="fw-bold text-warning">' . $fc['timeout'] . ' <small class="text-muted fw-normal">Sessions</small></span>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3 py-2">
            <i class="bi bi-person-x-fill fs-4 text-danger" style="width:28px;text-align:center"></i>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Gesamt abgebrochen <small class="text-muted">(Warenkorb ohne Abschluss)</small></span>
                    <span class="fw-bold text-danger">' . $fc['abandoned'] . ' <small class="text-muted fw-normal">Sessions</small></span>
                </div>
            </div>
            <span class="badge ' . $abandonBadge . ' fs-6">' . $fc['abandonRate'] . '%</span>
        </div>
    </div>
</div>';

$content = '
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="bi bi-journal-text"></i> System-Logs</h2>
    <a href="' . url('admin/email_failures.php') . '" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-envelope-exclamation"></i> Mail-Fehler
    </a>
</div>

<div class="row mb-4">' . $summaryHtml . '</div>

' . $funnelCardHtml . '

' . $modeBanner . $filterBar . '

<div class="text-muted small mb-2">' . $statusLine . '</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover table-bordered mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:155px">Zeitpunkt</th>
                    <th style="width:110px">Kanal</th>
                    <th style="width:100px">Level</th>
                    <th>Meldung</th>
                    <th style="width:40px" title="Behoben"></th>
                </tr>
            </thead>
            <tbody>' . $rowsHtml . '</tbody>
        </table>
    </div>
</div>
';

$ackApiUrl = url('api/log_acknowledge.php');
$content .= '<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".ack-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            const hash   = btn.dataset.hash;
            const action = btn.dataset.action;
            btn.disabled = true;
            fetch("' . $ackApiUrl . '", {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: "hash=" + encodeURIComponent(hash) + "&action=" + encodeURIComponent(action)
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { btn.disabled = false; return; }
                const row = document.getElementById("row-" + hash);
                if (action === "acknowledge") {
                    btn.dataset.action = "unacknowledge";
                    btn.title = "Behobener Fehler \u2013 klicken zum Zur\u00FCcksetzen";
                    btn.innerHTML = "<i class=\"bi bi-check-circle-fill\"></i>";
                    btn.classList.remove("text-muted");
                    btn.classList.add("text-success");
                    if (row) {
                        row.className = "text-muted";
                        const msgSpan = row.querySelector("td:nth-child(4) > span");
                        if (msgSpan) msgSpan.style = "text-decoration:line-through;opacity:.55;";
                    }
                } else {
                    btn.dataset.action = "acknowledge";
                    btn.title = "Als behoben markieren";
                    btn.innerHTML = "<i class=\"bi bi-circle\"></i>";
                    btn.classList.remove("text-success");
                    btn.classList.add("text-muted");
                    if (row) {
                        row.className = "";
                        const msgSpan = row.querySelector("td:nth-child(4) > span");
                        if (msgSpan) msgSpan.style = "";
                    }
                }
                btn.disabled = false;
            })
            .catch(() => { btn.disabled = false; });
        });
    });
});
</script>';

require __DIR__ . '/../../app/views/layouts/main.php';
