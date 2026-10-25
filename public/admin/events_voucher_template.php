<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\FileUpload;
use App\Models\Event;

Auth::requireAdmin();

$eventModel = new Event();
$eventId = (int)($_GET['id'] ?? 0);
$event = $eventModel->getById($eventId);

if (!$event) {
    header('Location: ' . url('admin/events.php'));
    exit;
}

$error = '';
$success = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_template'])) {
    // Validate CSRF token
    if (!Csrf::validatePost()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } elseif (isset($_FILES['pdf_template']) && $_FILES['pdf_template']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadDir = __DIR__ . '/../../storage/voucher_templates/';
        $customFileName = 'event_' . $eventId . '_' . time();
        
        $uploadResult = FileUpload::upload($_FILES['pdf_template'], $uploadDir, 'pdf', $customFileName);
        
        if ($uploadResult['success']) {
            // Delete old template if exists
            if (!empty($event['voucher_template'])) {
                $oldPath = __DIR__ . '/../../' . $event['voucher_template'];
                FileUpload::delete($oldPath);
            }
            
            // Save relative path
            $relativePath = 'storage/voucher_templates/' . $uploadResult['filename'];
            $eventModel->update($eventId, ['voucher_template' => $relativePath]);
            $event = $eventModel->getById($eventId);
            $success = 'PDF-Template erfolgreich hochgeladen!';
        } else {
            $error = $uploadResult['error'] ?? 'Fehler beim Hochladen der Datei.';
        }
    } else {
        $error = 'Keine Datei wurde hochgeladen.';
    }
}

// Handle template config save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    // Validate CSRF token
    if (!Csrf::validatePost()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $config = [
            'text_positions' => []
        ];
        
        if (isset($_POST['placeholders']) && is_array($_POST['placeholders'])) {
            foreach ($_POST['placeholders'] as $placeholder) {
                if (!empty($placeholder['name']) && !empty($placeholder['x']) && !empty($placeholder['y'])) {
                    $config['text_positions'][$placeholder['name']] = [
                        'x' => (float)$placeholder['x'],
                        'y' => (float)$placeholder['y'],
                        'font_size' => isset($placeholder['font_size']) ? (int)$placeholder['font_size'] : 12,
                        'font_family' => $placeholder['font_family'] ?? 'Helvetica',
                        'font_style' => isset($placeholder['bold']) && $placeholder['bold'] == '1' ? 'B' : '',
                        'color' => [
                            isset($placeholder['color_r']) ? (int)$placeholder['color_r'] : 0,
                            isset($placeholder['color_g']) ? (int)$placeholder['color_g'] : 0,
                            isset($placeholder['color_b']) ? (int)$placeholder['color_b'] : 0
                        ]
                    ];
                }
            }
        }
        
        $eventModel->update($eventId, ['voucher_template_config' => json_encode($config)]);
        $event = $eventModel->getById($eventId);
        $success = 'Konfiguration erfolgreich gespeichert!';
    }
}

// Load existing config
$config = [];
if (!empty($event['voucher_template_config'])) {
    $config = json_decode($event['voucher_template_config'], true) ?: [];
}

// Prepare JavaScript for existing placeholders
$existingPlaceholdersJs = '';
if (!empty($config['text_positions'])) {
    $placeholders = [];
    foreach ($config['text_positions'] as $name => $pos) {
        $placeholders[] = [
            'name' => $name,
            'x' => $pos['x'],
            'y' => $pos['y'],
            'font_size' => $pos['font_size'] ?? 12,
            'font_family' => $pos['font_family'] ?? 'Helvetica',
            'bold' => ($pos['font_style'] ?? '') === 'B' ? 1 : 0,
            'color_r' => $pos['color'][0] ?? 0,
            'color_g' => $pos['color'][1] ?? 0,
            'color_b' => $pos['color'][2] ?? 0
        ];
    }
    $placeholdersJson = json_encode($placeholders, JSON_HEX_APOS | JSON_HEX_QUOT);
    $existingPlaceholdersJs = "\nconst existingPlaceholders = " . $placeholdersJson . ";\nexistingPlaceholders.forEach(function(placeholder, index) {\n    placeholderIndex = index;\n    addPlaceholder(placeholder);\n});";
}

