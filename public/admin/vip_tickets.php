<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Session;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\SeatCategory;
use App\Models\Reservation;
use App\Models\Customer;
use App\Services\EmailService;

Auth::requireAdmin();
Session::start();

$eventModel      = new Event();
$seatPlanModel   = new SeatPlan();
$categoryModel   = new SeatCategory();
$reservationModel = new Reservation();
$customerModel   = new Customer();

$step     = $_GET['step'] ?? 'event';
$eventId  = (int)($_GET['event_id'] ?? 0);
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

$categories = [];
if ($eventId > 0) {
    $categories = $categoryModel->getByEvent($eventId);
}
$categoriesJs = json_encode($categories);

$flatpickrJsSrc   = url('libs/js/flatpickr.min.js');   // raw URL for HTML src=""
$flatpickrDeSrc   = url('libs/js/flatpickr.de.js');
$flatpickrJs      = json_encode($flatpickrJsSrc);       // JSON-encoded for JS variable
$flatpickrDeJs    = json_encode($flatpickrDeSrc);
$urlVip           = json_encode(url('admin/vip_tickets.php'));
$jsCustomerSearchApi = json_encode(url('api/customer_search.php'));

$earliestDate = !empty($eventDates) ? $eventDates[0]['date'] : date('Y-m-d');
$earliestDateJs = json_encode($earliestDate);

$error   = '';
$success = '';

