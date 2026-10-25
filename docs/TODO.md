# TODO – Dokumentation aktualisieren

Offene Doku-Updates basierend auf den aktuellen Code-Änderungen.

---

## ~~Handbuch-Updates~~ — erledigt (2026-04-28)

ADMIN_HANDBUCH.md und VORVERKAUF_HANDBUCH.md wurden vollständig auf den aktuellen Code-Stand aktualisiert (Version 2.1):

- [x] **Gutscheine** — 1 Code = N Tickets in beiden Handbüchern korrigiert
- [x] **Cleanup §5.4** — 20-Minuten-Fenster, Cron alle 5 Min., Gutschein-Bereinigung, Cron-Heartbeat
- [x] **Tagesbericht §11.2** — Cron-Heartbeat ergänzt
- [x] **System-Logs §12** — neuer Abschnitt mit Funnel, Filtern, Suche, klickbaren Verweisen, Acknowledge
- [x] **VIP-Tickets §8** — neuer Abschnitt
- [x] **Zahlungsanforderungen §9** — neuer Abschnitt
- [x] **Sitzplan-Belegung** — als eigener Unterabschnitt in beiden Handbüchern
- [x] **Kunden-Import §6.5** — neuer Abschnitt mit CSV-Format
- [x] **Umbuchen** — Hinweis zur Bestellungs-Aufteilung bei Datumswechsel
- [x] **Dokumentation** — neuer Menüpunkt erwähnt
- [x] **Vorverkauf §4.6** — Status-Tabelle vollständig

> **Nicht vergessen:** PDFs neu aus den `.md`-Dateien exportieren und in `docs/manuals/` ablegen, damit der Doku-Menüpunkt im System den aktuellen Stand zeigt.

---

## Verbesserung settings.php

ich möchte die settings.php umbauen so dass die seine bessere usability hat. ich stelle mir je Thema ein Reiter vor: Grundeinstellung, Email, Paypal, Mail-Texte, Zahlungsmehtoden, Funktionen
Beim Tagesbericht sollte als Info noch die URL, die vom CRON Job aufgefuden werden muss

---

## Log-Viewer Verbesserungen

- [x] **Kunden-ID als Link** — Erledigt. `user_id` wird jetzt als klickbarer Link dargestellt: im Customer-Kanal zu `admin/customers_edit.php`, in Admin-/Vorverkauf-Kanälen zu `admin/users_edit.php`. Username (falls vorhanden) ist ebenfalls verlinkt.

---

## Security-Hardening

`Csrf` und `RateLimiter` sind als Klassen vorhanden. Admin/Login nutzen beide. Öffentliche APIs werden über `App\Core\ApiGuard` abgesichert.

- [x] **Rate-Limiting auf API-Endpoints** — Umgesetzt via [app/core/ApiGuard.php](app/core/ApiGuard.php). Pro Kategorie (cart/seat/voucher/payment/pdf/…) ein Bucket pro IP. Limits sind so gewählt, dass normale Nutzer auch bei schnellen Mehrfach-Klicks nicht geblockt werden. DB-Tabelle `rate_limits` ist in `schema.sql` ergänzt; bestehende Installationen führen [database/migrate_rate_limits.sql](database/migrate_rate_limits.sql) aus.
- [x] **Bot-Erkennung** — `ApiGuard` verwirft Requests mit leerem User-Agent (403). Echte Browser senden immer einen UA — auch firmeninterne Proxies nicht leer. Reine UA-Pattern-Filter wurden bewusst ausgelassen (zu hohe False-Positive-Quote, UAs sind trivial zu fälschen, Rate-Limit ist die eigentliche Verteidigung).
- [ ] **Dokumentation** — Security-Maßnahmen im ADMIN_HANDBUCH dokumentieren (Rate-Limits pro Kategorie, IP-Blocking, ApiGuard-Verhalten bei 429).

> **Hinweis:** Die IP-Sperre in `.htaccess` (185.177.72.17) ist bereits aktiv und risikofrei — blockt nur einen bekannten Angreifer.

### Umsetzungsdetails Rate-Limit

Limits pro Kategorie (jeweils pro IP, 60-Sekunden-Fenster, anschließend 1 h Block nach Überschreitung):

| Kategorie | Limit | Endpoints |
|---|---|---|
| `cart` | 90/min | cart_add, cart_add_no_seatplan, cart_remove, cart_clear |
| `cart_read` | 180/min | cart_count, cart_update_timestamp, session_reset_timeout (JS-Heartbeats) |
| `seat` | 120/min | customer_reserve_seat, customer_free_seat |
| `voucher` | 30–60/min | voucher_add (30), voucher_remove (60) |
| `voucher_redeem` | 20/min | voucher_redeem (Brute-Force-Schutz für Codes) |
| `fncard` | 20/min | fncard_validate (Brute-Force-Schutz für 8-stellige Nummern) |
| `payment` | 20–60/min | paypal_create_order, paypal_capture_order (30), paypal_log_error (60), payment_request_* (20) |
| `pdf` | 30/min | ticket_download, voucher_download |

Nicht abgesichert (bewusst): `paypal_cancel.php`/`paypal_success.php` (Redirect-Callbacks, kein JSON-Output), auth-geschützte Admin/Presales-APIs (Login ist der Gate), `cart_clear_expired.php` (Cron).

### Nice-to-have (kein konkretes Sicherheitsloch)

CSRF im Kunden-Bereich bringt wenig praktischen Nutzen: es gibt keine dauerhafte Kunden-Anmeldung, und Zahlungen werden extern (PayPal) bestätigt. Defense-in-Depth, nicht dringend.

- [ ] **CSRF-Schutz im Checkout** — Token-Validierung in `checkout.php` (POST). Achtung: Kunden die das Formular lange offen haben → Token läuft ab → Checkout schlägt fehl. Braucht gute Fehlermeldung.
- [ ] **CSRF-Schutz in weiteren öffentlichen Formularen** — Kontakt, Gutschein-Kauf, etc.

---

## ~~Bestellzugehörigkeit bei Umbuchungen (`order_group_id`)~~ — wird nicht umgesetzt

**Entscheidung (2026-04-27):** Die Aufteilung umgebuchter Bestellungen in zwei Einträge ist akzeptabel. Der Vorverkauf findet die Bestellungs-Teile über den Kundennamen in der Reservierungs-Übersicht. Stattdessen wurde im Umbuchungs-Dialog (Admin + Vorverkauf) ein Hinweis ergänzt, der dieses Verhalten erklärt.

---

## E-Mail-Versand: Logging nach Rolle trennen

**Problem:** `EmailService::logEmail()` ([app/services/EmailService.php:34-54](app/services/EmailService.php#L34-L54)) schreibt jeden E-Mail-Versand fest verdrahtet über `LoggerService::logCustomer('email_sent', …)` ins `customer.log` — unabhängig davon, wer den Versand ausgelöst hat. Folge: Klickt ein Mitarbeiter im Vorverkauf auf „Mail senden" im Bestelldetail ([public/api/reservation_resend_email.php](public/api/reservation_resend_email.php)), erscheint der Eintrag im Kunden-Log statt im Vorverkauf-Log.

- [x] **`logEmail()` rollenabhängig machen** — Erledigt. `EmailService::logEmail()` routet jetzt anhand von `Auth::isPresales()` / `Auth::isAdmin()` ins jeweilige Log; `customer.log` bleibt Default für anonyme/öffentliche Flows und CLI-Scripts.

---

*Erstellt: 2026-04-13*
