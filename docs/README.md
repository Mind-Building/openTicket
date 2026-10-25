# Ticket System 2.0

Modernes Ticketing-System für Theaterveranstaltungen mit Sitzplan-Verwaltung.

## Features

- ✅ Moderne PHP 8.1+ Architektur
- ✅ Datenbank-basierte Konfiguration
- ✅ User Management (Admin & Vorverkauf) mit Geräte-Token
- ✅ Sitzplan-Editor (UI-basiert) mit Kategorien und Tischplätzen
- ✅ Veranstaltungsverwaltung mit Terminplanung
- ✅ Ticket-Reservierungssystem (Online & Vorverkauf)
- ✅ Gutschein-System (Kauf & Einlösung)
- ✅ PayPal-Integration
- ✅ PDF-Ticketgenerierung (TCPDF, individuelle Vorlagen)
- ✅ E-Mail-Versand (PHPMailer / SMTP)
- ✅ Konfigurierbare E-Mail-Vorlagen mit Platzhaltern
- ✅ Sicherer Sitzplan-Link für Kunden in E-Mails (HMAC-Token)
- ✅ Berichte & Auswertungen
- ✅ Abendkassen-Funktion (PDF-Druckliste)
- ✅ Kunden-Verwaltung
- ✅ Strukturiertes Logging (Monolog)
- ✅ FN-Card & Ermäßigungs-Unterstützung
- ✅ Modernes UI mit Bootstrap 5

## Installation

### Option A: Setup-Wizard (Empfohlen für schnelle Einrichtung)

1. **Projekt auf Webserver kopieren**
   ```bash
   # Projekt in das gewünschte Verzeichnis kopieren
   ```

2. **Datenbank erstellen** (muss vorher existieren)
   ```sql
   CREATE DATABASE ticket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   
   **Wichtig:** Importieren Sie das Schema:
   ```bash
   mysql -u root -p ticket < database/schema.sql
   ```

3. **Setup-Wizard starten**
   - Öffnen Sie im Browser: `http://ihr-server.de/ticket2.0/setup.php`
   - Geben Sie die Datenbankdaten ein:
     - Datenbank-Host (meist: `localhost`)
     - Datenbank-Name (muss bereits existieren)
     - Datenbank-Benutzer
     - Datenbank-Passwort
     - Basis-URL des Systems
   - Klicken Sie auf "Weiter"
   - Der Wizard erstellt automatisch:
     - Die `.env` Datei
     - Testet die Datenbankverbindung
     - Führt die Seed-Skripte aus (Konfiguration + Admin-Benutzer)

4. **Setup-Wizard löschen** (Sicherheit!)
   ```bash
   rm setup.php
   # oder unter Windows:
   del setup.php
   ```

5. **Fertig!** Sie können sich jetzt mit folgenden Daten einloggen:
   - **Benutzername:** `admin`
   - **Passwort:** `admin123`
   - ⚠️ **WICHTIG:** Passwort nach erstem Login ändern!

### Option B: Manuelle Installation

### 1. Datenbank einrichten

```sql
-- Datenbank erstellen
CREATE DATABASE ticket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Schema importieren
mysql -u root -p ticket < database/schema.sql
```

### 2. Konfiguration

**Schritt 1:** Die Datei `.env.example` liegt im **Hauptverzeichnis** des Projekts (gleicher Ordner wie `index.php`).

**Schritt 2:** Kopieren Sie die Datei `.env.example` zu `.env`:

**Windows (PowerShell):**
```powershell
Copy-Item .env.example .env
```

**Windows (CMD):**
```cmd
copy .env.example .env
```

**Linux/Mac:**
```bash
cp .env.example .env
```

**Schritt 3:** Öffnen Sie die neu erstellte `.env` Datei und passen Sie die Datenbankverbindung an:

```env
# Database Configuration
DB_HOST=localhost
DB_NAME=ticket
DB_USER=root
DB_PASS=ihr_passwort_hier

# Application Configuration
APP_ENV=development
APP_DEBUG=true
```

**Wichtig:** 
- Die `.env` Datei enthält sensible Daten und wird **nicht** in Git eingecheckt (siehe `.gitignore`)
- Passen Sie mindestens `DB_NAME`, `DB_USER` und `DB_PASS` an Ihre lokale Datenbank an
- Die `.env.example` Datei dient nur als Vorlage und kann in Git eingecheckt werden

### 3. PHPMailer installieren (optional, aber empfohlen)

