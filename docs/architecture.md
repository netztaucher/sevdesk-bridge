# Architektur

## Komponenten

| Datei | Verantwortung |
|---|---|
| `sevdesk-bridge.php` | Bootstrap, Asset-Enqueue, FluentCart-Hooks, CLI-Registrierung |
| `inc/Client.php` | HTTP-Wrapper um die sevDesk-API. Dry-Run, 500 ms Rate-Limit, 401-Auto-Disable |
| `inc/Pusher.php` | Kern-Mapping FluentCart → sevDesk. Push, Storno, Re-Push, Idempotenz, Metadaten |
| `inc/Logger.php` | Wrapper um `fluent_cart_add_log()` → Einträge im FluentCart-Activity-Stream |
| `inc/Scheduler.php` | Action-Scheduler-Jobs (`sevdesk_push_order`, `sevdesk_cancel_invoice`), Bulk-Enqueue |
| `inc/Rest.php` | REST-Routen (push/cancel/repush/status) für den Button |
| `inc/Settings.php` | Admin-Seite (Status, Auto-Push-Toggle, Bulk-Button, Log-Tabelle, Retry) |
| `inc/CLI/PushCommand.php` | `wp sevdesk push` Backfill-Command |
| `assets/orders-button.js` | DOM-Injection des Buttons in die FluentCart-Orders-Tabelle + schwebendes Confirm-Popover |
| `assets/orders-button.css` | Button-/Popover-Styling |

## Datenfluss

```
                        ┌──────────────── Trigger ────────────────┐
                        │ Button (REST) │ Hook (paid/refund) │ CLI │
                        └──────┬─────────┴─────────┬──────────┴──┬─┘
                               ▼                   ▼             ▼
                          Scheduler.enqueue*  →  Action Scheduler (async, retry)
                               │
                               ▼
                          Pusher::push / cancel / rePush
                               │
             ┌─────────────────┼─────────────────────────┐
             ▼                 ▼                          ▼
        Client (HTTP)     OrderMeta (Idempotenz/      Logger
        → sevDesk API      History)                  → FluentCart Activity
```

## OrderMeta-Schlüssel

| Key | Bedeutung |
|---|---|
| `_sevdesk_invoice_id` | sevDesk-Rechnungs-ID (Idempotenz-Schlüssel) |
| `_sevdesk_invoice_no` | Rechnungsnummer (z. B. `RE-1002`) |
| `_sevdesk_pushed_at` | Zeitpunkt der Übertragung |
| `_sevdesk_canceled_at` | Zeitpunkt des Stornos |
| `_sevdesk_storno_invoice_id` | Gutschrift-ID |
| `_sevdesk_storno_invoice_no` | Gutschrift-Nummer (z. B. `GU-1000`) |
| `_sevdesk_parent_invoice_id` | Audit: bei Abo-Renewals die Eltern-Rechnung |
| `_sevdesk_refund_voucher_ids` | reserviert (Teil-Gutschriften, aktuell ungenutzt) |
| `_sevdesk_history` | JSON-Array archivierter RE+GU-Paare (bei Re-Push) |

Idempotenz: `push()` bricht ab, sobald `_sevdesk_invoice_id` gesetzt ist → kein Doppel-Push.

## sevDesk-API-Sequenzen

### Push (Einnahme / EÜR-Zufluss)

```
POST /Invoice/Factory/saveInvoice
     invoice: { objectName:'Invoice', mapAll:true, status:100, taxType:'ss',
                taxRate:0, smallSettlement:1, currency:'EUR', ... }
     invoicePosSave: [ { objectName:'InvoicePos', mapAll:true, name, price, taxRate:0,
                         text:<Termin + Bestell-/Zahl-Metadaten> } ]
→ PUT /Invoice/{id}/sendBy   { sendType:'VPR', sendDraft:false }   // 100 → 200, festgeschrieben
→ PUT /Invoice/{id}/bookAmount { amount, date:completed_at, type:'N',
                                 checkAccount:{id}, createFeed:true } // → 1000 bezahlt
→ GET /Invoice/{id}          // Rechnungsnummer nach-laden (wird erst bei sendBy vergeben)
```

### Storno (Erstattung / EÜR-Abfluss)

```
POST /Invoice/{id}/cancelInvoice            → Gutschrift (CreditNote, Status 100, voller Betrag)
→ PUT /CreditNote/{id}/sendBy               // festschreiben
→ PUT /CreditNote/{id}/bookAmount { amount, date:refunded_at, ... }  // Auszahlung gebucht
```

Original-Rechnung behält Status 1000. Die Gutschrift ist ein **Ausgaben-Beleg** (Minderleistung/`UNDERACHIEVEMENT`) → reduziert die Einnahmen in der EÜR.

### Re-Push (Wiederübertragung)

```
1. _sevdesk_history  ← aktuelles RE+GU archivieren
2. Invoice- + Storno-Meta löschen
3. push(force=true)  → neue Rechnung (force umgeht den „erstattet"-Guard)
```

## Edge-Guards (in `push()`)

- keine Items → Skip
- Währung ≠ EUR → Skip
- bereits gepusht (`_sevdesk_invoice_id`) → Skip (idempotent)
- Status erstattet → Skip (außer `force` bei Re-Push)
- Contact-Tiebreaker bei mehreren sevDesk-Kontakten gleicher E-Mail (Namens-Match → neuester)

## Resilienz

- **Dry-Run** (`SEVDESK_DRY_RUN`): schreibende Calls werden gefälscht, GET-Calls laufen real, Payloads werden geloggt.
- **Rate-Limit**: 500 ms `usleep` zwischen Calls (sevDesk-Limit ~2 req/s).
- **401-Handling**: setzt `sevdesk_auto_push_disabled_reason`, Admin-Notice, blockt Auto-Push.
- **Action Scheduler**: Retry bei transienten Fehlern, serielle Verarbeitung.
