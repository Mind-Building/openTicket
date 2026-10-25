<?php
/**
 * Setup Wizard for Ticket System
 *
 * This wizard helps you set up the ticket system by:
 * 1. Creating the .env file with database credentials
 * 2. Testing the database connection
 * 3. Running seed scripts (config and users)
 *
 * SAFETY: This file refuses to run once the system is configured (env + DB +
 * admin user exist). After a successful run it tries to delete itself; if the
 * delete fails (e.g. Windows file lock) the success page shows a prominent
 * warning so the operator can remove it manually.
 */

// ──────────────────────────────────────────────────────────────────────────
//  Refuse to run if the system is already configured.
//
//  Without this guard, anyone reaching /setup.php could overwrite .env and
//  re-seed the users table – effectively a remote takeover of a live install.
// ──────────────────────────────────────────────────────────────────────────
function setup_isAlreadyConfigured(): bool
{
    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) {
        return false;
    }

    $env = [];
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }

    if (empty($env['APP_SECRET']) || empty($env['DB_HOST'])
        || empty($env['DB_NAME']) || empty($env['DB_USER'])) {
        return false;
    }

    try {
        $dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4";
        $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE active = 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0) > 0;
    } catch (PDOException $e) {
        // DB unreachable or users table missing → installation is not complete;
        // allow the wizard so the operator can recover.
        return false;
    }
}

if (setup_isAlreadyConfigured()) {
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Setup deaktiviert</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f8f9fa;padding:60px 20px;font-family:system-ui,sans-serif}</style>
</head>
<body>
    <div class="container" style="max-width:640px;">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3"><span class="text-danger">⛔</span> Setup deaktiviert</h1>
                <p>Das System ist bereits konfiguriert. Der Setup-Assistent ist aus Sicherheitsgründen gesperrt — sonst könnte jeder mit Zugriff auf diese URL <code>.env</code> überschreiben und Admin-Benutzer neu anlegen.</p>
                <p class="mb-1"><strong>Empfehlung:</strong> diese Datei jetzt löschen.</p>
                <pre class="bg-light p-2 small mb-3">rm <?= htmlspecialchars(__FILE__) ?></pre>
                <p class="text-muted small mb-0">Wirklich noch einmal das Setup laufen lassen (z.&nbsp;B. zur Wiederherstellung)? Dann erst manuell <code>.env</code> entfernen — die Sperre prüft <code>.env</code> + DB-Verbindung + mindestens einen aktiven Benutzer.</p>
            </div>
        </div>
    </div>
</body>
</html><?php
    exit;
}

$envExists = file_exists(__DIR__ . '/.env');
$step = $_GET['step'] ?? '1';

// Read existing .env values to pre-fill the form
$envValues = [];
if ($envExists) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $envValues[trim($key)] = trim($val);
    }
}

// Pre-generate APP_SECRET for the form (keep existing if .env already has one)
$generatedSecret = $envValues['APP_SECRET'] ?? bin2hex(random_bytes(32));

