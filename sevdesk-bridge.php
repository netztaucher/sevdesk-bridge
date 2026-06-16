<?php
/*
Plugin Name: sevDesk Bridge
Plugin URI: https://netztaucher.com/wordpress
Description: Überträgt bezahlte FluentCart-Bestellungen automatisch als Rechnungen nach sevDesk (Kleinunternehmer §19). Storno, Wiederübertragung, EÜR-konforme Buchung.
Version: 0.7.1
Author: netztaucher | digital
Author URI: https://netztaucher.com/wordpress
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;

define('SEVDESK_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('SEVDESK_BRIDGE_URL', plugin_dir_url(__FILE__));
define('SEVDESK_BRIDGE_VERSION', '0.7.1');

require_once SEVDESK_BRIDGE_PATH . 'inc/Client.php';
require_once SEVDESK_BRIDGE_PATH . 'inc/Logger.php';
require_once SEVDESK_BRIDGE_PATH . 'inc/Pusher.php';
require_once SEVDESK_BRIDGE_PATH . 'inc/Scheduler.php';
require_once SEVDESK_BRIDGE_PATH . 'inc/Rest.php';
require_once SEVDESK_BRIDGE_PATH . 'inc/Settings.php';

add_action('rest_api_init', ['\SevDeskBridge\Rest', 'register']);

\SevDeskBridge\Scheduler::register();
\SevDeskBridge\Settings::register();

add_action('admin_enqueue_scripts', function ($hook) {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'fluent-cart') return;

    wp_enqueue_style(
        'sevdesk-bridge',
        SEVDESK_BRIDGE_URL . 'assets/orders-button.css',
        [],
        SEVDESK_BRIDGE_VERSION
    );
    wp_enqueue_script(
        'sevdesk-bridge',
        SEVDESK_BRIDGE_URL . 'assets/orders-button.js',
        [],
        SEVDESK_BRIDGE_VERSION,
        true
    );
    wp_localize_script('sevdesk-bridge', 'SevDeskBridge', [
        'root'  => esc_url_raw(rest_url('sevdesk-bridge/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
});

// FluentCart-Status-Events liefern ein Array ['order'=>Order, 'old_status'=>..., 'new_status'=>...].
// Diese Helper holt die Order-ID robust aus Array-Payload ODER direktem Order-Objekt.
function sevdesk_bridge_order_id($payload): int
{
    if (is_object($payload)) return (int) ($payload->id ?? 0);
    if (is_array($payload)) {
        $order = $payload['order'] ?? null;
        if (is_object($order)) return (int) ($order->id ?? 0);
        if (is_array($order))  return (int) ($order['id'] ?? 0);
        return (int) ($payload['id'] ?? 0);
    }
    return 0;
}

add_action('fluent_cart/payment_status_changed_to_paid', function ($payload) {
    if (!defined('SEVDESK_AUTO_PUSH') || !SEVDESK_AUTO_PUSH) return;
    if (!get_option(\SevDeskBridge\Settings::OPT_AUTO, false)) return;
    if (get_option(\SevDeskBridge\Client::OPT_DISABLED_REASON)) return;

    $orderId = sevdesk_bridge_order_id($payload);
    if ($orderId) \SevDeskBridge\Scheduler::enqueuePush($orderId);
}, 20, 1);

add_action('fluent_cart/order_fully_refunded', function ($data) {
    $order = is_array($data) ? ($data['order'] ?? null) : null;
    $orderId = is_object($order) ? (int) $order->id : 0;
    if ($orderId) \SevDeskBridge\Scheduler::enqueueCancel($orderId);
}, 20, 1);

// Zahlung in FluentCart storniert/zurückgesetzt (Status -> refunded) => Gutschrift in sevDesk.
// Fängt auch manuelle Status-Änderung im Admin ab. Pusher::cancel ist idempotent.
add_action('fluent_cart/payment_status_changed_to_refunded', function ($payload) {
    $orderId = sevdesk_bridge_order_id($payload);
    if ($orderId) \SevDeskBridge\Scheduler::enqueueCancel($orderId);
}, 20, 1);

// Teil-Erstattung: halb-manuell. sevDesk lehnt API-Teilgutschriften für §19-Rechnungs-
// korrekturen ab ("Different taxRate than origin") — Teilgutschrift wird in der sevDesk-UI
// angelegt. Plugin loggt einen actionable Hinweis mit Betrag + Rechnungsnummer.
add_action('fluent_cart/order_partially_refunded', function ($data) {
    $order = is_array($data) ? ($data['order'] ?? null) : null;
    $orderId = is_object($order) ? (int) $order->id : 0;
    if (!$orderId) return;

    $amountCents = (int) ($data['refunded_amount'] ?? 0);
    $amount      = $amountCents > 0 ? number_format($amountCents / 100, 2, ',', '.') : '?';
    $invoiceNo   = \SevDeskBridge\Pusher::storedInvoiceNo($orderId);

    \SevDeskBridge\Logger::warning(
        $orderId,
        "Teil-Erstattung {$amount} € — bitte Teilgutschrift in sevDesk zu Rechnung " . ($invoiceNo ?: '(unbekannt)') . " manuell anlegen.",
        ['refunded_amount_cents' => $amountCents, 'invoice_no' => $invoiceNo]
    );
}, 20, 1);

if (defined('WP_CLI') && WP_CLI) {
    require_once SEVDESK_BRIDGE_PATH . 'inc/CLI/PushCommand.php';
    \WP_CLI::add_command('sevdesk', '\SevDeskBridge\CLI\PushCommand');
}
