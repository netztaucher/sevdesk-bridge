# ADR-0007: Teil-Erstattung halb-manuell

## Kontext
Bei einer Teil-Erstattung in FluentCart soll idealerweise eine Teilgutschrift über den Teilbetrag in sevDesk entstehen. `cancelInvoice` kann nur **voll** stornieren — eine Teilgutschrift erfordert `POST /CreditNote/Factory/saveCreditNote`.

## Entscheidung
Teil-Erstattung wird **nicht** automatisch übertragen. `fluent_cart/order_partially_refunded` loggt einen actionable Hinweis (Betrag + Rechnungsnummer); die Teilgutschrift wird in der **sevDesk-UI** angelegt.

## Begründung
Die sevDesk-API blockiert programmatische Teilgutschriften für §19-Rechnungskorrekturen. Alle Feld-Kombinationen wurden empirisch durchgetestet:
- `bookingCategory` ist Pflicht — nur `UNDERACHIEVEMENT` ist gültig (`REPAYMENT` → HTTP 422 „not supported").
- `UNDERACHIEVEMENT` erzwingt einen Origin-Bezug (`refer`) mit strikter Validierung.
- Diese Validierung lehnt mit **`Different taxRate than origin document: 0`** ab — obwohl Origin und Gutschrift beide `taxRate:0` / `taxRule:11` tragen.
- Zusätzlich Pflichtfelder: `deliveryDate` (code 460), `taxRule` (sonst „taxRule cannot be null").
- Kein Feld-Setup brachte die Factory durch → API-seitig nicht sauber lösbar.

Für eine Massagepraxis mit Vor-Ort-Zahlung sind Teil-Erstattungen ohnehin selten → der manuelle Weg ist vertretbar und vermeidet falsche Buchungen.

## Konsequenzen
- Kein toter/halb-funktionierender Auto-Code im Plugin (der Versuchs-Pfad wurde wieder entfernt).
- `_sevdesk_refund_voucher_ids` bleibt als Meta-Key reserviert, falls sevDesk die Factory später öffnet.
- Voll-Erstattung läuft automatisch ([ADR-0006](0006-storno-creditnote-eur.md)).