// Step 1: Show form
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $step === '1') {
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket System - Setup Wizard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px;
        }
        .setup-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px;
            position: relative;
        }
        .step::after {
            content: '';
            position: absolute;
            top: 20px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #dee2e6;
            z-index: 0;
        }
        .step:last-child::after {
            display: none;
        }
        .step.active .step-number {
            background: #0d6efd;
            color: white;
        }
        .step.completed .step-number {
            background: #198754;
            color: white;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="setup-card p-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-ticket-perforated" style="font-size: 3rem; color: #667eea;"></i>
                        <h1 class="mt-3">Ticket System Setup</h1>
                        <p class="text-muted">Willkommen beim Setup-Assistenten</p>
                    </div>

                    <div class="step-indicator">
                        <div class="step active">
                            <div class="step-number">1</div>
                            <div class="mt-2 small">Datenbank</div>
                        </div>
                        <div class="step">
                            <div class="step-number">2</div>
                            <div class="mt-2 small">Test</div>
                        </div>
                        <div class="step">
                            <div class="step-number">3</div>
                            <div class="mt-2 small">Fertig</div>
                        </div>
                    </div>

                    <?php if (!empty($_GET['error'])): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-x-circle"></i>
                        <strong>Fehler:</strong> <?= htmlspecialchars($_GET['error']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($envExists): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Hinweis:</strong> Eine .env Datei existiert bereits.
                        Diese wird überschrieben, wenn Sie fortfahren.
                    </div>
                    <?php endif; ?>

                    <?php if ($envExists && empty($envValues['APP_SECRET'])): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-shield-exclamation"></i>
                        <strong>Sicherheitswarnung:</strong> In der vorhandenen .env fehlt <code>APP_SECRET</code>.
                        Ohne diesen Wert funktionieren Ticket- und Gutschein-Download-Links nicht sicher.
                        Bitte Setup erneut ausführen oder <code>APP_SECRET=<?= htmlspecialchars($generatedSecret) ?></code> manuell in die .env eintragen.
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="?step=2">
                        <h5 class="mb-3"><i class="bi bi-database"></i> Datenbankverbindung</h5>
                        
                        <div class="mb-3">
                            <label for="db_host" class="form-label">Datenbank-Host <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="db_host" name="db_host"
                                   value="<?= htmlspecialchars($_POST['db_host'] ?? $envValues['DB_HOST'] ?? 'localhost') ?>" required>
                            <small class="text-muted">Meistens: localhost</small>
                        </div>

                        <div class="mb-3">
                            <label for="db_name" class="form-label">Datenbank-Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="db_name" name="db_name"
                                   value="<?= htmlspecialchars($_POST['db_name'] ?? $envValues['DB_NAME'] ?? 'ticket') ?>" required>
                            <small class="text-muted">Name der Datenbank (muss bereits existieren)</small>
                        </div>

                        <div class="mb-3">
                            <label for="db_user" class="form-label">Datenbank-Benutzer <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="db_user" name="db_user"
                                   value="<?= htmlspecialchars($_POST['db_user'] ?? $envValues['DB_USER'] ?? 'root') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="db_pass" class="form-label">Datenbank-Passwort</label>
                            <input type="password" class="form-control" id="db_pass" name="db_pass"
                                   value="<?= htmlspecialchars($_POST['db_pass'] ?? $envValues['DB_PASS'] ?? '') ?>">
                            <small class="text-muted">Leer lassen, wenn kein Passwort benötigt wird</small>
                        </div>

                        <div class="mb-3">
                            <label for="app_url" class="form-label">Basis-URL <span class="text-danger">*</span></label>
                            <input type="url" class="form-control" id="app_url" name="app_url"
                                   value="<?= htmlspecialchars($_POST['app_url'] ?? $envValues['APP_URL'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']) . '/public')) ?>" required>
                            <small class="text-muted">Basis-URL des Systems (ohne abschließenden Slash)</small>
                        </div>

                        <div class="mb-3">
                            <label for="app_secret" class="form-label">App Secret <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control font-monospace small" id="app_secret" name="app_secret"
                                       value="<?= htmlspecialchars($_POST['app_secret'] ?? $generatedSecret) ?>" required>
                                <a href="?step=1" class="btn btn-outline-secondary" title="Neuen Secret generieren">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </a>
                            </div>
                            <small class="text-muted">
                                Geheimer Schlüssel für sichere Ticket/Gutschein-Download-Links.
                                <strong class="text-danger">Nach Go-Live nicht mehr ändern</strong> — sonst werden alle E-Mail-Links ungültig.
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-arrow-right"></i> Weiter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

// Step 2: Process form and run setup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    $dbHost    = $_POST['db_host']    ?? '';
    $dbName    = $_POST['db_name']    ?? '';
    $dbUser    = $_POST['db_user']    ?? '';
    $dbPass    = $_POST['db_pass']    ?? '';
    $appUrl    = $_POST['app_url']    ?? '';
    $appSecret = $_POST['app_secret'] ?? '';

    // Auto-generate secret if form was submitted without one
    if (empty($appSecret)) {
        $appSecret = bin2hex(random_bytes(32));
    }

    $errors = [];

    // Validate inputs
    if (empty($dbHost)) $errors[] = "Datenbank-Host ist erforderlich";
    if (empty($dbName)) $errors[] = "Datenbank-Name ist erforderlich";
    if (empty($dbUser)) $errors[] = "Datenbank-Benutzer ist erforderlich";
    if (empty($appUrl)) $errors[] = "Basis-URL ist erforderlich";

    if (!empty($errors)) {
        header('Location: ?step=1&error=' . urlencode(implode(', ', $errors)));
        exit;
    }

    // Test database connection
    try {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        header('Location: ?step=1&error=' . urlencode('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage()));
        exit;
    }

    // Create .env file
    $envContent = "# Database Configuration\n";
    $envContent .= "DB_HOST=" . $dbHost . "\n";
    $envContent .= "DB_NAME=" . $dbName . "\n";
    $envContent .= "DB_USER=" . $dbUser . "\n";
    $envContent .= "DB_PASS=" . $dbPass . "\n";
    $envContent .= "\n";
    $envContent .= "# Application Configuration\n";
    $envContent .= "APP_ENV=production\n";
    $envContent .= "APP_SECRET=" . $appSecret . "\n";
    $envContent .= "\n";
    $envContent .= "# Base URL (without trailing slash)\n";
    $envContent .= "APP_URL=" . $appUrl . "\n";
    $envContent .= "\n";
    $envContent .= "# Base path override (optional)\n";
    $envContent .= "# Leer lassen wenn Document Root direkt auf /public zeigt (z.B. IONOS Subdomain)\n";
    $envContent .= "# APP_BASE_PATH=\n";

    if (file_put_contents(__DIR__ . '/.env', $envContent) === false) {
        header('Location: ?step=1&error=' . urlencode('Fehler beim Erstellen der .env Datei. Bitte prüfen Sie die Schreibrechte.'));
        exit;
    }

    // Run schema installation
    try {
        $schemaSql = file_get_contents(__DIR__ . '/database/schema.sql');
        if ($schemaSql === false) {
            throw new Exception("Schema-Datei (database/schema.sql) konnte nicht gelesen werden.");
        }
        
        // Execute schema
        $pdo->exec($schemaSql);
    } catch (Exception $e) {
        header('Location: ?step=1&error=' . urlencode('Fehler beim Installieren des Datenbankschemas: ' . $e->getMessage()));
        exit;
    }

    // Load bootstrap to use models
    putenv('APP_ENV=development'); // Temporarily enable development mode for better errors
    require_once __DIR__ . '/app/bootstrap.php';

    // Run seed scripts
    $seedErrors = [];
    
    // Seed config
    try {
        ob_start();
        include __DIR__ . '/database/seed_config.php';
        $output = ob_get_clean();
        if (strpos($output, 'Error') !== false || strpos($output, 'Fatal') !== false || strpos($output, 'failed') !== false) {
            $seedErrors[] = "Fehler beim Ausführen von seed_config.php: " . trim($output);
        }
    } catch (Exception $e) {
        $seedErrors[] = "Fehler beim Ausführen von seed_config.php: " . $e->getMessage();
    }

    // Seed users
    try {
        ob_start();
        include __DIR__ . '/database/seed_users.php';
        $output = ob_get_clean();
        if (strpos($output, 'Error') !== false || strpos($output, 'Fatal') !== false || strpos($output, 'failed') !== false) {
            $seedErrors[] = "Fehler beim Ausführen von seed_users.php: " . trim($output);
        }
    } catch (Exception $e) {
        $seedErrors[] = "Fehler beim Ausführen von seed_users.php: " . $e->getMessage();
    }

    // Try to delete this file. If the OS holds an exclusive lock (common on
    // Windows while PHP is executing the file), the operator has to do it
    // manually – the success page below makes that very visible.
    $selfDeleted = @unlink(__FILE__);

    // Show success page
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket System - Setup Abgeschlossen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px;
        }
        .setup-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="setup-card p-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        <h1 class="mt-3">Setup erfolgreich!</h1>
                    </div>

                    <div class="alert alert-success">
                        <h5><i class="bi bi-check-circle"></i> Was wurde erledigt:</h5>
                        <ul class="mb-0">
                            <li>✓ .env Datei erstellt</li>
                            <li>✓ Datenbankverbindung getestet</li>
                            <li>✓ Datenbank-Tabellen erstellt</li>
                            <li>✓ Standard-Konfiguration eingespielt</li>
                            <li>✓ Admin-Benutzer erstellt</li>
                        </ul>
                    </div>

                    <?php if (!empty($seedErrors)): ?>
                    <div class="alert alert-warning">
                        <h5><i class="bi bi-exclamation-triangle"></i> Warnungen:</h5>
                        <ul class="mb-0">
                            <?php foreach ($seedErrors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <h5><i class="bi bi-info-circle"></i> Standard-Login-Daten:</h5>
                        <p class="mb-1"><strong>Benutzername:</strong> admin</p>
                        <p class="mb-0"><strong>Passwort:</strong> admin123</p>
                        <small class="text-danger"><strong>WICHTIG:</strong> Bitte ändern Sie das Passwort nach dem ersten Login!</small>
                    </div>

                    <?php if ($selfDeleted): ?>
                    <div class="alert alert-success">
                        <h5 class="mb-1"><i class="bi bi-shield-check"></i> Sicherheit:</h5>
                        <p class="mb-0">Die Datei <code>setup.php</code> wurde automatisch gelöscht.</p>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <h5 class="mb-1"><i class="bi bi-shield-exclamation"></i> WICHTIG — Setup-Datei jetzt manuell löschen!</h5>
                        <p class="mb-2">Das automatische Löschen von <code>setup.php</code> ist fehlgeschlagen (Dateilock oder fehlende Rechte). Solange diese Datei existiert, ist das System verwundbar — sobald <code>.env</code> gelöscht wird, kann jeder das Setup erneut auslösen.</p>
                        <p class="mb-0 small">Über Konsole: <code>rm <?= htmlspecialchars(__FILE__) ?></code></p>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <a href="<?= htmlspecialchars($appUrl) ?>/admin" class="btn btn-primary btn-lg">
                            <i class="bi bi-box-arrow-in-right"></i> Zum Admin-Bereich
                        </a>
                        <a href="<?= htmlspecialchars($appUrl) ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-house"></i> Zur Startseite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

// If we get here, something went wrong
header('Location: ?step=1');
exit;

