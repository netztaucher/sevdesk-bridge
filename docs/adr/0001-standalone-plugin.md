# ADR-0001: Eigenständiges Plugin statt mu-plugin / Code-Snippet

## Kontext
Der sevDesk-Sync ist site-spezifischer Custom-Code für eine konkrete WordPress-Shop-Installation. Optionen: mu-plugin (single-file, immer aktiv), Code-Snippets-Plugin (UI-editierbar) oder eigenständiges Plugin.

## Entscheidung
Eigenständiges Plugin `sevdesk-bridge/` mit klarer Datei-/Klassentrennung (`inc/*`, `assets/*`).

## Begründung
- Mehrere Klassen + Assets (JS/CSS) + WP-CLI + REST → zu groß für ein Single-File-mu-plugin oder einen Snippet.
- De-/Aktivierbarkeit über die Admin-UI ist beim Debuggen nützlich (z. B. Sync temporär abschalten).
- Saubere Versionierung + Git-Historie möglich.

## Konsequenzen
- Etwas mehr Boilerplate (Plugin-Header, Bootstrap) als ein Snippet.
- Plugin kann versehentlich deaktiviert werden → Auto-Push stoppt still. Mitigation: Status-Block auf der Settings-Seite.