$pageTitle = 'Gutschein-Template: ' . htmlspecialchars($event['name']);
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Gutschein-Template verwalten</h2>
    <a href="' . url('admin/events_edit.php?id=' . $eventId) . '" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

' . ($success ? '<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> ' . htmlspecialchars($success) . '
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>' : '') . '

' . ($error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '') . '

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">PDF-Template hochladen</h5>
            </div>
            <div class="card-body">
                ' . ($event['voucher_template'] ? '
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle"></i> Aktuelles Template: ' . htmlspecialchars(basename($event['voucher_template'])) . '
                    <br><small>Hochgeladen am: ' . date('d.m.Y H:i', filemtime(__DIR__ . '/../../' . $event['voucher_template'])) . '</small>
                </div>
                ' : '') . '
                
                <form method="POST" enctype="multipart/form-data">
                    ' . \App\Core\Csrf::field() . '
                    <div class="mb-3">
                        <label for="pdf_template" class="form-label">PDF-Datei auswählen</label>
                        <input type="file" class="form-control" id="pdf_template" name="pdf_template" 
                               accept=".pdf" required>
                        <small class="form-text text-muted">Nur PDF-Dateien werden akzeptiert.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Hochladen
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Verfügbare Platzhalter</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">Verwenden Sie diese Platzhalter in Ihrer Konfiguration:</p>
                <ul class="list-unstyled">
                    <li><code>{event_name}</code> - Name der Veranstaltung</li>
                    <li><code>{voucher_code}</code> - Gutscheincode</li>
                    <li><code>{category_name}</code> - Name der Kategorie</li>
                    <li><code>{quantity}</code> - Anzahl</li>
                    <li><code>{order_date}</code> - Bestelldatum</li>
                    <li><code>{customer_name}</code> - Vollständiger Name des Kunden</li>
                    <li><code>{customer_first_name}</code> - Vorname</li>
                    <li><code>{customer_last_name}</code> - Nachname</li>
                    <li><code>{customer_email}</code> - E-Mail</li>
                    <li><code>{customer_street}</code> - Straße</li>
                    <li><code>{customer_house_number}</code> - Hausnummer</li>
                    <li><code>{customer_postal_code}</code> - Postleitzahl</li>
                    <li><code>{customer_city}</code> - Stadt</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Platzhalter-Konfiguration</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="configForm">
                    ' . \App\Core\Csrf::field() . '
                    <input type="hidden" name="save_config" value="1">
                    <div id="placeholders-container">
                        ' . (empty($config['text_positions']) ? '
                        <p class="text-muted">Noch keine Platzhalter konfiguriert. Klicken Sie auf "Platzhalter hinzufügen".</p>
                        ' : '') . '
                    </div>
                    <button type="button" class="btn btn-outline-primary" onclick="addPlaceholder()">
                        <i class="bi bi-plus-circle"></i> Platzhalter hinzufügen
                    </button>
                    <button type="submit" class="btn btn-primary ms-2">
                        <i class="bi bi-save"></i> Konfiguration speichern
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let placeholderIndex = ' . (empty($config['text_positions']) ? 0 : count($config['text_positions'])) . ';

