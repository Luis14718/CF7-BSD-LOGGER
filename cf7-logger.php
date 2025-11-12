<?php
/*
Plugin Name: BSD CF7 Error Logger & Recipient Manager
Description: Logs CF7 invalid events, manages global recipients for all forms, and provides toggleable validations.
Version: 1.0.4
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

class CF7_Logger_Recipients_Manager {
    const OPT_RECIPIENTS = 'cf7lrm_recipients';
    const OPT_MINLEN_ENABLED = 'cf7lrm_minlen_enabled';
    const OPT_MINLEN_VALUE = 'cf7lrm_minlen_value';
    const OPT_EMAILDOMAIN_ENABLED = 'cf7lrm_emaildomain_enabled';
    const OPT_EMAILDOMAIN_ALLOWED = 'cf7lrm_emaildomain_allowed';
    const CPT_LOG = 'cf7_error_log';

    public function __construct() {
        add_action('init', [$this,'register_cpt']);
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('admin_post_cf7lrm_apply_recipients', [$this,'apply_recipients_handler']);
        add_action('wp_enqueue_scripts', [$this,'enqueue']);
        add_action('wp_ajax_cf7lrm_log', [$this,'ajax_log']);
        add_action('wp_ajax_nopriv_cf7lrm_log', [$this,'ajax_log']);
        add_filter('wpcf7_validate_text', [$this,'validate_minlen'], 20, 2);
        add_filter('wpcf7_validate_text*', [$this,'validate_minlen'], 20, 2);
        add_filter('wpcf7_validate_email', [$this,'validate_email_domain'], 20, 2);
        add_filter('wpcf7_validate_email*', [$this,'validate_email_domain'], 20, 2);
    }

    public function register_cpt() {
        register_post_type(self::CPT_LOG, [
            'label' => 'CF7 Error Logs',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title','editor','custom-fields'],
            'capability_type' => 'post',
            'menu_position' => 26
        ]);
    }

    public function admin_menu() {
        add_menu_page('CF7 Logger & Recipients', 'CF7 Logger', 'manage_options', 'cf7lrm', [$this,'settings_page']);
    }

    public function register_settings() {
        register_setting('cf7lrm_settings', self::OPT_RECIPIENTS);
        register_setting('cf7lrm_settings', self::OPT_MINLEN_ENABLED);
        register_setting('cf7lrm_settings', self::OPT_MINLEN_VALUE);
        register_setting('cf7lrm_settings', self::OPT_EMAILDOMAIN_ENABLED);
        register_setting('cf7lrm_settings', self::OPT_EMAILDOMAIN_ALLOWED);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $recipients = esc_attr(get_option(self::OPT_RECIPIENTS, ''));
        $minlen_enabled = (bool)get_option(self::OPT_MINLEN_ENABLED, 0);
        $minlen_value = intval(get_option(self::OPT_MINLEN_VALUE, 3));
        $emaildomain_enabled = (bool)get_option(self::OPT_EMAILDOMAIN_ENABLED, 0);
        $allowed_domains = esc_attr(get_option(self::OPT_EMAILDOMAIN_ALLOWED, ''));
        ?>
        <div class="wrap">
            <h1>CF7 Logger & Recipients</h1>
            <form method="post" action="options.php">
                <?php settings_fields('cf7lrm_settings'); do_settings_sections('cf7lrm_settings'); ?>
                <h2>Recipients</h2>
                <input type="text" name="<?php echo self::OPT_RECIPIENTS; ?>" value="<?php echo $recipients; ?>" class="regular-text" placeholder="email1@example.com, email2@example.com" />
                <p><small>Comma-separated.</small></p>
                <h2>Validations</h2>
                <p><label><input type="checkbox" name="<?php echo self::OPT_MINLEN_ENABLED; ?>" value="1" <?php checked($minlen_enabled); ?>/> Min length for text fields</label></p>
                <p><input type="number" name="<?php echo self::OPT_MINLEN_VALUE; ?>" value="<?php echo $minlen_value; ?>" min="1" /></p>
                <p><label><input type="checkbox" name="<?php echo self::OPT_EMAILDOMAIN_ENABLED; ?>" value="1" <?php checked($emaildomain_enabled); ?>/> Restrict email domains</label></p>
                <p><input type="text" name="<?php echo self::OPT_EMAILDOMAIN_ALLOWED; ?>" value="<?php echo $allowed_domains; ?>" class="regular-text" placeholder="example.com, company.org" /></p>
                <?php submit_button('Save Settings'); ?>
            </form>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:20px;">
                <input type="hidden" name="action" value="cf7lrm_apply_recipients" />
                <?php wp_nonce_field('cf7lrm_apply_recipients'); ?>
                <?php submit_button('Apply Recipients To All CF7 Forms', 'primary'); ?>
            </form>
        </div>
        <?php
    }

    public function apply_recipients_handler() {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('cf7lrm_apply_recipients');
        if (!class_exists('WPCF7_ContactForm')) {
            wp_redirect(admin_url('admin.php?page=cf7lrm'));
            exit;
        }
        $recipients = array_filter(array_map('trim', explode(',', get_option(self::OPT_RECIPIENTS, ''))));
        $recipient_str = implode(',', $recipients);
        $forms = WPCF7_ContactForm::find();
        foreach ($forms as $form) {
            $props = $form->get_properties();
            if (isset($props['mail']) && is_array($props['mail'])) {
                $props['mail']['recipient'] = $recipient_str;
            }
            if (isset($props['mail_2']) && is_array($props['mail_2'])) {
                $props['mail_2']['recipient'] = $recipient_str;
            }
            $form->set_properties($props);
            $form->save();
        }
        wp_redirect(admin_url('admin.php?page=cf7lrm'));
        exit;
    }

    public function enqueue() {
        wp_register_script('cf7lrm', plugin_dir_url(__FILE__) . 'cf7lrm.js', ['jquery'], '1.0.1', true);
        wp_enqueue_script('cf7lrm');
        wp_localize_script('cf7lrm', 'CF7LRM', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cf7lrm_nonce')
        ]);
        $inline = "
document.addEventListener('wpcf7invalid', function (event) {
  const invalidFields = event.detail.apiResponse && event.detail.apiResponse.invalidFields ? event.detail.apiResponse.invalidFields : [];
  const payload = {
    action: 'cf7lrm_log',
    nonce: CF7LRM.nonce,
    formId: event.detail.contactFormId || null,
    status: event.detail.apiResponse ? event.detail.apiResponse.status : 'invalid',
    fields: invalidFields
  };
  try {
    fetch(CF7LRM.ajax_url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: new URLSearchParams({action: payload.action, nonce: payload.nonce, formId: payload.formId, status: payload.status, fields: JSON.stringify(payload.fields) })});
  } catch(e) {}
}, false);
";
        wp_add_inline_script('cf7lrm', $inline, 'after');
    }

    public function ajax_log() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cf7lrm_nonce')) wp_send_json_error();
        $form_id = isset($_POST['formId']) ? sanitize_text_field($_POST['formId']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $fields_raw = isset($_POST['fields']) ? wp_unslash($_POST['fields']) : '[]';
        $fields = json_decode($fields_raw, true);
        $title = 'CF7 Invalid ' . current_time('mysql');
        $content = wp_json_encode([
            'formId' => $form_id,
            'status' => $status,
            'invalidFields' => is_array($fields) ? $fields : []
        ]);
        $post_id = wp_insert_post([
            'post_type' => self::CPT_LOG,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $content
        ]);
        if ($post_id) wp_send_json_success();
        wp_send_json_error();
    }

    public function validate_minlen($result, $tag) {
        $enabled = (bool)get_option(self::OPT_MINLEN_ENABLED, 0);
        if (!$enabled) return $result;
        $min = max(1, intval(get_option(self::OPT_MINLEN_VALUE, 3)));
        $name = $tag->name;
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return $result;
        $data = $submission->get_posted_data($name);
        if (is_array($data)) $data = implode(' ', $data);
        $value = trim((string)$data);
        if ($value !== '' && mb_strlen($value) < $min) {
            $result->invalidate($tag, sprintf(__('Please enter at least %d characters.', 'contact-form-7'), $min));
        }
        return $result;
    }

    public function validate_email_domain($result, $tag) {
        $enabled = (bool)get_option(self::OPT_EMAILDOMAIN_ENABLED, 0);
        if (!$enabled) return $result;
        $allowed = array_filter(array_map('strtolower', array_map('trim', explode(',', get_option(self::OPT_EMAILDOMAIN_ALLOWED, '')))));
        if (empty($allowed)) return $result;
        $name = $tag->name;
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return $result;
        $email = strtolower(trim((string)$submission->get_posted_data($name)));
        if ($email && strpos($email, '@') !== false) {
            $domain = substr($email, strrpos($email, '@') + 1);
            if (!in_array($domain, $allowed, true)) {
                $result->invalidate($tag, __('Email domain is not allowed.', 'contact-form-7'));
            }
        }
        return $result;
    }
}

echo "test";
new CF7_Logger_Recipients_Manager();

if (!class_exists('Puc_v4_Factory')) {
    $puc_path = __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
    if (file_exists($puc_path)) require $puc_path;
}

if (class_exists('Puc_v4_Factory')) {
    $cf7lrm_update_checker = Puc_v4_Factory::buildUpdateChecker(
        'https://github.com/Luis14718/CF7-BSD-LOGGER',
        __FILE__,
        'CF7-BSD-LOGGER'
    );
    $cf7lrm_update_checker->setBranch('master');
    $api = $cf7lrm_update_checker->getVcsApi();
    if ($api && method_exists($api, 'enableReleaseAssets')) $api->enableReleaseAssets();
}