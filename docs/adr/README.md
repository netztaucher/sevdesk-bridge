# Architecture Decision Records

Chronologische Entscheidungen für sevDesk Bridge. Format: Context / Decision / Consequences.

| # | Entscheidung | Status |
|---|---|---|
| [0001](0001-standalone-plugin.md) | Eigenständiges Plugin statt mu-plugin / Code-Snippet | akzeptiert |
| [0002](0002-secrets-in-wp-config.md) | API-Token als wp-config-Konstanten (nicht DB) | akzeptiert |
| [0003](0003-dom-injected-button.md) | DOM-injizierter Button + schwebendes Confirm-Popover | akzeptiert |
| [0004](0004-idempotency-ordermeta.md) | Idempotenz über OrderMeta | akzeptiert |
| [0005](0005-sevdesk-invoice-flow.md) | Rechnungs-Buchungsfluss + Kleinunternehmer-Steuer | akzeptiert |
| [0006](0006-storno-creditnote-eur.md) | Storno via Gutschrift + EÜR-Abfluss-Buchung | akzeptiert |
| [0007](0007-partial-refund-semi-manual.md) | Teil-Erstattung halb-manuell | akzeptiert |
| [0008](0008-restorno-via-repush.md) | „Storno des Stornos" via Neu-Übertragung | akzeptiert |
| [0009](0009-autopush-gated.md) | Auto-Push hinter Konstante + Option (default aus) | akzeptiert |
| [0010](0010-action-scheduler.md) | Action Scheduler für Async/Retry | akzeptiert |
