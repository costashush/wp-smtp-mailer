<?php
/**
 * Plugin Name: WP SMTP & Mailer
 * Description: Configure SMTP and send custom emails inside WordPress using a clean modern UI.
 * Version: 1.0.1
 * Author: Storz
 */

if (!defined('ABSPATH')) exit;

define('WPSMTP_OPTION_KEY', 'wpsmtp_settings');

/**
 * Disable update check
 */
add_filter('site_transient_update_plugins', function ($value) {
    unset($value->response['wp-smtp-mailer/wp-smtp-mailer.php']);
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
    return array_merge(wpsmtp_default_settings(), is_array($saved) ? $saved : []);
}

/**
 * Apply SMTP settings
 */
add_action('phpmailer_init', function ($phpmailer) {
    $s = wpsmtp_get_settings();
    if (!$s['enabled'] || !$s['host']) return;

    $phpmailer->isSMTP();
    $phpmailer->Host       = $s['host'];
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = $s['port'];
    $phpmailer->Username   = $s['username'];
    $phpmailer->Password   = $s['password'];
    $phpmailer->SMTPSecure = $s['encryption'] === 'none' ? '' : $s['encryption'];

    if ($s['from_email'])
        $phpmailer->setFrom($s['from_email'], $s['from_name']);
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
 * Admin Page — MODERN UI
 */
function wpsmtp_admin_page() {
    if (!current_user_can('manage_options')) return;

    $s = wpsmtp_get_settings();
    $success = '';
    $error   = '';

    /* SAVE SETTINGS */
    if (isset($_POST['wpsmtp_save'])) {
        check_admin_referer('wpsmtp_save');

        $s['enabled']    = isset($_POST['enabled']) ? 1 : 0;
        $s['host']       = sanitize_text_field($_POST['host']);
        $s['port']       = (int) $_POST['port'];
        $s['encryption'] = sanitize_text_field($_POST['encryption']);
        $s['username']   = sanitize_text_field($_POST['username']);
        $s['password']   = sanitize_text_field($_POST['password']);
        $s['from_email'] = sanitize_email($_POST['from_email']);
        $s['from_name']  = sanitize_text_field($_POST['from_name']);

        update_option(WPSMTP_OPTION_KEY, $s);
        $success = "SMTP settings saved.";
    }

    /* SEND EMAIL */
    if (isset($_POST['wpsmtp_send'])) {
        check_admin_referer('wpsmtp_send');

        $to      = sanitize_email($_POST['mail_to']);
        $subject = sanitize_text_field($_POST['mail_subject']);
        $body    = wp_kses_post($_POST['mail_body']);

        if (!$to || !$subject || !$body) {
            $error = "Please fill all fields.";
        } else {
            add_filter('wp_mail_content_type', fn() => 'text/html');
            $sent = wp_mail($to, $subject, nl2br($body));
            add_filter('wp_mail_content_type', fn() => 'text/plain');

            $success = $sent ? "Email sent to <b>$to</b>" : "Email failed. Check SMTP settings.";
        }
    }

    ?>

    <div class="wrap wpsmtp-wrap">
        <h1 style="font-size: 28px; margin-bottom: 10px;">WP SMTP & Mailer</h1>
        <p style="color:#666; margin-bottom: 30px;">Modern interface to configure SMTP and send emails from WordPress.</p>

        <style>
            .wpsmtp-grid {
                display: grid;
                gap: 30px;
                grid-template-columns: 1fr 1fr;
            }
            @media(max-width: 960px) { .wpsmtp-grid { grid-template-columns: 1fr; } }

            .wpsmtp-card {
                background: #fff;
                border-radius: 12px;
                padding: 25px;
                border: 1px solid #e1e1e1;
                box-shadow: 0 5px 14px rgba(0,0,0,0.06);
                transition: all .2s ease;
            }
            .wpsmtp-card:hover {
                box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            }

            .wpsmtp-card h2 {
                margin-top: 0;
                font-size: 20px;
                padding-bottom: 8px;
                border-bottom: 1px solid #eee;
                margin-bottom: 18px;
            }

            .wpsmtp-input {
                width: 100%;
                padding: 10px 14px;
                border-radius: 8px;
                border: 1px solid #dcdcdc;
                margin-top: 5px;
                margin-bottom: 15px;
                font-size: 14px;
            }

            .wpsmtp-textarea {
                width: 100%;
                min-height: 180px;
                padding: 12px;
                border-radius: 10px;
                border: 1px solid #dcdcdc;
                font-size: 14px;
            }

            .wpsmtp-btn-primary {
                background: #3c6df0;
                border: none;
                padding: 10px 22px;
                border-radius: 8px;
                color: #fff;
                font-weight: 600;
                font-size: 14px;
                cursor: pointer;
            }
            .wpsmtp-btn-secondary {
                background: #555;
                border: none;
                padding: 10px 22px;
                border-radius: 8px;
                color: #fff;
                font-weight: 600;
                font-size: 14px;
                cursor: pointer;
            }

            .wpsmtp-notice {
                padding: 12px 15px;
                border-radius: 7px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .wpsmtp-success { background: #e5f8e8; border-left: 4px solid #2daa4a; }
            .wpsmtp-error { background: #ffeaea; border-left: 4px solid #d93025; }
        </style>

        <?php if ($success): ?>
            <div class="wpsmtp-notice wpsmtp-success"><?php echo wp_kses_post($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="wpsmtp-notice wpsmtp-error"><?php echo wp_kses_post($error); ?></div>
        <?php endif; ?>

        <div class="wpsmtp-grid">

            <!-- SMTP SETTINGS -->
            <div class="wpsmtp-card">
                <h2>SMTP Settings</h2>

                <form method="post">
                    <?php wp_nonce_field('wpsmtp_save'); ?>

                    <label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'],1); ?>> Enable SMTP</label>

                    <input class="wpsmtp-input" name="host" placeholder="SMTP Host" value="<?php echo esc_attr($s['host']); ?>">
                    <input class="wpsmtp-input" name="port" type="number" placeholder="Port" value="<?php echo esc_attr($s['port']); ?>">

                    <select name="encryption" class="wpsmtp-input">
                        <option value="none" <?php selected($s['encryption'],'none'); ?>>No Encryption</option>
                        <option value="ssl"  <?php selected($s['encryption'],'ssl'); ?>>SSL</option>
                        <option value="tls"  <?php selected($s['encryption'],'tls'); ?>>TLS</option>
                    </select>

                    <input class="wpsmtp-input" name="username" placeholder="SMTP Username" value="<?php echo esc_attr($s['username']); ?>">
                    <input class="wpsmtp-input" name="password" type="password" placeholder="SMTP Password" value="<?php echo esc_attr($s['password']); ?>">

                    <input class="wpsmtp-input" name="from_email" placeholder="From Email" value="<?php echo esc_attr($s['from_email']); ?>">
                    <input class="wpsmtp-input" name="from_name" placeholder="From Name" value="<?php echo esc_attr($s['from_name']); ?>">

                    <button class="wpsmtp-btn-primary" name="wpsmtp_save">Save Settings</button>
                </form>
            </div>

            <!-- SEND EMAIL -->
            <div class="wpsmtp-card">
                <h2>Email Composer</h2>

                <form method="post">
                    <?php wp_nonce_field('wpsmtp_send'); ?>

                    <input class="wpsmtp-input" name="mail_to" type="email" placeholder="Recipient email">
                    <input class="wpsmtp-input" name="mail_subject" placeholder="Subject">

                    <textarea class="wpsmtp-textarea" name="mail_body" placeholder="Write your message..."></textarea>

                    <button class="wpsmtp-btn-secondary" name="wpsmtp_send">Send Email</button>
                </form>
            </div>

        </div>
    </div>

<?php }
