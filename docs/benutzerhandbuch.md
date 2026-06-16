# Benutzerhandbuch – sevDesk Bridge

Dieses Handbuch erklärt, wie deine Bestellungen automatisch als Rechnungen in **sevDesk** landen – ohne dass du etwas doppelt eintippen musst.

> Kurz gesagt: Bezahlt ein Kunde, entsteht in sevDesk automatisch eine fertige, bezahlte Rechnung. Den Rest – Stornos, Korrekturen – erledigst du mit einem Klick.

---

## 1. Was macht das Plugin?

- Sobald eine Bestellung in deinem Shop **bezahlt** ist, wird in sevDesk automatisch eine **Rechnung** erstellt und als **bezahlt** verbucht.
- Die Rechnung enthält bereits alles Wichtige: Kunde, Leistung, Betrag und – bei Terminbuchungen – **Datum und Uhrzeit des Termins**.
- Du arbeitest weiter wie gewohnt in deinem Shop. sevDesk wird im Hintergrund mitgepflegt.

Du bist **Kleinunternehmerin (§19)** – deshalb wird auf allen Rechnungen **keine Umsatzsteuer** ausgewiesen. Das ist so eingestellt und richtig.

---

## 2. Der Überblick: die Bestell-Liste

Du findest deine Bestellungen im Shop-Menü unter **FluentCart → Bestellungen (Orders)**.

In jeder Bestellzeile siehst du einen kleinen farbigen Knopf. Seine **Farbe zeigt den Stand** in sevDesk:

| Knopf | Bedeutung | Was ein Klick macht |
|---|---|---|
| 🔵 **→ sevDesk** | Noch keine Rechnung in sevDesk | Rechnung anlegen |
| 🟢 **✓ RE-1234** | Rechnung existiert (Nummer wird angezeigt) | Rechnung **stornieren** |
| 🟠 **↻ Neu übertragen** | Wurde storniert | **Neue** Rechnung erzeugen |

Nach jedem Klick erscheint eine kleine **Sicherheitsabfrage „Ja / Nein"** – so passiert nichts aus Versehen.

---

## 3. Der normale Ablauf (automatisch)

Im Normalfall musst du **gar nichts** tun:

1. Kunde bucht/bestellt und bezahlt.
2. Das Plugin erstellt automatisch die Rechnung in sevDesk und markiert sie als **bezahlt** – mit dem korrekten **Zahlungsdatum**.
3. Der Knopf in der Bestellzeile wird **grün** und zeigt die Rechnungsnummer (z. B. `✓ RE-1234`).

Fertig. Die Rechnung liegt in sevDesk bereit.

---

## 4. Eine Rechnung von Hand anlegen

Manchmal willst du selbst auslösen (z. B. bei einer älteren Bestellung):

1. In der Bestell-Liste auf den blauen Knopf **→ sevDesk** klicken.
2. Die Abfrage **„Rechnung in sevDesk anlegen?"** mit **Ja** bestätigen.
3. Der Knopf wird grün und zeigt die neue Rechnungsnummer.

Ein zweimaliges Anlegen ist nicht möglich – ist schon eine Rechnung da, passiert nichts doppelt.

---

## 5. Stornieren (Geld zurück / Bestellung erstattet)

Wenn du eine Bestellung **erstattest** (z. B. abgesagter Termin), wird das auch in sevDesk berücksichtigt:

- **Automatisch:** Setzt du die Bestellung in deinem Shop auf „erstattet", erstellt das Plugin in sevDesk eine **Gutschrift** und verbucht die Rückzahlung.
- **Von Hand:** Auf den grünen Knopf **✓ RE-1234** klicken → Abfrage **„Stornieren? Erstellt Gutschrift."** mit **Ja** bestätigen.

Wichtig zu wissen:
- Die ursprüngliche Rechnung bleibt in sevDesk **bestehen** (das schreibt das Finanzamt so vor, GoBD). Zusätzlich entsteht eine **Gutschrift**, die den Betrag wieder ausgleicht.
- In deiner EÜR (Einnahmen-Überschuss-Rechnung) wird die Gutschrift als **Minderung der Einnahmen** geführt – also korrekt verrechnet.

---

## 6. „Doch wieder gültig" – Neu übertragen

Hast du versehentlich storniert, oder die Bestellung ist doch gültig?

1. Auf den orangen Knopf **↻ Neu übertragen** klicken.
2. Abfrage **„Rechnung neu übertragen?"** mit **Ja** bestätigen.
3. Es entsteht eine **neue** Rechnung (mit neuer Nummer).