**Für zuverlässigen E-Mail-Versand über SMTP wird PHPMailer benötigt.**

📖 **Detaillierte Anleitung**: Siehe `INSTALLATION_PHPMAILER.md`

**Schnellinstallation:**
1. Download: https://github.com/PHPMailer/PHPMailer/releases (neueste Version)
2. Entpacken und `src` Ordner kopieren nach `vendor/phpmailer/phpmailer/src/`
3. Test: `php test_email.php`

**Ohne PHPMailer**: Das System funktioniert auch ohne PHPMailer, verwendet dann PHP `mail()` Funktion (weniger zuverlässig).

### 4. Initiale Daten einrichten

```bash
# Standard-Admin-Benutzer erstellen
php database/seed_users.php

# Standard-Konfigurationen einrichten
php database/seed_config.php
```

**Standard-Login:**
- Benutzername: `admin`
- Passwort: `admin123`
- ⚠️ **WICHTIG:** Ändern Sie das Passwort nach dem ersten Login!

### 5. Webserver konfigurieren

#### Apache (.htaccess bereits vorhanden)
Stellen Sie sicher, dass `mod_rewrite` aktiviert ist.

#### Nginx
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Wartung

### Automatisches Cleanup abgelaufener Reservierungen

Das System reserviert Plätze automatisch für 20 Minuten (`Reservation::CART_LIFETIME_MINUTES`), wenn sie zum Warenkorb hinzugefügt werden. Die Frist startet bei jeder Kundenaktivität neu (Checkout, PayPal-Order, Keep-Alive-Heartbeat), damit ein laufender Bezahlvorgang nicht mitten drin bereinigt wird. Dieselbe Konstante bestimmt, wie lange ein Platz belegt gilt — ein Platz ist also genau so lange blockiert, wie sein Warenkorb-Eintrag existiert. Um abgelaufene Reservierungen automatisch freizugeben, sollte das Cleanup-Script regelmäßig ausgeführt werden:

**Cron-Job (empfohlen, alle 5 Minuten):**
```bash
*/5 * * * * cd /path/to/ticket2.0 && php app/scripts/cleanup_expired_reservations.php
```

**Manuell:**
```bash
php app/scripts/cleanup_expired_reservations.php
```

## Projektstruktur

