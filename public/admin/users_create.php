<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\User;

Auth::requireAdmin();

$userModel = new User();
$message = '';
$messageType = '';

// Handle form submission
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate input
        if (empty($_POST['username']) || empty($_POST['password'])) {
            throw new \Exception('Bitte füllen Sie alle Pflichtfelder aus.');
        }
        
        // Check if username already exists
        $existing = $userModel->getByUsername($_POST['username']);
        if ($existing) {
            throw new \Exception('Dieser Benutzername existiert bereits.');
        }
        
        $userModel->create([
            'username' => $_POST['username'],
            'email' => $_POST['email'],
            'password' => $_POST['password'],
            'role' => $_POST['role'] ?? 'presales',
            'active' => isset($_POST['active']) ? 1 : 0,
        ]);
        
        header('Location: ' . url('admin/users.php'));
        exit;
    } catch (\Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$pageTitle = 'Benutzer erstellen';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-person-plus"></i> Neuen Benutzer erstellen</h5>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '
        
        <form method="POST" action="">
            ' . \App\Core\Csrf::field() . '
            <div class="mb-3">
                <label for="username" class="form-label">Benutzername <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="username" name="username" required 
                       value="' . htmlspecialchars($_POST['username'] ?? '') . '">
            </div>
            
            <div class="mb-3">
                <label for="email" class="form-label">E-Mail</label>
                <input type="email" class="form-control" id="email" name="email" 
                       value="' . htmlspecialchars($_POST['email'] ?? '') . '">
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">Passwort <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            
            <div class="mb-3">
                <label for="role" class="form-label">Rolle</label>
                <select class="form-select" id="role" name="role">
                    <option value="presales" ' . (($_POST['role'] ?? 'presales') == 'presales' ? 'selected' : '') . '>Vorverkauf</option>
                    <option value="admin" ' . (($_POST['role'] ?? '') == 'admin' ? 'selected' : '') . '>Administrator</option>
                </select>
            </div>
            
            <div class="mb-3 form-check form-switch">
                <input class="form-check-input" type="checkbox" id="active" name="active" checked>
                <label class="form-check-label" for="active">Aktiv</label>
            </div>
            
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Benutzer erstellen
                </button>
                <a href="' . url('admin/users.php') . '" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

