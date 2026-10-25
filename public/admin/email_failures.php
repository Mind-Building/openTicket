<?php
/**
 * Mail-Fehler-Übersicht (nur Admin)
 *
 * Durchsucht die Logdateien (customer/admin/presales) nach fehlgeschlagenen
 * E-Mail-Versänden (email_sent / email_resend / presales_email_sent /
 * paypal_email_sent mit success:false) und listet sie gruppiert pro
 * Bestellung auf. Fehlschläge, für die später ein erfolgreicher Versand
 * mit derselben Reservierungsgruppe geloggt wurde, gelten als erledigt.
 * Für offene Fälle mit bekannten Reservierungs-IDs kann der Versand direkt
 * über api/email_resend.php wiederholt werden.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Customer;

Auth::requireAdmin();

$logDir = __DIR__ . '/../../logging';

// --- Filter params ---
$allowedDays = [7, 30, 90, 180];
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}
$showAll = ($_GET['show'] ?? 'open') === 'all';
$cutoffDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

$emailActions = ['email_sent', 'email_resend', 'presales_email_sent', 'paypal_email_sent'];

$typeLabels = [
    'ticket'      => 'Ticket-E-Mail',
    'reservation' => 'Reservierungsbestätigung',
    'presales'    => 'Vorverkaufsbestätigung',
    'pickup'      => 'Abholbestätigung',
    'vip'         => 'VIP-Ticket-E-Mail',
    'voucher'     => 'Gutschein-E-Mail',
];

// --- Scan log files ---
$failures = [];   // list of single failed attempts
$successes = [];  // group key => latest successful send datetime

foreach (glob($logDir . '/*.log') ?: [] as $file) {
    if (!preg_match('/^(admin|customer|presales)-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
        continue;
    }
    if ($m[2] < $cutoffDate) {
        continue;
    }

    $fh = fopen($file, 'r');
    if (!$fh) {
        continue;
    }
    while (($line = fgets($fh)) !== false) {
        // Cheap prefilter before the regex
        if (strpos($line, 'email_sent') === false && strpos($line, 'email_resend') === false) {
            continue;
        }
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): Action: (\w+) (\{.*\}) \[\]\s*$/', $line, $lm)) {
            continue;
        }
        $action = $lm[4];
        if (!in_array($action, $emailActions, true)) {
            continue;
        }
        $ctx = json_decode($lm[5], true);
        if (!is_array($ctx) || !array_key_exists('success', $ctx)) {
            continue;
        }

        // "E-Mail-Versand deaktiviert" ist eine Konfiguration, keine Störung
        if (($ctx['reason'] ?? '') === 'disabled') {
            continue;
        }

        // Group key: exact reservation set if logged, otherwise
        // customer + mail type + event + date (older log entries)
        $ids = [];
        if (!empty($ctx['reservation_ids'])) {
            $rawIds = is_array($ctx['reservation_ids']) ? $ctx['reservation_ids'] : explode(',', (string)$ctx['reservation_ids']);
            $ids = array_values(array_filter(array_map('intval', $rawIds)));
            sort($ids);
        }
        $type = $ctx['type'] ?? ($ctx['email_type'] ?? '');
        if ($ids) {
            $key = 'ids:' . implode(',', $ids);
        } else {
            $key = 'fb:' . ($ctx['customer_id'] ?? '?') . '|' . $type . '|' . ($ctx['event_name'] ?? '') . '|' . ($ctx['event_date'] ?? '');
        }

        if (!empty($ctx['success'])) {
            if (!isset($successes[$key]) || $lm[1] > $successes[$key]) {
                $successes[$key] = $lm[1];
            }
            continue;
        }

        $failures[] = [
            'datetime' => $lm[1],
            'channel'  => strtolower($lm[2]),
            'action'   => $action,
            'key'      => $key,
            'ids'      => $ids,
            'ctx'      => $ctx,
        ];
    }
    fclose($fh);
}

// --- Group failures per order ---
$groups = [];
foreach ($failures as $f) {
    $key = $f['key'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key'      => $key,
            'ids'      => $f['ids'],
            'first'    => $f['datetime'],
            'last'     => $f['datetime'],
            'attempts' => 0,
            'channels' => [],
            'ctx'      => $f['ctx'],
        ];
    }
    $g = &$groups[$key];
    $g['attempts']++;
    $g['channels'][$f['channel']] = true;
    if ($f['datetime'] > $g['last']) {
        $g['last'] = $f['datetime'];
        $g['ctx'] = $f['ctx'];   // context of the most recent attempt
    }
    if ($f['datetime'] < $g['first']) {
        $g['first'] = $f['datetime'];
    }
    if (empty($g['ids']) && !empty($f['ids'])) {
        $g['ids'] = $f['ids'];
    }
    unset($g);
}

// Resolved = a successful send for the same group was logged later
foreach ($groups as &$g) {
    $g['resolved'] = isset($successes[$g['key']]) && $successes[$g['key']] > $g['last'];
}
unset($g);

$openCount = count(array_filter($groups, fn($g) => !$g['resolved']));
$totalCount = count($groups);

if (!$showAll) {
    $groups = array_filter($groups, fn($g) => !$g['resolved']);
}

// Open failures first, newest on top
usort($groups, function ($a, $b) {
    if ($a['resolved'] !== $b['resolved']) {
        return $a['resolved'] <=> $b['resolved'];
    }
    return strcmp($b['last'], $a['last']);
});

// --- Resolve customer names/emails ---
$customerModel = new Customer();
$customerCache = [];
foreach ($groups as &$g) {
    $cid = (int)($g['ctx']['customer_id'] ?? 0);
    if ($cid > 0 && !isset($customerCache[$cid])) {
        $customerCache[$cid] = $customerModel->getById($cid) ?: false;
    }
    $g['customer'] = $cid > 0 ? ($customerCache[$cid] ?: null) : null;
}
unset($g);

$pageTitle = 'Mail-Fehler';
$showSidebar = true;
$showHeader = true;

// --- Render ---
$daysOptions = '';
foreach ($allowedDays as $d) {
    $daysOptions .= '<option value="' . $d . '"' . ($d === $days ? ' selected' : '') . '>' . $d . ' Tage</option>';
}

$content = '
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="bi bi-envelope-exclamation"></i> Mail-Fehler</h2>
    <form method="get" class="d-flex gap-2 align-items-center">
        <select name="days" class="form-select form-select-sm" onchange="this.form.submit()">' . $daysOptions . '</select>
        <select name="show" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="open"' . (!$showAll ? ' selected' : '') . '>Nur offene</option>
            <option value="all"' . ($showAll ? ' selected' : '') . '>Alle (inkl. erledigte)</option>
        </select>
        <a href="' . url('admin/logs.php') . '" class="btn btn-outline-secondary btn-sm text-nowrap"><i class="bi bi-journal-text"></i> System-Logs</a>
    </form>
</div>

<p class="text-muted">
    Fehlgeschlagene Bestätigungs-/Ticket-Mails der letzten <strong>' . $days . ' Tage</strong> laut Logdateien
    (' . $openCount . ' offen / ' . $totalCount . ' gesamt).
    Als <span class="badge bg-success">erledigt</span> gilt ein Eintrag, wenn für dieselbe Bestellung später ein erfolgreicher Versand geloggt wurde.
</p>';

if (empty($groups)) {
    $content .= '
<div class="alert alert-success">
    <i class="bi bi-check-circle"></i> ' . ($showAll ? 'Keine fehlgeschlagenen Mail-Versände im gewählten Zeitraum.' : 'Keine offenen Mail-Fehler im gewählten Zeitraum.') . '
</div>';
} else {
    $content .= '
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Letzter Fehler</th>
                    <th>Mail-Typ</th>
                    <th>Veranstaltung</th>
                    <th>Kunde</th>
                    <th>Reservierungen</th>
                    <th class="text-center">Versuche</th>
                    <th>Fehlergrund</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>';

    foreach ($groups as $g) {
        $ctx = $g['ctx'];
        $type = $ctx['type'] ?? '';
        $typeLabel = $typeLabels[$type] ?? ($ctx['email_type'] ?? ($type !== '' ? $type : '–'));

        $eventInfo = htmlspecialchars($ctx['event_name'] ?? '');
        if (!empty($ctx['event_date'])) {
            $ts = strtotime($ctx['event_date']);
            $eventInfo .= $eventInfo !== '' ? '<br><small class="text-muted">' . ($ts ? date('d.m.Y', $ts) : htmlspecialchars($ctx['event_date'])) . '</small>' : '';
        }
        if ($eventInfo === '') {
            $eventInfo = '<span class="text-muted">–</span>';
        }

        if ($g['customer']) {
            $custName = trim(($g['customer']['first_name'] ?? '') . ' ' . ($g['customer']['last_name'] ?? ''));
            $custEmail = $g['customer']['email'] ?? '';
            $customerInfo = htmlspecialchars($custName !== '' ? $custName : ('Kunde #' . $ctx['customer_id']));
            if ($custEmail !== '') {
                $customerInfo .= '<br><small class="text-muted">' . htmlspecialchars($custEmail) . '</small>';
            }
        } elseif (!empty($ctx['customer_id'])) {
            $customerInfo = '<span class="text-muted">Kunde #' . (int)$ctx['customer_id'] . ' (gelöscht?)</span>';
        } else {
            $customerInfo = '<span class="text-muted">–</span>';
        }

        if (!empty($g['ids'])) {
            $idsStr = implode(', ', $g['ids']);
            $reservationInfo = '<a href="' . url('admin/logs.php?reservation=' . implode(',', $g['ids'])) . '" title="Im Log suchen">' . htmlspecialchars($idsStr) . '</a>';
        } else {
            $reservationInfo = '<span class="text-muted" title="Ältere Logeinträge enthalten keine Reservierungs-IDs">unbekannt</span>';
        }

        $error = (string)($ctx['error'] ?? ($ctx['reason'] ?? ''));
        $errorInfo = $error !== ''
            ? '<span class="d-inline-block text-truncate" style="max-width: 260px;" title="' . htmlspecialchars($error) . '">' . htmlspecialchars($error) . '</span>'
            : '<span class="text-muted" title="Kein Fehlergrund im Log (ältere Einträge)">–</span>';

        $statusBadge = $g['resolved']
            ? '<span class="badge bg-success">erledigt</span>'
            : '<span class="badge bg-warning text-dark">offen</span>';

        $action = '';
        if (!$g['resolved'] && !empty($g['ids'])) {
            $action = '<button type="button" class="btn btn-outline-primary btn-sm text-nowrap email-resend-btn" data-ids="' . htmlspecialchars(implode(',', $g['ids'])) . '">
                            <i class="bi bi-envelope-arrow-up"></i> Erneut senden
                       </button>
                       <div class="small mt-1 resend-status"></div>';
        }

        $content .= '
                <tr' . ($g['resolved'] ? ' class="table-light text-muted"' : '') . '>
                    <td class="text-nowrap">' . date('d.m.Y H:i', strtotime($g['last'])) . '<br><small class="text-muted">Kanal: ' . htmlspecialchars(implode(', ', array_keys($g['channels']))) . '</small></td>
                    <td>' . htmlspecialchars($typeLabel) . '</td>
                    <td>' . $eventInfo . '</td>
                    <td>' . $customerInfo . '</td>
                    <td>' . $reservationInfo . '</td>
                    <td class="text-center">' . (int)$g['attempts'] . '</td>
                    <td>' . $errorInfo . '</td>
                    <td>' . $statusBadge . '</td>
                    <td>' . $action . '</td>
                </tr>';
    }

    $content .= '
            </tbody>
        </table>
    </div>
</div>';
}

$resendUrlJs = json_encode(url('api/email_resend.php'));
$additionalJs = <<<JS
<script>
document.querySelectorAll('.email-resend-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var ids = btn.dataset.ids.split(',').map(Number);
        var status = btn.parentElement.querySelector('.resend-status');
        btn.disabled = true;
        status.textContent = 'Wird gesendet…';
        status.className = 'small mt-1 resend-status text-muted';
        fetch({$resendUrlJs}, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reservation_ids: ids })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                status.textContent = 'E-Mail versendet.';
                status.className = 'small mt-1 resend-status text-success';
                var row = btn.closest('tr');
                var badge = row.querySelector('.badge');
                if (badge) { badge.className = 'badge bg-success'; badge.textContent = 'erledigt'; }
                btn.remove();
            } else {
                status.textContent = data.message || 'Versand erneut fehlgeschlagen.';
                status.className = 'small mt-1 resend-status text-danger';
                // Cooldown des Endpoints abwarten, dann neuen Versuch erlauben
                setTimeout(function() { btn.disabled = false; }, 31000);
            }
        })
        .catch(function() {
            status.textContent = 'Versand erneut fehlgeschlagen.';
            status.className = 'small mt-1 resend-status text-danger';
            setTimeout(function() { btn.disabled = false; }, 31000);
        });
    });
});
</script>
JS;

require __DIR__ . '/../../app/views/layouts/main.php';