```
ticket2.0/                       # Hauptverzeichnis (Root)
├── .env.example                 # ⭐ Vorlage für Umgebungsvariablen → kopieren zu .env
├── .env                         # Umgebungsvariablen (NICHT in Git)
├── .htaccess                    # Apache-Konfiguration
├── index.php                    # Einstiegspunkt
├── app/                         # Anwendungslogik
│   ├── bootstrap.php            # Autoloader & Initialisierung
│   ├── core/                    # Kern-Klassen
│   │   ├── Auth.php             # Authentifizierung & Rollen
│   │   ├── Config.php           # Konfigurations-Manager
│   │   ├── Database.php         # PDO-Datenbankverbindung
│   │   └── Session.php          # Session-Verwaltung
│   ├── models/                  # Datenmodelle (Active Record)
│   │   ├── Customer.php
│   │   ├── Event.php
│   │   ├── Reservation.php
│   │   ├── SeatCategory.php
│   │   ├── SeatPlan.php
│   │   └── Voucher.php
│   ├── services/                # Business-Logic-Services
│   │   ├── EmailService.php     # E-Mail-Versand (alle Typen)
│   │   ├── LoggerService.php    # Monolog-Logging
│   │   └── TicketPdfGenerator.php  # PDF-Generierung
│   └── helpers/
│       ├── functions.php        # Globale Hilfsfunktionen & Token-Generierung
│       └── UrlHelper.php        # URL-Generierung
├── public/                      # Document Root (öffentlich erreichbar)
│   ├── index.php                # Startseite / Veranstaltungsauswahl
│   ├── event.php                # Veranstaltungsdetail & Datumsauswahl
│   ├── seatplan.php             # Interaktiver Sitzplan (Kundenbuchung)
│   ├── seatplan_view.php        # ⭐ Sitzplan-Ansicht für Kunden (token-gesichert)
│   ├── checkout.php             # Checkout-Prozess
│   ├── checkout_success.php     # Bestellbestätigung
│   ├── cart.php                 # Warenkorb
│   ├── vouchers.php             # Gutschein-Einlösung
│   ├── admin/                   # Admin-Bereich (Auth::requireAuth('admin'))
│   │   ├── index.php            # Dashboard
│   │   ├── events.php           # Veranstaltungsliste
│   │   ├── events_create.php    # Veranstaltung erstellen
│   │   ├── events_edit.php      # Veranstaltung bearbeiten (inkl. E-Mail-Vorlagen)
│   │   ├── events_dates.php     # Termine verwalten
│   │   ├── seatplan.php         # Sitzplan-Editor
│   │   ├── seatplan_categories.php  # Kategorien verwalten
│   │   ├── seatplan_view.php    # Sitzplan-Ansicht (Admin, Login-geschützt)
│   │   ├── reservations.php     # Reservierungsverwaltung
│   │   ├── customers.php        # Kundenliste
│   │   ├── customers_edit.php   # Kunden bearbeiten
│   │   ├── vouchers.php         # Gutscheinkliste
│   │   ├── vouchers_create.php  # Gutscheine erstellen
│   │   ├── vouchers_edit.php    # Gutschein bearbeiten
│   │   ├── vouchers_detail.php  # Gutschein-Details
│   │   ├── box_office.php       # Abendkasse (Reservierungen umwandeln + Tickets drucken)
│   │   ├── box_office_plan.php  # Sitzplan freier Plätze als PDF (eigenständig)
│   │   ├── reports.php          # Berichte-Übersicht
│   │   ├── reports_ticket_overview.php   # Ticket-Statistiken
│   │   ├── reports_evaluations.php       # Auswertungs-Charts
│   │   ├── users.php            # Benutzerliste
│   │   ├── users_create.php     # Benutzer erstellen
│   │   ├── users_edit.php       # Benutzer bearbeiten (inkl. Geräte-Token)
│   │   └── settings.php         # System-Einstellungen
│   ├── presales/                # Vorverkauf-Bereich
│   │   ├── index.php            # Vorverkauf-Startseite
│   │   ├── sell.php             # Verkaufsassistent
│   │   ├── reservations.php     # Reservierungsübersicht
│   │   ├── customers.php        # Kunden suchen
│   │   ├── box_office.php       # Abendkasse
│   │   ├── checkout_success.php # Verkaufsbestätigung
│   │   ├── vouchers.php         # Gutschein-Liste
│   │   ├── vouchers_create.php  # Gutscheine erstellen
│   │   └── device_auth.php      # Geräte-Authentifizierung
│   └── api/                     # REST-API-Endpunkte
│       ├── cart_add.php         # Sitzplatz in Warenkorb
│       ├── cart_remove.php      # Sitzplatz aus Warenkorb
│       ├── customer_reserve_seat.php   # Platz reservieren
│       ├── customer_free_seat.php      # Platz freigeben
│       ├── ticket_download.php         # Ticket-PDF-Download
│       ├── reservation_resend_email.php # E-Mail erneut senden
│       ├── paypal_create_order.php     # PayPal-Bestellung erstellen
│       ├── paypal_capture_order.php    # PayPal-Zahlung abschließen
│       └── ...
├── database/                    # Datenbank-Skripte
│   ├── schema.sql               # Vollständiges Datenbankschema
│   ├── seed_users.php           # Admin-Benutzer anlegen
│   └── seed_config.php          # Standard-Konfigurationen
├── docs/                        # Dokumentation
│   ├── INDEX.md                 # Dokumentations-Übersicht
│   ├── README.md                # Technische Dokumentation (diese Datei)
│   ├── ADMIN_HANDBUCH.md        # ⭐ Admin-Benutzerhandbuch
│   ├── VORVERKAUF_HANDBUCH.md   # ⭐ Vorverkauf-Benutzerhandbuch
│   ├── DEPLOYMENT.md            # Deployment-Anleitung
│   ├── README_MIGRATIONS.md     # Datenbank-Migrationen
│   ├── FRONTEND_LIBRARIES.md    # Frontend-Bibliotheken
│   ├── PDF_LIBRARY_INSTALLATION.md  # PDF-Bibliotheken
│   └── INSTALLATION_PHPMAILER.md    # PHPMailer-Setup
├── logging/                     # Log-Dateien (nicht in Git)
│   ├── customer.log
│   ├── admin.log
│   └── presales.log
└── vendor/                      # Composer-Dependencies
```

**Wichtig:** Die `.env.example` Datei liegt im **Hauptverzeichnis** (gleicher Ordner wie `index.php` und `README.md`).

## Datenbankstruktur