Hinweis: Eine einmal ausgestellte Gutschrift kann gesetzlich **nicht gelöscht** werden. Deshalb wird nicht „rückgängig gemacht", sondern eine **neue Rechnung** erstellt. In sevDesk siehst du dann die saubere Kette: Rechnung → Gutschrift → neue Rechnung.

---

## 7. Teil-Erstattungen (nur ein Teilbetrag zurück)

Erstattest du nur **einen Teil** des Betrags, kann das Plugin die Gutschrift **nicht automatisch** anlegen (technische Einschränkung von sevDesk bei Kleinunternehmer-Rechnungen).

Was passiert:
- Das Plugin schreibt dir einen **Hinweis** (siehe Kapitel 9) mit dem **Betrag** und der **Rechnungsnummer**.
- Du legst die **Teil-Gutschrift** dann einmalig direkt in sevDesk an (in sevDesk: zur Rechnung gehen → „Storno / Gutschrift" → Teilbetrag).

Bei Vor-Ort-Bezahlung kommt das selten vor.

---

## 8. Was steht auf der Rechnung?

Jede Rechnung enthält automatisch:

- **Leistung** (z. B. „Thaimassage")
- **Termin**: Datum und Uhrzeit (wenn die Bestellung aus einer Terminbuchung stammt) – z. B. *„Termin: 18.06.2026, 15:00–15:45 Uhr"*
- **Bestellnummer** und **Bestelldatum**
- **Zahlungsart** (z. B. „vor Ort") und **Zahldatum**
- Hinweis **„Gemäß §19 UStG nicht ausgewiesen"** (keine Umsatzsteuer)

---

## 9. Wo sehe ich, was passiert ist?

Es gibt zwei Stellen:

1. **In der Bestellung selbst** – im Aktivitäts-/Verlauf-Bereich der Bestellung stehen Einträge mit dem Kürzel **`[sevDesk]`** (z. B. „Rechnung RE-1234 angelegt").
2. **Auf der Plugin-Seite** unter **Einstellungen → sevDesk Bridge** – dort siehst du die letzten Übertragungen, etwaige Fehler und Hinweise (z. B. zu Teil-Erstattungen).

---

## 10. Wenn etwas nicht klappt

**Der Knopf erscheint nicht in der Liste.**
→ Seite einmal komplett neu laden (Tastenkombination **Strg + F5**, am Mac **Cmd + Shift + R**).

**Der Knopf wird rot („✗").**
→ Es gab einen Fehler bei der Übertragung. Fahre mit der Maus über den Knopf – der Text zeigt den Grund. Häufigste Ursache: sevDesk war kurz nicht erreichbar. Einfach den Knopf noch einmal anklicken.

**Es entstehen plötzlich gar keine Rechnungen mehr automatisch.**
→ Auf der Plugin-Seite (Einstellungen → sevDesk Bridge) steht oben, ob alles „OK" ist. Steht dort eine Warnung zum **Zugang/Token**, muss der sevDesk-Zugang erneuert werden – dann bitte deinen technischen Betreuer informieren.

**Eine Rechnung sieht falsch aus / hat den falschen Betrag.**
→ Nicht in sevDesk „herumkorrigieren". Stattdessen in der Bestell-Liste **stornieren** und **neu übertragen** – oder kurz Bescheid geben.

---

## 11. Was du besser **nicht** tun solltest

- **Rechnungen/Gutschriften in sevDesk nicht von Hand löschen** – sobald sie festgeschrieben sind, geht das ohnehin nicht, und es bringt die Verknüpfung durcheinander. Korrekturen immer über die Knöpfe (Stornieren / Neu übertragen).
- **Rechnungsnummern nicht selbst vergeben** – sevDesk macht das automatisch fortlaufend.
- Bei Unsicherheit: lieber einmal **stornieren + neu übertragen** als in sevDesk manuell eingreifen.

---

## 12. Kurz-Spickzettel

| Ich will … | … so geht's |
|---|---|
| Nichts tun, läuft automatisch | Kunde zahlt → Rechnung entsteht von selbst |
| Rechnung manuell anlegen | 🔵 **→ sevDesk** → Ja |
| Erstatten / stornieren | 🟢 **✓ RE-…** → Ja |
| Doch wieder gültig | 🟠 **↻ Neu übertragen** → Ja |
| Teil-Erstattung | In sevDesk von Hand (Plugin gibt Hinweis) |
| Status / Fehler ansehen | Einstellungen → sevDesk Bridge |
| Knopf fehlt | Seite neu laden (Strg/Cmd + F5) |

---

*Bei Fragen oder Problemen: deinen technischen Betreuer kontaktieren.*
