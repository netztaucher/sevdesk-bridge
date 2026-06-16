# ADR-0009: Auto-Push hinter Konstante + Option (default aus)

## Kontext
Auto-Push überträgt jede bezahlte Bestellung automatisch. Eine Fehlkonfiguration würde bei jedem Verkauf echte (festgeschriebene) Belege erzeugen.

## Entscheidung
Zwei Schalter, beide müssen an sein:
- **`SEVDESK_AUTO_PUSH`** (wp-config-Konstante) — Master-Switch, Code-/Deploy-Ebene, default `false`.
- **`sevdesk_auto_push_enabled`** (wp_option) — Runtime-Switch, über die Settings-Seite.

Reihenfolge der Inbetriebnahme: erst **Backfill** (CLI/Bulk, idealerweise Dry-Run) verifizieren, dann Auto-Push scharf schalten.

## Begründung
- Auto-Push erzeugt unumkehrbare Buchhaltungsbelege → konservativer Default ist Pflicht.
- Backfill vor Auto-Push verhindert, dass Fehlkonfigurationen API-Calls bei jedem Verkauf verbrennen.
- 401-Handler deaktiviert Auto-Push automatisch bei ungültigem Token (`sevdesk_auto_push_disabled_reason` + Admin-Notice).
- Trennung Master/Runtime: Operatoren können ohne Server-Zugriff pausieren, der Master-Switch bleibt Deploy-kontrolliert.

## Konsequenzen
- Nach Plugin-Deploy ist Auto-Push aus, bis explizit aktiviert.
- Renewals (Abos) werden vom selben `payment_status_changed_to_paid`-Hook erfasst; Eltern-Rechnung als Audit in `_sevdesk_parent_invoice_id`.