Alle Tabellennamen sind auf Englisch:

- `users` - Benutzer (Admin & Vorverkauf)
- `configurations` - Systemkonfigurationen
- `events` - Veranstaltungen
- `event_dates` - Veranstaltungstermine
- `seat_categories` - Sitzplankategorien
- `seat_plan_rows` - Sitzplan-Reihen
- `seat_plan_elements` - Sitzplan-Elemente (Plätze, Tische, etc.)
- `customers` - Kunden
- `reservations` - Reservierungen
- `vouchers` - Gutscheine
- `reservation_statuses` - Reservierungsstatus

## Migration vom alten System

1. Alte Datenbank sichern
2. Neue Datenbankstruktur erstellen (siehe Installation)
3. Alte Tabellen umbenennen (z.B. `veranstaltungen` → `_obsolete_veranstaltungen`)
4. Migrationsskript anpassen und ausführen:

```sql
-- Tabellen umbenennen
RENAME TABLE veranstaltungen TO _obsolete_veranstaltungen;
RENAME TABLE reservierungen TO _obsolete_reservierungen;
-- etc.

-- Migration ausführen
source database/migration_old_to_new.sql;
```

**Hinweis:** Sitzpläne müssen manuell über den neuen UI-Editor erstellt werden, da die alte Struktur in PHP-Dateien lag.

## Entwicklung

### Code-Standards

- PHP 8.1+ Syntax (str_starts_with, match, named arguments, etc.)
- PSR-4 Autoloading via Composer
- Prepared Statements für alle Datenbankabfragen
- Englische Begriffe in Code und Datenbank
- Deutsche UI-Texte
- Bootstrap 5 für Frontend-Komponenten

### E-Mail-Platzhalter erweitern

Neue Platzhalter in `EmailService.php` werden im `$placeholders`-Array der jeweiligen `send*Email()`-Methode ergänzt. Die Methode `replaceEmailPlaceholders()` ersetzt alle `{key}`-Vorkommen im Template.

