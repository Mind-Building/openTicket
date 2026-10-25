# Konzept: PayPal-Reservierungen stornieren & zurückerstatten (Admin)

> Status: **geplant, noch nicht umgesetzt.** Dieses Dokument beschreibt Machbarkeit und
> Umsetzungsplan. Es wurden noch keine Code-Änderungen vorgenommen.

## Kontext / Ziel

Kunden, die online per PayPal bezahlt haben (Status 2 = `paid_paypal`), sollen im Admin
storniert und ihr Geld automatisch zurückerstattet werden können. Aktuell gibt es nur
„Löschen" (gibt den Platz frei, ohne Geldrückfluss) — die Rückerstattung passiert manuell
im PayPal-Konto.

### Geht das bei PayPal?
Ja. PayPal hat die Refunds-API (`POST /v2/payments/captures/{capture_id}/refund`, voll oder
anteilig). Unser `app/services/PayPalService.php` kann bisher nur
`createOrder`/`captureOrder`/`getOrder` — eine Refund-Methode fehlt.

### EC / Kreditkarte
In diesem System laufen **alle** elektronischen Zahlungen über den PayPal-Checkout — es gibt
keine separate EC-/Kartenterminal-Anbindung (Zahlarten: `paypal`, `cash`, Reservierung/
Abendkasse/Gutschein, siehe `public/checkout.php`). Zahlt ein Kunde im PayPal-Checkout mit
Kreditkarte oder SEPA-Lastschrift (auch als Gast **ohne** PayPal-Konto), entsteht trotzdem
eine ganz normale Capture. Der Refund läuft über dieselbe `capture_id`, und **PayPal bucht
das Geld automatisch an die Originalquelle zurück** (Karte → Karte, Lastschrift → Konto,
Guthaben → Guthaben). Der Code muss die Zahlquelle also nicht unterscheiden. (Girocard/„EC"
ist bei PayPal in DE keine eigene Zahlquelle; physische EC-Terminal-Zahlungen laufen gar
nicht durch dieses System und könnten von PayPal nicht erstattet werden.)

### Kann man über die API sehen, wie der Kunde bezahlt hat?
Ja. Die Order-/Capture-Antwort enthält `payment_source` — bei Kartenzahlung (auch Gast ohne
PayPal-Konto) `payment_source.card` mit `brand` (VISA/Mastercard/…), `last_digits` (letzte 4)
und `type` (CREDIT/DEBIT), bei Wallet `payment_source.paypal` (Zahler-Name/E-Mail). Aktuell
wird in `captureOrder()` nur Betrag + `payer.email_address` ausgelesen — die Zahlart wird
verworfen. Über `getOrder(order_id)` ist sie auch nachträglich abrufbar. ⇒ Optionale
Erweiterung (siehe unten): Zahlart mitspeichern und im Bestelldetail anzeigen.

### Kern-Hürde
Für einen Refund braucht PayPal die **Capture-ID**. Die speichern wir heute **nirgends** —
die `reservations`-Tabelle hat keine PayPal-Felder (`database/schema.sql`, Zeilen 246–275);
Order-ID ↔ Reservierungen existiert nur in den Logs (`payment_completed` mit `order_id` +
`reservation_ids`). Das Feature besteht daher zu großen Teilen aus **Persistieren der
Order-/Capture-ID** + Refund-Aufruf + Storno-Ablauf.

## Entscheidungen (abgestimmt)

- **Berechtigung:** nur **Admin** (nicht Vorverkauf).
- **Umfang:** ganze Bestellung **und** einzelne Sitze (anteilige Rückerstattung).
- **Alt-Bestellungen:** Order-ID aus den **Logs nachladen** (Backfill), damit auch vor der
  Änderung bezahlte Bestellungen erstattbar sind.
- **Nach Refund:** Reservierung **löschen** (Platz sofort frei) — konsistent mit dem
  bestehenden „Löschen". Dauerhafter Nachweis in einer neuen **`refunds`-Historie**.
- **Kundenmail:** automatische Bestätigungs-Mail nach erfolgreicher Rückerstattung.

## Umsetzungsschritte

