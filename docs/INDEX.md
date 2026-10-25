# Dokumentations-Übersicht – Ticket System 2.0

---

## 📖 Inhaltsverzeichnis

### Benutzer-Handbücher

| Dokument | Zielgruppe | Inhalt |
|----------|-----------|--------|
| [ADMIN_HANDBUCH.md](ADMIN_HANDBUCH.md) | Administratoren | Vollständige Bedienanleitung für den Admin-Bereich: Veranstaltungen, Sitzplan, Reservierungen, Kunden, Gutscheine, Kasse, Berichte, Benutzer, Einstellungen, E-Mail-Vorlagen |
| [VORVERKAUF_HANDBUCH.md](VORVERKAUF_HANDBUCH.md) | Vorverkaufs-Personal | Bedienanleitung für den Vorverkauf-Bereich: Tickets verkaufen, Reservierungen, Kasse, Gutscheine |

### Technische Dokumentation

| Dokument | Inhalt |
|----------|--------|
| [README.md](README.md) | Vollständige technische Dokumentation: Installation, Projektstruktur, Sicherheitskonzept, Entwicklung, Logging, Tests |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Multi-Customer-Deployment, automatisches Deployment-Script, Backup-Strategie |
| [README_MIGRATIONS.md](README_MIGRATIONS.md) | Datenbank-Migrationen, Versionshistorie |

### Installation & Einrichtung

| Dokument | Inhalt |
|----------|--------|
| [FRONTEND_LIBRARIES.md](FRONTEND_LIBRARIES.md) | Bootstrap 5, Bootstrap Icons, Flatpickr – Download und Installation |
| [PDF_LIBRARY_INSTALLATION.md](PDF_LIBRARY_INSTALLATION.md) | TCPDF, FPDI – Installation für Ticket-PDF-Generierung |
| [INSTALLATION_PHPMAILER.md](INSTALLATION_PHPMAILER.md) | PHPMailer-Setup, SMTP-Konfiguration, Troubleshooting |

---

## 🎯 Schnellzugriff

### Ich bin Admin und möchte...

- **...eine neue Veranstaltung anlegen**
  → [ADMIN_HANDBUCH.md → Abschnitt 3](ADMIN_HANDBUCH.md#3-veranstaltungen)

- **...den Sitzplan konfigurieren**
  → [ADMIN_HANDBUCH.md → Abschnitt 4](ADMIN_HANDBUCH.md#4-sitzplan)

- **...E-Mail-Vorlagen anpassen (inkl. Sitzplan-Link)**
  → [ADMIN_HANDBUCH.md → Abschnitt 12](ADMIN_HANDBUCH.md#12-e-mail-vorlagen--platzhalter)

- **...einen neuen Vorverkauf-Benutzer anlegen**
  → [ADMIN_HANDBUCH.md → Abschnitt 10](ADMIN_HANDBUCH.md#10-benutzer)

- **...die SMTP-E-Mail-Einstellungen konfigurieren**
  → [ADMIN_HANDBUCH.md → Abschnitt 11.4](ADMIN_HANDBUCH.md#114-e-mail-smtp-konfiguration)

- **...Berichte und Auswertungen einsehen**
  → [ADMIN_HANDBUCH.md → Abschnitt 9](ADMIN_HANDBUCH.md#9-berichte)

### Ich bin Vorverkaufs-Personal und möchte...

- **...Tickets verkaufen**
  → [VORVERKAUF_HANDBUCH.md → Abschnitt 3](VORVERKAUF_HANDBUCH.md#3-tickets--reservierungen-verkaufen)

- **...die Abendkasse öffnen**
  → [VORVERKAUF_HANDBUCH.md → Abschnitt 6](VORVERKAUF_HANDBUCH.md#6-kasse-abendkasse)

- **...Gutscheine erstellen**
  → [VORVERKAUF_HANDBUCH.md → Abschnitt 7](VORVERKAUF_HANDBUCH.md#7-gutscheine)

- **...mich auf einem neuen Gerät anmelden**
  → [VORVERKAUF_HANDBUCH.md → Abschnitt 1](VORVERKAUF_HANDBUCH.md#1-login--geräte-anmeldung)

### Ich bin Entwickler und möchte...

- **...das System installieren**
  → [README.md → Installation](README.md#installation)

- **...zu einem Kunden deployen**
  → [DEPLOYMENT.md](DEPLOYMENT.md)

- **...das Token-Sicherheitskonzept verstehen**
  → [README.md → Sicherheit](README.md#sicherheit)

- **...E-Mail-Platzhalter erweitern**
  → [README.md → Entwicklung](README.md#entwicklung)

- **...die Datenbank migrieren**
  → [README_MIGRATIONS.md](README_MIGRATIONS.md)

- **...PHPMailer einrichten**
  → [INSTALLATION_PHPMAILER.md](INSTALLATION_PHPMAILER.md)

---

## 🔧 Technologie-Stack

| Bereich | Technologie |
|---------|-------------|
| Backend | PHP 8.1+ |
| Frontend | Bootstrap 5, Bootstrap Icons, Flatpickr |
| Datenbank | MySQL (MariaDB) |
| PDF | TCPDF + FPDI |
| E-Mail | PHPMailer (SMTP) |
| Payment | PayPal REST SDK |
| Logging | Monolog |
| Dependencies | Composer |

---

## 📁 Wichtige Dateien im Überblick

| Datei | Zweck |
|-------|-------|
| `.env` | Datenbankverbindung, APP_SECRET, SMTP (nicht in Git!) |
| `app/helpers/functions.php` | Token-Generierung (Ticket, Sitzplan, Gutschein) |
| `app/services/EmailService.php` | E-Mail-Versand mit allen Platzhaltern |
| `public/seatplan_view.php` | Öffentliche, token-gesicherte Sitzplan-Ansicht für Kunden |
| `public/admin/seatplan_view.php` | Login-geschützte Sitzplan-Ansicht für Admins |
| `database/schema.sql` | Vollständiges Datenbankschema |

---

## 🆘 Häufige Probleme

| Problem | Lösung |
|---------|--------|
| E-Mails werden nicht versandt | [INSTALLATION_PHPMAILER.md](INSTALLATION_PHPMAILER.md) |
| PDF-Generierung schlägt fehl | [PDF_LIBRARY_INSTALLATION.md](PDF_LIBRARY_INSTALLATION.md) |
| Deployment-Fehler | [DEPLOYMENT.md → Troubleshooting](DEPLOYMENT.md) |
| Frontend-Darstellungsfehler | [FRONTEND_LIBRARIES.md](FRONTEND_LIBRARIES.md) |
| Datenbank-Migrationsfehler | [README_MIGRATIONS.md](README_MIGRATIONS.md) |
| Sitzplan-Link in E-Mail funktioniert nicht | APP_SECRET in `.env` prüfen; `{seatplan_link_html}` im E-Mail-Template eintragen |

### Allgemeine Debug-Tipps

1. **PHP-Fehlerlog:** `xampp/apache/logs/error.log`
2. **Anwendungs-Logs:** `logging/customer.log`, `admin.log`, `presales.log`
3. **Browser-Konsole:** F12 → Konsole
4. **PHP-Fehler aktivieren:** In `.env` → `APP_DEBUG=true`

---

*Letzte Aktualisierung: 2026-02-27 | Version 2.0*
