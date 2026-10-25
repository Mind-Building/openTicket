<?php
/**
 * Daily Event Report (Cron Script)
 *
 * Generates an overview of all active events with ticket/reservation counts
 * per date and in total, and sends it as an HTML email.
 *
 * Usage (CLI):
 *   php app/scripts/daily_report.php
 *
 * Usage (Cron, from project root):
 *   0 7 * * * /usr/bin/php /path/to/ticket2.0/app/scripts/daily_report.php >> /tmp/ticket_report.log 2>&1
 *
 * Usage (HTTP, for testing – protected by REPORT_SECRET from .env):
 *   https://domain.de/ticket2.0/cron_report.php?secret=DEIN_TOKEN
 *   The token is read from $_ENV['REPORT_SECRET'] (see .env). Leave .env entry
 *   empty to disable HTTP access entirely (CLI/cron will still work).
 */

// ============================================================
//  KONFIGURATION
// ============================================================

/** Betreff */
const REPORT_SUBJECT = 'Tagesbericht – Ticketverkauf';

// ============================================================
//  BOOTSTRAP
// ============================================================

// Bootstrap first – we need $_ENV populated to read REPORT_SECRET.
require_once __DIR__ . '/../bootstrap.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $expectedSecret = (string)($_ENV['REPORT_SECRET'] ?? '');
    if ($expectedSecret === '') {
        http_response_code(403);
        die('HTTP access disabled. Set REPORT_SECRET in .env to enable it.');
    }
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($expectedSecret, $provided)) {
        http_response_code(403);
        die('Forbidden.');
    }
}

use App\Core\Config;
use App\Core\Database;
use App\Services\EmailService;

// Load recipients from DB config (one address per line)
$rawRecipients = Config::get('cron_report_recipients', '');
$reportRecipients = array_filter(
    array_map('trim', preg_split('/[\r\n,]+/', $rawRecipients)),
    fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
);

// ============================================================
//  DATA COLLECTION
// ============================================================

$db  = Database::getInstance()->getConnection();
$eventModel = new \App\Models\Event();

// Cleanup expired cart items before generating report
(new \App\Models\Reservation())->cleanupExpiredCartItems();

// Status groupings (status_id → label/category)
$statusGroups = [
    'sold_online'   => [2, 8],         // Bezahlt Kunde (PayPal, Gutschein online)
    'sold_presales' => [4, 5, 9, 10],  // Bezahlt Vorverkauf (Bar, FN-Karte, Gutschein VVK)
    'reserved'      => [3, 6],         // Reserviert, noch nicht bezahlt
    'vip'           => [11],           // VIP-Ticket (kostenlos, Admin)
    'box'           => [7],            // Abendkasse
];

$statusLabels = [
    'sold_online'   => 'Verkauft Kunde',
    'sold_presales' => 'Verkauft VVK',
    'reserved'      => 'Reserviert (unbezahlt)',
    'vip'           => 'VIP',
    'box'           => 'Abendkasse',
];

// Load all active events
$eventsStmt = $db->query("SELECT * FROM events WHERE active = 1 ORDER BY name ASC");
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

// Load vouchers summary (presales, status_id=0 = created, not yet redeemed)
$voucherSql = "SELECT e.name as event_name, COUNT(*) as total,
                      SUM(CASE WHEN v.status_id = 0 THEN 1 ELSE 0 END) as created,
                      SUM(CASE WHEN v.status_id = 2 THEN 1 ELSE 0 END) as paid,
                      SUM(CASE WHEN v.status_id = 3 THEN 1 ELSE 0 END) as redeemed
               FROM vouchers v
               LEFT JOIN events e ON v.event_id = e.id
               WHERE e.active = 1
               GROUP BY v.event_id
               ORDER BY e.name ASC";
$voucherStmt = $db->query($voucherSql);
$vouchersByEvent = [];
foreach ($voucherStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $vouchersByEvent[$row['event_name']] = $row;
}

// Build report data per event
$reportData = [];

