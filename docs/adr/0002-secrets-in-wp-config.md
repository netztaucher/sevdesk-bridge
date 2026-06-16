# ADR-0002: API-Token als wp-config-Konstanten

## Kontext
Der sevDesk-API-Token und die CheckAccount-ID müssen gespeichert werden. Optionen: `wp_options` (DB, ggf. verschlüsselt) oder wp-config-Konstanten.

## Entscheidung
`SEVDESK_API_TOKEN` und `SEVDESK_CHECK_ACCOUNT_ID` als `define()` in `wp-config.php`.

## Begründung
- Token landet **nicht in der Datenbank** → nicht in DB-Backups/Exports, nicht über kompromittierte Admin-UI auslesbar.
- Keine Verschlüsselungs-Logik im Plugin nötig.
- Deploy-Steuerung über Konstanten passt zur restlichen Plugin-Konfiguration (`SEVDESK_DRY_RUN`, `SEVDESK_AUTO_PUSH`).

## Konsequenzen
- Konfiguration nur per Server-Zugriff änderbar (nicht über die WP-Admin-UI) — bewusst, da Secret.
- `wp-config.php` muss bei Server-Migration mitgenommen werden.
- Runtime-Schalter (Auto-Push an/aus) liegt zusätzlich als `wp_option` vor, damit Operatoren ohne Server-Zugriff toggeln können — aber Master-Switch bleibt die Konstante.
