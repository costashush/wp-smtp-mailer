<?php
/**
 * Plugin Name: WP SMTP & Mailer
 * Description: Configure SMTP and send custom emails inside WordPress using a clean modern UI, with error logs.
 * Version: 1.1.0
 * Author: Storz
 */

if (!defined('ABSPATH')) exit;

define('WPSMTP_OPTION_KEY', 'wpsmtp_settings');
define('WPSMTP_LOG_OPTION_KEY', 'wpsmtp_logs');

/**
 * Disable update check for this plugin
 */
add_filter('site_transient_update_plugins', function ($value) {
    if (!is_object($value)) {
        return $value;
    }
    if (isset($value->response['wp-smtp-mailer/wp-smtp-mailer.php'])) {
        unset($value->response['wp-smtp-mailer/wp-smtp-mailer.php']);
    }
    return $value;
});

/**
 * Default settings
 */
function wpsmtp_default_settings() {
    return [
        'enabled'    => 1,
        'host'       => '',
        'port'       => 587,
        'encryption' => 'tls',
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => get_bloginfo('name'),
    ];
}

/**
 * Get merged settings
 */
function wpsmtp_get_settings() {
    $saved = get_option(WPSMTP_OPTION_KEY, []);
    if (!is_array($saved)) {
        $saved = [];
    }
    return array_merge(wpsmtp_default_settings(), $saved);
}

/**
 * LOGGING
 * Store up to 50 recent entries in options table
 */
function wpsmtp_add_log($type, $message, $context = []) {
    $logs = get_option(WPSMTP_LOG_OPTION_KEY, []);
    if (!is_array($logs)) {
        $logs = [];
    }

    $logs[] = [
        'time'    => current_time('mysql'),
        'type'    => $type,
        'message' => $message,
        'context' => is_array($context) ? $context : [],
    ];

    // Keep only last 50 entries
    if (count($logs) > 50) {
        $logs = array_slice($logs, -50);
    }

    update_option(WPSMTP_LOG_OPTION_KEY, $logs);
}

function wpsmtp_get_logs() {
    $logs = get_option(WPSMTP_LOG_OPTION_KEY, []);
    if (!is_array($logs)) $logs = [];
    // newest first
    return array_reverse($logs);
}

function wpsmtp_clear_logs() {
    delete_option(WPSMTP_LOG_OPTION_KEY);
}

/**
 * Hook into wp_mail_failed for detailed errors
 */
add_action('wp_mail_failed', function ($wp_error) {
    if (!is_wp_error($wp_error)) return;

    $message = $wp_error->get_error_message();
    $data    = $wp_error->get_error_data();
    if (!is_array($data)) {
        $data = ['raw_data' => $data];
    }

    wpsmtp_add_log('error', $message, $data);
});

/**
 * Apply SMTP settings
 */