function updateColorPreview(index) {
    const element = document.getElementById("placeholder-" + index);
    if (!element) return;
    
    const r = element.querySelector(\'input[name*="[color_r]"]\')?.value || 0;
    const g = element.querySelector(\'input[name*="[color_g]"]\')?.value || 0;
    const b = element.querySelector(\'input[name*="[color_b]"]\')?.value || 0;
    
    const preview = element.querySelector(\'.color-preview\');
    if (preview) {
        preview.style.backgroundColor = "rgb(" + r + ", " + g + ", " + b + ")";
    }
}

function addPlaceholder(data = null) {
    const container = document.getElementById("placeholders-container");
    const colorR = data ? (data.color_r || 0) : 0;
    const colorG = data ? (data.color_g || 0) : 0;
    const colorB = data ? (data.color_b || 0) : 0;
    const placeholderHtml = 
        "<div class=\"card mb-3\" id=\"placeholder-" + placeholderIndex + "\">" +
        "<div class=\"card-body\">" +
        "<div class=\"d-flex justify-content-between align-items-center mb-3\">" +
        "<h6 class=\"mb-0\">Platzhalter " + (placeholderIndex + 1) + "</h6>" +
        "<button type=\"button\" class=\"btn btn-sm btn-outline-danger\" onclick=\"removePlaceholder(" + placeholderIndex + ")\">" +
        "<i class=\"bi bi-trash\"></i>" +
        "</button>" +
        "</div>" +
        "<div class=\"row\">" +
        "<div class=\"col-md-6 mb-3\">" +
        "<label class=\"form-label\">Platzhalter-Name</label>" +
        "<select class=\"form-select\" name=\"placeholders[" + placeholderIndex + "][name]\" required>" +
        "<option value=\"\">Bitte wählen...</option>" +
        "<option value=\"{event_name}\"" + (data && data.name === "{event_name}" ? " selected" : "") + ">Veranstaltungsname</option>" +
        "<option value=\"{voucher_code}\"" + (data && data.name === "{voucher_code}" ? " selected" : "") + ">Gutscheincode</option>" +
        "<option value=\"{category_name}\"" + (data && data.name === "{category_name}" ? " selected" : "") + ">Kategorie</option>" +
        "<option value=\"{quantity}\"" + (data && data.name === "{quantity}" ? " selected" : "") + ">Anzahl</option>" +
        "<option value=\"{order_date}\"" + (data && data.name === "{order_date}" ? " selected" : "") + ">Bestelldatum</option>" +
        "<option value=\"{customer_name}\"" + (data && data.name === "{customer_name}" ? " selected" : "") + ">Kundenname</option>" +
        "<option value=\"{customer_first_name}\"" + (data && data.name === "{customer_first_name}" ? " selected" : "") + ">Vorname</option>" +
        "<option value=\"{customer_last_name}\"" + (data && data.name === "{customer_last_name}" ? " selected" : "") + ">Nachname</option>" +
        "<option value=\"{customer_email}\"" + (data && data.name === "{customer_email}" ? " selected" : "") + ">E-Mail</option>" +
        "<option value=\"{customer_street}\"" + (data && data.name === "{customer_street}" ? " selected" : "") + ">Straße</option>" +
        "<option value=\"{customer_house_number}\"" + (data && data.name === "{customer_house_number}" ? " selected" : "") + ">Hausnummer</option>" +
        "<option value=\"{customer_postal_code}\"" + (data && data.name === "{customer_postal_code}" ? " selected" : "") + ">Postleitzahl</option>" +
        "<option value=\"{customer_city}\"" + (data && data.name === "{customer_city}" ? " selected" : "") + ">Stadt</option>" +
        "</select>" +
        "</div>" +
        "<div class=\"col-md-3 mb-3\">" +
        "<label class=\"form-label\">X-Position (mm)</label>" +
        "<input type=\"number\" step=\"0.1\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][x]\" " +
        "value=\"" + (data ? data.x : "") + "\" required>" +
        "</div>" +
        "<div class=\"col-md-3 mb-3\">" +
        "<label class=\"form-label\">Y-Position (mm)</label>" +
        "<input type=\"number\" step=\"0.1\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][y]\" " +
        "value=\"" + (data ? data.y : "") + "\" required>" +
        "</div>" +
        "<div class=\"col-md-4 mb-3\">" +
        "<label class=\"form-label\">Schriftgröße</label>" +
        "<input type=\"number\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][font_size]\" " +
        "value=\"" + (data ? data.font_size : 12) + "\">" +
        "</div>" +
        "<div class=\"col-md-4 mb-3\">" +
        "<label class=\"form-label\">Schriftart</label>" +
        "<select class=\"form-select\" name=\"placeholders[" + placeholderIndex + "][font_family]\">" +
        "<option value=\"Helvetica\"" + (data && data.font_family === "Helvetica" ? " selected" : "") + ">Helvetica</option>" +
        "<option value=\"Times\"" + (data && data.font_family === "Times" ? " selected" : "") + ">Times</option>" +
        "<option value=\"Courier\"" + (data && data.font_family === "Courier" ? " selected" : "") + ">Courier</option>" +
        "</select>" +
        "</div>" +
        "<div class=\"col-md-4 mb-3\">" +
        "<div class=\"form-check mt-4\">" +
        "<input class=\"form-check-input\" type=\"checkbox\" name=\"placeholders[" + placeholderIndex + "][bold]\" id=\"bold_" + placeholderIndex + "\" value=\"1\" " +
        (data && (data.bold == 1 || data.bold == "1" || data.bold === true) ? "checked" : "") + ">" +
        "<label class=\"form-check-label\" for=\"bold_" + placeholderIndex + "\">Fett (Bold)</label>" +
        "</div>" +
        "</div>" +
        "<div class=\"col-md-12 mb-3\">" +
        "<label class=\"form-label\">Schriftfarbe (RGB)</label>" +
        "<div class=\"row g-2\">" +
        "<div class=\"col-4\">" +
        "<label class=\"form-label small\">Rot (0-255)</label>" +
        "<input type=\"number\" min=\"0\" max=\"255\" class=\"form-control\" " +
        "name=\"placeholders[" + placeholderIndex + "][color_r]\" " +
        "value=\"" + (data ? (data.color_r || 0) : 0) + "\" " +
        "oninput=\"updateColorPreview(" + placeholderIndex + ");\">" +
        "</div>" +
        "<div class=\"col-4\">" +
        "<label class=\"form-label small\">Grün (0-255)</label>" +
        "<input type=\"number\" min=\"0\" max=\"255\" class=\"form-control\" " +
        "name=\"placeholders[" + placeholderIndex + "][color_g]\" " +
        "value=\"" + (data ? (data.color_g || 0) : 0) + "\" " +
        "oninput=\"updateColorPreview(" + placeholderIndex + ");\">" +
        "</div>" +
        "<div class=\"col-4\">" +
        "<label class=\"form-label small\">Blau (0-255)</label>" +
        "<input type=\"number\" min=\"0\" max=\"255\" class=\"form-control\" " +
        "name=\"placeholders[" + placeholderIndex + "][color_b]\" " +
        "value=\"" + (data ? (data.color_b || 0) : 0) + "\" " +
        "oninput=\"updateColorPreview(" + placeholderIndex + ");\">" +
        "</div>" +
        "</div>" +
        "<div class=\"mt-2\">" +
        "<div class=\"color-preview\" style=\"width: 50px; height: 30px; border: 1px solid #ccc; display: inline-block; vertical-align: middle;\"></div>" +
        "<small class=\"text-muted ms-2\">Vorschau</small>" +
        "</div>" +
        "</div>" +
        "</div>" +
        "</div>" +
        "</div>";
    container.insertAdjacentHTML("beforeend", placeholderHtml);
    // Update color preview for the newly added placeholder
    updateColorPreview(placeholderIndex);
    placeholderIndex++;
}

function removePlaceholder(index) {
    const element = document.getElementById("placeholder-" + index);
    if (element) {
        element.remove();
    }
}
</script>
';

// Append existing placeholders JavaScript outside of the string (in a separate script tag)
if (!empty($existingPlaceholdersJs)) {
    $content .= '<script>' . $existingPlaceholdersJs . '</script>';
}

require __DIR__ . '/../../app/views/layouts/main.php';

