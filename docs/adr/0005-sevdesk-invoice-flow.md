# ADR-0005: Rechnungs-Buchungsfluss + Kleinunternehmer-Steuer

## Kontext
Eine sevDesk-Rechnung muss als *bezahlt* mit korrektem Zahldatum entstehen (EÜR-Zufluss). Die API-Mechanik ist nicht offensichtlich und wurde empirisch ermittelt.

## Entscheidung
Dreistufiger Fluss:
```
POST /Invoice/Factory/saveInvoice   (Status 100, Entwurf)
PUT  /Invoice/{id}/sendBy           (Status 200, festgeschrieben)
PUT  /Invoice/{id}/bookAmount       (Status 1000, bezahlt — EÜR-Zufluss = completed_at)
```
Kleinunternehmer §19: `taxType:'ss'` (Rechnungs-Factory mappt automatisch auf `taxRule 11` „Steuer nicht erhoben nach §19 UStG"), `taxRate:0`, `smallSettlement:1`.

## Begründung (empirisch gefundene Fallstricke)
- Das `invoice`-Objekt **muss** `objectName:'Invoice'` + `mapAll:true` enthalten, sonst HTTP 400 *„expected array with id and objectName"*.
- `taxType:'ss'` ist Pflicht — `'noUst'` ergibt HTTP 422 *„Could not find taxType"*.
- Neue Rechnungen **müssen** mit `status:100` erstellt werden (HTTP 422 *„New invoices must be created with status 100"*).
- `bookAmount` verweigert Entwürfe (*„A draft can not be paid"*) → vorher `sendBy` (Status 200).
- EÜR ist Ist-Besteuerung: zählt das **Zahldatum** der gebuchten Zahlung (`payDate`), nicht das Rechnungsdatum → `bookAmount.date = completed_at`.
- Rechnungsnummer wird erst bei `sendBy` vergeben → nach dem Buchen per `GET /Invoice/{id}` nachladen.
- `bookAmount.amount` in **EUR** (nicht Cent); FluentCart speichert Cent.

## Konsequenzen
- Festschreibung (`sendBy`) macht die Rechnung GoBD-immutabel → nicht mehr löschbar (siehe [ADR-0008](0008-restorno-via-repush.md)).
- Position-`text` trägt Metadaten (Termin, Bestellnr., Zahlungsart).
