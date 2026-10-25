# openTicket

Open-Source-Ticketing-System für Theaterveranstaltungen mit Sitzplan-Verwaltung, PayPal-Integration und Multi-Customer-Deployment.

## 🚀 Quick Start

Dieses Projekt ist ein Multi-Customer-Ticketing-System mit zentraler Master-Installation.

### Für Entwickler

```bash
# 1. Repository klonen
git clone https://github.com/Mind-Building/openTicket.git

# 2. Dependencies installieren
composer install

# 3. Konfiguration erstellen
cp .env.example .env

# 4. Datenbank initialisieren
php setup.php  # Im Browser öffnen
```

### Für Deployment

```batch
# Zu Kunden-Installation deployen
cd d:\xampp\htdocs\ticket2.0
deploy_to_customer.bat
```

## 📚 Dokumentation

→ **[Vollständige Dokumentations-Übersicht](docs/INDEX.md)**

Alle Dokumentation befindet sich im [`docs/`](docs/) Verzeichnis:

- **[Installation & Setup](docs/README.md)** - Vollständige Installations- und Konfigurationsanleitung
- **[Deployment Guide](docs/DEPLOYMENT.md)** - Multi-Customer-Deployment-Prozess
- **[Frontend-Bibliotheken](docs/FRONTEND_LIBRARIES.md)** - Bootstrap, Icons, Flatpickr Setup
- **[Migrationen](docs/README_MIGRATIONS.md)** - Datenbank-Migrationen
- **[PDF-Bibliotheken](docs/PDF_LIBRARY_INSTALLATION.md)** - TCPDF/FPDF Installation
- **[E-Mail Setup](docs/INSTALLATION_PHPMAILER.md)** - PHPMailer Konfiguration

## ✨ Features

- ✅ Moderne PHP 8.1+ Architektur
- ✅ Multi-Customer-Deployment-System
- ✅ Sitzplan-Editor mit visueller UI
- ✅ PayPal-Integration (Live & Sandbox)
- ✅ Gutschein-System
- ✅ PDF-Ticket-Generierung
- ✅ E-Mail-Benachrichtigungen
- ✅ Admin & Vorverkaufs-Interface
- ✅ Bootstrap 5 UI

## 🏗️ Projekt-Struktur

```
ticket2.0/                    ← Master-Installation
├── app/                      ← PHP Anwendungs-Code
├── public/                   ← Document Root
│   ├── libs/                 ← Frontend-Bibliotheken
│   ├── api/                  ← REST API Endpoints
│   ├── admin/                ← Admin-Interface
│   └── presales/             ← Vorverkaufs-Interface
├── database/                 ← Schema & Seeds
├── docs/                     ← Dokumentation
├── vendor/                   ← Composer Dependencies
└── deploy_to_customer.bat    ← Deployment-Script
```

## 🎯 Multi-Customer-Setup

Das System unterstützt mehrere Kunden-Installationen:

```
d:\xampp\htdocs\
├── ticket2.0\              ← Master (Entwicklung)
├── ticket_kunde_a\         ← Kunde A
└── ticket_kunde_b\         ← Kunde B
```

Siehe [Deployment Guide](docs/DEPLOYMENT.md) für Details.

## 📝 Lizenz

openTicket steht unter der [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0).

Wer eine veränderte Version betreibt – auch nur als Website – muss den geänderten Quellcode den Nutzern unter derselben Lizenz zugänglich machen.

## 🆘 Support

Bei Fragen oder Problemen:
1. Siehe [Dokumentation](docs/)
2. Prüfe [Troubleshooting](docs/DEPLOYMENT.md#-troubleshooting)
3. Kontaktiere das Development Team
