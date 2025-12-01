<?php
/**
 * Plugin Name: WP SMTP & Mailer
 * Description: Configure SMTP, send custom emails with CC/BCC via AJAX, and view compact logs in admin + dashboard widget.
 * Version: 1.6.0
 * Author: Storz
 */

if (!defined('ABSPATH')) exit;

define('WPSMTP_OPTION_KEY', 'wpsmtp_settings');
define('WPSMTP_LOG_OPTION_KEY', 'WPSMTP_LOG_OPTION_KEY');

/**
 * Disable update checks for this plugin
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
    return array(
        'enabled'    => 1,
        'host'       => '',
        'port'       => 587,
        'encryption' => 'tls',
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => get_bloginfo('name'),
    );
}

/**
 * Get merged settings
 */
function wpsmtp_get_settings() {
    $saved = get_option(WPSMTP_OPTION_KEY, array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_merge(wpsmtp_default_settings(), $saved);
}

/**
 * LOGS
 */
function wpsmtp_add_log($type, $message, $context = array()) {
    $logs = get_option(WPSMTP_LOG_OPTION_KEY, array());
    if (!is_array($logs)) {
        $logs = array();
    }

    $logs[] = array(
        'time'    => current_time('mysql'),
        'type'    => $type,
        'message' => $message,
        'context' => is_array($context) ? $context : array(),
    );

    if (count($logs) > 50) {
        $logs = array_slice($logs, -50);
    }

    update_option(WPSMTP_LOG_OPTION_KEY, $logs);
}

function wpsmtp_get_logs() {
    $logs = get_option(WPSMTP_LOG_OPTION_KEY, array());
    if (!is_array($logs)) {
        $logs = array();
    }
    return array_reverse($logs); // newest first
}

function wpsmtp_clear_logs() {
    delete_option(WPSMTP_LOG_OPTION_KEY);
}

/**
 * Catch wp_mail_failed
 */
add_action('wp_mail_failed', function ($wp_error) {
    if (!is_wp_error($wp_error)) return;

    $message = $wp_error->get_error_message();
    $data    = $wp_error->get_error_data();
    if (!is_array($data)) {
        $data = array('raw_data' => $data);
    }
    wpsmtp_add_log('error', $message, $data);
});

/**
 * Apply SMTP settings (safe)
 */
add_action('phpmailer_init', function ($phpmailer) {
    try {
        $s = wpsmtp_get_settings();

        if (empty($s['enabled'])) return;

        if (empty($s['host']) || empty($s['username']) || empty($s['password'])) {
            wpsmtp_add_log('warning', 'SMTP not fully configured (missing host/username/password).');
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = $s['host'];
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Port       = (int)$s['port'];
        $phpmailer->Username   = $s['username'];
        $phpmailer->Password   = $s['password'];
        $phpmailer->SMTPSecure = ($s['encryption'] === 'none') ? '' : $s['encryption'];

        if (!empty($s['from_email'])) {
            $phpmailer->setFrom($s['from_email'], $s['from_name']);
        }

        wpsmtp_add_log('info', 'SMTP configuration applied for outgoing email.', array(
            'host'       => $s['host'],
            'port'       => $s['port'],
            'encryption' => $s['encryption'],
            'username'   => $s['username'] ? '[set]' : '[empty]',
        ));
    } catch (Exception $e) {
        wpsmtp_add_log('error', 'Exception in phpmailer_init: ' . $e->getMessage());
    }
});

/**
 * HTML mail helper
 */
function wpsmtp_set_html_mail_type() {
    return 'text/html';
}

/**
 * Admin menu (Settings → WP SMTP & Mailer)
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
 * Dashboard widget with mini send form + minimal logs
 */
add_action('wp_dashboard_setup', 'wpsmtp_add_dashboard_widget');

function wpsmtp_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'wpsmtp_dashboard_widget',
        'WP SMTP & Mailer',
        'wpsmtp_dashboard_widget_display'
    );
}

