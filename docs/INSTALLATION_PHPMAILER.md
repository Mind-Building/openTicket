# PHPMailer Installation (ohne Composer)

PHPMailer wird für den zuverlässigen E-Mail-Versand über SMTP empfohlen.

## Option 1: Manuelle Installation (empfohlen)

### Schritt 1: PHPMailer herunterladen

1. Besuchen Sie: https://github.com/PHPMailer/PHPMailer/releases
2. Laden Sie die neueste Version herunter (z.B. `PHPMailer-6.9.1.zip`)
3. Entpacken Sie das Archiv

### Schritt 2: Dateien kopieren

Kopieren Sie den `src` Ordner aus dem entpackten Archiv:

**Von:**
```
PHPMailer-6.9.1/src/
```

**Nach:**
```
D:\xampp\htdocs\ticket2.0\vendor\phpmailer\phpmailer\src\
```

Erstellen Sie die Ordnerstruktur, falls sie nicht existiert:
```
ticket2.0\
  └── vendor\
      └── phpmailer\
          └── phpmailer\
              └── src\
                  ├── PHPMailer.php
                  ├── SMTP.php
                  ├── Exception.php
                  └── ... (weitere Dateien)
```

### Schritt 3: Autoloader erstellen

Erstellen Sie die Datei `vendor/autoload.php`:

```php
<?php
/**
 * Simple autoloader for PHPMailer
 */

spl_autoload_register(function ($class) {
    // PHPMailer namespace
    if (strpos($class, 'PHPMailer\\PHPMailer\\') === 0) {
        $file = __DIR__ . '/phpmailer/phpmailer/src/' . str_replace('\\', '/', substr($class, 20)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});
```

### Schritt 4: Autoloader in bootstrap.php einbinden

Die Datei `app/bootstrap.php` sollte bereits den Autoloader laden:

```php
// Load Composer autoload (for PHPMailer and other dependencies)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
```

Falls diese Zeile fehlt, fügen Sie sie am Anfang der Datei hinzu (nach dem ersten `<?php`).

### Schritt 5: Installation testen

Erstellen Sie eine Test-Datei `test_email.php` im Hauptverzeichnis:

```php
<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\EmailService;

$emailService = new EmailService();

echo "PHPMailer vorhanden: " . (class_exists('\PHPMailer\PHPMailer\PHPMailer') ? 'JA' : 'NEIN') . "\n";

// Test-E-Mail senden (passen Sie die E-Mail-Adresse an)
$result = $emailService->sendEmail(
    'ihre-email@example.com',
    'Test Empfänger',
    'Test E-Mail',
    '<h1>Test</h1><p>Dies ist eine Test-E-Mail vom Ticket-System.</p>',
    true
);

echo "E-Mail gesendet: " . ($result ? 'JA' : 'NEIN') . "\n";
```

Führen Sie aus:
```bash
php test_email.php
```

## Option 2: Installation mit Composer (falls gewünscht)

Falls Sie später doch Composer nutzen möchten:

### Schritt 1: Composer installieren
Download: https://getcomposer.org/download/

### Schritt 2: PHPMailer installieren
```bash
cd D:\xampp\htdocs\ticket2.0
composer require phpmailer/phpmailer
```

## SMTP-Konfiguration

Nach der Installation konfigurieren Sie SMTP in den Einstellungen:

1. Öffnen Sie: `https://ihre-domain.de/ticket2.0/public/admin/settings.php`
2. Aktivieren Sie "E-Mail-Versand aktivieren"
3. Aktivieren Sie "SMTP verwenden"
4. Tragen Sie ein:
   - **SMTP Host**: `smtp.example.com` (z.B. `smtp.gmail.com`, `smtp.strato.de`)
   - **SMTP Port**: `587` (für TLS) oder `465` (für SSL)
   - **SMTP Benutzername**: Ihre E-Mail-Adresse
   - **SMTP Passwort**: Ihr E-Mail-Passwort oder App-Passwort
   - **Verschlüsselung**: Aktivieren (empfohlen)

5. Klicken Sie auf "Speichern"

## Beispiel-Konfigurationen

### Gmail:
- Host: `smtp.gmail.com`
- Port: `587`
- Verschlüsselung: TLS (aktiviert)
- Hinweis: Verwenden Sie ein [App-Passwort](https://support.google.com/accounts/answer/185833)

### Strato:
- Host: `smtp.strato.de`
- Port: `465` (SSL) oder `587` (TLS)
- Verschlüsselung: Aktiviert

### 1&1 / IONOS:
- Host: `smtp.ionos.de`
- Port: `587`
- Verschlüsselung: TLS

## Problembehandlung

### PHPMailer wird nicht gefunden
- Prüfen Sie, ob die Dateien im richtigen Pfad liegen
- Prüfen Sie, ob `vendor/autoload.php` existiert
- Prüfen Sie, ob `app/bootstrap.php` den Autoloader lädt

### E-Mails werden nicht versendet
- Prüfen Sie die SMTP-Zugangsdaten
- Prüfen Sie die Firewall (Port 587/465 muss offen sein)
- Aktivieren Sie Error-Logging in den PHP-Logs
- Testen Sie mit der `test_email.php`

### "SMTP Error: Could not authenticate"
- Falsches Passwort oder Username
- Bei Gmail: App-Passwort verwenden
- 2-Faktor-Authentifizierung könnte aktiviert sein

## Ohne SMTP (Fallback)

Das System funktioniert auch **ohne PHPMailer** und **ohne SMTP**:
- Verwendet PHP `mail()` Funktion
- Benötigt einen konfigurierten MTA (z.B. Sendmail)
- Weniger zuverlässig, aber für Tests ausreichend

