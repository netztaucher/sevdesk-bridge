<?php
namespace SevDeskBridge;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;

class Scheduler
{
    const HOOK_PUSH    = 'sevdesk_push_order';
    const HOOK_CANCEL  = 'sevdesk_cancel_invoice';
    const GROUP        = 'sevdesk';

    public static function register(): void
    {
        add_action(self::HOOK_PUSH, [self::class, 'runPush'], 10, 1);
        add_action(self::HOOK_CANCEL, [self::class, 'runCancel'], 10, 1);
    }

    public static function runPush(int $orderId): void
    {
        try {
            Pusher::push($orderId);
        } catch (\Throwable $e) {
            // Logger already called inside Pusher
        }
    }

    public static function runCancel(int $orderId): void
    {
        try {
            Pusher::cancel($orderId);
        } catch (\Throwable $e) {
            // Logger already called inside Pusher
        }
    }

    public static function enqueuePush(int $orderId): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_PUSH, [$orderId], self::GROUP);
            return;
        }
        wp_schedule_single_event(time() + 5, self::HOOK_PUSH, [$orderId]);
    }

    public static function enqueueCancel(int $orderId): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_CANCEL, [$orderId], self::GROUP);
            return;
        }
        wp_schedule_single_event(time() + 5, self::HOOK_CANCEL, [$orderId]);
    }

    public static function enqueueBulk(int $limit = 50, string $from = ''): int
    {
        $pushedOrderIds = OrderMeta::where('meta_key', Pusher::META_INVOICE_ID)->pluck('order_id')->toArray();

        $query = Order::where('payment_status', 'paid');
        if ($pushedOrderIds) {
            $query->whereNotIn('id', $pushedOrderIds);
        }
        if ($from) {
            $query->where('completed_at', '>=', $from . ' 00:00:00');
        }
        $orders = $query->orderBy('id', 'asc')->limit($limit)->get(['id']);

        $count = 0;
        foreach ($orders as $o) {
            self::enqueuePush((int) $o->id);
            $count++;
        }
        return $count;
    }
}