// ─── POST: Plätze weiterleiten ────────────────────────────────────────────────
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
            $step = 'customer';
        }
    } elseif (isset($_POST['create_vip'])) {
        // ─── VIP-Bestellung anlegen ───────────────────────────────────────────
        try {
            $selectedSeats = json_decode($_POST['seats_json'], true);
            if (empty($selectedSeats)) {
                throw new \Exception('Keine Plätze ausgewählt.');
            }

            $eventId   = (int)$_POST['event_id'];
            $eventDate = $_POST['event_date'];

            if (empty($eventDate)) {
                throw new \Exception('Kein Datum angegeben.');
            }

            // Kundendaten erfassen
            $skipEmail    = !empty($_POST['skip_email']);
            $firstName   = trim($_POST['first_name'] ?? '');
            $lastName    = trim($_POST['last_name'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $emailConfirm = trim($_POST['email_confirm'] ?? '');

            if (empty($firstName) || empty($lastName)) {
                throw new \Exception('Vor- und Nachname sind Pflichtfelder.');
            }
            if ($skipEmail) {
                // Ohne E-Mail: kein Mailversand, Karten werden manuell heruntergeladen.
                $email = '';
            } else {
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception('Bitte geben Sie eine gültige E-Mail-Adresse ein.');
                }
                if ($email !== $emailConfirm) {
                    throw new \Exception('Die E-Mail-Adressen stimmen nicht überein.');
                }
            }

            // Optionale Adressfelder (mit Platzhalter wenn leer, da DB NOT NULL)
            $street      = trim($_POST['street'] ?? '') ?: '-';
            $houseNumber = trim($_POST['house_number'] ?? '') ?: '-';
            $postalCode  = trim($_POST['postal_code'] ?? '') ?: '00000';
            $city        = trim($_POST['city'] ?? '') ?: '-';
            $phonePrefix = trim($_POST['phone_prefix'] ?? '') ?: '-';
            $phoneNumber = trim($_POST['phone_number'] ?? '') ?: '-';
            $salutation  = $_POST['salutation'] ?? '';

            $customerData = [
                'salutation'   => $salutation,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'street'       => $street,
                'house_number' => $houseNumber,
                'postal_code'  => $postalCode,
                'city'         => $city,
                'email'        => $email,
                'phone_prefix' => $phonePrefix,
                'phone_number' => $phoneNumber,
                'session_id'   => session_id(),
                'item_type'    => 'ticket',
            ];
            $customerId = $customerModel->createOrGet($customerData);

            // Reservierungen mit Status 11 (VIP) anlegen
            $createdReservationIds = [];
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
                        'status_id'       => 11,
                        'is_reduced'      => 0,
                        'is_fn_card'      => 0,
                        'is_honor_card'   => 0,
                        'voucher_id'      => null,
                    ]);
                    $createdReservationIds[] = $resId;
                } catch (\Exception $e) {
                    error_log("VIP ticket creation error: " . $e->getMessage());
                }
            }

            if (empty($createdReservationIds)) {
                throw new \Exception('Keine VIP-Karten konnten erstellt werden (Plätze möglicherweise bereits vergeben).');
            }

            // E-Mail mit Download-Link senden (nur wenn eine E-Mail erfasst wurde)
            $emailEnabled = Config::get('email_enabled', true);
            if ($emailEnabled && $email !== '') {
                try {
                    $token      = generateTicketDownloadToken($createdReservationIds);
                    $ticketUrl  = absoluteUrl('api/ticket_download.php?reservation_ids=' . implode(',', $createdReservationIds) . '&token=' . $token);

                    $vipReservations = [];
                    foreach ($createdReservationIds as $resId) {
                        $rd = $reservationModel->getById($resId);
                        if ($rd) $vipReservations[] = $rd;
                    }

                    $emailService = new EmailService();
                    $emailService->sendVipEmail(
                        $email,
                        trim($firstName . ' ' . $lastName),
                        $vipReservations,
                        $ticketUrl
                    );
                } catch (\Exception $emailEx) {
                    error_log("VIP email error: " . $emailEx->getMessage());
                    // Kein fataler Fehler – Karten wurden bereits erstellt
                }
            }

            // Log
            if (function_exists('logAdminAction')) {
                logAdminAction((string)Auth::id(), 'vip_tickets_created', [
                    'reservation_ids' => $createdReservationIds,
                    'customer_email'  => substr(md5($email), 0, 8) . '***',
                    'event_id'        => $eventId,
                    'event_date'      => $eventDate,
                    'count'           => count($createdReservationIds),
                ]);
            }

            $idsParam = implode(',', $createdReservationIds);
            $token    = generateTicketDownloadToken($createdReservationIds);
            $downloadUrl = url('api/ticket_download.php?reservation_ids=' . $idsParam . '&token=' . $token);

            $success = $email !== ''
                ? 'VIP-Karten erfolgreich erstellt und E-Mail an ' . htmlspecialchars($email) . ' gesendet.'
                : 'VIP-Karten erfolgreich erstellt. Es wurde keine E-Mail versendet – bitte die Karten unten herunterladen.';
            $step = 'done';
            // Merken für done-Anzeige
            $doneReservationIds  = $createdReservationIds;
            $doneDownloadUrl     = $downloadUrl;
            $doneCustomerName    = trim($firstName . ' ' . $lastName);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $step  = 'customer';
        }
    }
}

// ─── Sitzplandaten ────────────────────────────────────────────────────────────
$event       = null;
$seatPlan    = [];
$reservedSeats = [];
$selectedSeats = [];