add_action('phpmailer_init', function ($phpmailer) {
    $s = wpsmtp_get_settings();

    if (empty($s['enabled'])) {
        return;
    }

    if (empty($s['host']) || empty($s['username']) || empty($s['password'])) {
        wpsmtp_add_log('warning', 'SMTP not fully configured (missing host/username/password).');
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = $s['host'];
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = (int) $s['port'];
    $phpmailer->Username   = $s['username'];
    $phpmailer->Password   = $s['password'];
    $phpmailer->SMTPSecure = ($s['encryption'] === 'none') ? '' : $s['encryption'];

    if (!empty($s['from_email'])) {
        $phpmailer->setFrom($s['from_email'], $s['from_name']);
    }

    wpsmtp_add_log('info', 'SMTP configuration applied for outgoing email.', [
        'host'       => $s['host'],
        'port'       => $s['port'],
        'encryption' => $s['encryption'],
        'username'   => $s['username'] ? '[set]' : '[empty]',
    ]);
});

/**
 * Admin menu
 */
add_action('admin_menu', function () {
    add_options_page(
        'WP SMTP & Mailer',
        'WP SMTP & Mailer',
        'manage_options',
        'wpsmtp',
        'wpsmtp_admin_page'
    );
});

/**
 * Admin Page — modern UI + logs
 */
function wpsmtp_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $s       = wpsmtp_get_settings();
    $success = '';
    $error   = '';

    /* CLEAR LOGS */
    if (isset($_POST['wpsmtp_clear_logs'])) {
        check_admin_referer('wpsmtp_clear_logs');
        wpsmtp_clear_logs();
        $success = 'Logs cleared.';
    }

    /* SAVE SETTINGS */
    if (isset($_POST['wpsmtp_save'])) {
        check_admin_referer('wpsmtp_save');

        $host       = sanitize_text_field($_POST['host'] ?? '');
        $port       = (int) ($_POST['port'] ?? 0);
        $encryption = sanitize_text_field($_POST['encryption'] ?? 'tls');

        if ($port <= 0) {
            $error = 'Port must be a positive number.';
        } elseif (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $error = 'Invalid encryption type.';
        } else {
            $s['enabled']    = isset($_POST['enabled']) ? 1 : 0;
            $s['host']       = $host;
            $s['port']       = $port;
            $s['encryption'] = $encryption;
            $s['username']   = sanitize_text_field($_POST['username'] ?? '');
            $s['password']   = sanitize_text_field($_POST['password'] ?? '');
            $s['from_email'] = sanitize_email($_POST['from_email'] ?? '');
            $s['from_name']  = sanitize_text_field($_POST['from_name'] ?? '');

            update_option(WPSMTP_OPTION_KEY, $s);
            $success = 'SMTP settings saved.';
            wpsmtp_add_log('info', 'SMTP settings updated via admin UI.', [
                'host'       => $s['host'],
                'port'       => $s['port'],
                'encryption' => $s['encryption'],
                'enabled'    => $s['enabled'],
            ]);
        }
    }

    /* SEND EMAIL */
    if (isset($_POST['wpsmtp_send'])) {
        check_admin_referer('wpsmtp_send');

        $to      = sanitize_email($_POST['mail_to'] ?? '');
        $subject = sanitize_text_field($_POST['mail_subject'] ?? '');
        $body    = wp_kses_post($_POST['mail_body'] ?? '');

        if (!$to || !$subject || !$body) {
            $error = 'Please fill all fields before sending.';
        } else {
            add_filter('wp_mail_content_type', fn() => 'text/html');
            $sent = wp_mail($to, $subject, nl2br($body));
            add_filter('wp_mail_content_type', fn() => 'text/plain');

            if ($sent) {
                $success = "Email sent to <b>" . esc_html($to) . "</b>.";
                wpsmtp_add_log('info', 'Email sent successfully from composer.', [
                    'to'      => $to,
                    'subject' => $subject,
                ]);
            } else {
                $error = "Email failed. Check Logs below for more details.";
                wpsmtp_add_log('error', 'Email sending failed from composer.', [
                    'to'      => $to,
                    'subject' => $subject,
                ]);
            }
        }
    }

    $logs = wpsmtp_get_logs();

    ?>
    <div class="wrap wpsmtp-wrap">
        <h1 style="font-size: 28px; margin-bottom: 10px;">WP SMTP & Mailer</h1>
        <p style="color:#666; margin-bottom: 30px;">
            Configure SMTP, send emails, and inspect detailed logs directly from WordPress.
        </p>

        <style>
            .wpsmtp-grid {
                display: grid;
                gap: 30px;
                grid-template-columns: 1.1fr 0.9fr;
            }
            @media(max-width: 1100px) {
                .wpsmtp-grid { grid-template-columns: 1fr; }
            }

            .wpsmtp-card {
                background: #fff;
                border-radius: 12px;
                padding: 22px 24px;
                border: 1px solid #e1e1e1;
                box-shadow: 0 5px 14px rgba(0,0,0,0.05);
                transition: all .18s ease;
            }
            .wpsmtp-card:hover {
                box-shadow: 0 8px 20px rgba(0,0,0,0.09);
            }

            .wpsmtp-card h2 {
                margin-top: 0;
                font-size: 19px;
                padding-bottom: 8px;
                border-bottom: 1px solid #eee;
                margin-bottom: 16px;
            }

            .wpsmtp-input {
                width: 100%;
                padding: 9px 12px;
                border-radius: 8px;
                border: 1px solid #dcdcdc;
                margin-top: 4px;
                margin-bottom: 12px;
                font-size: 13px;
            }

            .wpsmtp-textarea {
                width: 100%;
                min-height: 170px;
                padding: 10px 12px;
                border-radius: 10px;
                border: 1px solid #dcdcdc;
                font-size: 13px;
                resize: vertical;
            }

            .wpsmtp-btn-primary {
                background: #3c6df0;
                border: none;
                padding: 9px 20px;
                border-radius: 8px;
                color: #fff;
                font-weight: 600;
                font-size: 13px;
                cursor: pointer;
            }
            .wpsmtp-btn-secondary {
                background: #555;
                border: none;
                padding: 9px 20px;
                border-radius: 8px;
                color: #fff;
                font-weight: 600;
                font-size: 13px;
                cursor: pointer;
            }
            .wpsmtp-btn-danger {
                background: #d93025;
                border: none;
                padding: 7px 14px;
                border-radius: 6px;
                color: #fff;
                font-weight: 500;
                font-size: 12px;
                cursor: pointer;
            }

            .wpsmtp-notice {
                padding: 11px 14px;
                border-radius: 7px;
                margin-bottom: 18px;
                font-size: 13px;
            }
            .wpsmtp-success { background: #e5f8e8; border-left: 4px solid #2daa4a; }
            .wpsmtp-error { background: #ffeaea; border-left: 4px solid #d93025; }

            .wpsmtp-logs-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }
            .wpsmtp-logs-table th,
            .wpsmtp-logs-table td {
                border-bottom: 1px solid #eee;
                padding: 6px 4px;
                vertical-align: top;
            }
            .wpsmtp-logs-table th {
                text-align: left;
                font-weight: 600;
            }
            .wpsmtp-log-type-info { color: #1a73e8; }
            .wpsmtp-log-type-warning { color: #e37400; }
            .wpsmtp-log-type-error { color: #d93025; }
            .wpsmtp-log-time { white-space: nowrap; color:#666; }
            .wpsmtp-context {
                color:#777;
                font-size:11px;
            }
        </style>

        <?php if ($success): ?>
            <div class="wpsmtp-notice wpsmtp-success"><?php echo wp_kses_post($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="wpsmtp-notice wpsmtp-error"><?php echo wp_kses_post($error); ?></div>
        <?php endif; ?>

        <div class="wpsmtp-grid">

            <!-- LEFT SIDE: Settings + Composer -->
            <div>
                <!-- SMTP SETTINGS CARD -->
                <div class="wpsmtp-card" style="margin-bottom: 24px;">
                    <h2>SMTP Settings</h2>
                    <form method="post">
                        <?php wp_nonce_field('wpsmtp_save'); ?>

                        <label style="display:block; margin-bottom:10px;">
                            <input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'],1); ?>>
                            <span style="margin-left:6px;">Enable SMTP override</span>
                        </label>

                        <input class="wpsmtp-input" name="host" placeholder="SMTP Host (e.g. smtp.gmail.com)" value="<?php echo esc_attr($s['host']); ?>">
                        <input class="wpsmtp-input" name="port" type="number" placeholder="Port (e.g. 587)" value="<?php echo esc_attr($s['port']); ?>">

                        <select name="encryption" class="wpsmtp-input">
                            <option value="none" <?php selected($s['encryption'],'none'); ?>>No Encryption</option>
                            <option value="ssl"  <?php selected($s['encryption'],'ssl'); ?>>SSL</option>
                            <option value="tls"  <?php selected($s['encryption'],'tls'); ?>>TLS</option>
                        </select>

                        <input class="wpsmtp-input" name="username" placeholder="SMTP Username" value="<?php echo esc_attr($s['username']); ?>">
                        <input class="wpsmtp-input" name="password" type="password" placeholder="SMTP Password / App Password" value="<?php echo esc_attr($s['password']); ?>">

                        <input class="wpsmtp-input" name="from_email" placeholder="From Email" value="<?php echo esc_attr($s['from_email']); ?>">
                        <input class="wpsmtp-input" name="from_name" placeholder="From Name" value="<?php echo esc_attr($s['from_name']); ?>">

                        <button class="wpsmtp-btn-primary" name="wpsmtp_save">Save Settings</button>
                    </form>
                </div>

                <!-- EMAIL COMPOSER CARD -->
                <div class="wpsmtp-card">
                    <h2>Email Composer</h2>
                    <form method="post">
                        <?php wp_nonce_field('wpsmtp_send'); ?>

                        <input class="wpsmtp-input" name="mail_to" type="email" placeholder="Recipient email">
                        <input class="wpsmtp-input" name="mail_subject" placeholder="Subject">

                        <textarea class="wpsmtp-textarea" name="mail_body" placeholder="Write your message...&#10;Supports basic HTML such as &lt;br&gt;, &lt;strong&gt;, &lt;a&gt;, etc."></textarea>

                        <button class="wpsmtp-btn-secondary" name="wpsmtp_send">Send Email</button>
                    </form>
                </div>
            </div>

            <!-- RIGHT SIDE: LOGS -->
            <div class="wpsmtp-card">
                <h2 style="display:flex; align-items:center; justify-content:space-between;">
                    <span>Logs & Debug</span>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('wpsmtp_clear_logs'); ?>
                        <button type="submit" name="wpsmtp_clear_logs" class="wpsmtp-btn-danger">Clear Logs</button>
                    </form>
                </h2>

                <?php if (empty($logs)): ?>
                    <p style="color:#777; font-size:13px;">No log entries yet. Errors and important events will appear here.</p>
                <?php else: ?>
                    <table class="wpsmtp-logs-table">
                        <thead>
                        <tr>
                            <th style="width:32%;">Time</th>
                            <th style="width:15%;">Type</th>
                            <th>Message</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($logs, 0, 25) as $log): ?>
                            <tr>
                                <td class="wpsmtp-log-time"><?php echo esc_html($log['time']); ?></td>
                                <td>
                                    <?php
                                    $type = isset($log['type']) ? $log['type'] : 'info';
                                    $class = 'wpsmtp-log-type-' . $type;
                                    ?>
                                    <span class="<?php echo esc_attr($class); ?>">
                                        <?php echo esc_html(ucfirst($type)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo esc_html($log['message']); ?>
                                    <?php if (!empty($log['context']) && is_array($log['context'])): ?>
                                        <div class="wpsmtp-context">
                                            <?php
                                            $parts = [];
                                            foreach ($log['context'] as $k => $v) {
                                                if (is_scalar($v)) {
                                                    $parts[] = esc_html($k) . ': ' . esc_html((string)$v);
                                                }
                                            }
                                            echo implode(' | ', $parts);
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <p style="margin-top:10px; font-size:12px; color:#888;">
                    Tip: If sending fails, check the latest <strong>Error</strong> rows here for exact reasons (auth, host, port, etc.).
                </p>
            </div>
        </div>
    </div>
    <?php
}
