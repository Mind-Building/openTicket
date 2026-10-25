<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\Event;
use App\Models\SeatCategory;

Auth::requireAdmin();

$eventModel = new Event();
$categoryModel = new SeatCategory();
$error = '';
$success = false;

// Get default values from settings
$defaultShowStartTime = Config::get('show_start_time', '20:30');
$defaultMaxReservationTime = Config::get('max_reservation_time', '12:00');

// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
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
        'show_start_time' => $_POST['show_start_time'] ?? $defaultShowStartTime,
        'max_reservation_time' => $_POST['max_reservation_time'] ?? $defaultMaxReservationTime,
    ];

    if (empty($data['name'])) {
        $error = 'Bitte geben Sie einen Namen ein.';
    } else {
        try {
            $eventId = $eventModel->create($data);
            header('Location: ' . url('admin/events_edit.php?id=' . $eventId . '&success=1'));
            exit;
        } catch (Exception $e) {
            $error = 'Fehler beim Erstellen: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Neue Veranstaltung';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Neue Veranstaltung</h2>
    <a href="' . url('admin/events.php') . '" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

' . ($error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '') . '

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            ' . \App\Core\Csrf::field() . '
            <div class="mb-3">
                <label for="name" class="form-label">Name der Veranstaltung <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required 
                       value="' . htmlspecialchars($_POST['name'] ?? '') . '">
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="show_start_time" class="form-label">Vorstellungsbeginn (Standard: ' . htmlspecialchars(substr($defaultShowStartTime, 0, 5)) . ' Uhr)</label>
                    <input type="time" class="form-control" id="show_start_time" name="show_start_time" 
                           value="' . htmlspecialchars($_POST['show_start_time'] ?? $defaultShowStartTime) . '">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="max_reservation_time" class="form-label">Max. Reservierungszeit (Standard: ' . htmlspecialchars(substr($defaultMaxReservationTime, 0, 5)) . ' Uhr)</label>
                    <input type="time" class="form-control" id="max_reservation_time" name="max_reservation_time" 
                           value="' . htmlspecialchars($_POST['max_reservation_time'] ?? $defaultMaxReservationTime) . '">
                </div>
            </div>

            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle"></i>
                Das Reservierungstelefon wird aus den <a href="' . url('admin/settings.php') . '">Einstellungen</a> übernommen.
            </p>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="active" name="active" 
                           ' . (isset($_POST['active']) ? 'checked' : '') . '>
                    <label class="form-check-label" for="active">
                        Veranstaltung ist aktiv
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="sell_tickets" name="sell_tickets" 
                           ' . (isset($_POST['sell_tickets']) ? 'checked' : '') . '>
                    <label class="form-check-label" for="sell_tickets">
                        Tickets verkaufen
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="sell_vouchers" name="sell_vouchers"
                           ' . (isset($_POST['sell_vouchers']) ? 'checked' : '') . '>
                    <label class="form-check-label" for="sell_vouchers">
                        Gutscheine verkaufen
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="allow_reduced_prices" name="allow_reduced_prices"
                           ' . (isset($_POST['allow_reduced_prices']) ? 'checked' : '') . '>
                    <label class="form-check-label" for="allow_reduced_prices">
                        Ermäßigte Preise erlauben
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="allow_fn_card" name="allow_fn_card"
                           ' . (isset($_POST['allow_fn_card']) ? 'checked' : '') . '>
                    <label class="form-check-label" for="allow_fn_card">
                        FN-Karte erlauben
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="use_seat_plan" name="use_seat_plan" checked
                           ' . (isset($_POST['use_seat_plan']) ? 'checked' : 'checked') . '
                           onchange="toggleSeatPlanFields()">
                    <label class="form-check-label" for="use_seat_plan">
                        Sitzplan verwenden
                    </label>
                </div>
            </div>

            <div id="no-seat-plan-fields" style="display: ' . (isset($_POST['use_seat_plan']) && $_POST['use_seat_plan'] ? 'none' : 'block') . ';">
                <div class="mb-3">
                    <label for="max_seats" class="form-label">Maximale Anzahl von Sitzen</label>
                    <input type="number" class="form-control" id="max_seats" name="max_seats" 
                           min="1" 
                           value="' . htmlspecialchars($_POST['max_seats'] ?? '') . '"
                           placeholder="z.B. 100">
                    <small class="form-text text-muted">Geben Sie die maximale Anzahl von verfügbaren Sitzen für diese Veranstaltung ein.</small>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> <strong>Hinweis:</strong> Nach dem Erstellen der Veranstaltung können Sie Sitzkategorien anlegen und diese hier zuweisen.
                </div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <a href="' . url('admin/events.php') . '" class="btn btn-secondary">Abbrechen</a>
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

