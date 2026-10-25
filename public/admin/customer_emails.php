<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Services\EmailService;
use App\Services\LoggerService;

Auth::requireAdmin();

$db = Database::getInstance();

// ─── Inputs ──────────────────────────────────────────────────────────────────
$mode          = $_POST['mode']          ?? 'all';
$eventId       = (int)($_POST['event_id'] ?? 0);
$eventDate     = trim($_POST['event_date']     ?? '');
$bookingDay    = trim($_POST['booking_day']    ?? '');
$reservationId = (int)($_POST['reservation_id'] ?? 0);
$customerQuery = trim($_POST['customer_query'] ?? '');
$statusFilter  = $_POST['status_filter'] ?? 'all'; // all|paid|reserved

$subject       = $_POST['subject']  ?? '';
$bodyHtml      = $_POST['body']     ?? '';
$replyTo       = trim($_POST['reply_to'] ?? (Config::get('sender_email', '') ?: ''));
$bcc           = trim($_POST['bcc'] ?? '');

$action        = $_POST['action'] ?? ''; // preview|send
$errors        = [];
$infoMessages  = [];
$sentCount     = 0;
$failCount     = 0;
$recipients    = [];
$previewHtml   = '';

// ─── Recipient resolver ──────────────────────────────────────────────────────
$resolveRecipients = function () use ($db, $mode, $eventId, $eventDate, $bookingDay, $reservationId, $customerQuery, $statusFilter, &$errors): array {

    $statusWhere = '';
    if ($statusFilter === 'paid') {
        $statusWhere = ' AND r.status_id IN (2,4,5,7,8,9,10,11)';
    } elseif ($statusFilter === 'reserved') {
        $statusWhere = ' AND r.status_id IN (3,6)';
    }

    $resSelect = "SELECT r.id, r.event_id, r.event_date, r.seat_identifier, r.status_id, r.category_id, r.is_reduced, r.is_fn_card, r.is_companion, r.session_id, r.created_at,
                         c.id AS customer_id, c.salutation, c.first_name, c.last_name, c.email,
                         e.name AS event_name, e.show_start_time AS event_time,
                         sc.name AS category_name, sc.price AS category_price
                  FROM reservations r
                  INNER JOIN customers c ON r.customer_id = c.id
                  LEFT JOIN events e ON r.event_id = e.id
                  LEFT JOIN seat_categories sc ON r.category_id = sc.id
                  WHERE c.email IS NOT NULL AND c.email <> ''";

    $rows = [];

    switch ($mode) {
        case 'all':
            $stmt = $db->query("SELECT id AS customer_id, salutation, first_name, last_name, email
                                FROM customers
                                WHERE email IS NOT NULL AND email <> ''
                                ORDER BY last_name, first_name");
            $customers = $stmt->fetchAll();
            $out = [];
            foreach ($customers as $c) {
                $key = strtolower(trim($c['email']));
                if ($key === '' || isset($out[$key])) continue;
                $out[$key] = [
                    'customer_id' => (int)$c['customer_id'],
                    'email'       => $c['email'],
                    'name'        => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                    'salutation'  => $c['salutation'] ?? '',
                    'first_name'  => $c['first_name'] ?? '',
                    'last_name'   => $c['last_name'] ?? '',
                    'reservations'=> [],
                ];
            }
            return array_values($out);

        case 'customer':
            if ($customerQuery === '') {
                $errors[] = 'Bitte Suchbegriff für Kunde angeben (ID, Name oder E-Mail).';
                return [];
            }
            if (ctype_digit($customerQuery)) {
                $stmt = $db->query("SELECT id AS customer_id, salutation, first_name, last_name, email
                                    FROM customers WHERE id = :id", [':id' => (int)$customerQuery]);
            } else {
                $like = '%' . $customerQuery . '%';
                $stmt = $db->query("SELECT id AS customer_id, salutation, first_name, last_name, email
                                    FROM customers
                                    WHERE email IS NOT NULL AND email <> ''
                                      AND (first_name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3)
                                    ORDER BY last_name, first_name
                                    LIMIT 50",
                    [':q1' => $like, ':q2' => $like, ':q3' => $like]);
            }
            $customers = $stmt->fetchAll();
            $out = [];
            foreach ($customers as $c) {
                if (empty($c['email'])) continue;
                $key = strtolower(trim($c['email']));
                if (isset($out[$key])) continue;
                $out[$key] = [
                    'customer_id' => (int)$c['customer_id'],
                    'email'       => $c['email'],
                    'name'        => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                    'salutation'  => $c['salutation'] ?? '',
                    'first_name'  => $c['first_name'] ?? '',
                    'last_name'   => $c['last_name'] ?? '',
                    'reservations'=> [],
                ];
            }
            return array_values($out);

        case 'event':
            if ($eventId <= 0) {
                $errors[] = 'Bitte eine Veranstaltung auswählen.';
                return [];
            }
            $sql = $resSelect . " AND r.event_id = :eid" . $statusWhere . " ORDER BY r.event_date, c.id";
            $stmt = $db->query($sql, [':eid' => $eventId]);
            $rows = $stmt->fetchAll();
            break;

        case 'event_date':
            if ($eventId <= 0 || $eventDate === '') {
                $errors[] = 'Bitte Veranstaltung und Termin angeben.';
                return [];
            }
            $sql = $resSelect . " AND r.event_id = :eid AND r.event_date = :dt" . $statusWhere . " ORDER BY c.id";
            $stmt = $db->query($sql, [':eid' => $eventId, ':dt' => $eventDate]);
            $rows = $stmt->fetchAll();
            break;

        case 'booking_day':
            if ($bookingDay === '') {
                $errors[] = 'Bitte Buchungsdatum angeben.';
                return [];
            }
            $sql = $resSelect . " AND DATE(r.created_at) = :bd" . $statusWhere . " ORDER BY c.id";
            $stmt = $db->query($sql, [':bd' => $bookingDay]);
            $rows = $stmt->fetchAll();
            break;

        case 'booking':
            if ($reservationId <= 0) {
                $errors[] = 'Bitte Reservierungs-ID angeben.';
                return [];
            }
            $stmt = $db->query("SELECT session_id FROM reservations WHERE id = :id", [':id' => $reservationId]);
            $row = $stmt->fetch();
            if (!$row || empty($row['session_id'])) {
                $errors[] = 'Reservierung nicht gefunden.';
                return [];
            }
            $sql = $resSelect . " AND r.session_id = :sid" . $statusWhere . " ORDER BY r.event_date, r.seat_identifier";
            $stmt = $db->query($sql, [':sid' => $row['session_id']]);
            $rows = $stmt->fetchAll();
            break;

        default:
            $errors[] = 'Unbekannter Auswahlmodus.';
            return [];
    }

    // Group reservations by customer (dedup by email)
    $out = [];
    foreach ($rows as $r) {
        $key = strtolower(trim($r['email']));
        if ($key === '') continue;
        if (!isset($out[$key])) {
            $out[$key] = [
                'customer_id' => (int)$r['customer_id'],
                'email'       => $r['email'],
                'name'        => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'salutation'  => $r['salutation'] ?? '',
                'first_name'  => $r['first_name'] ?? '',
                'last_name'   => $r['last_name'] ?? '',
                'reservations'=> [],
            ];
        }
        $out[$key]['reservations'][] = $r;
    }
    return array_values($out);
};

/**
 * Build placeholder set for a single recipient.
 */
$buildPlaceholders = function (array $recipient): array {
    $reservations = $recipient['reservations'] ?? [];
    $first = $reservations[0] ?? [];

    $eventTime = '';
    if (!empty($first['event_time'])) {
        $t = $first['event_time'];
        if (is_string($t) && strlen($t) >= 5) {
            $eventTime = substr($t, 0, 5) . ' Uhr';
        }
    }

    $totalPrice = 0.0;
    foreach ($reservations as $r) {
        $totalPrice += \App\Helpers\TicketPrice::forReservation($r)['price'];
    }
    $priceStr = $reservations ? (number_format($totalPrice, 2, ',', '.') . ' €') : '';

    $seatListHtml = '';
    if ($reservations) {
        $sorted = $reservations;
        usort($sorted, function ($a, $b) {
            preg_match('/(\d+)[_\-](\d+)$/', $a['seat_identifier'] ?? '', $mA);
            preg_match('/(\d+)[_\-](\d+)$/', $b['seat_identifier'] ?? '', $mB);
            $rA = isset($mA[1]) ? (int)$mA[1] : 0;
            $sA = isset($mA[2]) ? (int)$mA[2] : 0;
            $rB = isset($mB[1]) ? (int)$mB[1] : 0;
            $sB = isset($mB[2]) ? (int)$mB[2] : 0;
            return $rA !== $rB ? $rA - $rB : $sA - $sB;
        });
        $seatListHtml = '<ul>';
        foreach ($sorted as $r) {
            $sid = $r['seat_identifier'] ?? '';
            if (str_starts_with($sid, 'no_seatplan_')) continue;
            if (preg_match('/^seat_(\d+)_(\d+)$/', $sid, $m)) {
                $label = 'Reihe ' . $m[1] . ', Sitz ' . $m[2];
            } elseif (preg_match('/^wheelchair_(\d+)_(\d+)$/', $sid, $m)) {
                $label = 'Reihe ' . $m[1] . ', Sitz ' . $m[2] . ' (Rollstuhl)';
            } else {
                $label = $sid;
            }
            $line = htmlspecialchars($label);
            if (!empty($r['category_name'])) {
                $line .= ' (' . htmlspecialchars($r['category_name']) . ')';
            }
            if (!empty($r['is_companion'])) {
                $line .= ' – <strong>Begleitperson kostenfrei</strong>';
            }
            $seatListHtml .= '<li>' . $line . '</li>';
        }
        $seatListHtml .= '</ul>';
    }

    return [
        'customer_id'       => $recipient['customer_id'] ?? null,
        'customer_name'     => $recipient['name'] ?? '',
        'salutation'        => $recipient['salutation'] ?? '',
        'first_name'        => $recipient['first_name'] ?? '',
        'last_name'         => $recipient['last_name'] ?? '',
        'event_name'        => $first['event_name'] ?? '',
        'event_date'        => !empty($first['event_date']) ? date('d.m.Y', strtotime($first['event_date'])) : '',
        'event_time'        => $eventTime,
        'category_name'     => $first['category_name'] ?? '',
        'price'             => $priceStr,
        'seat_list'         => $seatListHtml,
        'reservation_count' => count($reservations),
    ];
};

// ─── POST handling ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validatePost()) {
        $errors[] = 'Ungültiges Sicherheits-Token. Bitte Seite neu laden.';
    } else {
        $recipients = $resolveRecipients();

        if ($action === 'send' && empty($errors)) {
            if (trim($subject) === '')   $errors[] = 'Bitte Betreff angeben.';
            if (trim($bodyHtml) === '')  $errors[] = 'Bitte Nachrichtentext angeben.';
            if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Antwort-Adresse ist keine gültige E-Mail.';
            }
            if ($bcc !== '' && !filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'BCC-Adresse ist keine gültige E-Mail.';
            }
            if (empty($recipients)) {
                $errors[] = 'Keine Empfänger gefunden – bitte Filter prüfen.';
            }

            if (empty($errors)) {
                $mailer = new EmailService();
                $adminId = (string)(Auth::id() ?? '');
                foreach ($recipients as $rcpt) {
                    if (empty($rcpt['email']) || !filter_var($rcpt['email'], FILTER_VALIDATE_EMAIL)) {
                        $failCount++;
                        continue;
                    }
                    $placeholders = $buildPlaceholders($rcpt);
                    $ok = $mailer->sendCustomEmail(
                        $rcpt['email'],
                        $rcpt['name'] ?: $rcpt['email'],
                        $subject,
                        $bodyHtml,
                        $placeholders,
                        $replyTo !== '' ? $replyTo : null,
                        $bcc     !== '' ? $bcc     : null
                    );
                    if ($ok) { $sentCount++; } else { $failCount++; }
                    usleep(100000);
                }

                LoggerService::logAdmin('admin_bulk_email_sent', [
                    'admin_id'        => $adminId,
                    'mode'            => $mode,
                    'event_id'        => $eventId ?: null,
                    'event_date'      => $eventDate ?: null,
                    'booking_day'     => $bookingDay ?: null,
                    'reservation_id'  => $reservationId ?: null,
                    'status_filter'   => $statusFilter,
                    'recipient_count' => count($recipients),
                    'sent'            => $sentCount,
                    'failed'          => $failCount,
                    'has_reply_to'    => $replyTo !== '',
                    'has_bcc'         => $bcc !== '',
                    'subject'         => substr($subject, 0, 200),
                ]);

                $infoMessages[] = "Versand abgeschlossen: {$sentCount} erfolgreich" . ($failCount > 0 ? ", {$failCount} fehlgeschlagen" : '') . '.';
            }
        } elseif ($action === 'preview' && empty($errors)) {
            if (empty($recipients)) {
                $infoMessages[] = 'Keine Empfänger gefunden – bitte Filter anpassen.';
            } else {
                $infoMessages[] = count($recipients) . ' Empfänger gefunden.';
                if (trim($subject) !== '' && trim($bodyHtml) !== '') {
                    $mailer = new EmailService();
                    $ph = $buildPlaceholders($recipients[0]);
                    $ref = new \ReflectionMethod($mailer, 'replaceEmailPlaceholders');
                    $ref->setAccessible(true);
                    $previewHtml = $ref->invoke($mailer, $bodyHtml, $ph);
                }
            }
        }
    }
}

