<?php
namespace SevDeskBridge;

if (!defined('ABSPATH')) exit;

class Client
{
    const BASE = 'https://my.sevdesk.de/api/v1';
    const RATE_LIMIT_USLEEP = 500000;
    const OPT_DISABLED_REASON = 'sevdesk_auto_push_disabled_reason';

    public static function token(): string
    {
        return defined('SEVDESK_API_TOKEN') ? (string) SEVDESK_API_TOKEN : '';
    }

    public static function checkAccountId(): int
    {
        return defined('SEVDESK_CHECK_ACCOUNT_ID') ? (int) SEVDESK_CHECK_ACCOUNT_ID : 0;
    }

    public static function isDryRun(): bool
    {
        return defined('SEVDESK_DRY_RUN') && SEVDESK_DRY_RUN;
    }

    public static function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if (self::isDryRun()) {
            return self::fakeDryRunResponse($method, $path, $body);
        }

        $token = self::token();
        if (!$token) {
            throw new \RuntimeException('SEVDESK_API_TOKEN nicht gesetzt (wp-config.php).');
        }

        $url = self::BASE . $path;
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => $token,
                'Accept'        => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request($url, $args);

        usleep(self::RATE_LIMIT_USLEEP);

        if (is_wp_error($res)) {
            throw new \RuntimeException('sevDesk HTTP-Fehler: ' . $res->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);
        $json = json_decode($raw, true);

        if ($code === 401) {
            update_option(self::OPT_DISABLED_REASON, '401 Token ungültig (' . current_time('mysql') . ')');
            delete_transient('sevdesk_healthy');
            throw new \RuntimeException("sevDesk {$method} {$path} → HTTP 401: Token ungültig.");
        }

        if ($code < 200 || $code >= 300) {
            $msg = is_array($json) ? wp_json_encode($json) : $raw;
            throw new \RuntimeException("sevDesk {$method} {$path} → HTTP {$code}: {$msg}");
        }

        delete_option(self::OPT_DISABLED_REASON);

        return is_array($json) ? $json : [];
    }

    protected static function fakeDryRunResponse(string $method, string $path, ?array $body = null): array
    {
        $m = strtoupper($method);
        if ($m === 'GET' && preg_match('#^/Contact$#', $path)) {
            return ['objects' => []];
        }
        if ($m === 'GET' && preg_match('#^/SevUser$#', $path)) {
            return ['objects' => [['id' => 1]]];
        }
        if ($m === 'POST' && preg_match('#^/Invoice/Factory/saveInvoice$#', $path)) {
            return [
                'objects' => [
                    'invoice' => [
                        'id'            => 999999,
                        'invoiceNumber' => 'DRY-RUN-' . substr(md5(wp_json_encode($body) . microtime(true)), 0, 8),
                    ],
                ],
            ];
        }
        if ($m === 'POST' && preg_match('#^/Contact$#', $path)) {
            return ['objects' => ['id' => 999999]];
        }
        if ($m === 'PUT' && preg_match('#^/Invoice/\d+/bookAmount$#', $path)) {
            return ['objects' => []];
        }
        if ($m === 'POST' && preg_match('#^/Invoice/\d+/cancelInvoice$#', $path)) {
            return ['objects' => ['id' => 999999, 'creditNoteNumber' => 'DRY-RUN-GU']];
        }
        if ($m === 'PUT' && preg_match('#^/CreditNote/\d+/sendBy$#', $path)) {
            return ['objects' => ['status' => 200]];
        }
        if ($m === 'PUT' && preg_match('#^/CreditNote/\d+/bookAmount$#', $path)) {
            return ['objects' => []];
        }
        return ['objects' => []];
    }
}
