# Ticket System - Deployment Guide

## 📋 Übersicht

Dieses Ticket-System wird als **Master-Installation** gepflegt und an mehrere Kunden ausgerollt. Jeder Kunde erhält eine separate Installation mit eigener Konfiguration und Daten.

## 🏗️ Struktur

```
d:\xampp\htdocs\
├── ticket2.0\              ← Master-Installation (Entwicklung)
├── ticket_kundeA\          ← Kunde A (Test/Staging)
├── ticket_kundeB\          ← Kunde B (Test/Staging)
└── ...
```

## 🚀 Deployment-Prozess

### Option 1: Automatisches Deployment (Empfohlen)

Nutze das bereitgestellte Batch-Script:

```batch
cd d:\xampp\htdocs\ticket2.0
deploy_to_customer.bat
```

Das Script:
- ✅ Fragt nach dem Ziel-Kunden
- ✅ Erstellt automatisch ein Backup
- ✅ Kopiert nur Code-Dateien (keine Kundendaten!)
- ✅ Erhält Konfiguration und Daten
- ✅ Zeigt eine Zusammenfassung

### Option 2: Manuelles Deployment

Falls du manuell deployen möchtest:

#### Erstinstallation

1. **Ordner erstellen:**
   ```batch
   mkdir d:\xampp\htdocs\ticket_kundeC
   ```

