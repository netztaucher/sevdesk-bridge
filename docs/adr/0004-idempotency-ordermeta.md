# ADR-0004: Idempotenz über OrderMeta

## Kontext
Push kann mehrfach ausgelöst werden (Button-Doppelklick, Hook + manueller Push, Bulk-Lauf, Action-Scheduler-Retry). Doppelte Rechnungen in sevDesk sind teuer rückgängig zu machen (Festschreibung).

## Entscheidung
`_sevdesk_invoice_id` in FluentCart-OrderMeta als Idempotenz-Schlüssel. `push()` bricht früh ab, sobald gesetzt.

## Begründung
- OrderMeta ist die natürliche, transaktionsnahe Ablage pro Bestellung (FluentCart-Model).
- Ein einzelner Schlüssel reicht: vorhanden = bereits übertragen.
- WP-CLI + Bulk filtern zusätzlich vorab (`whereNotIn` gepushte IDs), die echte Garantie liegt aber im `push()`-Guard.

## Konsequenzen
- Re-Push (Wiederübertragung) muss den Schlüssel **bewusst löschen** und vorher archivieren (siehe [ADR-0008](0008-restorno-via-repush.md)).
- Storno-Status liegt getrennt (`_sevdesk_canceled_at`, `_sevdesk_storno_invoice_id`), damit Original-Referenz erhalten bleibt.
- Bei sevDesk-seitiger Idempotenz (`CreditNote exists`, HTTP 422) wird die vorhandene Gutschrift übernommen statt neu erstellt.
