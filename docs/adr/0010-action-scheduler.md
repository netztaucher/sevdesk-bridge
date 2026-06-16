# ADR-0010: Action Scheduler für Async/Retry

## Kontext
Push/Storno sollen nicht synchron im Request laufen (mehrere langsame API-Calls mit 500 ms Rate-Limit) und bei transienten Fehlern wiederholt werden.

## Entscheidung
FluentCarts gebündelten **Action Scheduler** (`woocommerce/action-scheduler` in `vendor/`) nutzen:
```
as_enqueue_async_action('sevdesk_push_order',   [$orderId], 'sevdesk');
as_enqueue_async_action('sevdesk_cancel_invoice',[$orderId], 'sevdesk');
```
Fallback auf `wp_schedule_single_event`, falls Action Scheduler fehlt.

## Begründung
- Bereits durch FluentCart geladen → **keine zusätzliche Abhängigkeit**.
- Eingebauter Retry mit Backoff, serielle Verarbeitung pro Gruppe, Admin-UI unter *Tools → Geplante Aktionen*.
- Robuster als WP-Cron auf Low-Traffic-Sites; idempotente Job-Deduplizierung.
- Eigene Retry-Logik (WP-Cron + Zähler in OrderMeta) wäre fehleranfälliger Mehraufwand.

## Konsequenzen
- Button-Aktionen laufen synchron über REST (sofortiges Feedback), Auto-Push/Refund-Hooks laufen async über den Scheduler.
- Bei Wegfall von FluentCart (und damit Action Scheduler) greift der WP-Cron-Fallback.
