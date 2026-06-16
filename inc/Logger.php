<?php
namespace SevDeskBridge;

if (!defined('ABSPATH')) exit;

class Logger
{
    const TITLE_PREFIX = '[sevDesk] ';

    public static function success(int $orderId, string $message, $payload = null): void
    {
        self::log($orderId, self::TITLE_PREFIX . $message, 'success', $payload);
    }

    public static function failure(int $orderId, $error, $payload = null): void
    {
        $msg = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
        self::log($orderId, self::TITLE_PREFIX . 'Fehler: ' . $msg, 'error', $payload);
    }

    public static function info(int $orderId, string $message, $payload = null): void
    {
        self::log($orderId, self::TITLE_PREFIX . $message, 'info', $payload);
    }

    public static function warning(int $orderId, string $message, $payload = null): void
    {
        self::log($orderId, self::TITLE_PREFIX . $message, 'warning', $payload);
    }

    public static function dryRun(int $orderId, string $message, $payload = null): void
    {
        self::log($orderId, self::TITLE_PREFIX . '[DRY-RUN] ' . $message, 'info', $payload);
    }

    protected static function log(int $orderId, string $title, string $status, $payload): void
    {
        $content = '';
        if ($payload !== null) {
            $content = is_string($payload) ? $payload : wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        if (function_exists('fluent_cart_add_log')) {
            fluent_cart_add_log($title, '<pre>' . esc_html((string) $content) . '</pre>', $status, [
                'module_name' => 'order',
                'module_id'   => $orderId,
                'module_type' => 'FluentCart\\App\\Models\\Order',
            ]);
            return;
        }

        error_log('[sevdesk-bridge order ' . $orderId . '] ' . $title . ' :: ' . substr((string) $content, 0, 2000));
    }

    public static function recent(int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_activity';
        $like  = '%' . $wpdb->esc_like(self::TITLE_PREFIX) . '%';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, title, content, created_at, module_id
                 FROM {$table}
                 WHERE title LIKE %s
                 ORDER BY id DESC LIMIT %d",
                $like,
                $limit
            ),
            ARRAY_A
        ) ?: [];
        return $rows;
    }
}
