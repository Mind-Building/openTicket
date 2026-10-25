<?php
/**
 * Karten zuweisen nach eingegangener Zahlung (Zahlungsanforderung)
 * Erstellt echte bezahlte Reservierungen (Status 2 = paid_paypal) und
 * sendet dem Kunden den Download-Link per E-Mail.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Session;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\SeatCategory;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\PaymentRequest;
use App\Services\EmailService;

Auth::requireAdmin();
Session::start();

$paymentRequestModel = new PaymentRequest();
$eventModel          = new Event();
$seatPlanModel       = new SeatPlan();
$categoryModel       = new SeatCategory();
$reservationModel    = new Reservation();
$customerModel       = new Customer();

// Zahlungsanforderung laden
$requestId = (int)($_GET['id'] ?? 0);
$payReq    = $requestId ? $paymentRequestModel->getById($requestId) : null;

if (!$payReq) {
    header('Location: ' . url('admin/payment_requests.php'));
    exit;
}
if ($payReq['status'] !== 'paid') {
    header('Location: ' . url('admin/payment_requests.php?error=not_paid'));
    exit;
}

$step      = $_GET['step'] ?? 'event';
$eventId   = (int)($_GET['event_id'] ?? 0);
$eventDate = $_GET['date'] ?? '';
$selectedSeatsJson = $_GET['seats'] ?? '';

$events = $eventModel->getAll(false);
if (count($events) === 1 && $eventId === 0) {
    $eventId = (int)$events[0]['id'];
}

$eventDates = [];
if ($eventId > 0) {
    $eventDates = $eventModel->getDates($eventId);
}

$allowedDatesJs = json_encode(array_map(fn($ed) => $ed['date'], $eventDates));
$jsEventId   = json_encode($eventId);
$jsEventDate = json_encode($eventDate);
$jsRequestId = json_encode($requestId);

$categories = [];
if ($eventId > 0) {
    $categories = $categoryModel->getByEvent($eventId);
}
$categoriesJs = json_encode($categories);

$flatpickrJsSrc = url('libs/js/flatpickr.min.js');
$flatpickrDeSrc = url('libs/js/flatpickr.de.js');
$urlAssign      = json_encode(url('admin/payment_request_assign.php'));

$earliestDate   = !empty($eventDates) ? $eventDates[0]['date'] : date('Y-m-d');
$earliestDateJs = json_encode($earliestDate);

$error   = '';
$success = '';

// ─── POST ────────────────────────────────────────────────────────────────────
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['select_seats'])) {
        $selectedSeats = $_POST['seats'] ?? [];
        if (empty($selectedSeats)) {
            $error = 'Bitte wählen Sie mindestens einen Platz aus.';
            $step  = 'seats';
        } else {
            $selectedSeatsJson = json_encode($selectedSeats);
            $step = 'confirm';
        }

    } elseif (isset($_POST['assign_tickets'])) {
        try {
            $selectedSeats = json_decode($_POST['seats_json'], true);
            if (empty($selectedSeats)) throw new \Exception('Keine Plätze ausgewählt.');

            $eventId   = (int)$_POST['event_id'];
            $eventDate = $_POST['event_date'];
            if (empty($eventDate)) throw new \Exception('Kein Datum angegeben.');

            // Kunden-Datensatz anlegen / aktualisieren
            $customerData = [
                'salutation'   => '',
                'first_name'   => $payReq['customer_name'],
                'last_name'    => '',
                'street'       => '-',
                'house_number' => '-',
                'postal_code'  => '00000',
                'city'         => '-',
                'email'        => $payReq['customer_email'],
                'phone_prefix' => '-',
                'phone_number' => '-',
                'session_id'   => session_id(),
                'item_type'    => 'ticket',
            ];
            // Vorname/Nachname trennen wenn möglich
            $nameParts = explode(' ', $payReq['customer_name'], 2);
            $customerData['first_name'] = $nameParts[0];
            $customerData['last_name']  = $nameParts[1] ?? '';

            $customerId = $customerModel->createOrGet($customerData);

            // Reservierungen mit Status 2 (paid_paypal) erstellen
            $createdIds = [];
            foreach ($selectedSeats as $seatData) {
                if (!is_array($seatData)) {
                    $seatData = json_decode($seatData, true);
                }
                if (!$seatData) continue;

                try {
                    $resId = $reservationModel->create([
                        'session_id'      => session_id(),
                        'customer_id'     => $customerId,
                        'event_id'        => $eventId,
                        'event_date'      => $eventDate,
                        'seat_identifier' => $seatData['id'],
                        'category_id'     => $seatData['category'] ?? null,
                        'status_id'       => 2, // paid_paypal
                        'is_reduced'      => 0,
                        'is_fn_card'      => 0,
                        'is_honor_card'   => 0,
                        'voucher_id'      => null,
                    ]);
                    $createdIds[] = $resId;
                } catch (\Exception $e) {
                    error_log('payment_request_assign seat error: ' . $e->getMessage());
                }
            }

            if (empty($createdIds)) {
                throw new \Exception('Keine Karten konnten erstellt werden (Plätze möglicherweise bereits vergeben).');
            }

            // Download-Link generieren und E-Mail senden
            $token      = generateTicketDownloadToken($createdIds);
            $ticketUrl  = absoluteUrl('api/ticket_download.php?reservation_ids=' . implode(',', $createdIds) . '&token=' . $token);
            $downloadUrl = url('api/ticket_download.php?reservation_ids=' . implode(',', $createdIds) . '&token=' . $token);

            if (Config::get('email_enabled', true)) {
                try {
                    $reservationsForEmail = [];
                    foreach ($createdIds as $resId) {
                        $rd = $reservationModel->getById($resId);
                        if ($rd) $reservationsForEmail[] = $rd;
                    }
                    $emailService = new EmailService();
                    $emailService->sendTicketEmail(
                        $payReq['customer_email'],
                        $payReq['customer_name'],
                        $reservationsForEmail,
                        $ticketUrl,
                        $eventId
                    );
                } catch (\Exception $emailEx) {
                    error_log('payment_request_assign email error: ' . $emailEx->getMessage());
                }
            }

            if (function_exists('logAdminAction')) {
                logAdminAction((string)Auth::id(), 'payment_request_tickets_assigned', [
                    'payment_request_id' => $requestId,
                    'reservation_ids'    => $createdIds,
                    'event_id'           => $eventId,
                    'event_date'         => $eventDate,
                ]);
            }

            $success = 'Karten erfolgreich erstellt und E-Mail an ' . htmlspecialchars($payReq['customer_email']) . ' gesendet.';
            $step = 'done';
            $doneIds         = $createdIds;
            $doneDownloadUrl = $downloadUrl;

        } catch (\Exception $e) {
            $error = $e->getMessage();
            $step  = 'confirm';
        }
    }
}

// ─── Sitzplandaten ────────────────────────────────────────────────────────────
$event         = null;
$seatPlan      = [];
$reservedSeats = [];
$selectedSeats = [];

if ($eventId > 0 && !empty($eventDate)) {
    $event = $eventModel->getById($eventId);
    if ($event && $event['use_seat_plan']) {
        $seatPlan      = $seatPlanModel->getSeatPlanStructure($eventId);
        $categories    = $categoryModel->getByEvent($eventId);
        $reservedSeats = $reservationModel->getReservedSeats($eventId, $eventDate);
    }
}

if (!empty($selectedSeatsJson)) {
    $selectedSeats = json_decode($selectedSeatsJson, true) ?? [];
}

// ─── Layout ───────────────────────────────────────────────────────────────────
$pageTitle   = 'Karten zuweisen';
$showSidebar = true;
$showHeader  = true;

// ─── Inhalt ───────────────────────────────────────────────────────────────────
$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-ticket-perforated text-success"></i> Karten zuweisen</h2>
    <a href="' . url('admin/payment_requests.php') . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>';

// Info-Box zur Zahlungsanforderung
$content .= '
<div class="card border-success mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> Bezahlt</span>
            <span><strong>' . htmlspecialchars($payReq['customer_name']) . '</strong>
                &lt;' . htmlspecialchars($payReq['customer_email']) . '&gt;</span>
            <span class="ms-auto fw-bold">' . number_format((float)$payReq['amount'], 2, ',', '.') . ' €</span>
        </div>
        <div class="text-muted small mt-1">' . htmlspecialchars($payReq['description']) . '</div>
    </div>
</div>';

if ($error) {
    $content .= '<div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> ' . htmlspecialchars($error) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}

// ─── Schritt: Fertig ──────────────────────────────────────────────────────────
if ($step === 'done' && !empty($success)) {
    $content .= '
    <div class="alert alert-success"><i class="bi bi-check-circle"></i> ' . $success . '</div>
    <div class="card">
        <div class="card-body text-center py-4">
            <i class="bi bi-ticket-perforated-fill text-success" style="font-size:3rem;"></i>
            <h4 class="mt-3">Karten erfolgreich zugewiesen!</h4>
            <p class="text-muted">' . count($doneIds) . ' Karte(n) für <strong>' . htmlspecialchars($payReq['customer_name']) . '</strong></p>
            <div class="d-flex justify-content-center gap-3 mt-4 flex-wrap">
                <a href="' . htmlspecialchars($doneDownloadUrl) . '" class="btn btn-primary">
                    <i class="bi bi-download"></i> Karten herunterladen
                </a>
                <a href="' . url('admin/payment_requests.php') . '" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul"></i> Alle Zahlungsanforderungen
                </a>
            </div>
        </div>
    </div>';

// ─── Schritt 1: Veranstaltung und Datum ───────────────────────────────────────
} elseif ($step === 'event') {
    $eventSelectHtml = '';
    if (count($events) > 1) {
        $eventSelectHtml = '<div class="col-md-6 mb-3">
            <label class="form-label">Veranstaltung <span class="text-danger">*</span></label>
            <select class="form-select" id="event_id" name="event_id" required>
                <option value="">Bitte wählen...</option>';
        foreach ($events as $ev) {
            $selected = $eventId === (int)$ev['id'] ? 'selected' : '';
            $eventSelectHtml .= '<option value="' . $ev['id'] . '" ' . $selected . '>'
                . htmlspecialchars($ev['name']) . (!$ev['active'] ? ' (inaktiv)' : '') . '</option>';
        }
        $eventSelectHtml .= '</select></div>';
    } else {
        $eventSelectHtml = '<input type="hidden" id="event_id" name="event_id" value="' . $eventId . '">';
        if ($eventId > 0) {
            $eventSelectHtml .= '<div class="col-md-12 mb-3">
                <div class="alert alert-info mb-0"><strong>Veranstaltung:</strong> '
                . htmlspecialchars($events[0]['name'] ?? '') . '</div></div>';
        }
    }

    $content .= '
    <div class="card">
        <div class="card-header bg-success bg-opacity-25">
            <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Schritt 1: Veranstaltung und Datum wählen</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="">
                <input type="hidden" name="id" value="' . $requestId . '">
                <input type="hidden" name="step" value="seats">
                <div class="row">
                    ' . $eventSelectHtml . '
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Datum <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="date" name="date"
                                   value="' . htmlspecialchars($eventDate) . '" required readonly style="background-color:#fff;cursor:pointer;">
                            <button class="btn btn-outline-secondary" type="button" onclick="if(window.fp){window.fp.open();}">
                                <i class="bi bi-calendar3"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-success" id="continue-btn" ' . (empty($eventDate) ? 'disabled' : '') . '>
                        Weiter <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>';

// ─── Schritt 2: Sitzauswahl ───────────────────────────────────────────────────
} elseif ($step === 'seats' && $eventId > 0 && !empty($eventDate)) {
    $showTime      = $event['show_start_time'] ?? '20:30:00';
    $eventDateData = $eventModel->getDateByEventAndDate($eventId, $eventDate);
    if ($eventDateData && !empty($eventDateData['show_start_time'])) {
        $showTime = $eventDateData['show_start_time'];
    }
    $timeFormatted = date('H:i', strtotime($showTime)) . ' Uhr';

    $content .= '
    <div class="card mb-3">
        <div class="card-header bg-success bg-opacity-25">
            <h5 class="mb-0"><i class="bi bi-grid"></i> Schritt 2: Plätze auswählen</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <strong>' . htmlspecialchars($event['name'] ?? '') . '</strong> &ndash; '
                . date('d.m.Y', strtotime($eventDate)) . ' um ' . $timeFormatted . '
            </div>
            <form method="POST" id="seat-selection-form">
                ' . \App\Core\Csrf::field() . '
                <input type="hidden" name="event_id" value="' . $eventId . '">
                <input type="hidden" name="event_date" value="' . $eventDate . '">
                <div class="row g-3">
                    <div class="col-lg-10">
                        <div id="seatplan-container">';

    if (!empty($seatPlan)) {
        $seatPlanHtml = '<div class="seatplan-container">';
        foreach ($seatPlan as $row) {
            if ($row['type'] === 'row' && isset($row['no'])) {
                if ($row['no'] === 0) {
                    $seatPlanHtml .= '<div class="seatplan-row" style="width:100%;">
                        <div class="stage-element" style="width:100%;flex:1;"><i class="bi bi-music-note-beamed"></i> BÜHNE</div>
                    </div>';
                } else {
                    $seatPlanHtml .= '<div class="seatplan-row">
                        <div class="row-label">Reihe ' . $row['no'] . '</div>
                        <div class="seatplan-elements">';

                    foreach ($row['content'] as $element) {
                        $seatId     = '';
                        $isReserved = false;

                        if ($element['type'] === 'seat') {
                            $seatId     = 'seat_' . $row['no'] . '_' . $element['no'];
                            $isReserved = isset($reservedSeats[$seatId]);
                        } elseif ($element['type'] === 'table') {
                            $seatId = 'table_' . $element['no'];
                        }

                        $category = null;
                        if (!empty($element['category'])) {
                            foreach ($categories as $cat) {
                                if ($cat['id'] == $element['category']) { $category = $cat; break; }
                            }
                        }
                        $bgColor = $category ? ($category['color'] ?: '#0d6efd') : '#0d6efd';

                        if ($element['type'] === 'seat') {
                            $priceLabel = $category ? number_format((float)$category['price'], 2, ',', '.') . ' €' : '';
                            $seatPlanHtml .= '<span class="seat-element ' . ($isReserved ? 'reserved' : '') . '"
                                data-seat-id="' . htmlspecialchars($seatId) . '"
                                data-row="' . $row['no'] . '"
                                data-seat="' . $element['no'] . '"
                                data-category="' . ($element['category'] ?? '') . '"
                                style="border-color:' . $bgColor . ';border-width:1px;background-color:' . ($isReserved ? '#dc3545' : '#28a745') . ';color:white;"
                                onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                title="' . ($isReserved ? 'Reserviert' : ('Verfügbar' . ($priceLabel ? ' – ' . $priceLabel : ''))) . '">
                                ' . $element['no'] . '
                            </span>';
                        } elseif ($element['type'] === 'table') {
                            $tableSeats = $element['seats'] ?? [];
                            $seatPlanHtml .= '<div class="table-with-seats" style="display:inline-flex;flex-direction:column;align-items:center;gap:.25rem;">';

                            $renderTableSeats = function(array $seats) use ($reservedSeats, $categories, $element, $row, &$seatPlanHtml) {
                                foreach ($seats as $seat) {
                                    $tsId  = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                    $isRes = isset($reservedSeats[$tsId]);
                                    $tCat  = null;
                                    if ($seat['category']) {
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == $seat['category']) { $tCat = $cat; break; }
                                        }
                                    }
                                    $tColor = $tCat ? ($tCat['color'] ?: '#0d6efd') : '#0d6efd';
                                    $seatPlanHtml .= '<span class="seat-element ' . ($isRes ? 'reserved' : '') . '"
                                        data-seat-id="' . htmlspecialchars($tsId) . '"
                                        data-row="' . $row['no'] . '"
                                        data-seat="' . $seat['number'] . '"
                                        data-table="' . $element['no'] . '"
                                        data-category="' . ($seat['category'] ?? '') . '"
                                        style="border-color:' . $tColor . ';border-width:1px;background-color:' . ($isRes ? '#dc3545' : '#28a745') . ';color:white;font-size:.75rem;padding:.25rem .5rem;"
                                        onclick="' . ($isRes ? '' : 'toggleSeat(this)') . '"
                                        title="' . ($isRes ? 'Reserviert' : 'Tisch ' . $element['no'] . ', Platz ' . $seat['number']) . '">
                                        ' . $seat['number'] . '
                                    </span>';
                                }
                            };

                            if (!empty($tableSeats['front'])) {
                                $seatPlanHtml .= '<div style="display:flex;gap:.25rem;">';
                                $renderTableSeats($tableSeats['front']);
                                $seatPlanHtml .= '</div>';
                            }
                            $seatPlanHtml .= '<div style="display:flex;align-items:center;gap:.25rem;">';
                            if (!empty($tableSeats['left'])) {
                                $seatPlanHtml .= '<div style="display:flex;flex-direction:column;gap:.25rem;">';
                                $renderTableSeats($tableSeats['left']);
                                $seatPlanHtml .= '</div>';
                            }
                            $seatPlanHtml .= '<span class="table-element" style="font-size:.9rem;padding:.5rem;background-color:#6c757d;color:white;border:1px solid #5a6268;">Tisch ' . $element['no'] . '</span>';
                            if (!empty($tableSeats['right'])) {
                                $seatPlanHtml .= '<div style="display:flex;flex-direction:column;gap:.25rem;">';
                                $renderTableSeats($tableSeats['right']);
                                $seatPlanHtml .= '</div>';
                            }
                            $seatPlanHtml .= '</div>';
                            if (!empty($tableSeats['back'])) {
                                $seatPlanHtml .= '<div style="display:flex;gap:.25rem;">';
                                $renderTableSeats($tableSeats['back']);
                                $seatPlanHtml .= '</div>';
                            }
                            $seatPlanHtml .= '</div>';
                        } elseif ($element['type'] === 'distance' || $element['type'] === 'spacer') {
                            $seatPlanHtml .= '<span class="distance-element"' . seatplanStairsAttr($element) . '>' . (empty($element['stairs']) ? (int)($element['no'] ?? 0) : '') . '</span>';
                        }
                    }
                    $seatPlanHtml .= '</div></div>';
                }
            }
        }
        $seatPlanHtml .= '</div>';
        $content .= $seatPlanHtml;
    } else {
        $content .= '<p class="text-muted text-center">Kein Sitzplan vorhanden.</p>';
    }

    $content .= '
                        </div>
                    </div>
                    <div class="col-lg-2">
                        <div style="position:sticky;top:1rem;">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0">Ausgewählte Plätze <span id="selected-seats-count" class="badge bg-secondary ms-1" style="display:none"></span></h6>
                                </div>
                                <div class="card-body">
                                    <div id="selected-seats">
                                        <p class="text-muted small">Keine Plätze ausgewählt</p>
                                    </div>
                                    <div id="selected-total" class="fw-bold mt-2" style="display:none;"></div>
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="select_seats" class="btn btn-success" id="continue-btn" disabled>
                                    Weiter <i class="bi bi-arrow-right"></i>
                                </button>
                                <a href="' . url('admin/payment_request_assign.php?id=' . $requestId) . '" class="btn btn-outline-secondary">Zurück</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>';

// ─── Schritt 3: Bestätigung ───────────────────────────────────────────────────
} elseif ($step === 'confirm' && !empty($selectedSeats)) {
    // Gesamtpreis berechnen
    $totalPrice = 0;
    $seatRows   = [];
    foreach ($selectedSeats as $seatData) {
        if (!is_array($seatData)) $seatData = json_decode($seatData, true);
        if (!$seatData) continue;
        $catPrice = 0;
        $catName  = '–';
        if (!empty($seatData['category'])) {
            foreach ($categories as $cat) {
                if ($cat['id'] == $seatData['category']) {
                    $catPrice = (float)$cat['price'];
                    $catName  = $cat['name'];
                    break;
                }
            }
        }
        $totalPrice += $catPrice;
        $seatRows[] = [
            'id'    => $seatData['id'],
            'cat'   => $catName,
            'price' => $catPrice,
        ];
    }

    $seatsTableHtml = '<table class="table table-sm mb-0"><thead><tr><th>Platz</th><th>Kategorie</th><th class="text-end">Preis</th></tr></thead><tbody>';
    foreach ($seatRows as $sr) {
        $seatsTableHtml .= '<tr><td>' . htmlspecialchars($sr['id']) . '</td><td>' . htmlspecialchars($sr['cat']) . '</td>'
            . '<td class="text-end">' . number_format($sr['price'], 2, ',', '.') . ' €</td></tr>';
    }
    $seatsTableHtml .= '</tbody><tfoot><tr class="fw-bold"><td colspan="2">Gesamt</td><td class="text-end">'
        . number_format($totalPrice, 2, ',', '.') . ' €</td></tr></tfoot></table>';

    $content .= '
    <div class="card">
        <div class="card-header bg-success bg-opacity-25">
            <h5 class="mb-0"><i class="bi bi-check2-square"></i> Schritt 3: Bestätigen</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Empfänger</h6>
                    <p class="mb-1"><strong>' . htmlspecialchars($payReq['customer_name']) . '</strong></p>
                    <p class="text-muted small">' . htmlspecialchars($payReq['customer_email']) . '</p>
                </div>
                <div class="col-md-6">
                    <h6>Veranstaltung</h6>
                    <p class="mb-1"><strong>' . htmlspecialchars($event['name'] ?? '') . '</strong></p>
                    <p class="text-muted small">' . date('d.m.Y', strtotime($eventDate)) . '</p>
                </div>
            </div>
            <h6 class="mt-3">Ausgewählte Plätze</h6>
            ' . $seatsTableHtml . '
            <div class="alert alert-info mt-3 mb-0">
                <i class="bi bi-info-circle"></i>
                Nach dem Speichern wird dem Kunden automatisch der Ticket-Download-Link per E-Mail gesendet.
            </div>
            <form method="POST" class="mt-3">
                ' . \App\Core\Csrf::field() . '
                <input type="hidden" name="event_id" value="' . $eventId . '">
                <input type="hidden" name="event_date" value="' . $eventDate . '">
                <input type="hidden" name="seats_json" value="' . htmlspecialchars($selectedSeatsJson ?: json_encode($selectedSeats)) . '">
                <div class="d-flex gap-2 justify-content-end">
                    <a href="' . url('admin/payment_request_assign.php?id=' . $requestId . '&step=seats&event_id=' . $eventId . '&date=' . $eventDate) . '"
                       class="btn btn-outline-secondary">Zurück</a>
                    <button type="submit" name="assign_tickets" class="btn btn-success">
                        <i class="bi bi-ticket-perforated"></i> Karten erstellen &amp; E-Mail senden
                    </button>
                </div>
            </form>
        </div>
    </div>';
}

// ─── CSS + JS ─────────────────────────────────────────────────────────────────
$additionalCss = '
<link rel="stylesheet" href="' . url('libs/css/flatpickr.min.css') . '">
<style>
.seatplan-container { background:#f8f9fa; padding:1rem; border-radius:.5rem; margin:1rem 0; width:100%; overflow-x:auto; }
.seatplan-row { display:flex; align-items:center; margin-bottom:.5rem; gap:.25rem; }
.row-label { min-width:60px; font-weight:bold; text-align:right; font-size:.85rem; }
.seatplan-elements { display:flex; flex-wrap:nowrap; gap:.25rem; flex:1; }
.seat-element, .table-element, .distance-element, .stage-element {
    padding:.25rem .4rem; border-radius:.2rem; cursor:pointer; transition:all .2s;
    user-select:none; border:4px solid transparent; font-size:.75rem; min-width:28px; text-align:center;
}
.distance-element { background:#6c757d!important; color:white!important; border:1px solid #6c757d!important; cursor:not-allowed!important; width:28px; }
.table-element { background:#6c757d!important; color:white!important; border:1px solid #5a6268!important; }
.stage-element { background:#212529!important; color:white!important; width:100%; padding:.75rem 1rem; font-size:1rem; font-weight:bold; text-align:center; border:none!important; cursor:default; margin:.5rem 0; }
.seat-element { background:#28a745; color:white; }
.seat-element:hover { transform:scale(1.05); box-shadow:0 2px 4px rgba(0,0,0,.2); }
.seat-element.reserved { background:#dc3545; color:white; border-color:#dc3545; cursor:not-allowed; }
.seat-element.selected { background:#198754; color:white; border-color:#ffc107; border-width:3px!important; }
/* Seats and distances share one fixed slot width so seat numbers line up across rows */
.seatplan-row .seat-element, .seatplan-row .distance-element {
    display: inline-block; box-sizing: border-box;
    width: 2.5rem; min-width: 2.5rem; flex: 0 0 2.5rem;
    padding-left: 0; padding-right: 0;
}
</style>';

