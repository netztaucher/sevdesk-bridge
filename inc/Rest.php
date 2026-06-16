<?php
namespace SevDeskBridge;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Models\OrderMeta;

class Rest
{
    public static function register(): void
    {
        $perm = function () { return current_user_can('manage_options'); };
        $idArg = ['id' => ['validate_callback' => function ($v) { return ctype_digit((string) $v); }]];

        register_rest_route('sevdesk-bridge/v1', '/orders/(?P<id>\d+)/push', [
            'methods'             => 'POST',
            'permission_callback' => $perm,
            'callback'            => [self::class, 'push'],
            'args'                => $idArg,
        ]);

        register_rest_route('sevdesk-bridge/v1', '/orders/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'permission_callback' => $perm,
            'callback'            => [self::class, 'cancel'],
            'args'                => $idArg,
        ]);

        register_rest_route('sevdesk-bridge/v1', '/orders/(?P<id>\d+)/repush', [
            'methods'             => 'POST',
            'permission_callback' => $perm,
            'callback'            => [self::class, 'repush'],
            'args'                => $idArg,
        ]);

        register_rest_route('sevdesk-bridge/v1', '/status', [
            'methods'             => 'GET',
            'permission_callback' => $perm,
            'callback'            => [self::class, 'status'],
        ]);
    }

    public static function push(\WP_REST_Request $req)
    {
        $orderId = (int) $req->get_param('id');
        try {
            $result = Pusher::push($orderId);
            $result['state'] = self::orderState($orderId);
            return new \WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['error' => $e->getMessage(), 'order' => $orderId], 500);
        }
    }

    public static function cancel(\WP_REST_Request $req)
    {
        $orderId = (int) $req->get_param('id');
        try {
            $result = Pusher::cancel($orderId);
            $result['state'] = self::orderState($orderId);
            return new \WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['error' => $e->getMessage(), 'order' => $orderId], 500);
        }
    }

    public static function repush(\WP_REST_Request $req)
    {
        $orderId = (int) $req->get_param('id');
        try {
            $result = Pusher::rePush($orderId);
            $result['state'] = self::orderState($orderId);
            return new \WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['error' => $e->getMessage(), 'order' => $orderId], 500);
        }
    }

    public static function status(\WP_REST_Request $req)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $req->get_param('ids'))));
        if (!$ids) return new \WP_REST_Response([], 200);

        $rows = OrderMeta::whereIn('order_id', $ids)
            ->whereIn('meta_key', [
                Pusher::META_INVOICE_ID,
                Pusher::META_INVOICE_NO,
                Pusher::META_STORNO_INVOICE_NO,
                Pusher::META_CANCELED_AT,
            ])
            ->get(['order_id', 'meta_key', 'meta_value']);

        $byOrder = [];
        foreach ($rows as $r) {
            $byOrder[(int) $r->order_id][$r->meta_key] = $r->meta_value;
        }

        $out = [];
        foreach ($ids as $id) {
            $m = $byOrder[$id] ?? [];
            $out[$id] = self::shape($m);
        }
        return new \WP_REST_Response($out, 200);
    }

    protected static function orderState(int $orderId): array
    {
        $rows = OrderMeta::where('order_id', $orderId)
            ->whereIn('meta_key', [
                Pusher::META_INVOICE_ID,
                Pusher::META_INVOICE_NO,
                Pusher::META_STORNO_INVOICE_NO,
                Pusher::META_CANCELED_AT,
            ])
            ->get(['meta_key', 'meta_value']);
        $m = [];
        foreach ($rows as $r) $m[$r->meta_key] = $r->meta_value;
        return self::shape($m);
    }

    protected static function shape(array $m): array
    {
        $pushed   = !empty($m[Pusher::META_INVOICE_ID]);
        $canceled = !empty($m[Pusher::META_CANCELED_AT]);
        return [
            'pushed'     => $pushed,
            'canceled'   => $canceled,
            'invoice_no' => (string) ($m[Pusher::META_INVOICE_NO] ?? ''),
            'storno_no'  => (string) ($m[Pusher::META_STORNO_INVOICE_NO] ?? ''),
        ];
    }
}
