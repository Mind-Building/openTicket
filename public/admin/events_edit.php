<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Event;
use App\Models\SeatCategory;

Auth::requireAdmin();

$eventModel = new Event();
$categoryModel = new SeatCategory();
$eventId = (int)($_GET['id'] ?? 0);
$event = $eventModel->getById($eventId);

if (!$event) {
    header('Location: ' . url('admin/events.php'));
    exit;
}

$error = '';
$success = isset($_GET['success']);

// Get default values from settings
$defaultShowStartTime = Config::get('show_start_time', '20:30');
$defaultMaxReservationTime = Config::get('max_reservation_time', '12:00');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!\App\Core\Csrf::validatePost()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $data = [
            'name' => $_POST['name'] ?? '',
            'active' => isset($_POST['active']) ? 1 : 0,
            'sell_tickets' => isset($_POST['sell_tickets']) ? 1 : 0,
            'sell_vouchers' => isset($_POST['sell_vouchers']) ? 1 : 0,
            'allow_reduced_prices' => isset($_POST['allow_reduced_prices']) ? 1 : 0,
            'allow_fn_card' => isset($_POST['allow_fn_card']) ? 1 : 0,
            'use_seat_plan' => isset($_POST['use_seat_plan']) ? 1 : 0,
            'max_seats' => isset($_POST['max_seats']) && $_POST['max_seats'] !== '' ? (int)$_POST['max_seats'] : null,
            'seat_category_id' => isset($_POST['seat_category_id']) && $_POST['seat_category_id'] !== '' ? (int)$_POST['seat_category_id'] : null,
            'email_ticket_subject' => !empty($_POST['email_ticket_subject']) ? trim($_POST['email_ticket_subject']) : null,
            'email_ticket_body' => !empty($_POST['email_ticket_body']) ? trim($_POST['email_ticket_body']) : null,
            'show_start_time' => $_POST['show_start_time'] ?? $defaultShowStartTime,
            'max_reservation_time' => $_POST['max_reservation_time'] ?? $defaultMaxReservationTime,
            'voucher_use_template' => isset($_POST['voucher_use_template']) ? 1 : 0,
            // Leerer String statt null, damit der Ort auch wieder entfernt werden kann
            'alt_location_name' => trim((string)($_POST['alt_location_name'] ?? '')),
        ];

        // Ticket code (QR / barcode) - only once database/migrate_ticket_code.sql has run
        if (array_key_exists('ticket_code_type', $event)) {
            $codeType = (string)($_POST['ticket_code_type'] ?? '');
            $data['ticket_code_type'] = array_key_exists($codeType, \App\Helpers\TicketCode::types()) ? $codeType : '';
            $data['ticket_code_content'] = mb_substr(trim((string)($_POST['ticket_code_content'] ?? '')), 0, 255);
        }

        if (empty($data['name'])) {
            $error = 'Bitte geben Sie einen Namen ein.';
        } else {
            try {
                $eventModel->update($eventId, $data);
                $event = $eventModel->getById($eventId);
                $success = true;
            } catch (Exception $e) {
                $error = 'Fehler beim Aktualisieren: ' . $e->getMessage();
            }
        }
    }
}

/**
 * Settings block for the QR code / barcode printed via the {ticket_code} ticket placeholder.
 */
