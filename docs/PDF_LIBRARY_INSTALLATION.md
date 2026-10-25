# PDF-Bibliotheken

Das Ticket-System nutzt zwei PHP-Bibliotheken zur PDF-Erzeugung:

| Paket | Zweck |
|---|---|
| `tecnickcom/tcpdf` | PDF-Generierung |
| `setasign/fpdi` (TCPDF-Variante) | Bestehende PDF-Templates befüllen |

## Installation

Beide Pakete sind in `composer.json` deklariert. Ein normaler Installer-Lauf reicht:

```bash
composer install
```

Composer legt die Bibliotheken unter `vendor/tecnickcom/tcpdf/` und `vendor/setasign/fpdi/` ab und nimmt sie automatisch ins Autoloading auf. Im Anwendungscode genügt:

```php
use setasign\Fpdi\Tcpdf\Fpdi; // TCPDF-Flavor von FPDI – nicht den FPDF-Flavor!

$pdf = new Fpdi();
```

`bootstrap.php` muss **nicht** angepasst werden — `vendor/autoload.php` lädt beides on-demand.

## Versions-Constraints

In `composer.json`:

```json
"require": {
    "tecnickcom/tcpdf": "^6.10",
    "setasign/fpdi": "^2.6"
}
```

Aktuelle Lock-Versionen (Stand 2026-05): TCPDF 6.11.2, FPDI 2.6.6.

## Verifikation nach der Installation

```bash
php -r "require __DIR__ . '/vendor/autoload.php';
        echo class_exists('TCPDF') ? 'TCPDF: OK' : 'TCPDF: FEHLT', PHP_EOL;
        echo class_exists('setasign\\\\Fpdi\\\\Tcpdf\\\\Fpdi') ? 'FPDI:  OK' : 'FPDI:  FEHLT', PHP_EOL;"
```

Beide müssen `OK` melden. Falls nicht: `composer install` oder `composer update` läuft sauber durch?

Es gibt auch eine Test-Suite, die beide Bibliotheken instanziiert und durch typische Operationen führt:

```bash
php vendor/bin/phpunit --filter PdfGenerationTest
```

## Fallback ohne Composer

Wird in Ausnahmefällen verlangt (Shared Hosting ohne Shell-Zugang, etc.). Dann muss der gesamte `vendor/`-Ordner per FTP hochgeladen werden — er enthält **alle** Composer-Abhängigkeiten, nicht nur TCPDF/FPDI. Eine "nur TCPDF/FPDI manuell ablegen"-Lösung gibt es nicht mehr, weil das Autoloading dann nicht greift.

Empfehlung: `composer install` lokal ausführen, dann `vendor/` komplett hochladen.
