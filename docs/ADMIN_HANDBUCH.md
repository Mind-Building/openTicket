# Admin-Handbuch – Ticket System 2.0

Dieses Handbuch beschreibt alle Funktionen des Admin-Bereichs.

---

## Inhaltsverzeichnis

1. [Login & Zugang](#1-login--zugang)
2. [Dashboard](#2-dashboard)
3. [Veranstaltungen](#3-veranstaltungen)
4. [Sitzplan](#4-sitzplan)
5. [Reservierungen](#5-reservierungen)
6. [Kunden](#6-kunden)
7. [Gutscheine](#7-gutscheine)
8. [VIP-Tickets](#8-vip-tickets)
9. [Zahlungsanforderungen](#9-zahlungsanforderungen)
10. [Kasse](#10-kasse)
11. [Berichte](#11-berichte)
12. [System-Logs](#12-system-logs)
13. [Benutzer](#13-benutzer)
14. [Einstellungen](#14-einstellungen)
15. [E-Mail-Vorlagen & Platzhalter](#15-e-mail-vorlagen--platzhalter)
16. [Dokumentation](#16-dokumentation)

---

## 1. Login & Zugang

Öffnen Sie die Admin-Seite über:
```
https://ihre-domain.de/admin/index.php
```

Melden Sie sich mit Ihrem Benutzernamen und Passwort an. Als Admin haben Sie Zugriff auf alle Bereiche des Systems.

> **Sicherheit:** Ändern Sie das Standard-Passwort nach dem ersten Login unter **Einstellungen → Mein Konto**.

---

## 2. Dashboard

Das Dashboard zeigt eine Übersicht über:

| Kachel | Inhalt |
|--------|--------|
| Veranstaltungen | Gesamtanzahl der angelegten Veranstaltungen |
| Reservierungen | Gesamtanzahl der Reservierungen (alle Status) |
| Kunden | Anzahl der registrierten Kunden |
| Gutscheine | Anzahl der ausgestellten Gutscheine |

Von hier gelangen Sie schnell zu allen Hauptbereichen.

---

## 3. Veranstaltungen

### 3.1 Veranstaltungsliste

Unter **Veranstaltungen** sehen Sie alle angelegten Veranstaltungen mit:
- Name der Veranstaltung
- Status (aktiv / inaktiv)
- Ticket-Verkauf aktiv (Ja/Nein)
- Gutschein-Verkauf aktiv (Ja/Nein)
- Sitzplan vorhanden (Ja/Nein)

**Aktionen pro Veranstaltung:**
- Bearbeiten
- Termine verwalten
- Zur Kasse

#### Vergangene Veranstaltungen („Vorüber")

Eine Veranstaltung gilt als **vorüber**, sobald sie mindestens einen Termin hat und **alle Termine vor dem heutigen Tag** liegen. Am Veranstaltungstag selbst bleibt sie buchbar – dort greift die Bestellfrist (*Max. Reservierungszeit*).

- In der Liste erscheint dann das Kennzeichen **„Vorüber"**.
- Im **Kundenbereich** ist die Veranstaltung gesperrt: Sie wird nicht mehr angezeigt, und weder Tickets noch **Gutscheine** können gekauft werden. Gutscheine, die noch im Warenkorb liegen, werden beim Aufruf der Kasse entfernt.
- Der **Cron-Job** (derselbe, der abgelaufene Warenkörbe aufräumt) setzt solche Veranstaltungen automatisch auf **inaktiv** und schreibt einen Logeintrag `event_auto_deactivated`.
- Im **Vorverkauf und Admin** bleiben vergangene Veranstaltungen weiterhin nutzbar (dort werden auch inaktive Veranstaltungen angezeigt), damit Nachbearbeitung und Auswertungen möglich bleiben.
- Veranstaltungen **ohne Termine** (z. B. reine Gutschein-Veranstaltungen) gelten nie als vorüber.
- Wird ein **neuer, zukünftiger Termin** angelegt, gilt die Veranstaltung wieder als laufend; sie muss dann nur noch auf *Aktiv* gesetzt werden.

### 3.2 Neue Veranstaltung erstellen

Klicken Sie auf **Neue Veranstaltung**. Füllen Sie folgende Felder aus:

| Feld | Bedeutung |
|------|-----------|
| Name | Name der Veranstaltung (Pflichtfeld) |
| Aktiv | Veranstaltung für Kunden sichtbar |
| Ticket-Verkauf | Online-Ticketkauf durch Kunden möglich |
| Gutschein-Verkauf | Gutscheinkauf möglich |
| Ermäßigung erlaubt | Ermäßigte Preise können gewählt werden |
| FN-Card | FN-Card-Rabatt aktivieren |
| Mit Sitzplan | Reservierung erfolgt über Sitzplan |
| Max. Sitzplatzzahl | Nur ohne Sitzplan: Gesamtkapazität |
| Kategorie | Nur ohne Sitzplan: Standard-Preiskategorie |

### 3.3 Veranstaltung bearbeiten

Hier können alle Einstellungen der Veranstaltung geändert werden. Zusätzlich stehen veranstaltungsspezifische **E-Mail-Vorlagen** zur Verfügung (Betreff und Text für Ticket- und Gutschein-E-Mails), die die globalen Vorlagen aus den Einstellungen überschreiben.

→ Siehe [Abschnitt 15](#15-e-mail-vorlagen--platzhalter) für alle verfügbaren Platzhalter.

**Als Kunde testen:** Der Button öffnet die Veranstaltung in der Kundenansicht (neuer Tab). Als angemeldeter Admin funktioniert das auch, wenn die Veranstaltung **nicht aktiv** ist – so lässt sich der komplette Kauf vor der Freischaltung durchspielen. Oben erscheint dann der Hinweis „Admin-Vorschau". Achtung: Buchungen sind echte Bestellungen – mit PayPal-Sandbox testen und die Testbestellungen danach unter *Reservierungen* löschen.

### 3.4 Ticket-Templates (Kunde & Vorverkauf)

Pro Veranstaltung können **zwei** separate Ticket-Templates gepflegt werden. Beide sind unter *Veranstaltung bearbeiten* als eigene Buttons erreichbar:

| Template | Verwendet für |
|----------|---------------|
| **Ticket-Template verwalten** | Online-Kunden (PDF-Download / E-Mail-Anhang) und Admin-Downloads |
| **Vorverkaufs-Template verwalten** | Ausdrucke im Vorverkauf (Kasse) |

In beiden Editoren können eine PDF-Vorlage hochgeladen und Platzhalter-Positionen (X/Y in mm, Schriftgröße, Farbe, Ausrichtung) gepflegt werden. Die Farbe lässt sich per **Farbwähler** oder über die RGB-Werte einstellen.

Die **Live-Vorschau** verwendet echte Daten der Veranstaltung: Datum und Uhrzeit des nächsten anstehenden Termins (sind alle vorbei, des letzten; ohne Termine das heutige Datum mit der allgemeinen Startzeit) sowie die erste Kategorie mit ihrem Preis – hat sie einen Ermäßigungspreis, wird dieser samt Preisart „Ermäßigt" gezeigt. Kundenname, Sitz und Ticketnummer sind Beispielwerte.

**Platzhalter für die Alternativbelegung** (Ausweichspielstätte, siehe Abschnitt 4.1): `{alt_seat_identifier}` (z. B. „Reihe 2, Platz 7"), `{alt_row}`, `{alt_seat}`, `{alt_location}` (Name des alternativen Veranstaltungsortes) und `{alt_full}` (Ort + Reihe + Platz). Hat ein Sitz keine Alternativbelegung, bleiben diese Platzhalter leer und es wird nichts gedruckt.

**Preis und Preisart:** `{price}` druckt den tatsächlich bezahlten Ticketpreis — Ermäßigungspreis der Kategorie, Generalprobenpreis bzw. FN-Card-Rabatt sind darin berücksichtigt (gleiche Berechnung wie bei PayPal und in der Bestätigungsmail). `{price_type}` druckt die Preisart: **Ermäßigt**, **FN-Card**, **Generalprobe** oder **Begleitperson**; beim Normalpreis bleibt das Feld leer. So ist z. B. beim Einlass erkennbar, dass ein Ermäßigungsnachweis vorzuzeigen ist. `{fn_card_number}` druckt bei FN-Card-Tickets die Kartennummer (sonst leer).

**FN-Card:** Die 8-stellige Kartennummer wird bei jedem FN-Card-Ticket gespeichert – beim Online-Kauf über die Nummernprüfung im Warenkorb oder an der Kasse (ohne bestätigte Nummer lässt sich nicht mit PayPal bezahlen), im Admin-/Vorverkaufs-Verkauf über das Feld, das bei Preisart *FN-Card* erscheint. Pro Kartennummer sind je Veranstaltung höchstens so viele Tickets möglich wie unter *Einstellungen → Funktionen* eingestellt; darüber wird der Kauf mit Hinweis abgelehnt. In den Bestelldetails steht die Nummer in der Spalte *Preisart* (z. B. „FN-Card 12345678"). **Migration erforderlich:** `database/migrate_fn_card_number.sql`.

#### QR-Code / Strichcode (`{ticket_code}`)

Pro Veranstaltung kann auf dem Ticket **entweder ein QR-Code oder ein Strichcode (Code 128)** gedruckt werden.

1. **Art und Inhalt** unter *Veranstaltung bearbeiten → Ticket-Code*: Art wählen (*Kein Code*, *QR-Code*, *Strichcode*) und den Inhalt als Muster eingeben – freier Text plus Platzhalter, die per Klick eingefügt werden:

   | Platzhalter | Inhalt |
   |---|---|
   | `{ticket_number}` | Ticketnummer, z. B. `T-00001234` (Standard, wenn das Feld leer bleibt) |
   | `{reservation_id}` | Reservierungs-ID, z. B. `1234` |
   | `{event_id}` | ID der Veranstaltung |
   | `{event_date}` | Termin, z. B. `2026-10-02` |
   | `{row}` / `{seat}` | Reihe (bei Tischplätzen: Tisch) und Platz |
   | `{seat_identifier}` | Interne Platz-ID, z. B. `seat_5_12` |
   | `{check}` | 8-stelliger Prüfcode, nur mit dem App-Secret erzeugbar – erschwert gefälschte Tickets |

   Beispiel: `FREISCHUETZ-{ticket_number}-{check}`. Unter dem Feld wird eine Beispielausgabe angezeigt. Art und Inhalt gelten für Kunden- **und** Vorverkaufs-Tickets, damit ein Einlass-Scanner immer dasselbe liest.
2. **Position und Größe** im Ticket- bzw. Vorverkaufs-Template: Platzhalter *QR-Code / Strichcode* hinzufügen, X/Y = linke obere Ecke, **Breite/Höhe** in mm. Ein QR-Code ist quadratisch (nur die Breite zählt); beim Strichcode wird der Inhalt als Text darunter gedruckt, die Schriftgröße gilt für diesen Text. Soll der Text fehlen, stattdessen den Platzhalter *Strichcode ohne Text darunter* (`{ticket_code_notext}`) verwenden. Die Breite ist fest: Längere Inhalte ergeben dünnere Striche – für `{ticket_number}` mind. ca. 30 mm Breite wählen. Ist in der Veranstaltung noch kein Code gewählt, zeigt die Vorschau zum Positionieren einen Beispiel-QR-Code.

> Ein Strichcode wird umso breiter, je länger sein Inhalt ist – dafür möglichst kurze Inhalte wie `{ticket_number}` verwenden. Umlaute werden beim Strichcode umgeschrieben (ü → ue), da Code 128 nur einfache Zeichen kennt.
>
> **Migration erforderlich:** `database/migrate_ticket_code.sql`. Bis dahin zeigt *Veranstaltung bearbeiten* einen Hinweis statt der Einstellungen.

**Vorverkaufs-Template** – Besonderheiten:
- Falls noch kein eigenes Vorverkaufs-Template hinterlegt ist, greift automatisch das Kundentemplate (Fallback). Bestehende Installationen funktionieren dadurch ohne Anpassung weiter.
- Button **„Vom Kundentemplate kopieren"**: Übernimmt die PDF-Datei und die Platzhalter-Konfiguration als Startpunkt. Anschließend können Koordinaten und Datei für den Vorverkaufs-Drucker angepasst werden.
- Button **„Template leeren"**: Entfernt das Vorverkaufs-Template komplett und aktiviert den Fallback auf das Kundentemplate.

**Druckmodus** (nur im Vorverkaufs-Editor) – legt pro Veranstaltung fest, wie der Vorverkauf druckt:

| Modus | Verhalten |
|-------|-----------|
| **Template drucken** | Vollständiges PDF: Hintergrund (PDF) + Platzhalter. Standard. |
| **Template deaktiviert** | Nur Platzhalter auf einer leeren Seite in der **Seitengröße des hochgeladenen PDFs** – ideal, wenn der Drucker mit **vorgedrucktem Ticket-Papier** derselben Maße bestückt ist. |
| **Freie Fläche** | Nur Platzhalter auf einer leeren Seite mit frei wählbarer **Breite und Höhe (mm)** – kein PDF-Template nötig. Ideal für Endlospapier oder Sonderformate. |

Die Koordinaten der Platzhalter bleiben in allen drei Modi identisch. Kunden- und Admin-Downloads sind vom Vorverkaufs-Modus **nicht** betroffen und erhalten immer das vollständige Kunden-PDF inklusive Hintergrund.

### 3.5 Termine verwalten

Pro Veranstaltung können beliebig viele Aufführungstermine angelegt werden:

| Feld | Bedeutung |
|------|-----------|
| Datum | Aufführungsdatum |
| Uhrzeit | Beginn der Vorstellung |
| Generalprobe | Kennzeichnet den Termin als Generalprobe (Sonderpreise) |

Bestehende Termine können bearbeitet oder gelöscht werden (im Bearbeitungs-Dialog).

---

## 4. Sitzplan

### 4.1 Sitzplan-Editor

Der Editor ermöglicht das grafische Erstellen und Bearbeiten von Sitzplänen.

- **Veranstaltung auswählen** (Dropdown oben links)
- Reihen, Sitze, Tische und Abstände können platziert werden
- Der Sitzplan wird pro Veranstaltung gespeichert (nicht pro Termin)
- Bei breiten Sitzplänen bleibt die Kopfzeile jeder Reihe (Name und Buttons) beim seitlichen Scrollen sichtbar; der horizontale Scrollbalken sitzt immer am unteren Fensterrand

#### Mehrere Elemente auf einmal anlegen

Die Buttons **Platz**, **Tisch**, **Abstand** und **Rollstuhl** in der Kopfzeile einer Reihe öffnen einen Dialog mit dem Feld **Anzahl** – so lassen sich z. B. 20 Plätze mit einem Klick anlegen. Über **Position** wird festgelegt, wo sie eingefügt werden: *Am Ende der Reihe* (Standard), *Am Anfang der Reihe* oder *nach* einem bestimmten Platz, Abstand oder Tisch. Die Nummern richten sich nach der Position: am Anfang einer Reihe, die mit 7 beginnt, werden z. B. 1–6 vergeben, nach Platz 3 geht es mit 4 weiter. Passen sie nicht in die Lücke bis zum nächsten Platz, wird ab der höchsten vorhandenen Nummer weitergezählt und ein Hinweis angezeigt.

- **Platz / Rollstuhl:** Kategorie wählen, sie gilt für alle neuen Plätze.
- **Tisch:** Anzahl der Plätze je Seite (Vorne, Links, Rechts, Hinten) und deren Kategorie.
- **Abstand:** Häkchen „schluckt eine Sitznummer", wenn die Nummerierung den Abstand mitzählen soll.

#### Abstand oder Treppe

Beim Hinzufügen eines Abstands wird die **Art** gewählt: *Abstand* (grau) oder *Treppe* (weiß mit schwarzem Rahmen). Ein vorhandener Abstand lässt sich über *Bearbeiten* (Stift im Menü) zwischen Abstand und Treppe umschalten. Treppen erscheinen so in allen Sitzplan-Ansichten und im PDF; Kunden sehen „Treppe" in der Legende. Erst mit **Speichern** übernommen.

#### Elemente verschieben (Drag & Drop)

Neue Plätze, Abstände und Tische werden am Ende der Reihe angelegt. Um sie an die richtige Stelle zu bringen, das Element mit der Maus **anfassen und innerhalb der Reihe ziehen**: Eine blaue Linie zeigt, ob es links oder rechts vom Ziel-Element eingefügt wird; auf die freie Fläche hinter dem letzten Element gezogen, landet es am Ende. Verschoben wird nur innerhalb derselben Reihe, die Platznummern bleiben unverändert. **Wichtig:** Erst mit **Speichern** wird die neue Reihenfolge übernommen.

#### Plätze nach Nummer sortieren

Der Button **„Sortieren"** in der Kopfzeile einer Reihe ordnet die Plätze nach ihrer Nummer – hilfreich z. B. nach einem Excel-Import oder nachträglichem Einfügen. Abstände und Tische bleiben an ihrer Stelle; gespiegelte (absteigend nummerierte) Reihen werden absteigend sortiert. Erst mit **Speichern** übernommen.

#### Platz bearbeiten

Der Stift im Menü eines Platzes öffnet einen Dialog mit **Platznummer** und **Kategorie**, beide mit den aktuellen Werten vorbelegt. Gibt es die Nummer in der Reihe schon, wird nachgefragt.

#### Spiegeln

- **Sitzplan vertikal spiegeln:** Der Button **„Spiegeln"** oben neben „Bühne" dreht die Reihenfolge aller Reihen um (inkl. Bühne) – z. B. Bühne unten statt oben. Neue Reihen werden danach automatisch passend einsortiert.
- **Reihe spiegeln:** Der Button **„Spiegeln"** in der Kopfzeile einer Reihe dreht die Reihenfolge ihrer Plätze, Abstände und Tische um (Platz 1 steht dann auf der anderen Seite). Bei Tischen tauschen die linken und rechten Plätze die Seite.
- Die Platznummern bleiben dabei unverändert. **Wichtig:** Erst mit **Speichern** wird die neue Anordnung übernommen; sie gilt dann in allen Ansichten (Online-Buchung, Verkauf, Belegung, PDF).

#### Kategorie für mehrere Plätze setzen

Der Button **„Kategorie"** in der Kopfzeile einer Reihe öffnet eine Liste aller Plätze der Reihe (inkl. Tisch- und Rollstuhlplätzen) mit ihrer aktuellen Kategorie. Per Häkchen wählen Sie die Plätze aus, die eine neue Kategorie bekommen sollen:

- **Alle / Keine** – alles an- bzw. abwählen
- **Nur Kategorie** – wählt alle Plätze einer bestimmten (oder ohne) Kategorie aus
- **Von Pos. / Bis Pos.** + **Bereich auswählen** – wählt einen zusammenhängenden Bereich
- **Shift-Klick** auf ein Häkchen markiert alle Plätze zwischen dem zuletzt und dem aktuell geklickten

Unten die neue Kategorie wählen und **Übernehmen**. Wird die ganze Reihe geändert, erhält auch die Reihe selbst diese Kategorie. **Wichtig:** Die Änderung wird erst mit **Speichern** in die Datenbank geschrieben.

#### Alternativbelegung (Ausweichspielstätte)

Findet eine Veranstaltung wetterabhängig entweder auf der **Tribüne** oder in einem **Gebäude** statt, kann jeder Platz zusätzlich zu seiner originären Bezeichnung (Reihe/Platz bzw. Tisch/Platz) eine **alternative Reihe und Platznummer** erhalten.

- Button **„Alternativ"** in der Kopfzeile einer Reihe: öffnet eine Tabelle mit allen Plätzen dieser Reihe (inklusive Tischplätzen). Pro Platz werden *Alt-Reihe* und *Alt-Platz* eingetragen; leer lassen bedeutet „kein Alternativplatz".
- **Fortlaufend ausfüllen**: Alt-Reihe, ersten Alt-Platz und Schrittweite eingeben – die ganze Reihe wird automatisch durchnummeriert.
- Am einzelnen Platz öffnet der Button mit dem Wegweiser-Symbol denselben Dialog.
- Plätze mit Alternativbelegung zeigen im Editor einen kleinen Zusatz am Sitz (z. B. `13 →1/1`).
- **Wichtig:** Die Eingaben werden erst mit **Speichern** in die Datenbank geschrieben.

Der Name der Ausweichspielstätte (z. B. „Stadthalle") wird unter *Veranstaltung bearbeiten → Alternativer Veranstaltungsort* gepflegt und kann auf dem Ticket ausgegeben werden (siehe Abschnitt 3.4, Platzhalter `{alt_…}`).

#### Sitzplan-Import (Excel/CSV)

Über **Excel-Import** (Button im Sitzplan-Editor) lassen sich Alternativplätze und Kategorien für viele Plätze auf einmal einlesen – Formate: **.xlsx** und **.csv**.

1. Veranstaltung wählen und Datei hochladen.
2. **Spalten zuordnen**: Reihe und Platz (Original) sowie alternative Reihe/Platz; optional eine Tisch- und eine Kategoriespalte. Die Zuordnung wird anhand der Spaltenüberschriften vorgeschlagen und kann korrigiert werden. Hat die Datei keine Kopfzeile, wird „Kopfzeile in Zeile" auf `0` gesetzt.
3. **Import starten**. Das Ergebnis zeigt, wie viele Plätze aktualisiert, neu angelegt oder nicht gefunden wurden.

Hinweise:

- Der Inhalt der Zellen darf reine Zahlen (`1`, `13`) oder beschriftete Werte (`Reihe 1`, `Platz 13`) enthalten – maßgeblich ist die Spaltenzuordnung.
- **Nicht zugeordnete Spalten werden ignoriert** (Datum, Uhrzeit, Premiere, Preise usw.). Preise kommen immer aus der Kategorie und werden nie importiert.
- Eine **Kategoriespalte** wird über das *Zeichen* der angelegten Kategorie abgeglichen („Kat. I." → Zeichen „I"). Unbekannte Kategorien werden gemeldet und lassen den Platz unverändert.
- Kommt derselbe Platz mehrfach vor (z. B. eine Zeile je Vorstellung), wird er **einmal** verarbeitet; widersprüchliche Angaben werden im Ergebnis aufgelistet (der erste Wert gewinnt).
- **Fehlende Reihen/Plätze anlegen** (Option) legt nicht vorhandene Sitze an. Tischplätze werden nie automatisch angelegt.
- **Bestehende Alternativbelegungen vorher löschen** (Option) setzt alle Alternativplätze der Veranstaltung zurück, bevor die Datei eingelesen wird.

### 4.2 Sitzplankategorien

Unter **Sitzplan → Kategorien** definieren Sie die Preiskategorien einer Veranstaltung:

| Feld | Bedeutung |
|------|-----------|
| Name | Kategoriebezeichnung (z. B. „Parkett") |
| Kürzel | Kurzes Zeichen (z. B. „P") |
| Preis | Normalpreis |
| Ermäßigter Preis | Preis bei Ermäßigung |
| Generalprobenpreis | Preis bei Generalprobe-Terminen |
| Farbe | Farbe zur visuellen Unterscheidung im Sitzplan |

### 4.3 Sitzplan-Ansicht (Admin)

Über **Reservierungen → Sitzplan** öffnet sich eine Leseansicht des Sitzplans für einen bestimmten Termin. Hier sind gebuchte, freie und hervorgehobene Plätze farblich unterschieden.

Diese Ansicht ist nur für Admin und Vorverkauf zugänglich (Login erforderlich).

### 4.4 Sitzplan-Ansicht für Kunden

Kunden erhalten über die E-Mail einen **sicheren Link** zum Sitzplan. Der Link zeigt:
- Alle Plätze des Saals (frei / belegt)
- Die eigenen Plätze **orange hervorgehoben**

Der Link ist kryptographisch gesichert – Kunden können nur ihre eigenen Plätze als hervorgehoben sehen, nicht die anderer Reservierungen. Kein Login erforderlich.

→ Platzhalter für diesen Link: `{seatplan_link_html}` – siehe [Abschnitt 15](#15-e-mail-vorlagen--platzhalter).

### 4.5 Sitzplan-Belegung

> **Größe des Sitzplans (gilt für Online-Buchung, Verkauf und Sitzplan-Belegung):** Die Plätze passen sich automatisch der Fensterbreite an (zwischen 18 und 40 px pro Platz; Abstände sind genauso breit wie ein Sitz). Ist der Saal breiter, kann über **Größe −** weiter verkleinert werden, bis alles ohne seitliches Scrollen sichtbar ist; **+** vergrößert. Ein Klick auf die Prozentzahl setzt auf 100 % zurück. Die Einstellung wird pro Browser gespeichert.

Eigener Menüpunkt **Sitzplan-Belegung** in der Sidebar. Zeigt für eine ausgewählte Veranstaltung und einen Termin den vollständigen Sitzplan inkl. Belegungs-Status pro Platz:

- Status (Im Warenkorb, Reserviert, Bezahlt, Abgeholt, VIP, …)
- Klick auf einen belegten Platz öffnet die Reservierungs-Details

Oben rechts im Belegungs-Panel gibt es zwei Buttons **„Als PDF drucken (Farbe)"** und **„(S/W)"**. Sie erzeugen ein A4-PDF (Querformat, auto-skaliert auf eine Seite) im **gleichen Stil wie der Abendkasse-Sitzplan** — mit Titel, „BÜHNE"-Balken, Frei/Belegt-Darstellung und Zusammenfassung. Die Schwarz-Weiß-Variante spart Farbtoner.

Zweck: Schneller Überblick über die aktuelle Belegung eines Termins ohne den Umweg über die Reservierungsliste.

---

## 5. Reservierungen

### 5.1 Übersicht

Die Reservierungsliste zeigt alle Buchungen mit:
- Kunde (Name, E-Mail)
- Veranstaltung & Termin
- Sitzplatz(plätze)
- Status (Warenkorb / Reserviert / Bezahlt / Abgeholt etc.)
- Preis und Zahlungsart

**Standardmäßig werden nur Bestellungen zu aktiven Veranstaltungen angezeigt.** Da vergangene Veranstaltungen automatisch deaktiviert werden (siehe Abschnitt 3.1), bleibt die Liste auf das laufende Geschäft beschränkt. Ein Hinweis über der Liste weist darauf hin und verlinkt auf die vollständige Ansicht.

### 5.2 Filtern

Filtern Sie nach:
- Veranstaltung — inaktive Veranstaltungen sind mit „(inaktiv)" gekennzeichnet
- **Zeitraum** — *Nur aktive Veranstaltungen* (Standard) oder *Alle (inkl. beendete)*, um ältere Bestellungen aufzurufen
- Termin
- Status — **alle** Status sind auswählbar (u.a. VIP, Barzahlung, Gutschein, FN-Karte, abgeholt), nicht nur die vier Grundstatus
- Kundenname / E-Mail

> Bei einer gezielten Suche greift die Zeitraum-Einschränkung nicht: Wird eine bestimmte **Veranstaltung** gewählt oder über einen Link nach **Kunde** bzw. **Reservierungs-Nr.** gefiltert, werden die Treffer immer angezeigt – auch bei beendeten Veranstaltungen.

### 5.3 Aktionen

- **Sitzplan anzeigen:** Öffnet die Admin-Sitzplanansicht mit der markierten Reservierung
- **Status ändern:** Manuell auf bezahlt/abgeholt setzen
- **Kunde verknüpfen/ändern:** Neben „Kunde:" im Bestelldetail-Fenster öffnet der Button **Verknüpfen/Ändern** eine Kundensuche (Name/E-Mail). Der gewählte Kunde wird **allen Karten der Bestellung** zugewiesen. Typischer Anwendungsfall: Ein **Barverkauf ohne Kunde** wird per *Status ändern* zu **VIP** gemacht und soll danach einem echten Kunden zugeordnet werden. Da Bestellungen u.a. über den Kunden gruppiert werden, wird die Liste nach dem Verknüpfen neu geladen.
- **E-Mail erneut senden:** Bestätigungsmail an den Kunden schicken
- **Prüfkopie an Support:** Sendet eine Kopie der Bestätigungs-Mail (inkl. Ticket-Download-Link) an die in den Einstellungen hinterlegte **Support-E-Mail** — nicht an den Kunden. So lassen sich Inhalt und Tickets einer Bestellung prüfen, ohne den Kunden zu kontaktieren. Nur im Admin-Bereich verfügbar; setzt eine konfigurierte Support-E-Mail voraus.
- **Umbuchen:** Plätze und/oder Datum einer bestehenden Bestellung tauschen (gleiche Anzahl, gleiche Kategorie)
- **Plätze hinzufügen:** Zusätzliche Plätze zur bestehenden Bestellung hinzufügen
- **Erneut drucken:** Bei abgeschlossenen Bestellungen (bezahlt, abgeholt, VIP, FN-Karte, eingelöste Gutscheine) stehen im Fenster-Fuß die Buttons **Erneut drucken (A4 / Kunde)** und **Erneut drucken (Vorverkauf-Template)** zur Verfügung. Das gilt jetzt auch für **an der Abendkasse gedruckte** Karten (Status *Gedruckt (Abendkasse)*).
- **Löschen:** Reservierung stornieren und Platz freigeben

> **Bestelldetails – Endsumme & Begleitperson:** Das Bestelldetail-Fenster listet pro Karte den Preis und weist unten eine **Endsumme** aus. Bei Rollstuhl-Buchungen zahlt nur der Rollstuhlplatz (und alle übrigen Plätze); die **Begleitperson** erscheint mit `0,00 € (B)` und dem Hinweis „Begleitperson kostenfrei". Das gilt durchgängig auch in der Bestätigungs-E-Mail (Rollstuhlplätze werden als „Reihe X, Sitz Y (Rollstuhl)" angezeigt) und auf dem Ticket (Preisfeld `0 € (B)`).

#### Plätze hinzufügen

Über den Button **Plätze hinzufügen** in den Bestelldetails können einer bestehenden Reservierung weitere Plätze ergänzt werden. Die neuen Plätze werden derselben Bestellung zugeordnet (gleicher Kunde, gleiches Event/Datum, gleicher Status).

- Verfügbar für: **offene Reservierungen** (Status *Reserviert (Vorverkauf)* / *Reserviert (Online)*) sowie **VIP-Karten** (Status *VIP*)
- **Nicht verfügbar** für bezahlte/abgeholte Tickets — bezahlte Bestellungen können nicht erweitert werden
- Die Kategorie wird automatisch aus dem Sitzplan übernommen, der Preis aus der aktuellen Kategorien-Konfiguration (kann vom ursprünglichen Bestellpreis abweichen)
- Optional kann beim Bestätigen eine Bestätigungs-Mail mit dem aktualisierten Ticket-Link an den Kunden gesendet werden

#### Umbuchen

Über **Umbuchen** in den Bestelldetails können Plätze einer Reservierung auf andere Plätze und/oder ein anderes Datum verschoben werden. Voraussetzung: identische Kategorie, gleiche Anzahl Plätze.

Im Umbuchen-Dialog gibt es das Häkchen **„Kunde per E-Mail benachrichtigen"** (standardmäßig aktiv). Ist es gesetzt, erhält der Kunde nach der Umbuchung automatisch eine neue Ticket-/Bestätigungs-E-Mail mit den aktualisierten Daten — so wird er implizit aufgefordert, das Ticket neu herunterzuladen und den alten Ausdruck zu verwerfen. Kunden ohne hinterlegte E-Mail (z.B. Barverkäufe) werden dabei übersprungen.

> **Hinweis:** Wird auf ein **anderes Datum** umgebucht, wird die Bestellung in zwei Teile aufgeteilt — die umgebuchten Plätze erscheinen als eigene Bestellung beim neuen Datum. Beide Teile sind über den Kundennamen in der Reservierungs-Übersicht auffindbar. Bei Umbuchung innerhalb desselben Datums bleibt die Bestellung als Einheit erhalten.

> **Achtung – alte Ausdrucke:** Wurde eine bereits verkaufte Karte umgebucht (vor allem auf ein anderes Datum), kann der Kunde noch einen **alten Ausdruck** mit dem ursprünglichen Platz/Datum besitzen. Alle Umbuchungen sind unter *Auswertungen → Umbuchungen* je Veranstaltung nachvollziehbar (siehe Abschnitt 11.4).

### 5.4 Abgelaufene Warenkörbe bereinigen

Über den Button **Abgelaufene Warenkörbe bereinigen** werden Reservierungen freigegeben, deren Warenkorb-Eintrag älter als **20 Minuten** ist (Status *Im Warenkorb*).

Die Bereinigung läuft außerdem automatisch in folgenden Situationen:
- Beim Aufrufen der Reservierungsübersicht
- Wenn ein Kunde den Sitzplan aufruft (vor der Platzbelegungsanzeige)
- Wenn der Vorverkauf nach der Datumsauswahl zum Sitzplan wechselt
- Vor dem Versand des täglichen Berichts (Tagesbericht per E-Mail)
- Per **Cron-Job alle 5 Minuten** (`app/scripts/cleanup_expired_reservations.php`)

**Was wird bereinigt:**
- **Reservierungen** mit Status *Im Warenkorb* älter als 20 Min. → Sitzplätze werden freigegeben
- **Gutscheine** mit Status *Im Warenkorb* älter als 20 Min.:
  - **Kauf-Gutscheine** (`purchase_intent = buy`) werden gelöscht
  - **Einlöse-Gutscheine** (`purchase_intent = redeem`) werden auf den vorherigen Status zurückgesetzt, sodass sie wieder einlösbar sind

**Cron-Heartbeat:** Wenn beim Cron-Lauf nichts zu bereinigen ist, schreibt das Skript einmal pro Stunde einen `cron_heartbeat`-Eintrag ins Admin-Log. So ist im Log-Viewer sichtbar, dass der Cron tatsächlich läuft.

**Die 20 Minuten laufen ab der letzten Aktivität, nicht ab der Platzauswahl.** Solange ein Kunde aktiv ist — Plätze wählt, das Checkout-Formular ausfüllt, bei PayPal bezahlt — wird die Frist automatisch neu gestartet. Sonst wären Kunden mitten im Bezahlvorgang bereinigt worden, was zu abgebrochenen PayPal-Zahlungen führte. Ein Warenkorb verfällt also nur, wenn der Kunde ihn tatsächlich 20 Minuten lang unberührt lässt oder den Browser schließt.

**Ein Platz ist genau so lange belegt, wie sein Warenkorb-Eintrag existiert.** Die Anzeige in der Sitzplan-Belegung stimmt damit immer mit der tatsächlichen Buchbarkeit überein. Übernimmt ein anderer Kunde einen zwischenzeitlich freigewordenen Platz, wird der ältere Warenkorb-Eintrag beim nächsten Lebenszeichen entfernt; der betroffene Kunde bekommt im Checkout einen Hinweis mit der Platznummer. Im Log erscheint das als `seats_released` mit dem Grund `seat_taken_by_other_session`.

### 5.5 Neue Reservierung / Verkauf anlegen

Über den Verkaufsassistenten (**Verkauf / Reservierung**) können Sie als Admin selbst Tickets verkaufen oder reservieren. Der Ablauf entspricht dem Vorverkauf:

1. **Veranstaltung & Termin** auswählen.
2. **Plätze wählen:**
   - Bei Veranstaltungen **mit Sitzplan** über die interaktive Sitzplanansicht.
   - Bei Veranstaltungen **ohne Sitzplan** über eine Mengen-Auswahl: Feld **Anzahl Tickets** (mit Anzeige der noch verfügbaren Menge) und – sofern Ermäßigungen aktiv sind – **davon ermäßigt**. Ist die Veranstaltung ausverkauft, erscheint ein Hinweis.
3. **Bestellart & Zahlungsart** (Kaufen/Reservieren; Barverkauf / mit Kundendaten / Gutschein).
4. **Abschluss** – beim Verkauf ohne Sitzplan wird die Verfügbarkeit erneut geprüft, um Überverkäufe zu vermeiden.

**Bestätigungsmail-Status:** Die Abschluss-Seite (Admin, Vorverkauf und Kunden-Checkout) zeigt an, ob die Bestätigungsmail versendet wurde (grüne Box) oder der Versand fehlgeschlagen ist (gelbe Warnbox mit Button **„E-Mail erneut senden"**). Der erneute Versand ist auf 5 Versuche mit mind. 30 Sekunden Abstand begrenzt; Kunden sind dabei über einen an die Bestellung gebundenen Sicherheits-Token autorisiert. Jeder Versuch wird als `email_resend` im jeweiligen Log-Kanal protokolliert; fehlgeschlagene Sendungen enthalten im `email_sent`-Eintrag zusätzlich den konkreten Fehlergrund (Feld `error`, z.B. SMTP-Authentifizierungsfehler).

---

## 6. Kunden

### 6.1 Kundenliste

Die Kundenliste zeigt bis zu 100 Kunden pro Seite. Über die Suchfunktion können Sie nach:
- Vorname / Nachname
- E-Mail-Adresse
- Stadt

suchen.

### 6.2 Kunden bearbeiten

Pro Kunde können gespeichert werden:
- Anrede, Vorname, Nachname
- Straße, Hausnummer, PLZ, Ort
- E-Mail-Adresse
- Telefon (Vorwahl + Nummer)

### 6.3 Kunden löschen

Kunden können über den Löschen-Button entfernt werden. Verknüpfte Reservierungen bleiben erhalten (Kundendaten werden dabei anonymisiert).

### 6.4 Kunden-Mails (Massenversand)

Über den Menüpunkt **Kunden-Mails** können frei formulierte E-Mails an Gruppen von Kunden gesendet werden. Pro Kunde wird genau eine Mail versendet (gruppiert, auch wenn der Kunde mehrere Reservierungen hat); Empfänger werden anhand der E-Mail-Adresse dedupliziert.

**Schritt 1 – Empfänger auswählen:**

| Modus | Zielgruppe |
| --- | --- |
| Alle Kunden | Jeder Kunde mit hinterlegter E-Mail |
| Einzelner Kunde | Suche nach ID, Name oder E-Mail |
| Kunden einer Veranstaltung | Alle Kunden mit Reservierung für die Veranstaltung |
| Kunden eines Termins | Alle Kunden mit Reservierung für einen bestimmten Termin |
| Kunden nach Buchungstag | Kunden, die an einem bestimmten Tag gebucht haben |
| Kunden einer Bestellung | Alle Kunden mit derselben Session-ID wie die angegebene Reservierungs-ID |

Für die Reservierungs-basierten Modi kann zusätzlich per **Status-Filter** nach „Alle / Nur bezahlt / Nur reserviert (unbezahlt)" eingegrenzt werden.

Der Button **Empfänger prüfen** zeigt vor dem Versand die Liste der resultierenden Empfänger inkl. Anzahl.

**Schritt 2 – Nachricht verfassen:**

- **Antwort-Adresse (Reply-To)** – Pflichtfeld. Antworten der Kunden gehen an diese Adresse, nicht an den automatischen Absender.
- **BCC** – optional; eine einzelne Adresse, die zusätzlich eine Kopie erhält (z. B. für interne Archivierung).
- **Betreff** und **HTML-Nachricht** – dürfen Platzhalter enthalten.

**Verfügbare Platzhalter:**

- *Immer verfügbar*: `{customer_name}`, `{salutation}`, `{first_name}`, `{last_name}`, `{company_name}`, `{logo_html}`
- *Bei Veranstaltungs-/Termin-/Bestellbezug zusätzlich*: `{event_name}`, `{event_date}`, `{event_time}`, `{category_name}`, `{price}`, `{seat_list}`, `{reservation_count}` — jeweils bezogen auf die Reservierungen des jeweiligen Empfängers.

Der Button **Vorschau (1. Empfänger)** rendert die Mail mit den Platzhaltern des ersten Empfängers in einem Iframe – zur Kontrolle vor dem Versand. **Jetzt senden** verlangt eine Bestätigung und versendet anschließend nacheinander.

Jeder Versandlauf wird im `admin.log` als `admin_bulk_email_sent` protokolliert (Modus, Anzahl Empfänger, erfolgreich/fehlgeschlagen, Betreff, Flags für Reply-To und BCC).

### 6.5 Kunden-Import (CSV)

Über **Kunden → Importieren** können Kundendaten per CSV-Datei massenhaft eingelesen werden.

**Vorlage herunterladen:** Der Button liefert eine CSV-Vorlage mit korrekter Spaltenüberschrift und Beispielzeilen. Trennzeichen ist Semikolon (`;`), Zeichensatz UTF-8 mit BOM (für Excel-Kompatibilität).

**Spalten:** `anrede`, `vorname`, `nachname`, `strasse`, `hausnummer`, `postleitzahl`, `ort`, `email`, `telefon_vorwahl`, `telefonnummer`, `typ`

**Hinweise:**
- Trennzeichen wird automatisch erkannt (Semikolon oder Komma)
- Bestehende Kunden mit identischer E-Mail-Adresse werden aktualisiert, nicht doppelt angelegt
- Nach dem Import zeigt die Übersicht die Anzahl neu angelegter / aktualisierter / fehlerhafter Zeilen

---

## 7. Gutscheine

### 7.1 Gutschein-Liste

Alle Gutscheine mit Status:

| Status | Bedeutung |
|--------|-----------|
| Vorverkauf | Erstellt, aber noch nicht verkauft |
| Im Warenkorb | Gerade im Kaufprozess |
| Bezahlt / uneingelöst | Gekauft, aber noch nicht für Tickets eingelöst |
| Eingelöst | Für Tickets verwendet |

### 7.2 Gutscheine erstellen

Erstellt **einen** Gutscheincode, der für die angegebene Anzahl an Tickets gilt. Es wird also nicht je ein Code pro Stück erzeugt, sondern ein Code, der mehrere Tickets abdeckt.

1. Veranstaltung auswählen
2. Kategorie auswählen (bestimmt den Wert pro Ticket)
3. **Anzahl** eingeben — wie viele Tickets dieser Code abdeckt
4. Optional: Kunden zuordnen und E-Mail mit dem Gutscheincode versenden
5. Optional: Ermäßigung / FN-Card als Voreinstellung beim Einlösen

Der Wert des Gutscheins entspricht dem Kategoriepreis × Anzahl. Beim Einlösen kann der Kunde bis zu dieser Anzahl Tickets damit bezahlen.

Jeder Gutschein erhält automatisch einen eindeutigen Code.

### 7.3 Gutschein bearbeiten / Details

- Status ändern
- Kunden zuordnen oder ändern
- E-Mail erneut versenden
- Einlöse-Historie einsehen

---

## 8. VIP-Tickets

Eigener Menüpunkt **VIP-Tickets** in der Sidebar. VIP-Tickets sind vom System verwaltete Karten ohne Bezahlvorgang — typisch für Ehrengäste, Sponsoren oder Pressekarten.

### 8.1 VIP-Tickets ausstellen

Der Assistent führt durch mehrere Schritte:

1. **Veranstaltung & Termin auswählen** — Pflichtfelder. Wenn nur eine Veranstaltung existiert, wird sie automatisch vorausgewählt.
2. **Plätze wählen** — interaktive Sitzplanansicht; nur freie Plätze sind klickbar.
3. **Kundendaten** — bestehenden Kunden suchen oder neuen anlegen.
4. **Bestätigen** — die Plätze werden mit Status *VIP* angelegt. Optional kann eine VIP-E-Mail mit Ticket-Link an den Kunden versendet werden.

### 8.2 VIP-Tickets verwalten

VIP-Tickets erscheinen in der normalen Reservierungsliste mit dem Status-Badge *VIP*. Über die Bestelldetails können sie:
- Per **Plätze hinzufügen** erweitert werden (nur Admin)
- **Umgebucht** werden
- Per **Ticket-Download** als PDF ausgestellt werden
- **Storniert** werden

> VIP-Karten erscheinen in den Berichten und in der Sitzplan-Belegung wie reguläre Tickets, sind aber als VIP gekennzeichnet.

---

## 9. Zahlungsanforderungen

Über den Menüpunkt **Zahlungsanforderungen** können einem Kunden gezielt Zahlungs-Links zugesendet werden — z. B. wenn ein Kunde eine Reservierung getätigt hat und nun online bezahlen soll, oder für individuelle Beträge außerhalb des Kassen-Workflows.

### 9.1 Zahlungsanforderung erstellen

Pflichtfelder:
- **Name des Kunden**
- **E-Mail-Adresse**
- **Betrag** (z. B. `25.00`)
- **Beschreibung** — wofür ist die Zahlung?

Optional:
- **Notizen** — interne Anmerkungen, sind für den Kunden nicht sichtbar.

Beim Speichern wird automatisch ein Zahlungs-Token generiert und eine E-Mail mit Bezahl-Link an den Kunden versendet. Der Link öffnet eine PayPal-Zahlungsseite mit dem vorgegebenen Betrag.

### 9.2 Zahlungsanforderungen mit Reservierung verknüpfen

Über **Zuordnen** kann eine Zahlungsanforderung mit einer oder mehreren Reservierungen verknüpft werden. Nach erfolgreicher Zahlung wird der Status der zugeordneten Reservierungen automatisch auf *bezahlt* gesetzt.

### 9.3 Übersicht

Die Liste zeigt:
- Status (offen / bezahlt / abgelaufen)
- Betrag, Beschreibung, Kunde
- Zugeordnete Reservierungen (falls vorhanden)
- Erstellungs- und Bezahldatum

---

## 10. Kasse

Erreichbar über den Menüpunkt **Abendkasse** in der Sidebar (zwischen „Reservierungen" und „Kunden").

### 10.1 Kassen-Übersicht

Wählen Sie Veranstaltung und Termin aus, um zur Kassen-Sitzplanansicht zu gelangen.

### 10.2 Kassen-Sitzplan

Zeigt alle freien und belegten Plätze für den gewählten Termin. Geeignet für den Verkauf an der Abendkasse.

### 10.3 Kassendruck (PDF)

Erstellt ein druckbares PDF mit allen freien Plätzen des gewählten Termins. Der komplette Saalplan wird **automatisch auf eine A4-Seite (Querformat)** skaliert. Reihe 0 wird als **BÜHNE** dargestellt, Rollstuhlplätze sind enthalten und in der Zusammenfassung (Frei/Belegt/Gesamt) mitgezählt.

Beim Erzeugen stehen zwei Varianten zur Wahl:

- **Farbe** — freie Plätze farbkodiert nach Kategorie
- **Schwarz-Weiß** — freie Plätze weiß, belegte grau (spart Farbtoner am Kassendrucker)

Beide Varianten gibt es sowohl bei der Termin-Auswahl (Button „Sitzplan freier Plätze …", ohne dass Reservierungen umgewandelt werden müssen) als auch nach dem Starten der Abendkasse.

---

## 11. Berichte

### 11.1 Ticket-Übersicht

Zeigt für jede Veranstaltung und jeden Termin:
- Anzahl gebuchter Plätze nach Kategorie
- Anzahl freier Plätze
- Umsatz nach Kategorie und Status
- Gutschein-Übersicht

### 11.2 Tagesbericht (E-Mail)

Der Tagesbericht wird täglich per Cron-Job als HTML-E-Mail verschickt und zeigt für alle aktiven Veranstaltungen:
- Tickets gesamt, verkauft, reserviert, im Warenkorb, Abendkasse
- Zuwachs der letzten 24 Stunden (gesamt und davon verkauft)
- Aufschlüsselung nach Terminen

**Automatische Bereinigung:** Vor der Datenerhebung werden abgelaufene Warenkörbe (älter als 20 Minuten) automatisch gelöscht, sodass der Report nur tatsächlich aktive Datensätze zeigt.

Manueller Aufruf (Vorschau im Browser):
```
https://domain.de/cron_report.php?secret=DEIN_TOKEN&preview=1
```

### 11.3 Auswertungen

Grafische Darstellung der Reservierungsaktivität über Zeit:
- Linienchart: Reservierungen nach Status (nur Status mit tatsächlichen Einträgen werden angezeigt)
- Gutschein-Aktivität und Einlösungen (ebenfalls nur relevante Status)
- Filterbar nach Veranstaltung
- **Datum-Zoom:** Über die Buttons „Tage", „Wochen" und „Monate" kann die zeitliche Auflösung der Grafiken umgeschaltet werden. Die Werte werden entsprechend aggregiert (z.B. Summe pro Kalenderwoche oder Monat)

### 11.4 Umbuchungen

Übersicht aller umgebuchten Karten je Veranstaltung. Nach Auswahl der Veranstaltung erscheint eine Tabelle mit:

- **Zeitpunkt** der Umbuchung
- **Karte** (Reservierungsnummer)
- **Kunde** (sofern hinterlegt)
- **Von (alt)** und **Nach (neu)** — jeweils Datum und Sitzplatz
- **Bearbeiter** (Benutzername und Kanal: Admin oder Vorverkauf)

Ein **Datum-Badge** markiert Umbuchungen, bei denen sich der Termin geändert hat — genau dort besteht das Risiko, dass ein Kunde noch einen alten Ausdruck mit dem ursprünglichen Datum besitzt. Die Zahl in Klammern hinter jeder Veranstaltung in der Auswahl gibt die Anzahl umgebuchter Karten an.

> **Hinweis:** Umbuchungen werden ab Einführung dieser Funktion automatisch protokolliert. Bereits zuvor erfolgte Umbuchungen lassen sich einmalig aus den Logdateien nachladen — direkt auf dieser Seite unter **Wartung: Historie aus Logdateien nachladen** (Button *Vorschau* zeigt nur an, *Jetzt nachladen* übernimmt sie). Die Aktion ist gefahrlos wiederholbar; bereits vorhandene Einträge werden übersprungen. Alternativ per Kommandozeile: `php app/scripts/backfill_rebookings.php`.

---

## 12. System-Logs

Eigener Menüpunkt **System-Logs** in der Sidebar. Zentrale Anlaufstelle für Diagnose und Nachverfolgung. Das System schreibt drei getrennte Logs:

| Kanal | Inhalt |
|-------|--------|
| **Customer** | Öffentliche Kundenflows: Sessions, Warenkorb, Zahlung, ausgehende E-Mails an Kunden |
| **Admin** | Admin-Aktionen: Logins, Settings-Änderungen, Massen-Mails, Cron-Heartbeat, Mail-Versand durch Admin |
| **Vorverkauf (Presales)** | Vorverkauf-Aktionen: Logins, Verkäufe, Reservierungs-Statuswechsel, Mail-Versand durch Vorverkauf |

E-Mail-Versand wird automatisch in den Kanal des Auslösers geschrieben (Vorverkauf-Mitarbeiter → `presales.log`, Admin → `admin.log`, anonyme Kundenflows → `customer.log`).

### 12.1 Übersichts-Karten

Drei Status-Karten oben zeigen für jeden Kanal die Anzahl Warnungen/Fehler der letzten 7 Tage. Klick auf die Karte öffnet die 7-Tage-Wochenansicht für diesen Kanal.

### 12.2 Bestellabbrecher-Funnel

Die Funnel-Karte zeigt für die letzten 7 Tage:
- **Warenkorb befüllt** — Sessions, die mindestens einen Sitz reserviert haben
- **Checkout aufgerufen** — Sessions, die zum Bezahlvorgang weitergegangen sind
- **Zahlung abgeschlossen** — erfolgreich bezahlte Sessions
- **Timeout** — Reservierungen, die durch Ablauf verfallen sind
- **Conversion-** und **Abbruchrate** als Badge

### 12.3 Filter und Suche

- **Datum** auswählen — zeigt alle Einträge eines Tages
- **Kanal** umschalten — Alle / Admin / Kunden / Vorverkauf
- **Nur Warnungen** / **Alle Einträge** umschalten
- **Session-ID-Suche** — findet alle Einträge derselben Session über alle Tage hinweg
- **Gutscheincode-Suche** — findet alle Einträge eines Gutscheincodes (z. B. Anlage, Einlösung, Status-Änderungen)
- **Reservierungs-ID-Suche** — findet alle Einträge zu einer oder mehreren Reservierungs-IDs (Eingabe z. B. `1509, 711` oder `1509 711`) über alle Logdateien hinweg. IDs werden grenzgenau erkannt, sodass `711` nicht fälschlich in `17110` trifft.
- **IP-Adress-Suche** — findet alle Einträge einer IP-Adresse (ebenfalls grenzgenau, `1.2.3.4` trifft nicht `1.2.3.40`); nützlich, um z. B. wiederkehrende fehlgeschlagene Ticket-Downloads einer Quelle nachzuverfolgen

> Für die Suche auf der Kommandozeile (z. B. direkt auf dem Server) steht zusätzlich das Skript `app/scripts/search_logs.php` zur Verfügung: `php app/scripts/search_logs.php 1509 711` bzw. `--ip=62.206.166.6`.

### 12.4 Klickbare Verweise

Jeder Eintrag enthält Quick-Info-Badges, die klickbar sind:
- **Session-ID** → öffnet die Session-Suche
- **Gutscheincode** → öffnet die Gutschein-Suche
- **Reservierungs-IDs** → springen zur Reservierungs-Detailseite
- **IP-Adresse** → öffnet die IP-Suche (alle Einträge dieser IP)
- **Benutzer-ID / Username** — bei Customer-Logs zur Kunden-Detailseite, bei Admin-/Vorverkauf-Logs zum Benutzer-Bearbeiten

### 12.5 Warnungen als „behoben" markieren

Warnungen und Fehler können per Klick auf das Häkchen als behoben markiert werden. Sie werden danach durchgestrichen dargestellt und aus den Übersichtskarten herausgerechnet — der Eintrag bleibt aber im Log erhalten.

**Automatisch als erledigt erkannt:** PayPal-Fehler (`paypal_client_error`) müssen Sie in zwei Fällen nicht selbst abhaken. Das System durchsucht dazu die Logs derselben Sitzung nach einem späteren Ereignis:

| Badge | Bedeutung |
|---|---|
| **Abschluss erfolgt** | Dieselbe Sitzung hat danach erfolgreich bezahlt. Der Fehler war ein vorübergehender Abbruch, z.B. eine abgelehnte Karte beim ersten Versuch. |
| **Plätze freigegeben** | Die Plätze der Sitzung wurden danach automatisch freigegeben (Cron- oder Vorverkaufs-Bereinigung, Ablauf des Warenkorbs). Der Kunde hat den Kauf abgebrochen, es ist keine verwaiste Reservierung zurückgeblieben. |

Beide Fälle werden durchgestrichen dargestellt und zählen nicht in die Warnungs-Zähler der Übersichtskarten. Ein PayPal-Fehler **ohne** eines dieser Badges ist dagegen ein Fall, den Sie sich ansehen sollten.

### 12.6 Mail-Fehler-Übersicht

Über den Button **Mail-Fehler** auf der System-Logs-Seite (`admin/email_failures.php`) erreichen Sie eine Übersicht aller **fehlgeschlagenen E-Mail-Versände** laut Logdateien — wahlweise der letzten 7, 30, 90 oder 180 Tage. Die Fehlschläge werden **pro Bestellung gruppiert** angezeigt mit:

- Zeitpunkt des letzten Fehlversuchs und Log-Kanal
- Mail-Typ (Reservierungsbestätigung, Ticket-E-Mail, Vorverkaufsbestätigung, …)
- Veranstaltung und Termin
- Kunde mit E-Mail-Adresse
- Reservierungs-IDs (verlinkt zur Log-Suche); bei älteren Logeinträgen ohne IDs steht „unbekannt"
- Anzahl der Fehlversuche und der konkrete **Fehlergrund** (z. B. SMTP-Authentifizierungsfehler)

Wurde für dieselbe Bestellung später ein erfolgreicher Versand geloggt (z. B. über „E-Mail erneut senden"), wird der Eintrag als **erledigt** markiert; der Filter „Nur offene / Alle" blendet erledigte Fälle ein oder aus. Für offene Fälle mit bekannten Reservierungs-IDs kann die Mail direkt über **„Erneut senden"** nachgesendet werden — jeder Versuch wird wieder im Log protokolliert.

---

## 13. Benutzer

### 13.1 Benutzerliste

Alle System-Benutzer (Admin & Vorverkauf) mit:
- Benutzername, E-Mail, Rolle, Status

### 13.2 Benutzer erstellen

| Feld | Pflicht | Bedeutung |
|------|---------|-----------|
| Benutzername | Ja | Eindeutig, wird zum Einloggen verwendet |
| Passwort | Ja | Min. 8 Zeichen empfohlen |
| E-Mail | Nein | Kontakt-E-Mail |
| Rolle | Ja | `admin` oder `presales` |
| Aktiv | Ja | Inaktive Benutzer können sich nicht einloggen |

### 13.3 Benutzer bearbeiten

Alle Felder können geändert werden. Zusätzlich für Vorverkauf-Benutzer:

**Geräte-Token (für Tablet/POS):**
- Token mit Bezeichnung und Ablaufdauer erstellen
- Aktivierungs-URL an das Gerät senden
- Aktive Tokens einsehen und widerrufen

---

## 14. Einstellungen

### 14.1 Unternehmens-Branding

| Einstellung | Bedeutung |
|-------------|-----------|
| Firmenname | Wird in E-Mails und PDF angezeigt |
| Logo | Upload eines Logos (für PDF-Tickets) |
| Telefon | Kontakttelefon für Rückfragen |

### 14.1a Datenbank-Update

Nach einem Update der Programmdateien (Deploy) können Änderungen an der Datenbank nötig sein. Die Admin-Startseite weist darauf hin, solange welche ausstehen. Unter **Einstellungen → Datenbank-Update** steht, welche Änderungen eingespielt bzw. ausstehend sind; ein Klick auf **„… ausstehende Änderung(en) einspielen"** führt sie in der richtigen Reihenfolge aus. Bereits vorhandene Änderungen – auch von Hand eingespielte – werden erkannt und übersprungen, das Einspielen kann gefahrlos wiederholt werden. Vorher empfiehlt sich eine Sicherung der Datenbank.

Alternativ per Kommandozeile im Installationsverzeichnis: `php database/update_database.php` (nur prüfen: `--check`). Beide Wege arbeiten mit der Datenbank aus der `.env` der jeweiligen Installation.

### 14.2 Veranstaltungs-Defaults

| Einstellung | Bedeutung |
|-------------|-----------|
| Standard-Veranstaltungsbeginn | Vorausgefüllte Uhrzeit beim Erstellen von Terminen |
| Max. Reservierungszeit | Bis zu welcher Uhrzeit am Veranstaltungstag gebucht werden kann |

### 14.3 Funktionen ein-/ausschalten

| Einstellung | Bedeutung |
|-------------|-----------|
| PayPal aktiv | Online-Zahlung über PayPal möglich |
| Gutscheine aktiv | Gutscheinsystem global aktiv |
| Ermäßigung aktiv | Ermäßigte Preise wählbar |
| FN-Card aktiv | FN-Card-Rabatt verfügbar |
| FN-Karte: Rabatt in % | Rabatt auf den Normalpreis (Standard 10 %) |
| FN-Karte: max. Tickets pro Karte und Veranstaltung | Wie viele Tickets mit **derselben** FN-Card-Nummer für eine Veranstaltung gekauft werden dürfen – über alle Termine, inkl. Warenkörbe (Standard 2) |
| E-Mail-Versand aktiv | Alle automatischen E-Mails an Kunden |

### 14.4 E-Mail-SMTP-Konfiguration

| Einstellung | Beispiel |
|-------------|---------|
| SMTP-Server | `smtp.ionos.de` |
| Port | `587` |
| Benutzername | `ticket@theater.de` |
| Passwort | `geheimes-passwort` |
| TLS/SSL | Ein (empfohlen) |
| Absender-E-Mail | `ticket@theater.de` |
| Absender-Name | `Theater Musterstadt` |

### 14.5 Berechtigungen nach Rolle

Für Kunden, Admin und Vorverkauf separat konfigurierbar:
- PayPal-Zahlung erlaubt
- Reservierung erlaubt
- Barzahlung erlaubt
- Gutschein-Einlösung erlaubt

### 14.6 Kassen-PIN

PIN-Code für den Kassenzugang (Vorverkauf). Ohne gültigen PIN kann die Kasse nicht geöffnet werden.

### 14.7 PDF-Templates

Ticket- und Gutschein-PDFs können individuell gestaltet werden:
- PDF-Vorlage hochladen (A4-Hintergrund)
- Positionierung der Textfelder (X/Y-Koordinaten, Schriftgröße, Farbe)
- Pro Veranstaltung kann ein **eigenes Vorverkaufs-Template** gepflegt werden (siehe [3.4](#34-ticket-templates-kunde--vorverkauf))

### 14.8 Vorverkauf: Druckmodus

Der Druckmodus für den Vorverkauf (mit/ohne Hintergrund, freie Fläche) wird **pro Veranstaltung** im Vorverkaufs-Template-Editor eingestellt – siehe [3.4](#34-ticket-templates-kunde--vorverkauf).

---

## 15. E-Mail-Vorlagen & Platzhalter

### 15.1 Globale und veranstaltungsspezifische Vorlagen

E-Mail-Texte können auf zwei Ebenen konfiguriert werden:

1. **Global** (Einstellungen → E-Mail): gilt für alle Veranstaltungen
2. **Pro Veranstaltung** (Veranstaltung bearbeiten → E-Mail-Einstellungen): überschreibt die globale Vorlage

### 15.2 E-Mail-Typen

| Typ | Wann verschickt |
|-----|----------------|
| Ticket-E-Mail | Nach erfolgreichem Online-Kauf (PayPal) |
| Reservierungs-E-Mail | Nach Reservierung ohne sofortige Bezahlung |
| Gutschein-E-Mail | Nach Gutscheinkauf |
| Abholungs-E-Mail | Wenn Reservierung als abgeholt/bezahlt markiert wird |
| Vorverkaufs-E-Mail | Nach Direktverkauf über Vorverkauf-Interface |
| Kombinierte E-Mail | Wenn Tickets und Gutscheine zusammen bestellt werden |
| VIP-E-Mail | Beim Ausstellen einer VIP-Karte |
| Zahlungsanforderungs-Mail | Beim Erstellen einer Zahlungsanforderung |

### 15.3 Verfügbare Platzhalter

Die folgenden Platzhalter können in **Betreff und Text** aller E-Mail-Typen verwendet werden (soweit inhaltlich sinnvoll):

#### Kunden & Veranstaltung

| Platzhalter | Beispielwert |
|-------------|--------------|
| `{customer_name}` | `Max Mustermann` |
| `{event_name}` | `Don Camillo und Peppone` |
| `{event_date}` | `02.08.2026` |
| `{event_time}` | `19:30 Uhr` |
| `{category_name}` | `Parkett` |
| `{price}` | `25,00 €` |
| `{reservation_count}` | `2` |

#### Sitzplätze

| Platzhalter | Inhalt |
|-------------|--------|
| `{seat_list}` | HTML-Liste der gebuchten Plätze (z. B. „Reihe 3, Sitz 5") |
| `{seat_identifier}` | Interne Sitzplatz-ID der ersten Reservierung |

#### Sitzplan-Link für Kunden

| Platzhalter | Inhalt |
|-------------|--------|
| `{seatplan_link}` | Reine URL zum Sitzplan (für eigene Verlinkung) |
| `{seatplan_link_html}` | Fertiger grauer Button „Sitzplan ansehen" |

> **Sicherheit des Sitzplan-Links:** Der Link enthält einen kryptographischen Token, der an die genauen Reservierungs-IDs gebunden ist. Kunden können ausschließlich ihre eigenen Plätze als hervorgehoben sehen. Der Link ist dauerhaft gültig (kein Ablaufdatum). Bei Veranstaltungen ohne Sitzplan bleibt `{seatplan_link_html}` leer (kein Button in der E-Mail).

#### Tickets & Downloads

| Platzhalter | Inhalt |
|-------------|--------|
| `{ticket_link}` | Reine URL zum Ticket-Download |
| `{ticket_link_html}` | Fertiger blauer Button „Tickets herunterladen" |
| `{ticket_list}` | HTML-Liste der gebuchten Plätze (alias für `{seat_list}`) |

#### Gutscheine

| Platzhalter | Inhalt |
|-------------|--------|
| `{voucher_codes}` | Kommagetrennte Liste der Gutscheincodes |
| `{voucher_list}` | HTML-Block mit Gutschein-Details |
| `{quantity}` | Anzahl der Gutscheine |
| `{voucher_link}` | Link zur Gutschein-Ansicht |
| `{ticket_url}` | Basis-URL des Ticket-Systems |

#### Reservierung

| Platzhalter | Inhalt |
|-------------|--------|
| `{reservation_link}` | Link zur Reservierungsübersicht (wird derzeit nicht aktiv befüllt) |

#### Kombinierte Bestellung

| Platzhalter | Inhalt |
|-------------|--------|
| `{ticket_section}` | Vorgefertigter HTML-Block „Ihre Tickets" |
| `{voucher_section}` | Vorgefertigter HTML-Block „Ihre Gutscheine" |
| `{voucher_codes}` | Kommagetrennte Gutscheincodes |

### 15.4 Beispiel: Reservierungs-E-Mail mit Sitzplan-Link

```
Betreff: Ihre Reservierung – {event_name}

Hallo {customer_name},

vielen Dank für Ihre Reservierung!

Veranstaltung: {event_name}
Datum: {event_date} um {event_time}
Ihre Plätze:
{seat_list}

Möchten Sie Ihren Platz im Sitzplan sehen?
{seatplan_link_html}

Mit freundlichen Grüßen
Ihr Theater-Team
```

---

## 16. Dokumentation

Eigener Menüpunkt **Dokumentation** in der Sidebar. Zeigt alle als PDF hinterlegten Handbücher (Admin- und Vorverkauf-Handbuch). Jedes PDF kann angezeigt oder heruntergeladen werden.

PDFs liegen unter `docs/manuals/` und werden ausschließlich nach Auth-Check ausgeliefert. Vorverkauf-Mitarbeiter sehen das Admin-Handbuch nicht.

---

*Letzte Aktualisierung: 2026-04-28 | Version 2.1*
