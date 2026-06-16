# ADR-0006: Storno via Gutschrift + EÜR-Abfluss-Buchung

## Kontext
Wird eine Bestellung in FluentCart voll erstattet/storniert, muss das in sevDesk EÜR-konform abgebildet werden (Abfluss zum Erstattungsdatum).

## Entscheidung
```
POST /Invoice/{id}/cancelInvoice   → Gutschrift (CreditNote)
PUT  /CreditNote/{id}/sendBy        → festschreiben
PUT  /CreditNote/{id}/bookAmount    → Auszahlung buchen (date = refunded_at)
```
Ausgelöst durch `fluent_cart/order_fully_refunded` **und** `fluent_cart/payment_status_changed_to_refunded` (fängt auch manuelle Status-Änderung im Admin).

## Begründung
- `cancelInvoice` (nicht `cancel` — letzteres ergibt HTTP 500 *„wrong HTTP Type"*) erzeugt eine an die Rechnung gekoppelte **Gutschrift** (`bookingCategory: UNDERACHIEVEMENT` = Minderleistung), keine Storno-Rechnung.
- sevDesk wertet die Gutschrift als **Ausgaben-Beleg / Erlösschmälerung** → in der EÜR **reduziert** sie die Einnahmen. Die Richtung kommt vom Beleg-Typ, nicht vom Vorzeichen der Bank-Transaktion (die ist positiv = Betrag).
- Symmetrie zum Push: festschreiben + Zahlung buchen, damit der **Abfluss** mit Erstattungsdatum in der EÜR erscheint.
- Die Original-Rechnung behält Status 1000; Rechnung + Gutschrift saldieren netto 0 (GoBD-konform, beide bleiben sichtbar).
- `bookAmount` wird mit `creditNoteNumber`/`creditNoteType` geparst; bei bereits existierender Gutschrift liefert sevDesk HTTP 422 `CreditNote exists` (code 170) → vorhandene wird übernommen (idempotent).

## Konsequenzen
- Auf einem **Offline-Bankkonto** kann `bookAmount` mit `createFeed:true` Gutschrift-Buchungen an gleich hohe/gleich datierte Transaktionen auto-matchen → Status 750 (Übermatch). In Produktion mit variablen Beträgen unkritisch; mit identischen Testbeträgen tritt es auf.
- Die EÜR-**Richtung** ist über den Beleg-Typ (Minderleistung) korrekt; eine vollständige Verifikation per DATEV-Export scheitert an fehlendem `accountingYearBegin` (nur UI-seitig setzbar).
