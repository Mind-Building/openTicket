<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Event;
use App\Models\SeatCategory;

Auth::requireAdmin();

$eventModel = new Event();
$categoryModel = new SeatCategory();

$eventId = (int)($_GET['event_id'] ?? 0);
$event = $eventModel->getById($eventId);

if (!$event) {
    header('Location: ' . url('admin/seatplan.php'));
    exit;
}

$categories = $categoryModel->getByEvent($eventId);
$error = '';
$success = false;

// Handle category deletion
if (isset($_GET['delete'])) {
    $catId = (int)$_GET['delete'];
    try {
        $categoryModel->delete($catId);
        $success = true;
        $categories = $categoryModel->getByEvent($eventId);
    } catch (Exception $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Handle category creation/update
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'event_id' => $eventId,
        'name' => $_POST['name'] ?? '',
        'sign' => $_POST['sign'] ?? '',
        'price' => (float)($_POST['price'] ?? 0),
        'reduced_price' => !empty($_POST['reduced_price']) ? (float)$_POST['reduced_price'] : null,
        'general_rehearsal_price' => !empty($_POST['general_rehearsal_price']) ? (float)$_POST['general_rehearsal_price'] : null,
        'color' => $_POST['color'] ?? null,
        'is_general_rehearsal' => isset($_POST['is_general_rehearsal']) ? 1 : 0,
    ];

    if (empty($data['name']) || empty($data['sign'])) {
        $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
    } else {
        try {
            if (isset($_POST['category_id']) && $_POST['category_id']) {
                $categoryModel->update((int)$_POST['category_id'], $data);
            } else {
                $categoryModel->create($data);
            }
            $success = true;
            $categories = $categoryModel->getByEvent($eventId);
        } catch (Exception $e) {
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}

$editCategory = null;
if (isset($_GET['edit'])) {
    $editCategory = $categoryModel->getById((int)$_GET['edit']);
}

$pageTitle = 'Sitzplankategorien: ' . htmlspecialchars($event['name']);
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sitzplankategorien</h2>
    ' . (!empty($event['use_seat_plan'])
        ? '<a href="' . url('admin/seatplan.php?event_id=' . $eventId) . '" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Zurück zum Sitzplan
        </a>'
        : '<a href="' . url('admin/events_edit.php?id=' . $eventId) . '" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Zurück zur Veranstaltung
        </a>') . '
</div>

' . ($success ? '<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> Änderung erfolgreich gespeichert!
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>' : '') . '

' . ($error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '') . '

<div class="row">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    ' . ($editCategory ? 'Kategorie bearbeiten' : 'Neue Kategorie') . '
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    ' . \App\Core\Csrf::field() . '
                    ' . ($editCategory ? '<input type="hidden" name="category_id" value="' . $editCategory['id'] . '">' : '') . '
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required
                               value="' . htmlspecialchars($editCategory['name'] ?? '') . '"
                               placeholder="z.B. Kategorie A">
                    </div>

                    <div class="mb-3">
                        <label for="sign" class="form-label">Zeichen <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="sign" name="sign" required maxlength="10"
                               value="' . htmlspecialchars($editCategory['sign'] ?? '') . '"
                               placeholder="z.B. A">
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="price" class="form-label">Preis (€) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" id="price" name="price" required
                                   value="' . htmlspecialchars($editCategory['price'] ?? '') . '">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="reduced_price" class="form-label">Ermäßigter Preis (€)</label>
                            <input type="number" step="0.01" class="form-control" id="reduced_price" name="reduced_price"
                                   value="' . htmlspecialchars($editCategory['reduced_price'] ?? '') . '">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="general_rehearsal_price" class="form-label">Generalproben-Preis (€)</label>
                            <input type="number" step="0.01" class="form-control" id="general_rehearsal_price" name="general_rehearsal_price"
                                   value="' . htmlspecialchars($editCategory['general_rehearsal_price'] ?? '') . '">
                            <small class="form-text text-muted">Wird verwendet, wenn der Termin als Generalprobe markiert ist.</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="color" class="form-label">Farbe</label>
                        <input type="color" class="form-control form-control-color" id="color" name="color"
                               value="' . htmlspecialchars($editCategory['color'] ?? '#000000') . '">
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_general_rehearsal" name="is_general_rehearsal"
                                   ' . (($editCategory['is_general_rehearsal'] ?? 0) ? 'checked' : '') . '>
                            <label class="form-check-label" for="is_general_rehearsal">
                                Generalprobe
                            </label>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> ' . ($editCategory ? 'Aktualisieren' : 'Erstellen') . '
                        </button>
                        ' . ($editCategory ? '<a href="' . url('admin/seatplan_categories.php?event_id=' . $eventId) . '" class="btn btn-secondary">Abbrechen</a>' : '') . '
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Vorhandene Kategorien</h5>
            </div>
            <div class="card-body">
';

if (empty($categories)) {
    $content .= '<p class="text-muted">Noch keine Kategorien vorhanden.</p>';
} else {
    $content .= '<div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Zeichen</th>
                    <th>Name</th>
                    <th>Preis</th>
                    <th>Ermäßigt</th>
                    <th>Generalprobe</th>
                    <th>Farbe</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($categories as $cat) {
        $colorStyle = $cat['color'] ? 'background-color: ' . htmlspecialchars($cat['color']) . '; width: 30px; height: 30px;' : '';
        $content .= '
            <tr>
                <td><strong>' . htmlspecialchars($cat['sign']) . '</strong></td>
                <td>' . htmlspecialchars($cat['name']) . '</td>
                <td>' . number_format($cat['price'], 2, ',', '.') . ' €</td>
                <td>' . ($cat['reduced_price'] ? number_format($cat['reduced_price'], 2, ',', '.') . ' €' : '-') . '</td>
                <td>' . ($cat['general_rehearsal_price'] ? number_format($cat['general_rehearsal_price'], 2, ',', '.') . ' €' : '-') . '</td>
                <td><div class="border rounded" style="' . $colorStyle . '"></div></td>
                <td>
                    <a href="' . url('admin/seatplan_categories.php?event_id=' . $eventId . '&edit=' . $cat['id']) . '" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <a href="' . url('admin/seatplan_categories.php?event_id=' . $eventId . '&delete=' . $cat['id']) . '" 
                       class="btn btn-sm btn-outline-danger"
                       onclick="return confirm(\'Möchten Sie diese Kategorie wirklich löschen?\');">
                        <i class="bi bi-trash"></i>
                    </a>
                </td>
            </tr>';
    }
    
    $content .= '
            </tbody>
        </table>
    </div>';
}

$content .= '
            </div>
        </div>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

