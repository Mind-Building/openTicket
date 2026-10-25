# Vorverkauf-Handbuch – Ticket System 2.0

Dieses Handbuch beschreibt alle Funktionen des Vorverkauf-Bereichs für das Verkaufspersonal.

---

## Inhaltsverzeichnis

1. [Login & Geräte-Anmeldung](#1-login--geräte-anmeldung)
2. [Startseite](#2-startseite)
3. [Tickets & Reservierungen verkaufen](#3-tickets--reservierungen-verkaufen)
4. [Reservierungen verwalten](#4-reservierungen-verwalten)
5. [Sitzplan-Belegung](#5-sitzplan-belegung)
6. [Kunden](#6-kunden)
7. [Kasse (Abendkasse)](#7-kasse-abendkasse)
8. [Gutscheine](#8-gutscheine)
9. [Dokumentation](#9-dokumentation)

---

## 1. Login & Geräte-Anmeldung

### Standard-Login

Öffnen Sie die Vorverkauf-Seite:
```
https://ihre-domain.de/presales/index.php
```

Melden Sie sich mit Ihrem Benutzernamen und Passwort an.

### Geräte-Token (Tablet / POS-Terminal)

Für fest installierte Kassensysteme oder Tablets kann ein Admin einen **Geräte-Token** erstellen. Sie erhalten dabei eine Aktivierungs-URL, die Sie einmalig auf dem Gerät aufrufen. Das Gerät ist danach dauerhaft angemeldet, bis der Token widerrufen wird.

> **Hinweis:** Der Admin erstellt und verwaltet Geräte-Token unter **Benutzer → Benutzer bearbeiten → Geräte-Token**.

---

## 2. Startseite

Nach dem Login sehen Sie die Vorverkauf-Startseite mit Schnellzugriff auf:

- **Tickets / Reservierungen verkaufen**
- **Reservierungen verwalten**
- **Sitzplan-Belegung**
- **Kasse** (Abendkasse)
- **Gutscheine** (falls aktiviert)
- **Kunden suchen**
- **Dokumentation**

---

## 3. Tickets & Reservierungen verkaufen

Der Verkaufsassistent führt Sie in mehreren Schritten durch den Verkaufsprozess.

### Schritt 1 – Veranstaltung auswählen

Wählen Sie aus der Liste der verfügbaren Veranstaltungen die gewünschte aus.

> Nur aktive Veranstaltungen werden angezeigt.

### Schritt 2 – Termin auswählen

Wählen Sie das Aufführungsdatum. Es werden nur buchbare Termine angezeigt (keine vergangenen Termine, keine Termine nach der Buchungsschlusszeit).

### Schritt 3 – Plätze wählen (mit Sitzplan)

Beim Öffnen des Sitzplans werden automatisch alle abgelaufenen Warenkörbe anderer Nutzer bereinigt, sodass die Platzbelegung stets aktuell ist.

> **Hinweis:** Ein Warenkorb gilt nach **20 Minuten ohne Aktivität** als abgelaufen. Die Frist startet neu, solange der Kunde am Bezahlen ist — ein als *Im Warenkorb* angezeigter Platz kann also durchaus länger belegt bleiben, wenn der Kunde gerade im Checkout oder bei PayPal ist. Umgekehrt gilt: Was der Sitzplan als belegt anzeigt, ist auch tatsächlich nicht buchbar.

Bei Veranstaltungen **mit Sitzplan** öffnet sich die interaktive Sitzplanansicht:

> **Größe des Sitzplans:** Die Plätze passen sich automatisch der Fensterbreite an. Über **Größe − / +** oberhalb des Plans lässt sich der Plan verkleinern (bis der ganze Saal ohne seitliches Scrollen sichtbar ist) oder vergrößern (z. B. auf dem Tablet). Ein Klick auf die Prozentzahl stellt wieder 100 % ein. Die Einstellung merkt sich der Browser – sie gilt auch in der Sitzplan-Belegung.

| Farbe | Bedeutung |
|-------|-----------|
| Grün | Freier Platz – klicken zum Auswählen |
| Rot | Bereits belegt |
| Orange (markiert) | Aktuell ausgewählt |

- Klicken auf einen freien Platz → Platz wird ausgewählt (orange)
- Nochmaliges Klicken → Platz wird wieder freigegeben
- Kategorien und Preise sind farblich in der Legende unterhalb des Plans dargestellt
- **Ermäßigung / FN-Card:** Wird **nicht** in der Platzauswahl gewählt, sondern im nächsten Schritt unter **Plätze & Preise** pro Platz (ein Hinweis in der Seitenleiste „Ausgewählte Plätze" erinnert daran). Angeboten wird nur, was für Veranstaltung, Termin und Kategorie erlaubt ist; bei Generalproben gilt der Generalprobenpreis, Begleitpersonen von Rollstuhlplätzen sind kostenfrei.

Bei Veranstaltungen **ohne Sitzplan** erscheint statt des Sitzplans eine Mengen-Auswahl:

- **Anzahl Tickets** – wie viele Tickets verkauft/reserviert werden sollen. Unter dem Feld wird die noch verfügbare Anzahl angezeigt (z. B. „Verfügbar: 42 von 100").
- **davon ermäßigt** – nur sichtbar, wenn für die Veranstaltung Ermäßigungen aktiviert sind. Geben Sie an, wie viele der eingegebenen Tickets zum ermäßigten Preis verkauft werden.
- Kategorie und Preis pro Ticket werden oberhalb angezeigt. Ist die Veranstaltung ausverkauft, erscheint ein entsprechender Hinweis.

Die rechte Seitenleiste „Ausgewählte Plätze" listet die Tickets (Ticket 1, Ticket 2, …) inklusive Gesamtpreis. Mit **Weiter** gelangen Sie wie gewohnt zur Auswahl der Bestellart.

### Schritt 4 – Zahlungsart & Sonderpreise

Je nach Systemkonfiguration stehen folgende Optionen zur Verfügung:

| Option | Bedeutung |
|--------|-----------|
| PayPal | Kunde zahlt online via PayPal |
| Reservierung | Plätze reservieren ohne sofortige Zahlung |
| Barzahlung | Direktverkauf gegen Bargeld |
| Gutschein | Bezahlung mit Gutschein-Code |
| Ermäßigung / FN-Card | Oben unter **Plätze & Preise** pro Platz wählbar (Normal / Ermäßigt / FN-Card) mit Live-Gesamtsumme. Bei *FN-Card* erscheint ein Feld für die **8-stellige Kartennummer** (Pflicht); pro Karte sind je Veranstaltung nur so viele Tickets möglich wie eingestellt (Standard 2), sonst erscheint ein Hinweis. Ist keine Ermäßigung möglich, steht dort der Grund (z. B. „in den Einstellungen deaktiviert", „für diese Veranstaltung nicht freigegeben" oder „Kategorie ohne Ermäßigungspreis"). |
| FN-Card | Rabatt für FN-Card-Inhaber |

> Welche Optionen verfügbar sind, legt der Admin in den Einstellungen fest.

### Schritt 5 – Kundendaten eingeben

Suchen Sie nach einem vorhandenen Kunden **oder** legen Sie einen neuen an:

**Felder:**
- Anrede, Vorname, Nachname
- E-Mail-Adresse (für Bestätigungsmail)
- Telefon, Adresse (optional)

> Wenn Sie eine E-Mail-Adresse eingeben, wird automatisch eine Bestätigungsmail mit den gebuchten Plätzen verschickt. Diese Mail enthält auch einen **Sitzplan-Link**, über den der Kunde seinen Platz im Saalgrundriss sehen kann.

### Schritt 6 – Abschluss

Nach Bestätigung aller Angaben:
- Die Plätze werden sofort reserviert / gebucht
- Eine Bestätigungsseite zeigt alle Reservierungsdetails
- Optional: PDF-Tickets können ausgedruckt oder per Mail versandt werden

**Bestätigungsmail-Status:** Die Abschluss-Seite zeigt an, ob die Bestätigungsmail an den Kunden versendet wurde (grüne Box) oder ob der Versand fehlgeschlagen ist (gelbe Warnbox). Bei einem Fehlschlag ist die Bestellung trotzdem gespeichert — über den Button **„E-Mail erneut senden"** kann der Versand direkt wiederholt werden (max. 5 Versuche, mind. 30 Sekunden Abstand). Jeder Versuch wird im System-Log protokolliert. Schlägt der Versand dauerhaft fehl, informieren Sie den Administrator (mögliche Störung des Mailservers).

---

## 4. Reservierungen verwalten

### 4.1 Übersicht

Die Reservierungsliste zeigt alle Buchungen mit:
- Kundenname und Kontakt
- Veranstaltung, Termin und Sitzplatz
- Status der Reservierung
- Buchungszeitpunkt

> **Hinweis:** Solange Karten nur im Warenkorb liegen, sind noch keine Kundendaten erfasst — in der Spalte „Kunde" steht dann ein Strich (`-`), nicht „Barverkauf". „Barverkauf" erscheint nur bei tatsächlich getätigten Verkäufen ohne hinterlegten Kunden.

**Standardmäßig werden nur Bestellungen zu aktiven Veranstaltungen angezeigt.** Vergangene Veranstaltungen werden automatisch deaktiviert, ihre Bestellungen tauchen also nicht mehr in der täglichen Arbeitsliste auf. Ein Hinweis über der Liste weist darauf hin und verlinkt auf die vollständige Ansicht.

### 4.2 Filtern

Filtern Sie nach Veranstaltung, Termin und Status, um schnell die gewünschten Reservierungen zu finden.

Zusätzlich gibt es den Filter **Zeitraum**:

| Auswahl | Anzeige |
|---------|---------|
| **Nur aktive Veranstaltungen** (Standard) | Nur Bestellungen laufender Veranstaltungen |
| **Alle (inkl. beendete)** | Auch Bestellungen bereits vergangener Veranstaltungen |

Wählen Sie gezielt eine **bestimmte Veranstaltung** aus, werden deren Bestellungen immer angezeigt — auch wenn die Veranstaltung bereits beendet ist. Inaktive Veranstaltungen sind in der Auswahlliste mit „(inaktiv)" gekennzeichnet.

### 4.3 Bestelldetails & Aktionen

Klick auf eine Bestellung öffnet die Detail-Ansicht. Sie listet pro Karte den **Preis** und weist unten die **Endsumme** aus. Bei Rollstuhl-Buchungen erscheint die **Begleitperson** mit `0,00 € (B)` und dem Hinweis „Begleitperson kostenfrei" — es zahlt nur der Rollstuhlplatz (und alle übrigen Plätze).

Verfügbare Aktionen:

- **Sitzplan anzeigen** — öffnet den Sitzplan mit dieser Reservierung markiert
- **Tickets herunterladen** — PDF mit allen Tickets der Bestellung
- **Plätze hinzufügen** — siehe [4.4](#44-plätze-hinzufügen)
- **Umbuchen** — siehe [4.5](#45-umbuchen)
- **Mail erneut senden** — Bestätigungs- bzw. Ticket-Mail erneut an den Kunden schicken (nur wenn eine E-Mail-Adresse hinterlegt ist und der Status zum E-Mail-Typ passt)
- **Bezahlt setzen** — markiert reservierte Plätze als bezahlt
- **Einzelne Karte löschen** — In der Karten-Tabelle der Detail-Ansicht hat jede **reservierte** Karte ein eigenes Papierkorb-Symbol. Ein Klick storniert genau diese eine Karte und gibt den Platz frei; die Endsumme aktualisiert sich sofort. War es die letzte Karte der Bestellung, wird die Liste neu geladen. (Bereits bezahlte/abgeholte Karten haben kein Lösch-Symbol.)
- **Erneut drucken** — Bei abgeschlossenen Bestellungen (bezahlt/abgeholt, VIP, FN-Karte, eingelöste Gutscheine) sowie **an der Abendkasse gedruckten** Karten steht der Button **Karten erneut drucken (Vorverkauf-Template)** zur Verfügung.

### 4.4 Plätze hinzufügen

Über den Button **Plätze hinzufügen** können einer offenen Reservierung weitere Plätze ergänzt werden. Die neuen Plätze werden derselben Bestellung zugeordnet (gleicher Kunde, gleiches Event/Datum, gleicher Status).

- Nur verfügbar für **offene Reservierungen** (Status *Reserviert (Vorverkauf)* oder *Reserviert (Online)*) — bezahlte Tickets können nicht erweitert werden
- VIP-Karten können nur im Admin-Bereich erweitert werden
- Optional kann beim Bestätigen eine aktualisierte Reservierungs-Mail an den Kunden gesendet werden

### 4.5 Umbuchen

Über **Umbuchen** können Plätze einer Reservierung auf andere Plätze und/oder ein anderes Datum verschoben werden. Voraussetzung: identische Kategorie, gleiche Anzahl Plätze.

Im Umbuchen-Dialog gibt es das Häkchen **„Kunde per E-Mail benachrichtigen"** (standardmäßig aktiv). Ist es gesetzt, erhält der Kunde nach der Umbuchung automatisch eine neue Ticket-/Bestätigungs-E-Mail mit den aktualisierten Daten (der alte Ausdruck ist damit hinfällig). Kunden ohne hinterlegte E-Mail werden übersprungen.

> **Hinweis:** Wird auf ein **anderes Datum** umgebucht, wird die Bestellung in zwei Teile aufgeteilt — die umgebuchten Plätze erscheinen als eigene Bestellung beim neuen Datum. Beide Teile sind über den **Kundennamen** in der Reservierungs-Übersicht auffindbar. Innerhalb desselben Datums bleibt die Bestellung als Einheit erhalten.

### 4.6 Status-Bedeutungen

| Status | Bedeutung |
|--------|-----------|
| Im Warenkorb | Im Bezahlvorgang, noch nicht abgeschlossen |
| Reserviert (Vorverkauf) | Über das Vorverkauf-Interface reserviert (unbezahlt) |
| Reserviert (Online) | Vom Kunden online reserviert (unbezahlt) |
| Bezahlt (PayPal) | Online-Zahlung über PayPal abgeschlossen |
| Barzahlung | An der Kasse bzw. im Vorverkauf bar bezahlt |
| Bezahlt (Gutschein) | Mit Gutscheincode bezahlt |
| Bezahlt & Abgeholt | Reservierung an der Abendkasse bezahlt und abgeholt |
| Gedruckt (Abendkasse) | An der Abendkasse als Ticket gedruckt |
| VIP | VIP-Karte (kostenlos, vom Admin ausgestellt) |
| FN-Karte | FN-Card-Vergünstigung |
| PayPal gestartet | PayPal-Vorgang läuft (Zwischenzustand) |
| FEHLER | Technischer Fehler – bitte Admin informieren |

---

## 5. Sitzplan-Belegung

Eigener Menüpunkt **Sitzplan-Belegung**. Zeigt für die ausgewählte Veranstaltung und einen gewählten Termin den vollständigen Sitzplan inkl. Belegungsstatus pro Platz (Im Warenkorb, Reserviert, Bezahlt, Abgeholt, VIP, …).

Klick auf einen belegten Platz öffnet die Reservierungs-Details. Praktisch, um einen schnellen Überblick zu bekommen, ohne durch die Reservierungsliste zu scrollen.

Oben rechts im Belegungs-Panel gibt es zwei Buttons **„Als PDF drucken (Farbe)"** und **„(S/W)"**. Sie erzeugen ein A4-PDF (Querformat, auf eine Seite skaliert) im **gleichen Stil wie der Abendkasse-Sitzplan** — mit Titel, „BÜHNE"-Balken, Frei/Belegt-Darstellung und Zusammenfassung. Die Schwarz-Weiß-Variante spart Farbtoner.

---

## 6. Kunden

### Kunden suchen

Über das Suchfeld finden Sie Kunden nach:
- Vorname / Nachname
- E-Mail-Adresse
- Stadt

### Kunden anlegen

Neue Kunden können direkt im Verkaufsprozess (Schritt 5) angelegt werden. Eine separate Kunden-Anlage ist im Vorverkauf-Bereich nicht vorgesehen.

> Kunden **löschen** ist dem Admin vorbehalten.

---

## 7. Kasse (Abendkasse)

Die Kassen-Funktion ist für den Einsatz direkt am Veranstaltungsabend gedacht.

### Schritt 1 – Veranstaltung & Termin wählen

Wählen Sie die laufende Veranstaltung und den Termin des Abends.

> **Sitzplan freier Plätze drucken:** Schon in der Auswahl stehen — wie im Admin — zwei Buttons **„Sitzplan freier Plätze (Farbe)"** und **„(Schwarz-Weiß)"** bereit. Damit lässt sich der aktuelle Plan der freien Plätze als A4-PDF drucken, ganz ohne Reservierungen umzuwandeln. Nach dem Starten der Abendkasse stehen dieselben beiden Varianten erneut zur Verfügung.

### Schritt 2 – Freie Plätze prüfen

Sie sehen alle freien Plätze im Sitzplan (grün). Bereits belegte Plätze sind rot.

### Schritt 3 – PIN eingeben & bestätigen

Zur Sicherheit ist die Kassen-Funktion durch einen **PIN** geschützt (vom Admin konfiguriert). Geben Sie den PIN ein, um die Kassenansicht freizuschalten.

### Was passiert dann?

Freie Plätze werden als „Abendkasse" markiert. Sie erhalten eine druckbare Sitzliste (PDF) mit allen noch verfügbaren Plätzen für die manuelle Vergabe an der Abendkasse.

---

## 8. Gutscheine

> Dieser Bereich ist nur sichtbar, wenn Gutscheine im System aktiviert sind.

### 8.1 Gutschein-Liste

Zeigt alle Gutscheine mit Status und Suchfunktion. Sie können:
- Gutschein suchen
- Status einsehen
- E-Mail mit Gutscheincode erneut versenden

### 8.2 Gutscheine erstellen

Erstellt **einen** Gutscheincode, der für die angegebene Anzahl an Tickets gilt — nicht je ein Code pro Stück.

1. **Veranstaltung** auswählen
2. **Kategorie** auswählen (bestimmt den Wert pro Ticket)
3. **Anzahl** eingeben — wie viele Tickets dieser Code abdeckt
4. Optional: Kunden zuordnen und E-Mail mit Gutscheincode versenden

Der Wert des Gutscheins entspricht dem Kategoriepreis × Anzahl. Beim Einlösen kann der Kunde bis zu dieser Anzahl Tickets damit bezahlen.

Jeder Gutschein erhält automatisch einen eindeutigen Code.

---

## 9. Dokumentation

Eigener Menüpunkt **Dokumentation** in der Sidebar. Hier ist das Vorverkauf-Handbuch als PDF zum Anzeigen oder Herunterladen hinterlegt.

---

## Tipps für den täglichen Einsatz

### Schnell einen Stammkunden finden

Im Schritt „Kundendaten" des Verkaufsassistenten können Sie nach Name oder E-Mail suchen. Wenn der Kunde schon im System ist, werden seine Daten automatisch übernommen.

### Fehler bei der Reservierung

Wurde versehentlich der falsche Platz ausgewählt, können Sie im Schritt „Plätze wählen" einfach nochmals auf den Platz klicken, um die Auswahl aufzuheben.

### Kein Sitzplan sichtbar?

Prüfen Sie, ob:
- Die richtige Veranstaltung ausgewählt ist
- Für diese Veranstaltung ein Sitzplan im Admin-Bereich angelegt wurde
- Der Termin noch buchbar ist (Buchungsschluss nicht überschritten)

### E-Mail wird nicht versandt

E-Mail-Versand funktioniert nur, wenn:
- Eine gültige E-Mail-Adresse des Kunden eingetragen ist
- Der E-Mail-Versand in den Admin-Einstellungen aktiviert ist

### Bestellung scheinbar geteilt

Wenn eine Bestellung in der Übersicht plötzlich als zwei separate Einträge erscheint, wurde wahrscheinlich auf ein anderes Datum umgebucht — siehe [4.5](#45-umbuchen). Beide Teile sind über den Kundennamen findbar.

---

*Letzte Aktualisierung: 2026-04-28 | Version 2.1*