function wpsmtp_dashboard_widget_display() {
    if (!current_user_can('manage_options')) {
        echo '<p>No permission.</p>';
        return;
    }

    ?>
    <div style="font-size:12px;">
        <strong>Quick Send</strong>
        <div style="margin-top:6px; margin-bottom:8px;">
            <input type="email" id="wpsmtp_widget_to" placeholder="To" style="width:100%;padding:5px 7px;margin-bottom:4px;font-size:11px;">
            <input type="text" id="wpsmtp_widget_subject" placeholder="Subject" style="width:100%;padding:5px 7px;margin-bottom:4px;font-size:11px;">
            <textarea id="wpsmtp_widget_body" placeholder="Message..." style="width:100%;padding:5px 7px;min-height:60px;font-size:11px;"></textarea>
            <button type="button" id="wpsmtp_widget_send_btn" style="margin-top:4px;padding:4px 10px;font-size:11px;border-radius:4px;border:none;background:#3c6df0;color:#fff;cursor:pointer;">
                Send
            </button>
            <div id="wpsmtp_widget_status" style="margin-top:4px;font-size:11px;"></div>
        </div>

        <strong>Recent Logs</strong>
        <?php
        $logs = wpsmtp_get_logs();
        $logs = array_slice($logs, 0, 3); // minimal logs: last 3

        if (empty($logs)) {
            echo '<p style="font-size:11px;color:#777;margin-top:4px;">No log entries yet.</p>';
        } else {
            echo '<table style="width:100%;border-collapse:collapse;font-size:11px;margin-top:4px;">';
            echo '<thead><tr>';
            echo '<th style="text-align:left;border-bottom:1px solid #eee;padding:3px 2px;width:35%;">Time</th>';
            echo '<th style="text-align:left;border-bottom:1px solid #eee;padding:3px 2px;width:15%;">Type</th>';
            echo '<th style="text-align:left;border-bottom:1px solid #eee;padding:3px 2px;">Message</th>';
            echo '</tr></thead><tbody>';

            foreach ($logs as $log) {
                $type  = isset($log['type']) ? $log['type'] : 'info';
                $time  = isset($log['time']) ? $log['time'] : '';
                $msg   = isset($log['message']) ? $log['message'] : '';

                $color = '#1a73e8';
                if ($type === 'warning') $color = '#e37400';
                if ($type === 'error')   $color = '#d93025';

                echo '<tr>';
                echo '<td style="border-bottom:1px solid #f1f1f1;padding:3px 2px;color:#666;white-space:nowrap;">' . esc_html($time) . '</td>';
                echo '<td style="border-bottom:1px solid #f1f1f1;padding:3px 2px;color:' . esc_attr($color) . ';">' . esc_html(ucfirst($type)) . '</td>';
                echo '<td style="border-bottom:1px solid #f1f1f1;padding:3px 2px;">' . esc_html($msg) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<p style="margin-top:4px;font-size:10px;color:#777;">Full logs and advanced composer in Settings → WP SMTP & Mailer.</p>';
        ?>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        var btn    = document.getElementById("wpsmtp_widget_send_btn");
        var status = document.getElementById("wpsmtp_widget_status");

        if (btn && typeof ajaxurl !== "undefined") {
            btn.addEventListener("click", function () {
                var to      = document.getElementById("wpsmtp_widget_to").value;
                var subject = document.getElementById("wpsmtp_widget_subject").value;
                var body    = document.getElementById("wpsmtp_widget_body").value;

                if (!to || !subject || !body) {
                    status.innerHTML = "Please fill all fields.";
                    status.style.color = "#d93025";
                    return;
                }

                status.innerHTML = "Sending...";
                status.style.color = "#555";

                var formData = new FormData();
                formData.append("action", "wpsmtp_send_email");
                formData.append("to", to);
                formData.append("subject", subject);
                formData.append("body", body);
                formData.append("cc", "");
                formData.append("bcc", "");
                formData.append("source", "widget");

                fetch(ajaxurl, {
                    method: "POST",
                    body: formData
                })
                .then(function(res){ return res.json(); })
                .then(function(data){
                    if (data.success) {
                        status.innerHTML = data.data;
                        status.style.color = "#2daa4a";
                    } else {
                        status.innerHTML = data.data || "Error sending email.";
                        status.style.color = "#d93025";
                    }
                })
                .catch(function(){
                    status.innerHTML = "Connection error.";
                    status.style.color = "#d93025";
                });
            });
        }
    });
    </script>
    <?php
}

