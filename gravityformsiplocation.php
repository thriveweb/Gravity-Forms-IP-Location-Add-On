<?php
/*
Plugin Name: Gravity Forms IP Location Add-On
Description: Replaces {user:country} merge tag with the country of the user's IP
Version: 1.0
Author: Dean Oakley
Author URI: https://thriveweb.com.au/
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

add_action('admin_menu', 'add_iplocation_key_option_page');

function add_iplocation_key_option_page() {
    add_options_page('IP Location Settings', 'IP Location Settings', 'manage_options', 'iplocation', 'iplocation_option_page');
}

function iplocation_option_page() {
    ?>
    <div>
        <h2>Gravity Forms IP Location Add-On Settings</h2>
        <form method="post" action="options.php">
            <?php wp_nonce_field('update-options') ?>
            <p><strong>Ipstack Access Key:</strong><br />
                <input type="text" name="ipstack_access_key" size="45" value="<?php echo esc_attr(get_option('ipstack_access_key')); ?>" />
            </p>
            <p><input type="submit" name="Submit" value="Save" /></p>
            <input type="hidden" name="action" value="update" />
            <input type="hidden" name="page_options" value="ipstack_access_key" />
        </form>
    </div>
    <?php
}

add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'add_plugin_page_settings_link' );
function add_plugin_page_settings_link( $links ) {
    $links[] = '<a href="' .
        admin_url( 'options-general.php?page=iplocation' ) .
        '">' . __('Settings') . '</a>';
    return $links;
}

function replace_country_merge_tag( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
    $custom_merge_tag = '{user:country}';
    if ( strpos( $text, $custom_merge_tag ) !== false && $entry !== false ) {
        $ip = GFFormsModel::get_ip();
        $user_country = getCountryFromIP($ip);
        $text = str_replace( $custom_merge_tag, $user_country, $text );
    }
    return $text;
}

add_filter('gform_pre_submission_filter', 'set_country_field_value');
function set_country_field_value($form) {
    // Get the user's IP and country
    $ip = GFFormsModel::get_ip();
    $user_country = getCountryFromIP($ip);

    // Loop through the form fields and find the hidden field with default value as {user:country}
    foreach($form['fields'] as &$field) {
        if($field->type == 'hidden' && $field->defaultValue == '{user:country}') {
            $_POST['input_' . $field->id] = $user_country;  // Set the field value
        }
    }
    return $form;
}

function getCountryFromIP($ip) {
    if(filter_var($ip, FILTER_VALIDATE_IP)){
        $ipstack_access_key = get_option('ipstack_access_key', '');
        $response = wp_remote_get("http://api.ipstack.com/{$ip}?access_key={$ipstack_access_key}");

        if(is_wp_error($response)){
            return 'Not Found';
        }

        $ipdata = json_decode(wp_remote_retrieve_body($response), true);

        if($ipdata && isset($ipdata['country_name'])) {
            return $ipdata['country_name'];
        } else {
            return 'Not Found';
        }
    } else {
        return 'Invalid IP';
    }
}