$additionalJs = <<<JS
<script src="{$flatpickrJsSrc}"></script>
<script src="{$flatpickrDeSrc}"></script>
<script>
let selectedSeats  = [];
let allowedDates   = {$allowedDatesJs};
let categories     = {$categoriesJs};
let currentEventId = {$jsEventId};
let requestId      = {$jsRequestId};
let urlAssign      = {$urlAssign};

document.addEventListener('DOMContentLoaded', function() {
    var dateInput = document.getElementById('date');
    if (dateInput && typeof flatpickr !== 'undefined') {
        window.fp = flatpickr(dateInput, {
            locale: 'de',
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd.m.Y',
            defaultDate: {$earliestDateJs},
            enable: allowedDates,
            disableMobile: true,
            onChange: function(selectedDates, dateStr) {
                document.getElementById('continue-btn').disabled = !dateStr;
            }
        });
    }
    var evSel = document.getElementById('event_id');
    if (evSel) {
        evSel.addEventListener('change', function() {
            var eid = this.value;
            if (eid) window.location.href = urlAssign.replace(/"/g,'') + '?id=' + requestId + '&step=event&event_id=' + eid;
        });
    }
});

function toggleSeat(el) {
    var seatId   = el.dataset.seatId;
    var category = el.dataset.category || null;
    var row      = el.dataset.row || null;
    var seat     = el.dataset.seat || null;
    var table    = el.dataset.table || null;

    var idx = selectedSeats.findIndex(function(s) { return s.id === seatId; });
    if (idx >= 0) {
        el.classList.remove('selected');
        el.style.backgroundColor = '#28a745';
        el.style.color = 'white';
        el.style.borderWidth = '1px';
        selectedSeats.splice(idx, 1);
    } else {
        el.classList.add('selected');
        el.style.backgroundColor = '#198754';
        el.style.color = 'white';
        var obj = { id: seatId, category: category, is_reduced: 0, is_fn_card: 0 };
        if (row)   obj.row   = row;
        if (seat)  obj.seat  = seat;
        if (table) obj.table = table;

        if (category && categories) {
            var cat = categories.find(function(c) { return c.id == category; });
            if (cat) {
                obj.category_price = cat.price || 0;
                obj.category_name  = cat.name  || '';
            }
        }
        selectedSeats.push(obj);
    }
    updateSeatDisplay();
    syncSeatsToForm();
}

function formatSeatId(seatId) {
    var m = seatId.match(/^seat_(\d+)_(\d+)$/);
    if (m) return 'Reihe ' + m[1] + ', Platz ' + m[2];
    var t = seatId.match(/^tableseat_(\d+)_(\d+)$/);
    if (t) return 'Tisch ' + t[1] + ', Platz ' + t[2];
    return seatId;
}

function updateSeatDisplay() {
    var container = document.getElementById('selected-seats');
    var badge     = document.getElementById('selected-seats-count');
    var totalEl   = document.getElementById('selected-total');
    var btn       = document.getElementById('continue-btn');
    if (!container) return;

    if (selectedSeats.length === 0) {
        container.innerHTML = '<p class="text-muted small">Keine Plätze ausgewählt</p>';
        if (badge)   badge.style.display = 'none';
        if (totalEl) totalEl.style.display = 'none';
        if (btn)     btn.disabled = true;
    } else {
        var total = 0;
        var html  = '<ul class="list-unstyled mb-0 small">';
        selectedSeats.forEach(function(s) {
            var price = parseFloat(s.category_price || 0);
            total += price;
            html += '<li><i class="bi bi-ticket text-success"></i> ' + formatSeatId(s.id)
                + (price > 0 ? ' <span class="text-muted">(' + price.toFixed(2).replace('.', ',') + ' €)</span>' : '') + '</li>';
        });
        html += '</ul>';
        container.innerHTML = html;
        if (badge)   { badge.textContent = selectedSeats.length; badge.style.display = 'inline'; }
        if (totalEl) {
            totalEl.textContent = 'Gesamt: ' + total.toFixed(2).replace('.', ',') + ' €';
            totalEl.style.display = 'block';
        }
        if (btn) btn.disabled = false;
    }
}

function syncSeatsToForm() {
    document.querySelectorAll('input[name="seats[]"]').forEach(function(el){ el.remove(); });
    var form = document.getElementById('seat-selection-form');
    if (!form) return;
    selectedSeats.forEach(function(s) {
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'seats[]';
        inp.value = JSON.stringify(s);
        form.appendChild(inp);
    });
}
</script>
JS;

require_once __DIR__ . '/../../app/views/layouts/main.php';