if ($eventId > 0 && !empty($eventDate)) {
    $event         = $eventModel->getById($eventId);
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
$pageTitle    = 'VIP-Tickets';
$showSidebar  = true;
$showHeader   = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-star text-warning"></i> VIP-Tickets erstellen</h2>
    <a href="' . url('admin/reservations.php') . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Reservierungen
    </a>
</div>';

if ($error) {
    $content .= '<div class="alert alert-danger alert-dismissible fade show" role="alert">
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
            <i class="bi bi-star-fill text-warning" style="font-size:3rem;"></i>
            <h4 class="mt-3">VIP-Karten erfolgreich erstellt!</h4>
            <p class="text-muted">' . count($doneReservationIds) . ' Karte(n) für <strong>' . htmlspecialchars($doneCustomerName) . '</strong></p>
            <div class="d-flex justify-content-center gap-3 mt-4 flex-wrap">
                <a href="' . htmlspecialchars($doneDownloadUrl) . '" class="btn btn-primary">
                    <i class="bi bi-download"></i> Karten herunterladen
                </a>
                <a href="' . url('admin/vip_tickets.php') . '" class="btn btn-outline-secondary">
                    <i class="bi bi-plus-circle"></i> Weitere VIP-Karten erstellen
                </a>
                <a href="' . url('admin/reservations.php') . '" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul"></i> Alle Reservierungen
                </a>
            </div>
        </div>
    </div>';
}

// ─── Schritt 1: Veranstaltung und Datum ───────────────────────────────────────
elseif ($step === 'event') {
    $eventSelectHtml = '';
    if (count($events) > 1) {
        $eventSelectHtml = '
            <div class="col-md-6 mb-3">
                <label for="event_id" class="form-label">Veranstaltung <span class="text-danger">*</span></label>
                <select class="form-select" id="event_id" name="event_id" required onchange="loadEventDates()">
                    <option value="">Bitte wählen...</option>';
        foreach ($events as $ev) {
            $selected = $eventId === (int)$ev['id'] ? 'selected' : '';
            $label    = htmlspecialchars($ev['name']) . (!$ev['active'] ? ' (inaktiv)' : '');
            $eventSelectHtml .= '<option value="' . $ev['id'] . '" ' . $selected . '>' . $label . '</option>';
        }
        $eventSelectHtml .= '
                </select>
            </div>';
    } else {
        $eventSelectHtml = '<input type="hidden" id="event_id" name="event_id" value="' . $eventId . '">';
        if ($eventId > 0) {
            $eventSelectHtml .= '
            <div class="col-md-12 mb-3">
                <div class="alert alert-info"><strong>Veranstaltung:</strong> ' . htmlspecialchars($events[0]['name'] ?? '') . '</div>
            </div>';
        }
    }

    $content .= '
    <div class="card">
        <div class="card-header bg-warning bg-opacity-25">
            <h5 class="mb-0"><i class="bi bi-star"></i> Schritt 1: Veranstaltung und Datum wählen</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" id="event-date-form">
                <input type="hidden" name="step" value="seats">
                <div class="row">
                    ' . $eventSelectHtml . '
                    <div class="col-md-6 mb-3">
                        <label for="date" class="form-label">Datum <span class="text-danger">*</span></label>
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
                    <button type="submit" class="btn btn-warning" id="continue-btn" ' . (empty($eventDate) ? 'disabled' : '') . '>
                        Weiter <i class="bi bi-arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>';
}

// ─── Schritt 2: Sitzauswahl ───────────────────────────────────────────────────
elseif ($step === 'seats' && $eventId > 0 && !empty($eventDate)) {
    $showTime = $event['show_start_time'] ?? '20:30:00';
    $eventDateData = $eventModel->getDateByEventAndDate($eventId, $eventDate);
    if ($eventDateData && !empty($eventDateData['show_start_time'])) {
        $showTime = $eventDateData['show_start_time'];
    }
    $timeFormatted = date('H:i', strtotime($showTime)) . ' Uhr';

    $content .= '
    <div class="card mb-3">
        <div class="card-header bg-warning bg-opacity-25">
            <h5 class="mb-0"><i class="bi bi-star"></i> Schritt 2: VIP-Plätze auswählen</h5>
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
                            $seatPlanHtml .= '<span class="seat-element ' . ($isReserved ? 'reserved' : '') . '"
                                data-seat-id="' . htmlspecialchars($seatId) . '"
                                data-row="' . $row['no'] . '"
                                data-seat="' . $element['no'] . '"
                                data-category="' . ($element['category'] ?? '') . '"
                                style="border-color:' . $bgColor . ';border-width:1px;background-color:' . ($isReserved ? '#dc3545' : '#28a745') . ';color:white;"
                                onclick="' . ($isReserved ? '' : 'toggleSeat(this)') . '"
                                title="' . ($isReserved ? 'Reserviert' : 'Verfügbar') . '">
                                ' . $element['no'] . '
                            </span>';
                        } elseif ($element['type'] === 'table') {
                            $tableSeats   = $element['seats'] ?? [];
                            $seatPlanHtml .= '<div class="table-with-seats" style="display:inline-flex;flex-direction:column;align-items:center;gap:.25rem;">';

                            $renderTableSeats = function(array $seats) use ($reservedSeats, $categories, $element, $row, &$seatPlanHtml) {
                                foreach ($seats as $seat) {
                                    $tsId    = 'tableseat_' . $element['no'] . '_' . $seat['number'];
                                    $isRes   = isset($reservedSeats[$tsId]);
                                    $tCat    = null;
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
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="select_seats" class="btn btn-warning" id="continue-btn" disabled>
                                    Weiter <i class="bi bi-arrow-right"></i>
                                </button>
                                <a href="' . url('admin/vip_tickets.php') . '" class="btn btn-outline-secondary">Zurück</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>';
}

// ─── Schritt 3: Kundendaten ───────────────────────────────────────────────────
elseif ($step === 'customer' && !empty($selectedSeats)) {
    $content .= '
    <div class="card">
        <div class="card-header bg-warning bg-opacity-25">
            <h5 class="mb-0"><i class="bi bi-star"></i> Schritt 3: Empfängerdaten (für den Download-Link)</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Die VIP-Karten sind <strong>kostenlos</strong>. Der Empfänger bekommt eine E-Mail mit dem direkten Download-Link.
                Auf dem Ticket erscheint <strong>„VIP"</strong> anstelle eines Preises.
            </div>
            <form method="POST" id="vip-form">
                ' . \App\Core\Csrf::field() . '
                <input type="hidden" name="event_id" value="' . $eventId . '">
                <input type="hidden" name="event_date" value="' . $eventDate . '">
                <input type="hidden" name="seats_json" value="' . htmlspecialchars($selectedSeatsJson ?: json_encode($selectedSeats)) . '">

                <div class="mb-3 position-relative">
                    <label for="customer-search" class="form-label"><i class="bi bi-search"></i> Kunde suchen</label>
                    <input type="text" class="form-control" id="customer-search" placeholder="Name oder E-Mail eingeben..." autocomplete="off">
                    <div id="customer-search-results" class="list-group shadow-sm position-absolute w-100" style="z-index: 1050; display: none; max-height: 320px; overflow-y: auto;"></div>
                </div>

                <div class="row">
                    <div class="col-md-2 mb-3">
                        <label for="salutation" class="form-label">Anrede</label>
                        <select class="form-select" id="salutation" name="salutation">
                            <option value="">–</option>
                            <option value="Herr">Herr</option>
                            <option value="Frau">Frau</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="first_name" class="form-label">Vorname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required
                               value="' . htmlspecialchars($_POST['first_name'] ?? '') . '">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="last_name" class="form-label">Nachname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required
                               value="' . htmlspecialchars($_POST['last_name'] ?? '') . '">
                    </div>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="vip_skip_email" name="skip_email" value="1"' . (!empty($_POST['skip_email']) ? ' checked' : '') . '>
                    <label class="form-check-label" for="vip_skip_email">Ohne E-Mail erstellen (kein Mailversand – Karten unten herunterladen/drucken)</label>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">E-Mail <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required
                               value="' . htmlspecialchars($_POST['email'] ?? '') . '">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="email_confirm" class="form-label">E-Mail bestätigen <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email_confirm" name="email_confirm" required
                               value="' . htmlspecialchars($_POST['email_confirm'] ?? '') . '">
                    </div>
                </div>

                <details class="mb-3">
                    <summary class="text-muted" style="cursor:pointer;">Adressdaten (optional)</summary>
                    <div class="row mt-3">
                        <div class="col-md-8 mb-3">
                            <label for="street" class="form-label">Straße</label>
                            <input type="text" class="form-control" id="street" name="street"
                                   value="' . htmlspecialchars($_POST['street'] ?? '') . '">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="house_number" class="form-label">Hausnummer</label>
                            <input type="text" class="form-control" id="house_number" name="house_number"
                                   value="' . htmlspecialchars($_POST['house_number'] ?? '') . '">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="postal_code" class="form-label">PLZ</label>
                            <input type="text" class="form-control" id="postal_code" name="postal_code"
                                   value="' . htmlspecialchars($_POST['postal_code'] ?? '') . '">
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="city" class="form-label">Ort</label>
                            <input type="text" class="form-control" id="city" name="city"
                                   value="' . htmlspecialchars($_POST['city'] ?? '') . '">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="phone_prefix" class="form-label">Tel. Vorwahl</label>
                            <input type="text" class="form-control" id="phone_prefix" name="phone_prefix"
                                   value="' . htmlspecialchars($_POST['phone_prefix'] ?? '') . '">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="phone_number" class="form-label">Telefonnummer</label>
                            <input type="text" class="form-control" id="phone_number" name="phone_number"
                                   value="' . htmlspecialchars($_POST['phone_number'] ?? '') . '">
                        </div>
                    </div>
                </details>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                    <a href="' . url('admin/vip_tickets.php?step=seats&event_id=' . $eventId . '&date=' . $eventDate) . '"
                       class="btn btn-outline-secondary">Zurück</a>
                    <button type="submit" name="create_vip" class="btn btn-warning">
                        <i class="bi bi-star"></i> VIP-Karten erstellen &amp; E-Mail senden
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
.seat-element.selected { background:#ffc107; color:#212529; }
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
let selectedSeats = [];
let allowedDates  = {$allowedDatesJs};
let categories    = {$categoriesJs};
let currentEventId   = {$jsEventId};
let currentEventDate = {$jsEventDate};
let urlVip = {$urlVip};
let customerSearchApi = {$jsCustomerSearchApi};

// Flatpickr Kalender
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
        // defaultDate setzt den Wert, löst aber kein onChange aus → Button-Status initial setzen
        var contBtn = document.getElementById('continue-btn');
        if (contBtn) contBtn.disabled = !dateInput.value;
    }
    // Event-Auswahl: Seite neu laden um Termine zu holen
    var evSel = document.getElementById('event_id');
    if (evSel) {
        evSel.addEventListener('change', function() {
            var eid = this.value;
            if (eid) window.location.href = urlVip.replace(/"/g,'') + '?step=event&event_id=' + eid;
        });
    }

    // Kundensuche (nur im Kundenschritt vorhanden)
    var customerSearchInput = document.getElementById('customer-search');
    var customerSearchResults = document.getElementById('customer-search-results');
    if (customerSearchInput) {
        var searchTimer = null;
        customerSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                runCustomerSearch(customerSearchInput.value.trim());
            }, 300);
        });
        document.addEventListener('click', function(e) {
            if (!customerSearchInput.contains(e.target) && !customerSearchResults.contains(e.target)) {
                customerSearchResults.style.display = 'none';
            }
        });
    }

    // Schalter "Ohne E-Mail erstellen": E-Mail-Felder optional/deaktiviert
    var skipEmailCb = document.getElementById('vip_skip_email');
    if (skipEmailCb) {
        var emailEl = document.getElementById('email');
        var emailConfirmEl = document.getElementById('email_confirm');
        var applySkipEmail = function() {
            var skip = skipEmailCb.checked;
            [emailEl, emailConfirmEl].forEach(function(el) {
                if (!el) return;
                el.required = !skip;
                el.disabled = skip;
                if (skip) el.value = '';
            });
        };
        skipEmailCb.addEventListener('change', applySkipEmail);
        applySkipEmail();
    }
});