foreach ($events as $event) {
    $eventId = (int)$event['id'];

    // Get dates for this event
    $datesStmt = $db->prepare(
        "SELECT * FROM event_dates WHERE event_id = :eid ORDER BY date ASC"
    );
    $datesStmt->execute([':eid' => $eventId]);
    $dates = $datesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Capacity: seat plan seat count when the event has a seat plan,
    // otherwise the configured max_seats (see Event::getCapacity).
    $maxSeatsEvent = $eventModel->getCapacity($event);

    $eventReport = [
        'event'      => $event,
        'max_seats'  => $maxSeatsEvent,
        'dates'      => [],
        'totals'     => array_fill_keys(array_keys($statusGroups), 0),
        'total_all'  => 0,
        'delta_24h'  => array_fill_keys(array_keys($statusGroups), 0),
        'delta_all'  => 0,
    ];

    foreach ($dates as $ed) {
        $dateStr = $ed['date'];

        // Count reservations per status group for this date
        $sql = "SELECT status_id, COUNT(*) as cnt
                FROM reservations
                WHERE event_id = :eid AND event_date = :date
                GROUP BY status_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':eid' => $eventId, ':date' => $dateStr]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = array_fill_keys(array_keys($statusGroups), 0);
        foreach ($rows as $row) {
            $sid = (int)$row['status_id'];
            foreach ($statusGroups as $grp => $ids) {
                if (in_array($sid, $ids, true)) {
                    $counts[$grp] += (int)$row['cnt'];
                }
            }
        }

        $dateTotal = array_sum($counts);

        // Delta: reservations created in the last 24 hours
        $deltaSql = "SELECT status_id, COUNT(*) as cnt
                     FROM reservations
                     WHERE event_id = :eid AND event_date = :date
                       AND created_at >= NOW() - INTERVAL 24 HOUR
                     GROUP BY status_id";
        $deltaStmt = $db->prepare($deltaSql);
        $deltaStmt->execute([':eid' => $eventId, ':date' => $dateStr]);
        $deltaRows = $deltaStmt->fetchAll(PDO::FETCH_ASSOC);

        $delta = array_fill_keys(array_keys($statusGroups), 0);
        foreach ($deltaRows as $row) {
            $sid = (int)$row['status_id'];
            foreach ($statusGroups as $grp => $ids) {
                if (in_array($sid, $ids, true)) {
                    $delta[$grp] += (int)$row['cnt'];
                }
            }
        }
        $deltaTotal = array_sum($delta);

        // Also get max_seats for this event (for capacity %)
        $maxSeats = $event['max_seats'] ? (int)$event['max_seats'] : null;

        $eventReport['dates'][] = [
            'date'     => $dateStr,
            'time'     => $ed['show_start_time'] ?? null,
            'counts'   => $counts,
            'total'    => $dateTotal,
            'max'      => $maxSeats,
            'delta'    => $delta,
            'delta_total' => $deltaTotal,
        ];

        foreach ($counts as $grp => $cnt) {
            $eventReport['totals'][$grp] += $cnt;
        }
        $eventReport['total_all'] += $dateTotal;
        foreach ($delta as $grp => $cnt) {
            $eventReport['delta_24h'][$grp] += $cnt;
        }
        $eventReport['delta_all'] += $deltaTotal;
    }

    $reportData[] = $eventReport;
}

// ============================================================
//  HTML EMAIL GENERATION
// ============================================================

$now        = date('d.m.Y H:i');
$totalSold  = 0;
$totalRes   = 0;

