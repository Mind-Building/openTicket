<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\User;

Auth::requireAdmin();
$currentUserId = Auth::id();

$userModel = new User();
$message = '';
$messageType = '';

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $userModel->delete((int)$_GET['delete']);
        $message = 'Benutzer erfolgreich gelöscht.';
        $messageType = 'success';
    } catch (\Exception $e) {
        $message = 'Fehler beim Löschen: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$users = $userModel->getAll();

$pageTitle = 'Benutzer';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-person-gear"></i> Benutzerverwaltung</h5>
        <a href="' . url('admin/users_create.php') . '" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle"></i> Neuer Benutzer
        </a>
    </div>
    <div class="card-body">
        ' . ($message ? '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>' : '') . '
        
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Benutzername</th>
                        <th>E-Mail</th>
                        <th>Rolle</th>
                        <th>Aktiv</th>
                        <th>Erstellt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
';

if (empty($users)) {
    $content .= '<tr><td colspan="7" class="text-center text-muted">Keine Benutzer gefunden.</td></tr>';
} else {
    foreach ($users as $user) {
        $roleLabel = $user['role'] === 'admin' ? '<span class="badge bg-danger">Admin</span>' : '<span class="badge bg-info">Vorverkauf</span>';
        $activeLabel = $user['active'] ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Inaktiv</span>';
        $createdAt = date('d.m.Y H:i', strtotime($user['created_at']));
        
        $content .= '<tr>
            <td>' . htmlspecialchars($user['id']) . '</td>
            <td>' . htmlspecialchars($user['username']) . '</td>
            <td>' . htmlspecialchars($user['email'] ?? '') . '</td>
            <td>' . $roleLabel . '</td>
            <td>' . $activeLabel . '</td>
            <td>' . $createdAt . '</td>
            <td>
                <a href="' . url('admin/users_edit.php?id=' . $user['id']) . '" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                    <i class="bi bi-pencil"></i>
                </a>
                ' . ($user['id'] != $currentUserId ? '<a href="' . url('admin/users.php?delete=' . $user['id']) . '" class="btn btn-sm btn-outline-danger" title="Löschen" onclick="return confirm(\'Möchten Sie diesen Benutzer wirklich löschen?\')">
                    <i class="bi bi-trash"></i>
                </a>' : '') . '
            </td>
        </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';