function loadEventDates() {}   // Platzhalter (Reload via onChange)

function runCustomerSearch(query) {
    var resultsEl = document.getElementById('customer-search-results');
    if (!resultsEl) return;
    if (query.length < 2) { resultsEl.style.display = 'none'; return; }

    fetch(customerSearchApi + '?q=' + encodeURIComponent(query))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.results || data.results.length === 0) {
                resultsEl.style.display = 'none';
                return;
            }
            resultsEl.innerHTML = '';
            data.results.forEach(function(c) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action py-2';
                btn.innerHTML =
                    '<strong>' + escHtml((c.first_name + ' ' + c.last_name).trim()) + '</strong>' +
                    (c.email ? ' <small class="text-muted">- ' + escHtml(c.email) + '</small>' : '') +
                    '<br><small class="text-muted">' +
                    escHtml([c.street, c.house_number, c.postal_code, c.city].filter(Boolean).join(' ')) +
                    '</small>';
                btn.addEventListener('click', function() {
                    fillCustomerForm(c);
                    resultsEl.style.display = 'none';
                    var searchInput = document.getElementById('customer-search');
                    if (searchInput) searchInput.value = (c.first_name + ' ' + c.last_name).trim();
                });
                resultsEl.appendChild(btn);
            });
            resultsEl.style.display = 'block';
        })
        .catch(function() {});
}

