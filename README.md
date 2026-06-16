# sevDesk Bridge

WordPress-Plugin, das **FluentCart-Bestellungen automatisch als Rechnungen in [sevDesk](https://sevdesk.de) überträgt** — inklusive Storno (Gutschrift), Wiederübertragung und EÜR-konformer Zahlungsbuchung für Kleinunternehmer (§19 UStG).

Ursprünglich für eine Massage-/Therapiepraxis entwickelt (Vor-Ort-Zahlung, Terminbuchung über FluentBooking), aber generisch für jeden FluentCart-Shop mit sevDesk nutzbar.

---

## Funktionsumfang

| Vorgang | Verhalten |
|---|---|
| **Push** | Bezahlte Bestellung → sevDesk-Rechnung (Status *bezahlt*), Zahlung gebucht mit echtem Zahldatum (EÜR-Zufluss) |
| **Auto-Push** | Hook auf `payment_status_changed_to_paid` → automatische Übertragung via Action Scheduler |
| **Storno** | FluentCart-Refund → sevDesk-Gutschrift, festgeschrieben + Auszahlung gebucht (EÜR-Abfluss) |
| **Wiederübertragung** | „Storno des Stornos" → **neue** Rechnung (GoBD-Kette), altes RE+GU bleibt archiviert |
| **Teil-Erstattung** | Halb-manuell: Plugin loggt Hinweis, Teilgutschrift wird in sevDesk-UI angelegt ([ADR-0007](docs/adr/0007-partial-refund-semi-manual.md)) |
| **Backfill** | WP-CLI `wp sevdesk push` + Admin-Bulk-Button für historische Bestellungen |
| **Metadaten** | Rechnung enthält Termin (Datum/Uhrzeit aus FluentBooking), Bestellnr., Zahlungsart |

Bedienung pro Bestellzeile über einen Button in der FluentCart-Orders-Tabelle (4 Zustände, Bestätigungs-Popover).

---

## Voraussetzungen

- WordPress + [FluentCart](https://fluentcart.com) (nutzt dessen Models + gebündelten Action Scheduler)
- PHP ≥ 7.4
- Optional: FluentBooking (für Termin-Metadaten)
- sevDesk-Account mit API-Token (Kleinunternehmer §19 — `taxRule 11`)

## Installation

1. Ordner `sevdesk-bridge/` nach `wp-content/plugins/` kopieren
2. Konstanten in `wp-config.php` setzen (siehe unten)
3. Plugin aktivieren

## Konfiguration (`wp-config.php`)

```php
define( 'SEVDESK_API_TOKEN', 'dein-sevdesk-api-token' );   // Pflicht
define( 'SEVDESK_CHECK_ACCOUNT_ID', 12345 );               // Pflicht — eigene sevDesk Bankkonto-ID
define( 'SEVDESK_DRY_RUN', false );                         // true = simulieren, keine Schreib-Calls
define( 'SEVDESK_AUTO_PUSH', false );                       // Master-Switch Auto-Push (default aus)
```

Secrets gehören bewusst in `wp-config.php` (nicht in die DB) — siehe [ADR-0002](docs/adr/0002-secrets-in-wp-config.md).

Zusätzlicher Runtime-Schalter unter **Einstellungen → sevDesk Bridge** (Option `sevdesk_auto_push_enabled`). Auto-Push greift nur, wenn **Konstante UND Option** an sind.

---

## Bedienung

### Button (FluentCart → Orders)

| Zustand | Button | Aktion |
|---|---|---|
| nicht übertragen | `→ sevDesk` (blau) | Rechnung anlegen |
| übertragen | `✓ RE-xxxx` (grün) | Stornieren (Gutschrift) |
| storniert | `↻ Neu übertragen` (amber) | neue Rechnung erzeugen |

Jede Aktion mit Bestätigungs-Popover (an `document.body`, immun gegen Tabellen-Re-Render — [ADR-0003](docs/adr/0003-dom-injected-button.md)).

### WP-CLI (Backfill)

```bash
wp sevdesk push --from=2026-01-01 --to=2026-06-30 --status=paid --limit=100 [--dry-run] [--include-pushed]
```

### REST

```
POST /wp-json/sevdesk-bridge/v1/orders/{id}/push
POST /wp-json/sevdesk-bridge/v1/orders/{id}/cancel
POST /wp-json/sevdesk-bridge/v1/orders/{id}/repush
GET  /wp-json/sevdesk-bridge/v1/status?ids=1,2,3
```
Alle nur mit `manage_options`.

---

## Architektur (Kurzform)

```
FluentCart Order ──(Button/Hook/CLI)──► Pusher ──► Client ──► sevDesk API
                                          │
                                          ├─ Logger   → FluentCart Activity-Stream
                                          ├─ OrderMeta → Idempotenz + History
                                          └─ Scheduler → Action Scheduler (async/retry)
```

Details: [docs/architecture.md](docs/architecture.md). Entscheidungen: [docs/adr/](docs/adr/). Endkunden-Anleitung: [docs/benutzerhandbuch.md](docs/benutzerhandbuch.md).

### sevDesk-Buchungsfluss

```
Push:   POST /Invoice/Factory/saveInvoice (Status 100)
        → PUT /Invoice/{id}/sendBy (Status 200, festgeschrieben)
        → PUT /Invoice/{id}/bookAmount (Status 1000 bezahlt, EÜR-Zufluss)

Storno: POST /Invoice/{id}/cancelInvoice → Gutschrift (CreditNote)
        → PUT /CreditNote/{id}/sendBy
        → PUT /CreditNote/{id}/bookAmount (EÜR-Abfluss)
```

Kleinunternehmer §19: `taxRule 11` / `taxType 'ss'`, `smallSettlement 1`, 0 % ([ADR-0005](docs/adr/0005-sevdesk-invoice-flow.md)).

---

## Bekannte Grenzen

- **Teil-Erstattungen** laufen halb-manuell — die sevDesk-API blockiert programmatische Teilgutschriften für §19-Rechnungskorrekturen ([ADR-0007](docs/adr/0007-partial-refund-semi-manual.md)).
- **Nur EUR** — andere Währungen werden übersprungen + geloggt.
- **Festgeschriebene Belege** sind GoBD-immutabel und nicht löschbar; Korrekturen laufen über Gutschrift/Neu-Übertragung ([ADR-0008](docs/adr/0008-restorno-via-repush.md)).
- **DATEV-Export per API** benötigt `accountingYearBegin` (nur über sevDesk-UI setzbar).

## Über

Entwickelt von **[netztaucher | digital](https://netztaucher.com/wordpress)** — WordPress-Projekte und individuelle Plugin-Entwicklung.

Du brauchst eine maßgeschneiderte WordPress-Lösung oder ein Plugin wie dieses für deinen Shop, dein CRM oder deine Buchhaltung? → **[netztaucher.com/wordpress](https://netztaucher.com/wordpress)**

## Lizenz

GPL-2.0 (wie WordPress).
