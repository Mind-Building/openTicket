# Frontend-Bibliotheken

Dieses Verzeichnis enthält die erforderlichen CSS- und JavaScript-Bibliotheken für das Ticket-System.

## Benötigte Dateien

### Bootstrap 5
Download: https://getbootstrap.com/

- **css/bootstrap.min.css** (v5.3.8 oder höher)
  - Download: https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css

- **js/bootstrap.bundle.min.js** (v5.3.8 oder höher, inkl. Popper.js)
  - Download: https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js

### Bootstrap Icons
Download: https://icons.getbootstrap.com/

- **css/bootstrap-icons.css** (v1.11.3 oder höher)
  - Download: https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css
  - **WICHTIG:** Auch die Font-Dateien (.woff, .woff2) herunterladen!
  - Entweder die komplette Zip-Datei herunterladen oder:
    - fonts/bootstrap-icons.woff
    - fonts/bootstrap-icons.woff2

### Flatpickr (Datepicker)
Download: https://flatpickr.js.org/

- **css/flatpickr.min.css** (v4.6.13 oder höher)
  - Download: https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css

- **js/flatpickr.min.js** (v4.6.13 oder höher)
  - Download: https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js

- **js/flatpickr.de.js** (Deutsche Lokalisierung)
  - Download: https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/de.js
  - Als `flatpickr.de.js` speichern

## Schnelle Installation via PowerShell

```powershell
# In das libs-Verzeichnis wechseln
cd d:\xampp\htdocs\ticket2.0\libs

# Bootstrap CSS
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" -OutFile "css/bootstrap.min.css"

# Bootstrap JS
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" -OutFile "js/bootstrap.bundle.min.js"

# Bootstrap Icons CSS
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" -OutFile "css/bootstrap-icons.css"

# Bootstrap Icons Fonts (Ordner erstellen falls nicht vorhanden)
mkdir -p css/fonts
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff" -OutFile "css/fonts/bootstrap-icons.woff"
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2" -OutFile "css/fonts/bootstrap-icons.woff2"

# Flatpickr CSS
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" -OutFile "css/flatpickr.min.css"

# Flatpickr JS
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" -OutFile "js/flatpickr.min.js"

# Flatpickr Deutsche Lokalisierung
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/de.js" -OutFile "js/flatpickr.de.js"
```

## Verzeichnisstruktur

Nach dem Download sollte die Struktur so aussehen:

```
libs/
├── css/
│   ├── bootstrap.min.css
│   ├── bootstrap-icons.css
│   ├── flatpickr.min.css
│   └── fonts/
│       ├── bootstrap-icons.woff
│       └── bootstrap-icons.woff2
└── js/
    ├── bootstrap.bundle.min.js
    ├── flatpickr.min.js
    └── flatpickr.de.js
```

## Hinweise

- **Bootstrap Icons Fonts:** Die CSS-Datei referenziert Font-Dateien, die im Unterordner `fonts/` liegen müssen
- **Bootstrap Bundle:** Enthält bereits Popper.js, daher keine separate Popper-Datei nötig
- **Flatpickr Lokalisierung:** Die Datei `de.js` muss als `flatpickr.de.js` gespeichert werden

## Alternative: CDN-Links verwenden

Falls du die Dateien nicht lokal hosten möchtest, könntest du in den PHP-Dateien auch direkt auf CDNs verlinken. Allerdings ist lokales Hosting für Produktiv-Umgebungen empfohlen.