ob_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body      { font-family: Arial, sans-serif; font-size: 14px; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
  .wrap     { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
  .header   { background: #0d6efd; color: #fff; padding: 16px 20px; }
  .header h1{ margin: 0; font-size: 18px; }
  .header p { margin: 4px 0 0; font-size: 12px; opacity: .85; }
  .body     { padding: 16px 20px; }
  h2        { font-size: 15px; margin: 20px 0 8px; color: #0d6efd; border-bottom: 2px solid #e9ecef; padding-bottom: 4px; }
  h2:first-child { margin-top: 0; }
  .tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 8px; }
  table     { border-collapse: collapse; font-size: 12px; min-width: 100%; }
  th        { background: #e9ecef; text-align: left; padding: 5px 7px; color: #555; font-weight: 600; white-space: nowrap; }
  td        { padding: 4px 7px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; white-space: nowrap; }
  tr:last-child td { border-bottom: none; }
  tr.total  { background: #f8f9fa; font-weight: bold; }
  .badge    { display: inline-block; padding: 2px 6px; border-radius: 10px; font-size: 11px; font-weight: 600; }
  .bg-green { background: #d1e7dd; color: #0f5132; }
  .bg-warn  { background: #fff3cd; color: #664d03; }
  .bg-sec   { background: #e2e3e5; color: #41464b; }
  .bg-blue  { background: #cfe2ff; color: #084298; }
  .cap      { font-size: 11px; color: #999; }
  .summary  { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
  .sum-card { flex: 1; min-width: 110px; border: 1px solid #dee2e6; border-radius: 6px; padding: 10px 12px; }
  .sum-card .num  { font-size: 22px; font-weight: bold; color: #0d6efd; }
  .sum-card .lbl  { font-size: 11px; color: #666; margin-top: 2px; }
  .voucher-section { margin-top: 6px; }
  .footer   { background: #f8f9fa; padding: 12px 20px; font-size: 11px; color: #888; border-top: 1px solid #dee2e6; }
  .bg-delta { background: #d1ecf1; color: #0c5460; }
  .delta-zero { color: #ccc; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>Tagesbericht – Ticketverkauf</h1>
    <p>Erstellt am: <?= $now ?> &nbsp;|&nbsp; Aktive Veranstaltungen: <?= count($reportData) ?></p>
  </div>
  <div class="body">

<?php
// Global summary cards
$globalSoldOnline = 0; $globalSoldPresales = 0; $globalRes = 0; $globalVip = 0; $globalBox = 0;
$globalDelta = 0; $globalDeltaSold = 0;
foreach ($reportData as $r) {
    $globalSoldOnline   += $r['totals']['sold_online'];
    $globalSoldPresales += $r['totals']['sold_presales'];
    $globalRes          += $r['totals']['reserved'];
    $globalVip          += $r['totals']['vip'];
    $globalBox          += $r['totals']['box'];
    $globalDelta        += $r['delta_all'];
    $globalDeltaSold    += $r['delta_24h']['sold_online'] + $r['delta_24h']['sold_presales'];
}
$globalSold  = $globalSoldOnline + $globalSoldPresales;
$globalTotal = $globalSold + $globalRes + $globalVip + $globalBox;
?>

<div class="summary">
  <div class="sum-card">
    <div class="num"><?= $globalTotal ?></div>
    <div class="lbl">Tickets gesamt</div>
  </div>
  <div class="sum-card">
    <div class="num" style="color:#198754"><?= $globalSold ?></div>
    <div class="lbl">Verkauft gesamt</div>
  </div>
  <div class="sum-card">
    <div class="num" style="color:#198754"><?= $globalSoldOnline ?></div>
    <div class="lbl">Verkauft Kunde</div>
  </div>
  <div class="sum-card">
    <div class="num" style="color:#198754"><?= $globalSoldPresales ?></div>
    <div class="lbl">Verkauft VVK</div>
  </div>
  <div class="sum-card">
    <div class="num" style="color:#fd7e14"><?= $globalRes ?></div>
    <div class="lbl">Reserviert (unbezahlt)</div>
  </div>
  <div class="sum-card">
    <div class="num" style="color:#b8860b"><?= $globalVip ?></div>
    <div class="lbl">VIP</div>
  </div>
  <div class="sum-card">
    <div class="num" style="color:#6f42c1"><?= $globalBox ?></div>
    <div class="lbl">Abendkasse</div>
  </div>
  <div class="sum-card" style="border-color:#0c5460">
    <div class="num" style="color:#0c5460">+<?= $globalDeltaSold ?></div>
    <div class="lbl">Neu verkauft (24h)</div>
  </div>
  <div class="sum-card" style="border-color:#0c5460">
    <div class="num" style="color:#0c5460">+<?= $globalDelta ?></div>
    <div class="lbl">Neu gesamt (24h)</div>
  </div>
</div>

<?php foreach ($reportData as $r):
    $ev = $r['event'];
    $maxSeats = $r['max_seats'];
?>
  <h2><?= htmlspecialchars($ev['name']) ?></h2>
  <div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>Datum</th>
        <th>Online</th>
        <th>VVK</th>
        <th>Res.</th>
        <th>VIP</th>
        <th>AK</th>
        <th>Ges.<?= $maxSeats ? '/Kap.' : '' ?></th>
        <th>Δ24h</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($r['dates'] as $d):
        $dateSold = $d['counts']['sold_online'] + $d['counts']['sold_presales'];
        // Auslastung: alle vergebenen Plätze (Verkauft + Reserviert + VIP + Abendkasse) = Tagessumme.
        $pct = ($maxSeats && $maxSeats > 0) ? ($d['total'] / $maxSeats * 100) : null;
        $deltaSold = $d['delta']['sold_online'] + $d['delta']['sold_presales'];
    ?>
      <tr>
        <td><?= date('d.m.y', strtotime($d['date'])) ?>
          <?= $d['time'] ? ' <span class="cap">' . date('H:i', strtotime($d['time'])) . '</span>' : '' ?>
          <?php if ($d['date'] === date('Y-m-d')): ?>
            <span class="badge bg-blue">Heute</span>
          <?php elseif ($d['date'] < date('Y-m-d')): ?>
            <span class="badge bg-sec">Alt</span>
          <?php endif; ?>
        </td>
        <td><?= $d['counts']['sold_online'] > 0 ? '<span class="badge bg-green">' . $d['counts']['sold_online'] . '</span>' : '–' ?></td>
        <td><?= $d['counts']['sold_presales'] > 0 ? '<span class="badge bg-green">' . $d['counts']['sold_presales'] . '</span>' : '–' ?></td>
        <td><?= $d['counts']['reserved'] > 0 ? '<span class="badge bg-warn">' . $d['counts']['reserved'] . '</span>' : '–' ?></td>
        <td><?= $d['counts']['vip'] > 0 ? '<span class="badge" style="background:#fef3cd;color:#7d6608">' . $d['counts']['vip'] . '</span>' : '–' ?></td>
        <td><?= $d['counts']['box'] > 0 ? $d['counts']['box'] : '–' ?></td>
        <td>
          <strong><?= $d['total'] ?></strong>
          <?php if ($maxSeats): ?>
            <span class="cap">/ <?= $maxSeats ?> (<?= number_format($pct, 1, ',', '.') ?>%)</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($d['delta_total'] > 0): ?>
            <span class="badge bg-delta">+<?= $d['delta_total'] ?></span>
            <?php if ($deltaSold > 0): ?>
              <span class="cap"><?= $deltaSold ?>vk</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="delta-zero">–</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (count($r['dates']) > 1): ?>
      <tr class="total">
        <td>Gesamt</td>
        <td><?= $r['totals']['sold_online'] > 0 ? '<span class="badge bg-green">' . $r['totals']['sold_online'] . '</span>' : '–' ?></td>
        <td><?= $r['totals']['sold_presales'] > 0 ? '<span class="badge bg-green">' . $r['totals']['sold_presales'] . '</span>' : '–' ?></td>
        <td><?= $r['totals']['reserved'] > 0 ? '<span class="badge bg-warn">' . $r['totals']['reserved'] . '</span>' : '–' ?></td>
        <td><?= $r['totals']['vip'] > 0 ? '<span class="badge" style="background:#fef3cd;color:#7d6608">' . $r['totals']['vip'] . '</span>' : '–' ?></td>
        <td><?= $r['totals']['box'] > 0 ? $r['totals']['box'] : '–' ?></td>
        <td><strong><?= $r['total_all'] ?></strong></td>
        <td>
          <?php if ($r['delta_all'] > 0): ?>
            <span class="badge bg-delta">+<?= $r['delta_all'] ?></span>
          <?php else: ?>
            <span class="delta-zero">–</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div><!-- /tbl-wrap -->

  <?php if (isset($vouchersByEvent[$ev['name']])): $v = $vouchersByEvent[$ev['name']]; ?>
  <div class="voucher-section">
    <small style="color:#666">
      Gutscheine: <strong><?= (int)$v['total'] ?></strong> gesamt &nbsp;|&nbsp;
      Erstellt (uneingelöst): <strong><?= (int)$v['created'] ?></strong> &nbsp;|&nbsp;
      Bezahlt: <strong><?= (int)$v['paid'] ?></strong> &nbsp;|&nbsp;
      Eingelöst: <strong><?= (int)$v['redeemed'] ?></strong>
    </small>
  </div>
  <?php endif; ?>

<?php endforeach; ?>

  </div><!-- /body -->
  <div class="footer">
    Automatisch generiert am <?= $now ?> &nbsp;&middot;&nbsp; Ticket System 2.0
  </div>
</div>
</body>
</html>
<?php
$htmlBody = ob_get_clean();

// ============================================================
//  PREVIEW-MODE: nur anzeigen, kein E-Mail-Versand
// ============================================================

$preview = !$isCli && isset($_GET['preview']) && $_GET['preview'] === '1';

if ($preview) {
    echo $htmlBody;
    exit(0);
}

// ============================================================
//  SEND EMAILS (nur ohne preview-Schalter)
// ============================================================

$emailService = new EmailService();
$subject      = REPORT_SUBJECT . ' – ' . date('d.m.Y');
$sentCount    = 0;
$errors       = [];

foreach ($reportRecipients as $recipient) {
    $ok = $emailService->sendEmail($recipient, $recipient, $subject, $htmlBody);
    if ($ok) {
        $sentCount++;
    } else {
        $errors[] = $recipient;
    }
}

// ============================================================
//  OUTPUT (CLI or HTTP)
// ============================================================

$msg = sprintf(
    "[%s] Report sent to %d/%d recipient(s).",
    date('Y-m-d H:i:s'),
    $sentCount,
    count($reportRecipients)
);
if (!empty($errors)) {
    $msg .= " Failed: " . implode(', ', $errors);
}

if ($isCli) {
    echo $msg . PHP_EOL;
    exit(empty($errors) ? 0 : 1);
} else {
    // HTTP ohne preview: Versandstatus als Text ausgeben
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . PHP_EOL;
}
