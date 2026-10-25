<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\User;
use App\Models\DeviceToken;

Auth::requireAdmin();

$userModel        = new User();
$deviceTokenModel = new DeviceToken();
$db               = Database::getInstance();
$message          = '';
$messageType      = '';
$newTokenInfo     = null; // Set when a token is freshly generated

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user   = $userModel->getById($userId);

if (!$user) {
    header('Location: ' . url('admin/users.php'));
    exit;
}

// ── Handle form submission ─────────────────────────────────────────────────
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_user';

    // ── Generate device token ──────────────────────────────────────────────
    if ($action === 'generate_token' && $user['role'] === 'presales') {
        try {
            $label      = trim($_POST['token_label'] ?? '');
            $expiryDays = isset($_POST['token_expiry']) && is_numeric($_POST['token_expiry'])
                ? (int)$_POST['token_expiry']
                : 365;

            if (empty($label)) {
                throw new \Exception('Bitte eine Bezeichnung für das Gerät angeben.');
            }

            $newTokenInfo = $deviceTokenModel->create($userId, $label, $expiryDays);
            $message      = 'Device-Token für "' . htmlspecialchars($label) . '" erfolgreich erstellt. Aktivierungs-URL unten kopieren.';
            $messageType  = 'success';
        } catch (\Exception $e) {
            $message     = 'Fehler beim Erstellen: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // ── Revoke device token ────────────────────────────────────────────────
    elseif ($action === 'revoke_token') {
        try {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            $record  = $deviceTokenModel->getById($tokenId);

            if (!$record || (int)$record['user_id'] !== $userId) {
                throw new \Exception('Token nicht gefunden.');
            }

            $deviceTokenModel->revoke($tokenId);
            $message     = 'Device-Token wurde widerrufen.';
            $messageType = 'success';
        } catch (\Exception $e) {
            $message     = 'Fehler beim Widerrufen: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // ── Update user data ───────────────────────────────────────────────────
    else {
        try {
            if (empty($_POST['username'])) {
                throw new \Exception('Bitte füllen Sie alle Pflichtfelder aus.');
            }

            $existing = $userModel->getByUsername($_POST['username']);
            if ($existing && $existing['id'] != $userId) {
                throw new \Exception('Dieser Benutzername existiert bereits.');
            }

            if (!empty($_POST['email'])) {
                $stmt          = $db->query("SELECT id FROM users WHERE email = :email AND id != :id", [':email' => $_POST['email'], ':id' => $userId]);
                $existingEmail = $stmt->fetch();
                if ($existingEmail) {
                    throw new \Exception('Diese E-Mail-Adresse wird bereits von einem anderen Benutzer verwendet.');
                }
            }

            $data = [
                'username' => $_POST['username'],
                'email'    => $_POST['email'],
                'role'     => $_POST['role'],
                'active'   => isset($_POST['active']) ? 1 : 0,
            ];

            if (!empty($_POST['password'])) {
                $data['password'] = $_POST['password'];
            }

            $userModel->update($userId, $data);

            header('Location: ' . url('admin/users.php'));
            exit;
        } catch (\Exception $e) {
            $message     = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // Reload user after actions
    $user = $userModel->getById($userId);
}

// ── Load device tokens ─────────────────────────────────────────────────────
$deviceTokens = ($user['role'] === 'presales') ? $deviceTokenModel->getByUserId($userId) : [];

// ── Page layout ───────────────────────────────────────────────────────────
$pageTitle    = 'Benutzer bearbeiten';
$showSidebar  = true;
$showHeader   = true;

// ── Build new-token alert (shown immediately after creation) ───────────────
$newTokenHtml = '';
if ($newTokenInfo) {
    $activationUrl = absoluteUrl('presales/device_auth.php?token=' . urlencode($newTokenInfo['token']));
    $newTokenHtml  = '
    <div class="alert alert-warning border border-warning mt-3">
        <h6 class="alert-heading"><i class="bi bi-key"></i> Aktivierungs-URL (nur einmal anzeigbar)</h6>
        <p class="mb-1">Kopieren Sie diese URL und rufen Sie sie auf dem Gerät auf. Der Cookie wird gesetzt und das Gerät kann danach ohne Login auf den Vorverkauf zugreifen.</p>
        <div class="input-group">
            <input type="text" class="form-control font-monospace" id="activation-url" value="' . htmlspecialchars($activationUrl) . '" readonly>
            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'activation-url\').value)">
                <i class="bi bi-clipboard"></i> Kopieren
            </button>
        </div>
        <small class="text-muted mt-1 d-block">Diese URL ist nur jetzt sichtbar und kann später nicht erneut abgerufen werden. Bei Verlust den Token widerrufen und einen neuen erstellen.</small>
    </div>';
}

// ── Build device-token table rows ──────────────────────────────────────────
$tokenRowsHtml = '';
foreach ($deviceTokens as $dt) {
    $isRevoked   = !empty($dt['revoked_at']);
    $isExpired   = !empty($dt['expires_at']) && strtotime($dt['expires_at']) < time();
    $isActive    = !$isRevoked && !$isExpired;

    $statusBadge = $isRevoked
        ? '<span class="badge bg-danger">Widerrufen</span>'
        : ($isExpired
            ? '<span class="badge bg-secondary">Abgelaufen</span>'
            : '<span class="badge bg-success">Aktiv</span>');

    $expiresStr  = $dt['expires_at']
        ? date('d.m.Y', strtotime($dt['expires_at']))
        : '<em class="text-muted">unbegrenzt</em>';

    $lastUsed    = $dt['last_used_at']
        ? date('d.m.Y H:i', strtotime($dt['last_used_at']))
        : '<em class="text-muted">nie</em>';

    $revokeBtn = $isActive ? '
        <form method="POST" style="display:inline" onsubmit="return confirm(\'Token wirklich widerrufen?\')">
            ' . \App\Core\Csrf::field() . '
            <input type="hidden" name="action" value="revoke_token">
            <input type="hidden" name="token_id" value="' . (int)$dt['id'] . '">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Widerrufen">
                <i class="bi bi-x-circle"></i>
            </button>
        </form>' : '';

    // Hashed tokens (new format) can't be turned back into an activation URL –
    // the URL is only shown once at creation. Legacy plaintext tokens still can.
    $urlBtn = '';
    if (!DeviceToken::isHashed($dt['token'])) {
        $activationUrl = absoluteUrl('presales/device_auth.php?token=' . urlencode($dt['token']));
        $urlBtn = '
        <button type="button" class="btn btn-sm btn-outline-secondary" title="Aktivierungs-URL anzeigen"
            onclick="navigator.clipboard.writeText(\'' . htmlspecialchars($activationUrl, ENT_QUOTES) . '\');alert(\'URL in Zwischenablage kopiert.\')">
            <i class="bi bi-qr-code"></i>
        </button>';
    }

    $tokenRowsHtml .= '
        <tr' . ($isActive ? '' : ' class="text-muted"') . '>
            <td>' . htmlspecialchars($dt['label']) . '</td>
            <td>' . $statusBadge . '</td>
            <td>' . date('d.m.Y', strtotime($dt['created_at'])) . '</td>
            <td>' . $expiresStr . '</td>
            <td>' . $lastUsed . '</td>
            <td class="text-end">' . $urlBtn . ' ' . $revokeBtn . '</td>
        </tr>';
}

if (empty($deviceTokens)) {
    $tokenRowsHtml = '<tr><td colspan="6" class="text-center text-muted">Keine Device-Tokens vorhanden.</td></tr>';
}

// ── Device token section (only for presales users) ─────────────────────────
$deviceTokenSection = '';
if ($user['role'] === 'presales') {
    $deviceTokenSection = '
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0"><i class="bi bi-laptop"></i> Gerät-Tokens (Login-freier Zugang)</h6>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Gerät-Tokens ermöglichen es, einen Vorverkaufs-Rechner dauerhaft mit diesem Account zu verknüpfen,
            ohne dass ein Passwort eingegeben werden muss. Rufen Sie die Aktivierungs-URL einmalig auf dem Gerät auf.
            Alle Aktionen werden im Log unter dem verknüpften Account protokolliert.
        </p>

        ' . $newTokenHtml . '

        <div class="table-responsive mb-3">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Bezeichnung</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                        <th>Gültig bis</th>
                        <th>Zuletzt genutzt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>' . $tokenRowsHtml . '</tbody>
            </table>
        </div>

        <form method="POST" class="border-top pt-3">
            ' . \App\Core\Csrf::field() . '
            <input type="hidden" name="action" value="generate_token">
            <div class="row g-2 align-items-end">
                <div class="col-sm-5">
                    <label class="form-label mb-1">Gerät-Bezeichnung <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="token_label"
                           placeholder="z.B. Kasse 1, Tablet Eingang" maxlength="200" required>
                </div>
                <div class="col-sm-3">
                    <label class="form-label mb-1">Gültigkeitsdauer</label>
                    <select class="form-select form-select-sm" name="token_expiry">
                        <option value="365">1 Jahr</option>
                        <option value="730">2 Jahre</option>
                        <option value="1825">5 Jahre</option>
                    </select>
                </div>
                <div class="col-sm-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle"></i> Token erstellen
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>';
}

// ── Main user form ─────────────────────────────────────────────────────────
$content = '
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-pencil"></i> Benutzer bearbeiten</h5>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '

        <form method="POST" action="">
            ' . \App\Core\Csrf::field() . '
            <input type="hidden" name="action" value="update_user">
            <div class="mb-3">
                <label for="username" class="form-label">Benutzername <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="username" name="username" required
                       value="' . htmlspecialchars($_POST['username'] ?? $user['username'] ?? '') . '">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">E-Mail</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="' . htmlspecialchars($_POST['email'] ?? $user['email'] ?? '') . '">
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Passwort (leer lassen, um nicht zu ändern)</label>
                <input type="password" class="form-control" id="password" name="password">
            </div>

            <div class="mb-3">
                <label for="role" class="form-label">Rolle</label>
                <select class="form-select" id="role" name="role">
                    <option value="presales" ' . (($_POST['role'] ?? $user['role']) == 'presales' ? 'selected' : '') . '>Vorverkauf</option>
                    <option value="admin" ' . (($_POST['role'] ?? $user['role']) == 'admin' ? 'selected' : '') . '>Administrator</option>
                </select>
            </div>

            <div class="mb-3 form-check form-switch">
                <input class="form-check-input" type="checkbox" id="active" name="active"
                       ' . (($_POST['active'] ?? $user['active']) ? 'checked' : '') . '>
                <label class="form-check-label" for="active">Aktiv</label>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Änderungen speichern
                </button>
                <a href="' . url('admin/users.php') . '" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>

' . $deviceTokenSection;

require __DIR__ . '/../../app/views/layouts/main.php';