function fillCustomerForm(c) {
    var map = {
        salutation: c.salutation || '',
        first_name: c.first_name || '',
        last_name: c.last_name || '',
        street: c.street || '',
        house_number: c.house_number || '',
        postal_code: c.postal_code || '',
        city: c.city || '',
        email: c.email || '',
        email_confirm: c.email || '',
        phone_prefix: c.phone_prefix || '',
        phone_number: c.phone_number || ''
    };
    Object.keys(map).forEach(function(field) {
        var el = document.getElementById(field);
        if (el) el.value = map[field];
    });
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

// Sitzplan-Interaktion
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
        selectedSeats.splice(idx, 1);
    } else {
        el.classList.add('selected');
        el.style.backgroundColor = '#ffc107';
        el.style.color = '#212529';
        var obj = { id: seatId, category: category, is_reduced: 0, is_fn_card: 0 };
        if (row)   obj.row   = row;
        if (seat)  obj.seat  = seat;
        if (table) obj.table = table;

        // Kategorie-Preis ermitteln (für Anzeige – VIP hat keinen Preis)
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
    // seat_4_17 → Reihe 4, Platz 17
    var m = seatId.match(/^seat_(\d+)_(\d+)$/);
    if (m) return 'Reihe ' + m[1] + ', Platz ' + m[2];
    // tableseat_3_5 → Tisch 3, Platz 5
    var t = seatId.match(/^tableseat_(\d+)_(\d+)$/);
    if (t) return 'Tisch ' + t[1] + ', Platz ' + t[2];
    return seatId;
}

function updateSeatDisplay() {
    var container = document.getElementById('selected-seats');
    var badge     = document.getElementById('selected-seats-count');
    var btn       = document.getElementById('continue-btn');
    if (!container) return;

    if (selectedSeats.length === 0) {
        container.innerHTML = '<p class="text-muted small">Keine Plätze ausgewählt</p>';
        if (badge) badge.style.display = 'none';
        if (btn)   btn.disabled = true;
    } else {
        var html = '<ul class="list-unstyled mb-0 small">';
        selectedSeats.forEach(function(s) {
            html += '<li><i class="bi bi-star-fill text-warning"></i> ' + formatSeatId(s.id) + '</li>';
        });
        html += '</ul>';
        container.innerHTML = html;
        if (badge) { badge.textContent = selectedSeats.length; badge.style.display = 'inline'; }
        if (btn)   btn.disabled = false;
    }
}

function syncSeatsToForm() {
    // Bestehende seat-inputs entfernen
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