function renderTicketCodeSection(array $event, int $eventId): string
{
    $html = '
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-qr-code"></i> Ticket-Code (QR-Code / Strichcode)</div>
                <div class="card-body">';

    if (!array_key_exists('ticket_code_type', $event)) {
        return $html . '
                    <div class="alert alert-warning mb-0">
                        Für diese Funktion muss zuerst die Datenbank-Migration <code>database/migrate_ticket_code.sql</code> ausgeführt werden.
                    </div>
                </div>
            </div>';
    }

    $type = (string)($event['ticket_code_type'] ?? '');
    $content = (string)($event['ticket_code_content'] ?? '');

    $options = '<option value="">Kein Code</option>';
    foreach (\App\Helpers\TicketCode::types() as $value => $label) {
        $options .= '<option value="' . $value . '"' . ($type === $value ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }

    $tokens = '';
    foreach (\App\Helpers\TicketCode::tokens() as $token => $description) {
        $tokens .= '<li><button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="insertTicketCodeToken(\'' . $token . '\')"><code>' . $token . '</code></button> – ' . htmlspecialchars($description) . '</li>';
    }

    $example = '';
    if ($type !== '') {
        $sample = ['id' => 1234, 'event_id' => $eventId, 'event_date' => date('Y-m-d'), 'seat_identifier' => 'seat_5_12'];
        $example = '<div class="form-text">Beispiel für Ticket T-00001234, Reihe 5, Platz 12: <code>'
            . htmlspecialchars(\App\Helpers\TicketCode::content($content, $sample, $type)) . '</code></div>';
    }

    return $html . '
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="ticket_code_type" class="form-label">Art des Codes</label>
                            <select class="form-select" id="ticket_code_type" name="ticket_code_type">' . $options . '</select>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="ticket_code_content" class="form-label">Inhalt des Codes</label>
                            <input type="text" class="form-control font-monospace" id="ticket_code_content" name="ticket_code_content" maxlength="255"
                                   value="' . htmlspecialchars($content) . '" placeholder="' . \App\Helpers\TicketCode::DEFAULT_CONTENT . '">
                            ' . $example . '
                        </div>
                    </div>
                    <div class="small text-muted">
                        Freier Text und folgende Platzhalter (anklicken zum Einfügen); leer = <code>' . \App\Helpers\TicketCode::DEFAULT_CONTENT . '</code>:
                        <ul class="mb-2">' . $tokens . '</ul>
                        Position und Größe des Codes legen Sie im
                        <a href="' . url('admin/events_ticket_template.php?id=' . $eventId) . '">Ticket-Layout</a> bzw.
                        <a href="' . url('admin/events_presales_ticket_template.php?id=' . $eventId) . '">Vorverkaufs-Layout</a>
                        über den Platzhalter <code>{ticket_code}</code> fest. Ein Strichcode wird umso breiter, je länger der Inhalt ist – dort möglichst kurze Inhalte verwenden.
                    </div>
                    <script>
                    function insertTicketCodeToken(token) {
                        var input = document.getElementById("ticket_code_content");
                        var start = input.selectionStart ?? input.value.length;
                        var end = input.selectionEnd ?? input.value.length;
                        input.value = input.value.slice(0, start) + token + input.value.slice(end);
                        input.focus();
                        input.setSelectionRange(start + token.length, start + token.length);
                    }
                    </script>
                </div>
            </div>';
}

$pageTitle = 'Veranstaltung bearbeiten: ' . htmlspecialchars($event['name']);
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Veranstaltung bearbeiten</h2>
    <a href="' . url('admin/events.php') . '" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

' . ($success ? '<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> Veranstaltung erfolgreich gespeichert!
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>' : '') . '

' . ($error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '') . '

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            ' . \App\Core\Csrf::field() . '
            <div class="mb-3">
                <label for="name" class="form-label">Name der Veranstaltung <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required 
                       value="' . htmlspecialchars($event['name']) . '">
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="show_start_time" class="form-label">Vorstellungsbeginn (Standard: ' . htmlspecialchars(substr($defaultShowStartTime, 0, 5)) . ' Uhr)</label>
                    <input type="time" class="form-control" id="show_start_time" name="show_start_time" 
                           value="' . htmlspecialchars(substr($event['show_start_time'] ?? $defaultShowStartTime, 0, 5)) . '">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="max_reservation_time" class="form-label">Max. Reservierungszeit (Standard: ' . htmlspecialchars(substr($defaultMaxReservationTime, 0, 5)) . ' Uhr)</label>
                    <input type="time" class="form-control" id="max_reservation_time" name="max_reservation_time" 
                           value="' . htmlspecialchars(substr($event['max_reservation_time'] ?? $defaultMaxReservationTime, 0, 5)) . '">
                </div>
            </div>

            <div class="mb-3">
                <label for="alt_location_name" class="form-label">Alternativer Veranstaltungsort</label>
                <input type="text" class="form-control" id="alt_location_name" name="alt_location_name"
                       value="' . htmlspecialchars($event['alt_location_name'] ?? '') . '"
                       placeholder="z. B. Stadthalle">
                <div class="form-text">
                    Name der Ausweichspielstätte. Wird zusammen mit der im
                    <a href="' . url('admin/seatplan.php?event_id=' . $eventId) . '">Sitzplan-Editor</a>
                    hinterlegten Alternativbelegung auf dem Ticket ausgegeben.
                </div>
            </div>

            ' . renderTicketCodeSection($event, $eventId) . '

            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle"></i>
                Das Reservierungstelefon wird aus den <a href="' . url('admin/settings.php') . '">Einstellungen</a> übernommen.
            </p>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="active" name="active" 
                           ' . ($event['active'] ? 'checked' : '') . '>
                    <label class="form-check-label" for="active">
                        Veranstaltung ist aktiv
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="sell_tickets" name="sell_tickets" 
                           ' . ($event['sell_tickets'] ? 'checked' : '') . '>
                    <label class="form-check-label" for="sell_tickets">
                        Tickets verkaufen
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="sell_vouchers" name="sell_vouchers"
                           ' . ($event['sell_vouchers'] ? 'checked' : '') . '>
                    <label class="form-check-label" for="sell_vouchers">
                        Gutscheine verkaufen
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="allow_reduced_prices" name="allow_reduced_prices"
                           ' . ($event['allow_reduced_prices'] ? 'checked' : '') . '>
                    <label class="form-check-label" for="allow_reduced_prices">
                        Ermäßigte Preise erlauben
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="allow_fn_card" name="allow_fn_card"
                           ' . ($event['allow_fn_card'] ? 'checked' : '') . '>
                    <label class="form-check-label" for="allow_fn_card">
                        FN-Karte erlauben
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="use_seat_plan" name="use_seat_plan"
                           ' . ($event['use_seat_plan'] ? 'checked' : '') . '
                           onchange="toggleSeatPlanFields()">
                    <label class="form-check-label" for="use_seat_plan">
                        Sitzplan verwenden
                    </label>
                </div>
            </div>

            <div id="no-seat-plan-fields" style="display: ' . ($event['use_seat_plan'] ? 'none' : 'block') . ';">
                <div class="mb-3">
                    <label for="max_seats" class="form-label">Maximale Anzahl von Sitzen</label>
                    <input type="number" class="form-control" id="max_seats" name="max_seats" 
                           min="1" 
                           value="' . htmlspecialchars($event['max_seats'] ?? '') . '"
                           placeholder="z.B. 100">
                    <small class="form-text text-muted">Geben Sie die maximale Anzahl von verfügbaren Sitzen für diese Veranstaltung ein.</small>
                </div>
                <div class="mb-3">
                    <label for="seat_category_id" class="form-label">Sitzkategorie</label>
                    <select class="form-select" id="seat_category_id" name="seat_category_id">
                        <option value="">-- Keine Kategorie --</option>';

$categories = $categoryModel->getByEvent($eventId);
foreach ($categories as $category) {
    $selected = (isset($event['seat_category_id']) && $event['seat_category_id'] == $category['id']) ? 'selected' : '';
    $content .= '<option value="' . $category['id'] . '" ' . $selected . '>' . 
                htmlspecialchars($category['name']) . ' (' . htmlspecialchars($category['sign']) . ') - ' . 
                number_format($category['price'], 2, ',', '.') . ' €</option>';
}

$content .= '
                    </select>
                    <small class="form-text text-muted">Wählen Sie eine Kategorie für die Sitze. Der Preis wird automatisch aus der Kategorie übernommen.</small>
                </div>
                <div class="mb-3">
                    <a href="' . url('admin/seatplan_categories.php?event_id=' . $eventId) . '" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-tags"></i> Kategorien verwalten
                    </a>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="voucher_use_template" name="voucher_use_template"
                           ' . (!empty($event['voucher_use_template']) ? 'checked' : '') . '>
                    <label class="form-check-label" for="voucher_use_template">
                        Gutscheine mit Template darstellen (sonst nur Code in E-Mail)
                    </label>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">E-Mail-Texte (optional)</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Sie können hier E-Mail-Texte für diese Veranstaltung konfigurieren. Wenn leer gelassen, werden die Standard-Texte aus den Einstellungen verwendet.</p>
                    
                    <div class="mb-3">
                        <label for="email_ticket_subject" class="form-label">E-Mail-Betreff für Ticket-E-Mails</label>
                        <input type="text" class="form-control" id="email_ticket_subject" name="email_ticket_subject" 
                               value="' . htmlspecialchars($event['email_ticket_subject'] ?? '') . '"
                               placeholder="z.B. Ihre Tickets für {event_name}">
                        <small class="form-text text-muted">Verfügbare Platzhalter: {customer_name}, {event_name}, {event_date}, {reservation_count}</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email_ticket_body" class="form-label">E-Mail-Text für Ticket-E-Mails (HTML)</label>
                        <textarea class="form-control" id="email_ticket_body" name="email_ticket_body" rows="10"
                                  placeholder="HTML-Text für die E-Mail. Verfügbare Platzhalter: {customer_name}, {event_name}, {event_date}, {event_time}, {reservation_count}, {ticket_link}, {ticket_list}, {category_name}, {price}, {voucher_codes}">' . htmlspecialchars($event['email_ticket_body'] ?? '') . '</textarea>
                        <small class="form-text text-muted">
                            Verfügbare Platzhalter: {customer_name}, {event_name}, {event_date}, {event_time}, {reservation_count}, {ticket_link}, {ticket_list}, {category_name}, {price}, {voucher_codes}<br>
                            <strong>Hinweis:</strong> Bei Veranstaltungen ohne Sitzplan wird {ticket_list} nicht angezeigt.
                        </small>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <a href="' . url('admin/events_ticket_template.php?id=' . $eventId) . '" class="btn btn-outline-primary">
                    <i class="bi bi-file-pdf"></i> Ticket-Template verwalten
                </a>
                <a href="' . url('admin/events_presales_ticket_template.php?id=' . $eventId) . '" class="btn btn-outline-primary">
                    <i class="bi bi-printer"></i> Vorverkaufs-Template verwalten
                </a>
                ' . ($event['sell_vouchers'] ? '
                <a href="' . url('admin/events_voucher_template.php?id=' . $eventId) . '" class="btn btn-outline-warning">
                    <i class="bi bi-gift"></i> Gutschein-Template verwalten
                </a>' : '') . '
                <a href="' . url('event.php?id=' . $eventId) . '" target="_blank" class="btn btn-outline-secondary" title="Buchung aus Kundensicht testen – funktioniert für Admins auch bei inaktiven Veranstaltungen">
                    <i class="bi bi-eye"></i> Als Kunde testen
                </a>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <a href="' . url('admin/events.php') . '" class="btn btn-secondary">Abbrechen</a>
                <a href="' . url('admin/events_dates.php?id=' . $eventId) . '" class="btn btn-info">
                    <i class="bi bi-calendar"></i> Termine verwalten
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Speichern
                </button>
            </div>
        </form>
    </div>
</div>
';

$additionalJs = '
<script>
function toggleSeatPlanFields() {
    const useSeatPlan = document.getElementById("use_seat_plan").checked;
    const noSeatPlanFields = document.getElementById("no-seat-plan-fields");
    if (noSeatPlanFields) {
        noSeatPlanFields.style.display = useSeatPlan ? "none" : "block";
    }
}

// Initialize on page load
document.addEventListener("DOMContentLoaded", function() {
    toggleSeatPlanFields();
});
</script>
';

require __DIR__ . '/../../app/views/layouts/main.php';

