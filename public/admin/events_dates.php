<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Event;

Auth::requireAdmin();

$eventModel = new Event();
$eventId = (int)($_GET['id'] ?? 0);
$event = $eventModel->getById($eventId);

if (!$event) {
    header('Location: ' . url('admin/events.php'));
    exit;
}

$dates = $eventModel->getDates($eventId);
$error = '';
$success = false;

// Handle date deletion
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dateId = (int)$_GET['delete'];
    try {
        $eventModel->removeDate($dateId);
        $success = true;
        $dates = $eventModel->getDates($eventId);
    } catch (Exception $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Handle date addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_date'])) {
    $date = $_POST['date'] ?? '';
    $showStartTime = !empty($_POST['show_start_time']) ? $_POST['show_start_time'] : null;
    if (empty($date)) {
        $error = 'Bitte wählen Sie ein Datum aus.';
    } else {
        try {
            // If no time specified, use event default
            if (empty($showStartTime)) {
                $showStartTime = $event['show_start_time'] ?? '20:30:00';
            }
            $isGeneralRehearsal = isset($_POST['is_general_rehearsal']) && $_POST['is_general_rehearsal'] == '1';
            $eventModel->addDate($eventId, $date, $showStartTime, $isGeneralRehearsal);
            $success = true;
            $dates = $eventModel->getDates($eventId);
        } catch (Exception $e) {
            $error = 'Fehler beim Hinzufügen: ' . $e->getMessage();
        }
    }
}

// Handle date update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_date'])) {
    $dateId = (int)($_POST['date_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $showStartTime = !empty($_POST['show_start_time']) ? $_POST['show_start_time'] : null;
    if ($dateId <= 0 || empty($date)) {
        $error = 'Ungültige Daten.';
    } else {
        try {
            $updateData = ['date' => $date];
            if ($showStartTime !== null) {
                $updateData['show_start_time'] = $showStartTime;
            }
            if (isset($_POST['is_general_rehearsal'])) {
                $updateData['is_general_rehearsal'] = $_POST['is_general_rehearsal'] == '1';
            }
            $eventModel->updateDate($dateId, $updateData);
            $success = true;
            $dates = $eventModel->getDates($eventId);
        } catch (Exception $e) {
            $error = 'Fehler beim Aktualisieren: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Termine: ' . htmlspecialchars($event['name']);
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Termine verwalten</h2>
    <div>
        <a href="' . url('admin/events_edit.php?id=' . $eventId) . '" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Zurück zur Veranstaltung
        </a>
    </div>
</div>

' . ($success ? '<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> Änderung erfolgreich gespeichert!
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>' : '') . '

' . ($error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '') . '

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-calendar-plus"></i> Neuen Termin hinzufügen</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    ' . \App\Core\Csrf::field() . '
                    <div class="mb-3">
                        <label for="date" class="form-label">Datum</label>
                        <input type="date" class="form-control" id="date" name="date" required>
                    </div>
                    <div class="mb-3">
                        <label for="show_start_time" class="form-label">Vorstellungsbeginn (optional, Standard: ' . htmlspecialchars(substr($event['show_start_time'] ?? '20:30:00', 0, 5)) . ' Uhr)</label>
                        <input type="time" class="form-control" id="show_start_time" name="show_start_time" 
                               value="' . htmlspecialchars(substr($event['show_start_time'] ?? '20:30:00', 0, 5)) . '">
                        <small class="form-text text-muted">Wenn leer, wird die Standard-Uhrzeit der Veranstaltung verwendet.</small>
                    </div>
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_general_rehearsal" name="is_general_rehearsal" value="1">
                        <label class="form-check-label" for="is_general_rehearsal">Generalprobe</label>
                        <small class="form-text text-muted d-block">Wenn aktiviert, gelten die Generalproben-Preise der Kategorien für diesen Termin.</small>
                    </div>
                    <button type="submit" name="add_date" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Hinzufügen
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Vorhandene Termine</h5>
            </div>
            <div class="card-body">
';

if (empty($dates)) {
    $content .= '<p class="text-muted">Noch keine Termine vorhanden.</p>';
} else {
    $content .= '<div class="list-group">';
    foreach ($dates as $date) {
        $dateFormatted = date('d.m.Y', strtotime($date['date']));
        $timeFormatted = !empty($date['show_start_time']) ? date('H:i', strtotime($date['show_start_time'])) . ' Uhr' : (substr($event['show_start_time'] ?? '20:30:00', 0, 5) . ' Uhr (Standard)');
        $content .= '
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-calendar-event"></i>                         <strong>' . htmlspecialchars($dateFormatted) . '</strong>
                        ' . (($date['is_general_rehearsal'] ?? 0) == 1 ? '<span class="badge bg-warning ms-2">Generalprobe</span>' : '') . '
                        <br><small class="text-muted"><i class="bi bi-clock"></i> ' . htmlspecialchars($timeFormatted) . '</small>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editDate(' . $date['id'] . ', \'' . $date['date'] . '\', \'' . (!empty($date['show_start_time']) ? substr($date['show_start_time'], 0, 5) : '') . '\', ' . (($date['is_general_rehearsal'] ?? 0) == 1 ? 'true' : 'false') . ')">
                            <i class="bi bi-pencil"></i> Bearbeiten
                        </button>
                        <form method="POST" action="?id=' . $eventId . '&delete=' . $date['id'] . '" 
                              onsubmit="return confirm(\'Möchten Sie diesen Termin wirklich löschen?\');" class="d-inline">
                            ' . \App\Core\Csrf::field() . '
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>';
    }
    $content .= '</div>';
    
    // Add edit modal
    $content .= '
    <div class="modal fade" id="editDateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    ' . \App\Core\Csrf::field() . '
                    <div class="modal-header">
                        <h5 class="modal-title">Termin bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="date_id" id="edit_date_id">
                        <div class="mb-3">
                            <label for="edit_date" class="form-label">Datum</label>
                            <input type="date" class="form-control" id="edit_date" name="date" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_show_start_time" class="form-label">Vorstellungsbeginn</label>
                            <input type="time" class="form-control" id="edit_show_start_time" name="show_start_time" 
                                   value="' . htmlspecialchars(substr($event['show_start_time'] ?? '20:30:00', 0, 5)) . '">
                            <small class="form-text text-muted">Wenn leer, wird die Standard-Uhrzeit der Veranstaltung verwendet.</small>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="edit_is_general_rehearsal" name="is_general_rehearsal" value="1">
                            <label class="form-check-label" for="edit_is_general_rehearsal">Generalprobe</label>
                            <small class="form-text text-muted d-block">Wenn aktiviert, gelten die Generalproben-Preise der Kategorien für diesen Termin.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" name="update_date" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    function editDate(id, date, time, isGeneralRehearsal) {
        document.getElementById("edit_date_id").value = id;
        document.getElementById("edit_date").value = date;
        document.getElementById("edit_show_start_time").value = time || "";
        document.getElementById("edit_is_general_rehearsal").checked = isGeneralRehearsal || false;
        new bootstrap.Modal(document.getElementById("editDateModal")).show();
    }
    </script>';
}

$content .= '
            </div>
        </div>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