/**
 * AJAX handler: send email (from composer + widget)
 */
add_action('wp_ajax_wpsmtp_send_email', 'wpsmtp_send_email_ajax');
function wpsmtp_send_email_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied.');
    }

    $to      = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
    $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : '';
    $body    = isset($_POST['body']) ? wp_kses_post($_POST['body']) : '';
    $cc_raw  = isset($_POST['cc']) ? sanitize_text_field($_POST['cc']) : '';
    $bcc_raw = isset($_POST['bcc']) ? sanitize_text_field($_POST['bcc']) : '';

    if (!$to || !$subject || !$body) {
        wpsmtp_add_log('error', 'AJAX send failed - missing fields');
        wp_send_json_error('Please fill To, Subject and Body.');
    }

    // Process CC / BCC as comma-separated lists
    $headers = array();

    if (!empty($cc_raw)) {
        $cc_list = array_filter(array_map('trim', explode(',', $cc_raw)));
        $clean_cc = array();
        foreach ($cc_list as $cc_addr) {
            $valid = sanitize_email($cc_addr);
            if (!empty($valid)) {
                $clean_cc[] = $valid;
            }
        }
        if (!empty($clean_cc)) {
            $headers[] = 'Cc: ' . implode(', ', $clean_cc);
        }
    }

    if (!empty($bcc_raw)) {
        $bcc_list = array_filter(array_map('trim', explode(',', $bcc_raw)));
        $clean_bcc = array();
        foreach ($bcc_list as $bcc_addr) {
            $valid = sanitize_email($bcc_addr);
            if (!empty($valid)) {
                $clean_bcc[] = $valid;
            }
        }
        if (!empty($clean_bcc)) {
            $headers[] = 'Bcc: ' . implode(', ', $clean_bcc);
        }
    }

    add_filter('wp_mail_content_type', 'wpsmtp_set_html_mail_type');
    $sent = wp_mail($to, $subject, nl2br($body), $headers);
    remove_filter('wp_mail_content_type', 'wpsmtp_set_html_mail_type');

    $context = array(
        'to'   => $to,
        'cc'   => $cc_raw,
        'bcc'  => $bcc_raw,
        'src'  => isset($_POST['source']) ? sanitize_text_field($_POST['source']) : 'admin',
        'subj' => $subject,
    );

    if ($sent) {
        wpsmtp_add_log('info', 'Email sent via AJAX.', $context);
        wp_send_json_success('Email sent successfully!');
    } else {
        wpsmtp_add_log('error', 'AJAX email send failed.', $context);
        wp_send_json_error('Failed to send email. Check Logs.');
    }
}

/**
 * Admin Page
 */
