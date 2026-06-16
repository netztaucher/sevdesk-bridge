# Changelog

Format orientiert an [Keep a Changelog](https://keepachangelog.com/de/). Versionierung SemVer.

## [0.7.1]
### Behoben
- **Auto-Push/Storno-Hooks feuerten nicht**: FluentCart-Status-Events liefern ein Array `['order'=>…, 'new_status'=>…]`, die Hooks lasen aber `$order['id']` → Order-ID 0. Neue Helper `sevdesk_bridge_order_id()` extrahiert die ID robust aus Array-Payload oder Order-Objekt.

## [0.7.0]
### Hinzugefügt
- **Wiederübertragung / „Storno des Stornos"**: `Pusher::rePush()` erzeugt nach einem Storno eine neue Rechnung; altes RE+GU wird in `_sevdesk_history` archiviert (ADR-0008).
- REST `POST /orders/{id}/repush`; Button-Zustand „storniert" wird klickbar (`↻ Neu übertragen`).
- `push()` erhält `force`-Flag (umgeht „erstattet"-Guard für Re-Push).

## [0.6.0]
### Geändert
- Kaputten Auto-Pfad für Teil-Erstattungen entfernt (`saveCreditNote` API-seitig blockiert) → Teil-Erstattung halb-manuell mit actionable Log-Hinweis (ADR-0007).

## [0.5.0]
### Hinzugefügt
- EÜR-konformes Voll-Storno: Gutschrift wird festgeschrieben (`sendBy`) + Auszahlung gebucht (`bookAmount`, Datum = `refunded_at`) → EÜR-Abfluss (ADR-0006).
- Hook `payment_status_changed_to_refunded` → Storno (fängt manuelle Status-Änderung).

## [0.4.0]
### Geändert
- Confirm-Bestätigung als schwebendes Popover an `document.body` (immun gegen Vue-Table-Re-Render bei Hover); Button-State-Cache (ADR-0003).

## [0.3.0]
### Hinzugefügt
- Termin-Metadaten (Datum/Uhrzeit aus FluentBooking) + Bestellnr. + Zahlungsart im Rechnungs-Positionstext.
- Auto-Push-Hook + Teil-Refund-Hinweis.

## [0.2.0]
### Hinzugefügt
- Logger (FluentCart Activity-Stream), Dry-Run, Rate-Limit, 401-Auto-Disable.
- Edge-Guards (keine Items / Non-EUR / refunded), Contact-Tiebreaker.
- Settings-Seite (Status, Auto-Push-Toggle, Bulk-Button, Log-Tabelle, Retry).
- WP-CLI `wp sevdesk push` (Backfill), Scheduler (Action Scheduler), Storno-Grundgerüst.
### Behoben
- Korrekte FluentCart-Feldnamen (`post_title`, `name`, `postcode`, `order_items`, `billing_address`).
- sevDesk-Buchungsfluss: `saveInvoice` (Status 100) → `sendBy` → `bookAmount`; `taxType:'ss'`; `objectName/mapAll` (ADR-0005).

## [0.1.0]
### Hinzugefügt
- Erstes Gerüst: Button pro Order-Zeile, REST-Push, Contact find-or-create, Invoice-Erstellung, OrderMeta-Idempotenz.