2. **Code kopieren:**
   ```batch
   xcopy d:\xampp\htdocs\ticket2.0\app d:\xampp\htdocs\ticket_kundeC\app /E /I /Y
   xcopy d:\xampp\htdocs\ticket2.0\public d:\xampp\htdocs\ticket_kundeC\public /E /I /Y
   ```
   (`public\libs\` ist Teil von `public\` und wird damit bereits mitkopiert.)

3. **Kunden-Ordner erstellen:**
   ```batch
   mkdir d:\xampp\htdocs\ticket_kundeC\data
   mkdir d:\xampp\htdocs\ticket_kundeC\public\uploads
   ```
   (Der Log-Ordner `logging/` wird beim ersten Schreibzugriff automatisch angelegt.)

4. **Konfiguration kopieren:**
   ```batch
   copy d:\xampp\htdocs\ticket2.0\.env.example d:\xampp\htdocs\ticket_kundeC\.env
   ```

5. **Konfiguration anpassen:**
   - `.env` Datei bearbeiten
   - Datenbank-Pfad anpassen
   - URL-Konfiguration setzen
   - PayPal-Credentials eintragen

#### Update (bestehende Installation)

1. **Backup erstellen:**
   ```batch
   xcopy d:\xampp\htdocs\ticket_kundeC d:\xampp\htdocs\ticket_kundeC_backup_%date% /E /I
   ```

2. **Code aktualisieren:**
   ```batch
   xcopy d:\xampp\htdocs\ticket2.0\app d:\xampp\htdocs\ticket_kundeC\app /E /I /Y
   xcopy d:\xampp\htdocs\ticket2.0\public\*.php d:\xampp\htdocs\ticket_kundeC\public\ /Y
   xcopy d:\xampp\htdocs\ticket2.0\public\api d:\xampp\htdocs\ticket_kundeC\public\api /E /I /Y
   xcopy d:\xampp\htdocs\ticket2.0\public\admin d:\xampp\htdocs\ticket_kundeC\public\admin /E /I /Y
   xcopy d:\xampp\htdocs\ticket2.0\public\presales d:\xampp\htdocs\ticket_kundeC\public\presales /E /I /Y
   ```

3. **Testen:**
   - Anwendung öffnen
   - Login testen
   - Funktionen prüfen

## 📦 Composer Dependencies (vendor/)

Das Ticket-System nutzt Composer-Pakete (z.B. TCPDF für PDF-Generierung). Diese werden im `/vendor/` Verzeichnis gespeichert.

### Deployment-Optionen:

**Option 1: Vendor kopieren (Standard - empfohlen für XAMPP)**
- Das Deployment-Script kopiert automatisch das komplette `/vendor/` Verzeichnis
- ✅ Einfach und schnell
- ✅ Keine zusätzlichen Tools benötigt
- ⚠️ Größere Datenmenge (~50-100 MB)

**Option 2: Composer install auf Zielserver (für Produktiv-Server)**
```bash
cd /var/www/html/ticket_kunde_a
composer install --no-dev --optimize-autoloader
```
- ✅ Sauberer Ansatz
- ✅ Kleinere Deployments (nur composer.json/lock kopieren)
- ⚠️ Benötigt Composer auf dem Server

### Für lokales XAMPP-Deployment:
Das Script kopiert automatisch `/vendor/` → keine weiteren Schritte nötig!

## 📦 Was wird kopiert / NICHT kopiert

### ✅ Wird kopiert (Code):
- `/app/` - PHP-Anwendungs-Code
- `/public/*.php` - Öffentliche PHP-Dateien
- `/public/api/` - API-Endpoints
- `/public/admin/` - Admin-Interface
- `/public/presales/` - Vorverkaufs-Interface
- `/public/libs/` - Frontend-Bibliotheken (Bootstrap, Bootstrap Icons, Flatpickr)
- `/vendor/` - PHP-Dependencies (Composer-Pakete wie TCPDF, FPDF, etc.)
- `/database/` - Datenbank-Schema und Seed-Dateien
- `composer.json` - Dependency-Definition
- `composer.lock` - Exakte Versionen der Dependencies
- `setup.php` - Setup-Script für Datenbank-Initialisierung
- `.htaccess` - Apache-Konfiguration (Root und public/, nur Erstinstallation)
- `README_KUNDE.txt` - Wird automatisch generiert bei Erstinstallation

### ❌ Wird NICHT kopiert (Kundendaten):
- `.env` - Kunden-Konfiguration
- `/data/` - Datenbank-Dateien
- `/logging/` - Log-Dateien (wird automatisch erzeugt)
- `/public/uploads/` - Hochgeladene Dateien (Tickets, Gutscheine, Sitzpläne)

## 🔧 Nach dem Deployment

### Erstinstallation Checklist

- [ ] `.env` Datei konfigurieren (Datenbank-Pfad, PayPal, E-Mail)
- [ ] Datenbank initialisieren:
  - **Option A:** Browser: `http://localhost/ticket_kunde/setup.php` öffnen
  - **Option B:** Manuell: SQL-Dateien aus `/database/seeds/` importieren
- [ ] Web-Server auf `public/` Verzeichnis zeigen lassen
- [ ] Admin-Login testen (Credentials aus Datenbank-Seeds)
- [ ] PayPal-Credentials testen (falls aktiviert)
- [ ] E-Mail-Versand testen
- [ ] Testbestellung durchführen
- [ ] `README_KUNDE.txt` lesen für Details

### Update Checklist

- [ ] Backup verifizieren
- [ ] Anwendung testen
- [ ] Login testen
- [ ] Ticket-Generierung testen
- [ ] PayPal-Integration testen (falls aktiviert)
- [ ] E-Mail-Versand testen

## 📊 Versionsverwaltung

### Git-Workflow

1. **Master-Installation entwickeln:**
   ```bash
   cd d:\xampp\htdocs\ticket2.0
   git add .
   git commit -m "Feature: Neue Funktionalität"
   git push
   ```

2. **Zu Kunden deployen:**
   ```batch
   deploy_to_customer.bat
   ```

3. **Auf Produktiv-Server kopieren:**
   - FTP/SFTP
   - rsync (Linux)
   - Git (empfohlen für Produktiv-Server)

## 🌐 Produktiv-Deployment

### Vorbereitung

1. **Master-Installation vorbereiten:**
   ```bash
   cd d:\xampp\htdocs\ticket2.0
   git tag v1.0.0
   git push --tags
   ```

2. **Auf Server deployen:**

   **Option A: Git (empfohlen)**
   ```bash
   # Auf Server
   cd /var/www/html/ticket_kunde_a
   git pull origin main
   ```

   **Option B: FTP/SFTP**
   - Nutze FileZilla oder WinSCP
   - Kopiere nur Code-Dateien
   - **NICHT** `.env`, `/data/`, `/uploads/` überschreiben!

   **Option C: rsync (Linux)**
   ```bash
   rsync -avz --exclude='.env' --exclude='data/' --exclude='uploads/' \
         d:\xampp\htdocs\ticket_kundeA/ \
         user@server:/var/www/html/ticket_kunde_a/
   ```

## 🔒 Sicherheit

### Wichtige Punkte:

1. **`.env` niemals ins Git!**
   - Enthält Passwörter und Secrets
   - Bereits in `.gitignore`

2. **Kundendaten schützen:**
   - Backups verschlüsseln
   - Zugriff beschränken
   - DSGVO beachten

3. **Server-Konfiguration:**
   - `public/` als Document Root
   - PHP 8.0+ verwenden
   - HTTPS erzwingen
   - Firewall aktivieren

## 🐛 Troubleshooting

### Problem: "Datei nicht gefunden"
- **Lösung:** Prüfe, ob Document Root auf `public/` zeigt

### Problem: "Datenbank-Fehler"
- **Lösung:** Prüfe `.env` Datei - `DB_PATH` korrekt?

### Problem: "PayPal funktioniert nicht"
- **Lösung:** Prüfe PayPal-Credentials in `.env`

### Problem: "Alte Version wird angezeigt"
- **Lösung:** Browser-Cache leeren, PHP OpCache neustarten

## 📞 Support

Bei Problemen:
1. Logs prüfen: `/logging/`
2. PHP-Fehler aktivieren: `display_errors = On` in `php.ini`
3. Browser-Console prüfen (F12)

## 🎯 Best Practices

1. **Immer Backups vor Updates!**
2. **Erst lokal testen, dann auf Produktiv**
3. **Versionstags verwenden** (v1.0.0, v1.1.0, etc.)
4. **Deployment-Log führen** (wann, was, wer)
5. **Kunden informieren** vor größeren Updates

## 📝 Deployment-Log Beispiel

Führe ein Log über Deployments:

```
# Deployment-Log

## 2026-02-15 - v1.0.0 - Kunde A
- Erstinstallation
- PayPal-Integration aktiviert
- E-Mail konfiguriert
- Getestet: ✅

## 2026-02-20 - v1.1.0 - Kunde A
- Update: Layout-Optimierung Checkout
- Backup: ticket_kundeA_backup_20260220
- Getestet: ✅

## 2026-02-22 - v1.1.0 - Kunde B
- Update: Layout-Optimierung Checkout
- Backup: ticket_kundeB_backup_20260222
- Getestet: ✅
```

---

**Erstellt:** 2026-02-15
**Version:** 1.0
**Autor:** Ticket System Development Team
