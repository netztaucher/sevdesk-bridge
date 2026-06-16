<?php
namespace SevDeskBridge\CLI;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use SevDeskBridge\Pusher;

class PushCommand
{
    /**
     * Push FluentCart orders to sevDesk.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : ISO date YYYY-MM-DD. Filter completed_at >=.
     *
     * [--to=<date>]
     * : ISO date YYYY-MM-DD. Filter completed_at <=.
     *
     * [--status=<status>]
     * : payment_status filter. Default: paid.
     *
     * [--limit=<n>]
     * : Max number of orders. Default: 100.
     *
     * [--dry-run]
     * : Override: ignore SEVDESK_DRY_RUN constant — runs in dry-run regardless.
     *
     * [--include-pushed]
     * : Include orders that already have _sevdesk_invoice_id (idempotent — they get logged-skipped, not re-pushed).
     *
     * ## EXAMPLES
     *
     *     wp sevdesk push --from=2026-01-01 --limit=10 --dry-run
     *     wp sevdesk push --status=paid --limit=500
     *
     * @when after_wp_load
     */
    public function push($args, $assoc_args): void
    {
        $from   = (string) ($assoc_args['from'] ?? '');
        $to     = (string) ($assoc_args['to'] ?? '');
        $status = (string) ($assoc_args['status'] ?? 'paid');
        $limit  = max(1, (int) ($assoc_args['limit'] ?? 100));
        $includePushed = isset($assoc_args['include-pushed']);

        if (isset($assoc_args['dry-run']) && !defined('SEVDESK_DRY_RUN')) {
            define('SEVDESK_DRY_RUN', true);
        }

        $query = Order::where('payment_status', $status);
        if ($from) $query->where('completed_at', '>=', $from . ' 00:00:00');
        if ($to)   $query->where('completed_at', '<=', $to   . ' 23:59:59');

        if (!$includePushed) {
            $pushedOrderIds = OrderMeta::where('meta_key', Pusher::META_INVOICE_ID)->pluck('order_id')->toArray();
            if ($pushedOrderIds) {
                $query->whereNotIn('id', $pushedOrderIds);
            }
        }

        $orders = $query->orderBy('id', 'asc')->limit($limit)->get();
        $total  = count($orders);

        if (!$total) {
            \WP_CLI::log('Keine passenden Orders gefunden.');
            return;
        }

        $dryRunNote = (defined('SEVDESK_DRY_RUN') && SEVDESK_DRY_RUN) ? ' [DRY-RUN]' : '';
        \WP_CLI::log("Pushe {$total} Orders{$dryRunNote}...");

        $progress = \WP_CLI\Utils\make_progress_bar('sevDesk Push', $total);
        $okCount = $skipCount = $errCount = 0;

        foreach ($orders as $o) {
            $oid = (int) $o->id;
            try {
                $res = Pusher::push($oid);
                if (!empty($res['already_pushed'])) {
                    $skipCount++;
                } else {
                    $okCount++;
                }
            } catch (\Throwable $e) {
                $errCount++;
                \WP_CLI::warning("Order {$oid}: " . $e->getMessage());
            }
            $progress->tick();
        }
        $progress->finish();

        \WP_CLI::success("Fertig — pushed: {$okCount}, skipped (idempotent): {$skipCount}, failed: {$errCount}");
    }
}
