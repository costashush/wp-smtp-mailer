<?php
/**
 * Plugin Name: WP SMTP & Mailer
 * Description: Configure SMTP via UI and send custom emails from WordPress using a built-in email editor.
 * Version: 1.0.0
 * Author: Storz
 */

if (!defined('ABSPATH')) exit;

define('WPSMTP_OPTION_KEY', 'wpsmtp_settings');

/**
 * Disable update checks for this plugin
 */
add_filter('site_transient_update_plugins', function ($value) {
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
    if (!is_array($saved)) $saved = [];
    return array_merge(wpsmtp_default_settings(), $saved);
}

/**
 * Apply SMTP settings to PHPMailer
 */
add_action('phpmailer_init', function ($phpmailer) {
    $s = wpsmtp_get_settings();
    if (empty($s['enabled'])) return;

    if (!$s['host'] || !$s['username'] || !$s['password']) return;

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
 * Admin page UI
 */
function wpsmtp_admin_page() {
    if (!current_user_can('manage_options')) return;

    $s          = wpsmtp_get_settings();
    $save_msg   = '';
    $mail_msg   = '';
    $mail_class = '';

    /* SAVE SETTINGS */
    if (isset($_POST['wpsmtp_save_settings'])) {
        check_admin_referer('wpsmtp_save_settings');

        $s['enabled']    = isset($_POST['enabled']) ? 1 : 0;
        $s['host']       = sanitize_text_field($_POST['host']);
        $s['port']       = intval($_POST['port']);
        $s['encryption'] = sanitize_text_field($_POST['encryption']);
        $s['username']   = sanitize_text_field($_POST['username']);
        $s['password']   = sanitize_text_field($_POST['password']);
        $s['from_email'] = sanitize_email($_POST['from_email']);
        $s['from_name']  = sanitize_text_field($_POST['from_name']);

        update_option(WPSMTP_OPTION_KEY, $s);
        $save_msg = 'SMTP settings saved.';
    }

    /* SEND EMAIL */
    if (isset($_POST['wpsmtp_send_email'])) {
        check_admin_referer('wpsmtp_send_email');

        $to      = sanitize_email($_POST['mail_to']);
        $subject = sanitize_text_field($_POST['mail_subject']);
        $body    = wp_kses_post($_POST['mail_body']);

        if (!$to || !$subject || !$body) {
            $mail_msg   = 'Please fill all fields.';
            $mail_class = 'notice-error';
        } else {
            add_filter('wp_mail_content_type', fn() => 'text/html');
            $sent = wp_mail($to, $subject, nl2br($body));
            add_filter('wp_mail_content_type', fn() => 'text/plain');

            if ($sent) {
                $mail_msg   = "Email sent successfully to <b>$to</b>.";
                $mail_class = 'notice-success';
            } else {
                $mail_msg   = "Email failed. Check SMTP settings.";
                $mail_class = 'notice-error';
            }
        }
    }

    ?>

    <div class="wrap wpsmtp-wrap">
        <h1>WP SMTP & Mailer</h1>
        <p class="description">Configure SMTP and send emails directly from WordPress.</p>

        <style>
            .wpsmtp-wrap .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                margin-top: 20px;
            }
            @media(max-width:900px) {
                .wpsmtp-wrap .grid { grid-template-columns: 1fr; }
            }
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            }
            textarea { width:100%; min-height:180px; }
        </style>

        <?php if ($save_msg): ?>
            <div class="notice notice-success"><p><?php echo esc_html($save_msg); ?></p></div>
        <?php endif; ?>
        <?php if ($mail_msg): ?>
            <div class="notice <?php echo esc_attr($mail_class); ?>"><p><?php echo wp_kses_post($mail_msg); ?></p></div>
        <?php endif; ?>

        <div class="grid">

            <!-- SMTP SETTINGS -->
            <div class="card">
                <h2>SMTP Settings</h2>
                <form method="post">
                    <?php wp_nonce_field('wpsmtp_save_settings'); ?>

                    <table class="form-table">
                        <tr><th>Enable SMTP</th><td><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'],1); ?>></td></tr>
                        <tr><th>SMTP Host</th><td><input type="text" name="host" class="regular-text" value="<?php echo esc_attr($s['host']); ?>"></td></tr>
                        <tr><th>Port</th><td><input type="number" name="port" class="small-text" value="<?php echo esc_attr($s['port']); ?>"></td></tr>
                        <tr>
                            <th>Encryption</th>
                            <td>
                                <select name="encryption">
                                    <option value="none" <?php selected($s['encryption'],'none'); ?>>None</option>
                                    <option value="ssl" <?php selected($s['encryption'],'ssl'); ?>>SSL</option>
                                    <option value="tls" <?php selected($s['encryption'],'tls'); ?>>TLS</option>
                                </select>
                            </td>
                        </tr>
                        <tr><th>Username</th><td><input type="text" name="username" class="regular-text" value="<?php echo esc_attr($s['username']); ?>"></td></tr>
                        <tr><th>Password</th><td><input type="password" name="password" class="regular-text" value="<?php echo esc_attr($s['password']); ?>"></td></tr>
                        <tr><th>From Email</th><td><input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr($s['from_email']); ?>"></td></tr>
                        <tr><th>From Name</th><td><input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr($s['from_name']); ?>"></td></tr>
                    </table>

                    <p><button class="button button-primary" name="wpsmtp_save_settings">Save Settings</button></p>
                </form>
            </div>

            <!-- EMAIL EDITOR -->
            <div class="card">
                <h2>Email Editor</h2>
                <form method="post">
                    <?php wp_nonce_field('wpsmtp_send_email'); ?>

                    <table class="form-table">
                        <tr><th>To</th><td><input type="email" name="mail_to" class="regular-text"></td></tr>
                        <tr><th>Subject</th><td><input type="text" name="mail_subject" class="regular-text"></td></tr>
                        <tr><th>Body</th><td><textarea name="mail_body"></textarea></td></tr>
                    </table>

                    <p><button class="button button-secondary" name="wpsmtp_send_email">Send Email</button></p>
                </form>
            </div>

        </div>
    </div>

<?php }
