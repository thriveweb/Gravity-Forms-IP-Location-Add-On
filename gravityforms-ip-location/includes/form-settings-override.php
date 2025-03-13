<?php
/**
 * Form Settings Override
 * 
 * Handles enhanced UI multiselect field values and provides
 * compatibility with Gravity Forms settings framework.
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Process multiselect values from form submissions
 * This function handles both standard and enhanced UI multiselect fields
 * 
 * @param array $settings The current settings array
 * @param array $posted_data Sanitized post data (optional)
 * @return array Modified settings array with processed multiselect values
 */
function gf_iplocation_process_multiselect_values($settings, $posted_data = null) {
    // Use provided posted data or safely get from $_POST
    if (null === $posted_data) {
        $posted_data = wp_unslash($_POST);
    }
    
    // Process JSON-encoded multiselect values from hidden fields
    foreach ($posted_data as $key => $value) {
        if (strpos($key, '_gf_multiselect_values_') === 0) {
            $field_name = str_replace('_gf_multiselect_values_', '', $key);
            $simple_name = str_replace('_gaddon_setting_', '', $field_name);
            
            // Sanitize and decode with error handling
            $sanitized_value = sanitize_text_field($value);
            $decoded_values = json_decode($sanitized_value, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON decode error in gf_iplocation_process_multiselect_values: ' . json_last_error_msg());
                continue;
            }
            
            if (is_array($decoded_values)) {
                $settings[$simple_name] = array_map('sanitize_text_field', $decoded_values);
            }
        }
    }
    
    // Fall back to standard multiselect handling if needed
    if (!isset($settings['allowed_countries']) || empty($settings['allowed_countries'])) {
        $field_key = '_gaddon_setting_allowed_countries';
        
        if (isset($posted_data[$field_key])) {
            if (is_array($posted_data[$field_key])) {
                $settings['allowed_countries'] = array_map('sanitize_text_field', $posted_data[$field_key]);
            } else {
                $settings['allowed_countries'] = array(sanitize_text_field($posted_data[$field_key]));
            }
        } else {
            $settings['allowed_countries'] = array();
        }
    }
    
    return $settings;
}

/**
 * Filter to modify form settings before they're saved
 * Also handles security verification
 */
add_filter('gfaddon_pre_save_form_settings', 'gf_iplocation_form_settings_override', 10, 2);
function gf_iplocation_form_settings_override($settings, $addon) {
    // Only process for our add-on
    if ($addon->get_slug() !== 'gravityformsiplocation') {
        return $settings;
    }
    
    // Strict nonce verification
    if (!wp_verify_nonce(rgpost('_wpnonce'), $addon->get_slug())) {
        // Add admin error notice
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Error:</strong> Security verification failed. Settings not saved.</p>';
            echo '</div>';
        });
        return $settings;
    }
    
    // Sanitize POST data properly
    $sanitized_post = array();
    foreach ($_POST as $key => $value) {
        if (is_array($value)) {
            $sanitized_post[$key] = map_deep($value, 'sanitize_text_field');
        } else {
            $sanitized_post[$key] = sanitize_text_field($value);
        }
    }
    
    return gf_iplocation_process_multiselect_values($settings, $sanitized_post);
}