// ─── Load events for dropdown ────────────────────────────────────────────────
$eventsStmt = $db->query("SELECT id, name FROM events ORDER BY name");
$events = $eventsStmt->fetchAll();

$eventDates = [];
if ($eventId > 0) {
    $stmt = $db->query("SELECT DISTINCT `date` FROM event_dates WHERE event_id = :eid ORDER BY `date`", [':eid' => $eventId]);
    $eventDates = array_column($stmt->fetchAll(), 'date');
}

$pageTitle   = 'Kunden-Mails';
$showSidebar = true;
$showHeader  = true;
$csrfField   = Csrf::field();

// ─── Build view ──────────────────────────────────────────────────────────────
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<form method="POST" id="bulkMailForm">
  <?= $csrfField ?>
  <input type="hidden" name="action" id="formAction" value="preview">

  <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= $h($e) ?></div>
  <?php endforeach; ?>
  <?php foreach ($infoMessages as $m): ?>
    <div class="alert alert-success"><?= $h($m) ?></div>
  <?php endforeach; ?>

  <div class="card mb-4">
    <div class="card-header"><i class="bi bi-funnel"></i> <strong>1. Empfänger auswählen</strong></div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Modus</label>
        <select class="form-select" name="mode" id="modeSelect">
          <option value="all"         <?= $mode==='all'         ? 'selected' : '' ?>>Alle Kunden in der Datenbank</option>
          <option value="customer"    <?= $mode==='customer'    ? 'selected' : '' ?>>Einzelner Kunde (Suche)</option>
          <option value="event"       <?= $mode==='event'       ? 'selected' : '' ?>>Kunden einer Veranstaltung</option>
          <option value="event_date"  <?= $mode==='event_date'  ? 'selected' : '' ?>>Kunden eines Termins</option>
          <option value="booking_day" <?= $mode==='booking_day' ? 'selected' : '' ?>>Kunden nach Buchungstag</option>
          <option value="booking"     <?= $mode==='booking'     ? 'selected' : '' ?>>Kunden einer Bestellung (Reservierungs-ID)</option>
        </select>
      </div>

      <div class="mb-3 mode-field" data-modes="customer">
        <label class="form-label">Suche (ID, Name oder E-Mail)</label>
        <input type="text" class="form-control" name="customer_query" value="<?= $h($customerQuery) ?>" placeholder="z. B. meier oder kunde@example.com">
      </div>

      <div class="row mode-field" data-modes="event event_date">
        <div class="col-md-6 mb-3">
          <label class="form-label">Veranstaltung</label>
          <select class="form-select" name="event_id" id="eventSelect">
            <option value="">-- Veranstaltung wählen --</option>
            <?php foreach ($events as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $eventId === (int)$e['id'] ? 'selected' : '' ?>>
                <?= $h($e['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3 mode-field" data-modes="event_date">
          <label class="form-label">Termin</label>
          <select class="form-select" name="event_date">
            <option value="">-- alle Termine --</option>
            <?php foreach ($eventDates as $d): ?>
              <option value="<?= $h($d) ?>" <?= $eventDate === $d ? 'selected' : '' ?>>
                <?= $h(date('d.m.Y', strtotime($d))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mb-3 mode-field" data-modes="booking_day">
        <label class="form-label">Buchungsdatum</label>
        <input type="date" class="form-control" name="booking_day" value="<?= $h($bookingDay) ?>">
      </div>

      <div class="mb-3 mode-field" data-modes="booking">
        <label class="form-label">Reservierungs-ID</label>
        <input type="number" class="form-control" name="reservation_id" value="<?= $reservationId ? (int)$reservationId : '' ?>">
        <small class="text-muted">Es werden alle Reservierungen dieser Bestellung (gleiche Session) berücksichtigt.</small>
      </div>

      <div class="mb-3 mode-field" data-modes="event event_date booking_day booking">
        <label class="form-label">Status-Filter</label>
        <select class="form-select" name="status_filter">
          <option value="all"      <?= $statusFilter==='all'      ? 'selected' : '' ?>>Alle Reservierungen</option>
          <option value="paid"     <?= $statusFilter==='paid'     ? 'selected' : '' ?>>Nur bezahlt / abgeholt</option>
          <option value="reserved" <?= $statusFilter==='reserved' ? 'selected' : '' ?>>Nur reserviert (unbezahlt)</option>
        </select>
      </div>

      <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('formAction').value='preview';">
        <i class="bi bi-search"></i> Empfänger prüfen
      </button>
    </div>
  </div>

  <?php if (!empty($recipients)): ?>
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Empfänger (<?= count($recipients) ?>)</strong>
        <span class="text-muted small">dedupliziert nach E-Mail</span>
      </div>
      <div class="card-body">
        <div class="table-responsive" style="max-height:320px;overflow:auto;">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>#</th><th>Name</th><th>E-Mail</th><th class="text-end">Reservierungen</th></tr></thead>
            <tbody>
              <?php $i = 0; foreach ($recipients as $r): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= $h($r['name']) ?></td>
                  <td><?= $h($r['email']) ?></td>
                  <td class="text-end"><?= count($r['reservations']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header"><i class="bi bi-envelope"></i> <strong>2. Nachricht</strong></div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Antwort-Adresse (Reply-To) *</label>
          <input type="email" class="form-control" name="reply_to" value="<?= $h($replyTo) ?>" required>
          <small class="text-muted">Antworten der Kunden gehen an diese Adresse.</small>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">BCC (optional)</label>
          <input type="email" class="form-control" name="bcc" value="<?= $h($bcc) ?>" placeholder="z. B. archiv@example.com">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Betreff</label>
        <input type="text" class="form-control" name="subject" value="<?= $h($subject) ?>">
      </div>

      <div class="mb-2">
        <label class="form-label">HTML-Nachricht</label>
        <textarea class="form-control font-monospace" name="body" rows="14"><?= $h($bodyHtml) ?></textarea>
      </div>

      <div class="alert alert-info small mb-3">
        <strong>Verfügbare Platzhalter:</strong><br>
        Immer:
        <code>{customer_name}</code>, <code>{salutation}</code>, <code>{first_name}</code>, <code>{last_name}</code>,
        <code>{company_name}</code>, <code>{logo_html}</code><br>
        Bei Veranstaltungs-/Termin-/Bestellbezug zusätzlich:
        <code>{event_name}</code>, <code>{event_date}</code>, <code>{event_time}</code>,
        <code>{category_name}</code>, <code>{price}</code>, <code>{seat_list}</code>, <code>{reservation_count}</code>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-outline-secondary" onclick="document.getElementById('formAction').value='preview';">
          <i class="bi bi-eye"></i> Vorschau (1. Empfänger)
        </button>
        <button type="submit" class="btn btn-primary" id="sendBtn" onclick="return confirmSend();">
          <i class="bi bi-send"></i> Jetzt senden
        </button>
      </div>
    </div>
  </div>

  <?php if ($previewHtml !== ''): ?>
    <div class="card mb-4">
      <div class="card-header"><strong>Vorschau (erster Empfänger)</strong></div>
      <div class="card-body p-0">
        <iframe style="width:100%;height:420px;border:0;" srcdoc="<?= $h($previewHtml) ?>"></iframe>
      </div>
    </div>
  <?php endif; ?>
</form>

<script>
(function() {
  const modeSel = document.getElementById('modeSelect');
  const eventSel = document.getElementById('eventSelect');
  const fields = document.querySelectorAll('.mode-field');
  const recipientsCount = <?= (int)count($recipients) ?>;

  function updateFields() {
    const mode = modeSel.value;
    fields.forEach(f => {
      const modes = (f.dataset.modes || '').split(/\s+/);
      f.style.display = modes.includes(mode) ? '' : 'none';
    });
  }
  modeSel.addEventListener('change', updateFields);
  updateFields();

  // Reload page on event change to refresh dates dropdown
  if (eventSel) {
    eventSel.addEventListener('change', function() {
      document.getElementById('formAction').value = 'preview';
      document.getElementById('bulkMailForm').submit();
    });
  }

  window.confirmSend = function() {
    document.getElementById('formAction').value = 'send';
    if (recipientsCount < 1) {
      alert('Bitte zuerst Empfänger laden ("Empfänger prüfen").');
      return false;
    }
    return confirm('E-Mail an ' + recipientsCount + ' Empfänger senden?');
  };
})();
</script>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../app/views/layouts/main.php';