Verfügbare Platzhalter sind im [Admin-Handbuch](ADMIN_HANDBUCH.md#123-verfügbare-platzhalter) dokumentiert.

### Sitzplan-Token (öffentlicher Kunden-Link)

Neue öffentliche Seite `public/seatplan_view.php`:
- Kein Admin-Login erforderlich
- Token-Validierung via `generateSeatplanViewToken()` in `app/helpers/functions.php`
- Token-Format: `sha256('seatplan:' + sorted_ids + ':' + APP_SECRET)`
- Verarbeitet Seat- und Table-Seat-Typen identisch wie die Admin-Ansicht

### Sicherheit

- ✅ Prepared Statements (SQL-Injection-Schutz)
- ✅ Password Hashing (bcrypt)
- ✅ Session Management mit Timeout
- ✅ Rollenbasierte Zugriffskontrolle (Admin / Presales / Kunde)
- ✅ HMAC-Token für Ticket-Downloads (`sha256('ticket:IDs:SECRET')`)
- ✅ HMAC-Token für Sitzplan-Links (`sha256('seatplan:IDs:SECRET')`)
- ✅ HMAC-Token für Gutschein-Downloads (`sha256('voucher:IDs:SECRET')`)
- ✅ Input-Validierung & htmlspecialchars-Ausgabe
- ✅ Geräte-Token für Vorverkaufs-Terminals

### Token-Sicherheitskonzept

Alle öffentlichen Links (Ticket-Download, Sitzplan-Ansicht, Gutschein-Download) verwenden das gleiche Muster:

```php
hash('sha256', 'prefix:' . implode(',', sorted_ids) . ':' . APP_SECRET)
```

- Jeder Token ist an genau die übergebenen IDs gebunden
- Ein Angreifer, der die IDs in der URL ändert, erhält sofort einen 403-Fehler
- Verschiedene Präfixe (`ticket:`, `seatplan:`, `voucher:`) verhindern Cross-Type-Reuse
- `APP_SECRET` muss in der `.env` als starker Zufallswert gesetzt sein

## PDF-Bibliotheken

Für die Ticket-PDF-Generierung werden TCPDF und FPDI benötigt.
Siehe [PDF_LIBRARY_INSTALLATION.md](PDF_LIBRARY_INSTALLATION.md) für Installationsanweisungen.

## Logging-System

Das System verwendet **Monolog** für strukturiertes Logging mit drei separaten Logfiles:

### Logfiles

| Logfile | Beschreibung | Enthaltene Events |
|---------|--------------|-------------------|
| `customer.log` | Kunden-Aktionen | Session, Reservierungen, Warenkorb, Zahlung, E-Mails |
| `admin.log` | Admin-Aktionen | Login/Logout, Benutzer/Event-Verwaltung, Einstellungen |
| `presales.log` | Vorverkauf-Aktionen | Login/Logout, Verkauf, Gutscheine, Rückerstattungen |

### Log-Verzeichnis

```
logging/
├── customer.log          # Kunden-Events
├── admin.log             # Admin-Events  
├── presales.log          # Vorverkauf-Events
└── .htaccess             # Schutz vor direktem Zugriff
```

### Log-Format

```
[2026-01-20 22:10:12] customer.INFO: Action: seats_added_to_cart {"session_id":"abc123...","user_id":"1","event_id":10,"event_date":"2026-01-22","seats":["A1","A2"],"seat_count":2,"ip":"127.0.0.1","user_agent":"..."} []
```

### Logging-Verwendung

**Helper-Funktionen (empfohlen):**
```php
// Customer Logging
logSessionCreated($sessionId, $userId);
logSessionDestroyed($sessionId, $userId, $reason);
logSeatsAddedToCart($sessionId, $eventId, $eventDate, $seats, $userId);
logSeatsReleased($sessionId, $eventId, $eventDate, $seats, $userId, $reason);
logVoucherApplied($sessionId, $voucherCode, $discount, $userId);
logCartCleared($sessionId, $itemCount, $userId, $reason);
logPaymentStarted($sessionId, $orderId, $amount, $currency, $userId);
logPaymentCancelled($sessionId, $orderId, $userId, $reason);
logPaymentCompleted($sessionId, $orderId, $amount, $currency, $userId, $details);

// Admin Logging
logAdminLogin($adminId, $username, $success, $reason);
logAdminLogout($adminId, $username);
logAdminUserCreate($adminId, $targetUserId, $role);
logAdminUserUpdate($adminId, $targetUserId, $changes);
logAdminEventCreate($adminId, $eventId, $eventName);
logAdminSettingsChange($adminId, $settingName, $oldValue, $newValue);

// Presales Logging
logPresalesLogin($userId, $username, $success, $reason);
logPresalesLogout($userId, $username);
logPresalesSale($userId, $customerName, $amount, $ticketCount, $paymentMethod);
logPresalesRefund($userId, $customerName, $amount, $reason);
logPresalesVoucherCreate($userId, $voucherCode, $value);

// E-Mail Logging
logEmailSent($type, $emailHash, $success, $userId, $details);
```

**Direkte Nutzung:**
```php
use App\Services\LoggerService;

LoggerService::logCustomer('action_name', ['key' => 'value'], 'info');
LoggerService::logAdmin('admin_action', ['admin_id' => '1'], 'warning');
LoggerService::logPresales('sale_action', ['amount' => 100], 'info');
```

### Log-Rotation

- Logfiles werden automatisch nach 30 Tagen rotiert
- Tägliche Logfiles mit Datum: `customer-2026-01-20.log`
- Maximal 30 Versionen werden aufbewahrt

## Tests

### PHPUnit Tests

Das System enthält PHPUnit-Tests für das Logging-System:

```bash
# Alle Tests ausführen
php vendor/bin/phpunit

# Nur Logger-Tests ausführen
php vendor/bin/phpunit tests/Unit/Services/LoggerServiceTest.php --testdox

# Mit Coverage-Report
php vendor/bin/phpunit --coverage-html coverage/
```

### Test-Resultate

```
Logger Service (Tests\Unit\Services\LoggerService)
 ✔ Log path exists
 ✔ Log customer does not throw exception
 ✔ Log admin does not throw exception
 ✔ Log presales does not throw exception
 ✔ Log session created does not throw exception
 ...
 ✔ Log with warning level does not throw exception
 ✔ Log with error level does not throw exception

OK (19 tests, 1 assertion)
```

### Test-Dateien

```
tests/
├── bootstrap.php              # PHPUnit Bootstrap
├── phpunit.xml                # PHPUnit Konfiguration
└── Unit/
    └── Services/
        └── LoggerServiceTest.php   # 19 Tests für LoggerService
```

## Support

Bei Fragen oder Problemen wenden Sie sich an den Entwickler.

---

*Letzte Aktualisierung: 2026-02-27 | Version 2.0*

