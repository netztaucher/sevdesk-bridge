# Betriebs-Runbook

## Deployment-Ziel

- WordPress-Installation mit FluentCart (z. B. auf einem Plesk-/Managed-Hosting)
- Plugin-Pfad: `<wp-root>/wp-content/plugins/sevdesk-bridge/`
- WP-CLI als jeweiliger Site-User ausführen (z. B. `sudo -u <site-user> wp …`)

## Inbetriebnahme-Reihenfolge

1. Konstanten in `wp-config.php` setzen (`SEVDESK_API_TOKEN`, `SEVDESK_CHECK_ACCOUNT_ID`), `SEVDESK_DRY_RUN=true`.
2. Plugin aktivieren: `wp plugin activate sevdesk-bridge`.
3. **Dry-Run-Backfill** prüfen: `wp sevdesk push --limit=3 --dry-run` → Payloads im Activity-Log.
4. `SEVDESK_DRY_RUN=false`, einen echten Push testen → Rechnung in sevDesk verifizieren.
5. Backfill historischer Bestellungen: `wp sevdesk push --from=YYYY-MM-DD`.
6. Erst danach Auto-Push: `SEVDESK_AUTO_PUSH=true` + Settings-Checkbox „Auto-Push" an.

## Beispiel-Live-Status (nach Inbetriebnahme)

```
SEVDESK_DRY_RUN           = false   (live)
SEVDESK_AUTO_PUSH         = true    (Auto-Push aktiv)
sevdesk_auto_push_enabled = on
CheckAccount              = <deine sevDesk-Bankkonto-ID>
```

## EÜR-Verifikation (offen)

Die EÜR-**Richtung** des Stornos ist über den Beleg-Typ korrekt (Gutschrift = Minderleistung = Erlösschmälerung, reduziert Einnahmen — siehe ADR-0006). Für eine UI-seitige 100 %-Bestätigung:

1. **sevDesk → Auswertungen/Berichte → EÜR** ansehen, oder
2. **DATEV-Export über die sevDesk-UI** (setzt dabei `accountingYearBegin`), oder
3. Steuerberater den DATEV-Stapel prüfen lassen.

DATEV-Export **per API** ist nicht möglich, solange `accountingYearBegin` null ist (nur UI-/Admin-seitig setzbar; per API-Token „Access denied").

## Test-Artefakte in sevDesk (aus der Entwicklung)

Live-Tests erzeugen **festgeschriebene und damit nicht löschbare** Belege (Rechnungen + Gutschriften). Entfernen festgeschriebener Belege geht nur über sevDesk-Support.

Empfehlung: Tests mit einer **Wegwerf-Test-Bestellung** durchführen, nicht auf echten Kundenbestellungen. In Produktion (variable Beträge) entsteht das Übermatch-Artefakt (CreditNote-Status 750) nicht — es tritt nur bei mehreren identischen Testbeträgen auf einem Offline-Konto auf.

## Diagnose

- **Logs**: FluentCart Order-Activity-Stream (Titel-Präfix `[sevDesk]`) + Settings-Seite (letzte 50 Versuche).
- **Health**: Settings-Seite zeigt 401-Disable-Grund; Option `sevdesk_auto_push_disabled_reason`.
- **Jobs**: Tools → Geplante Aktionen (Action Scheduler, Gruppe `sevdesk`).
- **Dry-Run-Schnelltest**: `SEVDESK_DRY_RUN=true` setzen, Aktion auslösen, Payloads im Log prüfen, zurückstellen.