### 1. PayPalService: Refund-Methode
`app/services/PayPalService.php`
- `refundCapture(string $captureId, ?float $amount, string $currency, ?string $note): array`
  → `POST /v2/payments/captures/{captureId}/refund`. `$amount = null` ⇒ Voll-Refund
  (kein Body); sonst `{amount:{value,currency_code}, note_to_payer}`.
- Rückgabe: `['success'=>bool, 'refund_id'=>?string, 'status'=>?string, 'error'=>?string]`.
  PayPal-Fehler durchreichen (analog `createOrder`): `already refunded`,
  `refund window exceeded` (>180 Tage), `insufficient funds`.
- Helfer `getCaptureIdFromOrder(string $orderId): ?string` (nutzt bestehendes `getOrder()` →
  `purchase_units[0].payments.captures[0].id`).

### 2. Order-/Capture-ID persistieren (ab jetzt)
**Migration** `database/migrate_paypal_refunds.sql`:
- `reservations` um `paypal_order_id VARCHAR(255)`, `paypal_capture_id VARCHAR(255)`
  (+ optional `paypal_funding VARCHAR(64)`) erweitern, Index auf `paypal_order_id`.
- Neue Tabelle **`refunds`** (Muster wie `database/migrate_rebookings.sql`):
  `id, reservation_id, event_id, event_date, seat_identifier, customer_id, paypal_order_id,
  paypal_capture_id, refund_id, amount, currency, reason, channel, user_id, username,
  created_at`, `UNIQUE(reservation_id, created_at)`.
- Beides auch in `database/schema.sql` nachziehen.
- `Reservation::update()` (`app/models/Reservation.php`, ~Z. 415) um die neuen Felder ergänzen.

**Beim Capture speichern:** in `public/api/paypal_capture_order.php` (~Z. 163–177) und
`public/api/paypal_success.php` das `$updateData` je Reservierung um `paypal_order_id` und
`paypal_capture_id` (aus `captureResult[...]['captures'][0]['id']`) erweitern.

### 3. Refund-Model + Backfill (Muster: `app/models/Rebooking.php`)
Neu `app/models/Refund.php`:
- `record(...)` idempotent (`INSERT IGNORE` + UNIQUE).
- `getByEvent(int)` / `countsByEvent()` für eine spätere Übersicht.
- `backfillOrderIdsFromLogs(string $dir, bool $dryRun): array` — scannt `payment_completed`
  mit `payment_method=paypal`, mappt jede `reservation_ids[i]` → `order_id` und schreibt
  `paypal_order_id` in noch leere `reservations`-Zeilen.
- CLI-Wrapper `app/scripts/backfill_paypal_orders.php`.
- `capture_id` für Alt-Fälle lazy über `getCaptureIdFromOrder(order_id)`.

### 4. Admin-API-Endpunkt
Neu `public/api/refund_paypal.php` (POST JSON `{reservation_ids:[...], reason?}`),
`Auth::requireAdmin()` + CSRF-Header:
1. Reservierungen laden; prüfen: alle Status 2, gleiche Bestellgruppe (gleiche
   `paypal_order_id`/`customer_id`/`event`).
2. `getOrder(orderId)` → `capture_id`, gezahlter Betrag, bereits erstattete Beträge →
   `remaining = captured − sum(refunds)`.
3. Betrag der gewählten Karten aus bestehender Preis-Logik (`public/api/order_details.php`,
   Z. 46–56: Begleitperson 0 €, ermäßigt 50 %). `refundAmount = min(summeGewählt, remaining)`.
   Bei `remaining <= 0` → Fehler „bereits vollständig erstattet".
4. `PayPalService::refundCapture(...)`. Bei Erfolg: je Reservierung `Refund::record(...)`,
   dann Reservierung **löschen** (`Reservation::delete`) → Platz frei.
5. Kundenmail (siehe 6) + Logging `admin_refund` via `app/services/LoggerService.php`.
6. JSON-Antwort mit Betrag/refund_id oder klarer Fehlermeldung.