function wpsmtp_admin_page() {
    if (!current_user_can('manage_options')) return;

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

        $host       = isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '';
        $port       = isset($_POST['port']) ? (int)$_POST['port'] : 0;
        $encryption = isset($_POST['encryption']) ? sanitize_text_field($_POST['encryption']) : 'tls';

        if ($port <= 0) {
            $error = 'Port must be a positive number.';
        } elseif (!in_array($encryption, array('none', 'ssl', 'tls'), true)) {
            $error = 'Invalid encryption type.';
        } else {
            $s['enabled']    = isset($_POST['enabled']) ? 1 : 0;
            $s['host']       = $host;
            $s['port']       = $port;
            $s['encryption'] = $encryption;
            $s['username']   = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
            $s['password']   = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';
            $s['from_email'] = isset($_POST['from_email']) ? sanitize_email($_POST['from_email']) : '';
            $s['from_name']  = isset($_POST['from_name']) ? sanitize_text_field($_POST['from_name']) : '';

            update_option(WPSMTP_OPTION_KEY, $s);
            $success = 'SMTP settings saved.';
            wpsmtp_add_log('info', 'SMTP settings updated via admin UI.', array(
                'host'       => $s['host'],
                'port'       => $s['port'],
                'encryption' => $s['encryption'],
                'enabled'    => $s['enabled'],
            ));
        }
    }

    $logs = wpsmtp_get_logs();
    ?>
    <div class="wrap wpsmtp-wrap">
        <h1 style="font-size: 28px; margin-bottom: 10px;">WP SMTP & Mailer</h1>
        <p style="color:#666; margin-bottom: 30px;">
            Configure SMTP, send emails (with CC/BCC) via AJAX, and inspect compact logs.
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

            /* COMPACT LOGS UI */
            .wpsmtp-logs-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }
            .wpsmtp-logs-table th,
            .wpsmtp-logs-table td {
                border-bottom: 1px solid #eee;
                padding: 4px 3px;
                vertical-align: top;
            }
            .wpsmtp-logs-table th {
                text-align: left;
                font-weight: 600;
                color: #444;
                font-size: 11px;
            }
            .wpsmtp-log-time {
                white-space: nowrap;
                color: #666;
                font-size: 10.5px;
            }
            .wpsmtp-context {
                color:#777;
                font-size:10px;
                margin-top: 2px;
            }
            .wpsmtp-log-type-info { color: #1a73e8; font-size:11px; }
            .wpsmtp-log-type-warning { color: #e37400; font-size:11px; }
            .wpsmtp-log-type-error { color: #d93025; font-size:11px; }
        </style>

        <?php if ($success): ?>
            <div class="wpsmtp-notice wpsmtp-success"><?php echo wp_kses_post($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="wpsmtp-notice wpsmtp-error"><?php echo wp_kses_post($error); ?></div>
        <?php endif; ?>

        <div class="wpsmtp-grid">
            <!-- LEFT: SETTINGS + COMPOSER -->
            <div>
                <!-- SMTP SETTINGS -->
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

                        <!-- PASSWORD WITH SHOW/HIDE -->
                        <div style="position: relative;">
                            <input class="wpsmtp-input"
                                   name="password"
                                   type="password"
                                   id="wpsmtp-pass"
                                   placeholder="SMTP Password / App Password"
                                   value="<?php echo esc_attr($s['password']); ?>">
                            <span id="wpsmtp-toggle-pass"
                                  style="
                                      position:absolute;
                                      right:14px;
                                      top:50%;
                                      transform:translateY(-50%);
                                      cursor:pointer;
                                      font-size:14px;
                                      color:#555;
                                  ">👁️</span>
                        </div>

                        <input class="wpsmtp-input" name="from_email" placeholder="From Email" value="<?php echo esc_attr($s['from_email']); ?>">
                        <input class="wpsmtp-input" name="from_name" placeholder="From Name" value="<?php echo esc_attr($s['from_name']); ?>">

                        <button class="wpsmtp-btn-primary" name="wpsmtp_save">Save Settings</button>
                    </form>
                </div>

                <!-- EMAIL COMPOSER (AJAX, WITH CC/BCC) -->
                <div class="wpsmtp-card">
                    <h2>Email Composer</h2>

                    <input class="wpsmtp-input" id="wpsmtp_mail_to" type="email" placeholder="To (email)">
                    <input class="wpsmtp-input" id="wpsmtp_mail_cc" type="text" placeholder="CC (comma separated, optional)">
                    <input class="wpsmtp-input" id="wpsmtp_mail_bcc" type="text" placeholder="BCC (comma separated, optional)">
                    <input class="wpsmtp-input" id="wpsmtp_mail_subject" placeholder="Subject">
                    <textarea class="wpsmtp-textarea" id="wpsmtp_mail_body" placeholder="Write your message...&#10;Supports basic HTML such as &lt;br&gt;, &lt;strong&gt;, &lt;a&gt;, etc."></textarea>

                    <button type="button" id="wpsmtp_send_btn" class="wpsmtp-btn-secondary">Send Email</button>

                    <div id="wpsmtp_send_status" style="margin-top:12px;font-size:13px;"></div>
                </div>
            </div>

            <!-- RIGHT: COMPACT LOGS -->
            <div class="wpsmtp-card">
                <h2 style="display:flex; align-items:center; justify-content:space-between;">
                    <span>Logs</span>
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
                            <th style="width:28%;">Time</th>
                            <th style="width:12%;">Type</th>
                            <th>Message</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($logs, 0, 35) as $log): ?>
                            <tr>
                                <td class="wpsmtp-log-time"><?php echo esc_html($log['time']); ?></td>
                                <td>
                                    <?php
                                    $type  = isset($log['type']) ? $log['type'] : 'info';
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
                                            $parts = array();
                                            foreach ($log['context'] as $k => $v) {
                                                if (is_scalar($v)) {
                                                    $parts[] = esc_html($k) . ': ' . esc_html((string)$v);
                                                }
                                            }
                                            echo implode(' • ', $parts);
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
                    Tip: Check <strong>Error</strong> rows for SMTP connection/auth issues (host, port, password, etc.).
                </p>
            </div>
        </div>

        <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Show / hide password
            var passInput = document.getElementById("wpsmtp-pass");
            var toggleBtn = document.getElementById("wpsmtp-toggle-pass");

            if (passInput && toggleBtn) {
                toggleBtn.addEventListener("click", function () {
                    if (passInput.type === "password") {
                        passInput.type = "text";
                        toggleBtn.textContent = "🙈";
                    } else {
                        passInput.type = "password";
                        toggleBtn.textContent = "👁️";
                    }
                });
            }

            // AJAX send email (admin composer)
            var sendBtn   = document.getElementById("wpsmtp_send_btn");
            var statusBox = document.getElementById("wpsmtp_send_status");

            if (sendBtn && typeof ajaxurl !== "undefined") {
                sendBtn.addEventListener("click", function () {
                    var to      = document.getElementById("wpsmtp_mail_to").value;
                    var cc      = document.getElementById("wpsmtp_mail_cc").value;
                    var bcc     = document.getElementById("wpsmtp_mail_bcc").value;
                    var subject = document.getElementById("wpsmtp_mail_subject").value;
                    var body    = document.getElementById("wpsmtp_mail_body").value;

                    if (!to || !subject || !body) {
                        statusBox.innerHTML = "Please fill at least To, Subject and Body.";
                        statusBox.style.color = "#d93025";
                        return;
                    }

                    statusBox.innerHTML = "Sending...";
                    statusBox.style.color = "#555";

                    var formData = new FormData();
                    formData.append("action", "wpsmtp_send_email");
                    formData.append("to", to);
                    formData.append("subject", subject);
                    formData.append("body", body);
                    formData.append("cc", cc);
                    formData.append("bcc", bcc);
                    formData.append("source", "admin");

                    fetch(ajaxurl, {
                        method: "POST",
                        body: formData
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            statusBox.innerHTML = data.data;
                            statusBox.style.color = "#2daa4a";
                        } else {
                            statusBox.innerHTML = data.data || "Error sending email.";
                            statusBox.style.color = "#d93025";
                        }
                    })
                    .catch(function () {
                        statusBox.innerHTML = "Connection error.";
                        statusBox.style.color = "#d93025";
                    });
                });
            }
        });
        </script>
    </div>
    <?php
}
