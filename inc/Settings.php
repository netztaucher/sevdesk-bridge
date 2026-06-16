<?php
namespace SevDeskBridge;

if (!defined('ABSPATH')) exit;

class Settings
{
    const PAGE_SLUG  = 'sevdesk-bridge';
    const OPT_AUTO   = 'sevdesk_auto_push_enabled';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sevdesk_save_settings', [self::class, 'saveSettings']);
        add_action('admin_post_sevdesk_retry', [self::class, 'retryPush']);
        add_action('admin_post_sevdesk_bulk_push', [self::class, 'bulkPush']);
        add_action('admin_notices', [self::class, 'maybeRenderHealthNotice']);
    }

    public static function menu(): void
    {
        add_options_page(
            'sevDesk Bridge',
            'sevDesk Bridge',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function maybeRenderHealthNotice(): void
    {
        if (!current_user_can('manage_options')) return;
        $reason = get_option(Client::OPT_DISABLED_REASON);
        if (!$reason) return;
        echo '<div class="notice notice-error"><p><strong>sevDesk Bridge:</strong> Auto-Push deaktiviert — ' . esc_html((string) $reason) . '. <a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)) . '">Settings öffnen</a></p></div>';
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');

        $tokenSet     = Client::token() !== '';
        $accountSet   = Client::checkAccountId() > 0;
        $dryRun       = Client::isDryRun();
        $autoConst    = defined('SEVDESK_AUTO_PUSH') && SEVDESK_AUTO_PUSH;
        $autoOption   = (bool) get_option(self::OPT_AUTO, false);
        $disabledRsn  = get_option(Client::OPT_DISABLED_REASON);
        $logs         = Logger::recent(50);

        $tick = '<span style="color:#1a7c4b">✓</span>';
        $cross= '<span style="color:#b32d2e">✗</span>';

        echo '<div class="wrap"><h1>sevDesk Bridge</h1>';

        echo '<h2>Status</h2><table class="form-table"><tbody>';
        echo '<tr><th>API-Token</th><td>' . ($tokenSet ? $tick : $cross) . ' ' . ($tokenSet ? 'gesetzt' : '<code>SEVDESK_API_TOKEN</code> fehlt in wp-config.php') . '</td></tr>';
        echo '<tr><th>CheckAccount-ID</th><td>' . ($accountSet ? $tick : $cross) . ' ' . ($accountSet ? esc_html((string) Client::checkAccountId()) : '<code>SEVDESK_CHECK_ACCOUNT_ID</code> fehlt') . '</td></tr>';
        echo '<tr><th>Dry-Run</th><td>' . ($dryRun ? '<strong style="color:#b26a00">AKTIV</strong> (SEVDESK_DRY_RUN=true)' : 'aus') . '</td></tr>';
        echo '<tr><th>Auto-Push Master (Konstante)</th><td>' . ($autoConst ? $tick . ' SEVDESK_AUTO_PUSH=true' : $cross . ' SEVDESK_AUTO_PUSH nicht gesetzt') . '</td></tr>';
        if ($disabledRsn) {
            echo '<tr><th>Health</th><td><strong style="color:#b32d2e">DISABLED</strong>: ' . esc_html((string) $disabledRsn) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Einstellungen</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="sevdesk_save_settings">';
        wp_nonce_field('sevdesk_save_settings');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="auto_push">Auto-Push (Runtime-Switch)</label></th>';
        echo '<td><label><input type="checkbox" id="auto_push" name="auto_push" value="1" ' . checked($autoOption, true, false) . '> Bei <code>payment_status=paid</code> automatisch pushen</label>';
        if (!$autoConst) echo '<p class="description">Greift erst wenn <code>define(\'SEVDESK_AUTO_PUSH\', true)</code> in wp-config.php gesetzt ist.</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button('Speichern');
        echo '</form>';

        echo '<h2>Bulk-Backfill</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Alle ungepushten Paid-Orders pushen?\');">';
        echo '<input type="hidden" name="action" value="sevdesk_bulk_push">';
        wp_nonce_field('sevdesk_bulk_push');
        echo '<p>Stellt Action-Scheduler-Jobs für alle Orders mit <code>payment_status=paid</code> und ohne <code>_sevdesk_invoice_id</code> ein.</p>';
        echo '<p><label>Limit: <input type="number" name="limit" value="50" min="1" max="500"></label> ';
        echo '<label>Ab Datum: <input type="date" name="from" value=""></label></p>';
        submit_button('Jetzt enqueuen', 'secondary');
        echo '</form>';

        echo '<h2>Letzte ' . count($logs) . ' Push-Versuche</h2>';
        if (!$logs) {
            echo '<p>Noch keine Einträge.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Zeit</th><th>Order</th><th>Status</th><th>Titel</th><th></th></tr></thead><tbody>';
            foreach ($logs as $row) {
                $oid = (int) ($row['module_id'] ?? 0);
                $orderLink = $oid ? '<a href="' . esc_url(admin_url('admin.php?page=fluent-cart#/orders/' . $oid)) . '">#' . $oid . '</a>' : '-';
                $status = (string) ($row['status'] ?? '');
                $color  = $status === 'success' ? '#1a7c4b' : ($status === 'error' ? '#b32d2e' : ($status === 'warning' ? '#b26a00' : '#444'));
                echo '<tr>';
                echo '<td><small>' . esc_html((string) ($row['created_at'] ?? '')) . '</small></td>';
                echo '<td>' . $orderLink . '</td>';
                echo '<td style="color:' . $color . '"><strong>' . esc_html($status) . '</strong></td>';
                echo '<td>' . esc_html((string) ($row['title'] ?? '')) . '</td>';
                echo '<td>';
                if ($status === 'error' && $oid) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                    echo '<input type="hidden" name="action" value="sevdesk_retry">';
                    echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $oid) . '">';
                    wp_nonce_field('sevdesk_retry_' . $oid);
                    echo '<button type="submit" class="button button-small">Retry</button>';
                    echo '</form>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function saveSettings(): void
    {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('sevdesk_save_settings');
        update_option(self::OPT_AUTO, !empty($_POST['auto_push']));
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&saved=1'));
        exit;
    }

    public static function retryPush(): void
    {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        check_admin_referer('sevdesk_retry_' . $orderId);
        if ($orderId) {
            try {
                Pusher::push($orderId);
            } catch (\Throwable $e) {
                // log via Pusher
            }
        }
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&retried=' . $orderId));
        exit;
    }

    public static function bulkPush(): void
    {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('sevdesk_bulk_push');

        $limit = max(1, min(500, (int) ($_POST['limit'] ?? 50)));
        $from  = (string) ($_POST['from'] ?? '');

        $count = Scheduler::enqueueBulk($limit, $from);

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&bulk=' . $count));
        exit;
    }
}