### 5. Admin-UI (Bestelldetail-Modal)
`public/admin/reservations.php`:
- Button **„Stornieren & Rückerstatten"** nur sichtbar, wenn mind. eine Karte Status 2 ist.
- Auswahl-Schritt (Checkbox je Status-2-Karte, „alle" vorausgewählt) — Muster wie der
  vorhandene **Umbuchen**-Flow (`startRebook`) — mit Betragsvorschau + Bestätigung.
- Ruft `api/refund_paypal.php`; bei Erfolg Feedback + Liste neu laden; Fehler klar anzeigen.
- **Kein** Refund-Button im Vorverkauf.

### 6. Kundenmail
`app/services/EmailService.php`: `sendRefundEmail($toEmail,$toName,$reservations,$amount)`
(Muster `sendReservationEmail`, inkl. `logEmail('refund', …)`), Templates optional über
`configurations` (`email_refund_subject/body`) mit Platzhaltern
`{customer_name} {event_name} {event_date} {amount} {seat_list}`. Default-Text im Code.

### 7. Optional: Zahlart erfassen & anzeigen
Beim Capture zusätzlich `payment_source` auswerten und kompakt in `reservations.paypal_funding`
speichern (z. B. `card:VISA:4242` oder `paypal`). Im Bestelldetail-Modal lesbar anzeigen
(„Kreditkarte VISA ••4242" / „PayPal-Guthaben"). Für den Refund nicht nötig, aber praktisch
für den Support.

### 8. Optional: Storno-Übersicht
Admin-Report `public/admin/reports_refunds.php` analog `public/admin/reports_rebookings.php`,
liest aus `refunds`.

## Randbedingungen / Fallstricke

- **180-Tage-Frist:** Refund über die API nur bis ~180 Tage nach Capture; danach PayPal-Fehler
  → klare Meldung (Alt-Fälle im PayPal-Konto manuell).
- **Gebühren:** PayPal behält je nach Konto/Region die feste Transaktionsgebühr bei
  Rückerstattung — kleiner Verlust pro Storno (nicht code-seitig änderbar).
- **Gutschein-Rabatt:** Bei Teil-Refund kann die Summe der Ticketpreise vom anteiligen
  Capture-Betrag abweichen. Deshalb wird auf `remaining` (siehe 4.2) gedeckelt.
- **Doppelklick-/Race-Schutz:** vor Refund erneut Status 2 prüfen; nach Refund sofort löschen.
- **Sandbox zuerst:** kompletter Ablauf im PayPal-Sandbox testen, bevor live.

## Betroffene / neue Dateien

- **Ändern:** `app/services/PayPalService.php`, `public/api/paypal_capture_order.php`,
  `public/api/paypal_success.php`, `public/admin/reservations.php`,
  `app/services/EmailService.php`, `app/services/LoggerService.php`,
  `app/models/Reservation.php`, `database/schema.sql`.
- **Neu:** `public/api/refund_paypal.php`, `app/models/Refund.php`,
  `database/migrate_paypal_refunds.sql`, `app/scripts/backfill_paypal_orders.php`,
  optional `public/admin/reports_refunds.php`.
- **Doku:** `docs/CHANGELOG.md`, `docs/ADMIN_HANDBUCH.md`.

## Verifikation (bei Umsetzung)

1. Migration einspielen, Spalten/Tabelle prüfen.
2. Sandbox-Kauf → `paypal_order_id` + `paypal_capture_id` in der `reservations`-Zeile.
3. Backfill (dry-run) auf `__debug`-Logs → Order-IDs erkannt/gemappt.
4. Voll-Refund über Admin-Modal → Sandbox zeigt Rückerstattung, Reservierung gelöscht/Platz
   frei, `refunds`-Eintrag, Kundenmail geloggt, `admin_refund` geloggt.
5. Teil-Refund (1 von 2 Sitzen) → nur gewählte Karte gelöscht, korrekter anteiliger Betrag.
6. Fehlerfälle: erneuter Refund → „bereits erstattet"; alte Order → „Frist überschritten".
7. `php vendor/bin/phpunit` grün; PHP-Syntaxcheck; Unit-Test für die Betragsberechnung.
