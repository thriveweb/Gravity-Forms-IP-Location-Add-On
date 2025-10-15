<?php
/*
Plugin Name: Gravity Forms IP Location Add-On
Description: Enhances Gravity Forms with IP geolocation features. Auto-populates form fields with user location data (country, city, region, etc.), enables country-based form restrictions, and includes detailed location data in form submissions.
Version: 1.3
Author: Thrive Digital
Author URI: https://thriveweb.com.au/
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants
define( 'GF_IPLOCATION_ADDON_VERSION', '1.3' );
define( 'GF_IPLOCATION_SUCCESS_CACHE_DURATION', 24 * HOUR_IN_SECONDS );
define( 'GF_IPLOCATION_ERROR_CACHE_DURATION', 1 * HOUR_IN_SECONDS );

// After Gravity Forms is loaded, load the add-on
add_action( 'gform_loaded', array( 'GF_IP_Location_AddOn_Bootstrap', 'load' ), 5 );

/**
 * Bootstrap class to initialize the add-on
 */
class GF_IP_Location_AddOn_Bootstrap {
    public static function load() {
        if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
            return;
        }
        
        // Register the add-on with Gravity Forms
        GFAddOn::register( 'GFIPLocationAddOn' );
    }
}

// Include form settings override to fix multiselect issues
require_once plugin_dir_path(__FILE__) . 'includes/form-settings-override.php';

// Create the main class file
if ( ! class_exists( 'GFForms' ) ) {
    return;
}

GFForms::include_addon_framework();

/**
 * Main IP Location Add-On class
 */
class GFIPLocationAddOn extends GFAddOn {
    protected $_version = GF_IPLOCATION_ADDON_VERSION;
    protected $_min_gravityforms_version = '1.9';
    protected $_slug = 'gravityformsiplocation';
    protected $_path = 'gravityformsiplocation/gravityformsiplocation.php';
    protected $_full_path = __FILE__;
    protected $_title = 'Gravity Forms IP Location Add-On';
    protected $_short_title = 'IP Location';
    
    private static $_instance = null;
    
    // Cache implementation
    private $request_ip_cache = array();
    private $cache_lru;
    private $cache_max_size = 100;
    
    /**
     * Get an instance of this class.
     */
    public static function get_instance() {
        if ( self::$_instance == null ) {
            self::$_instance = new GFIPLocationAddOn();
            
            // Initialize constants and cache structures
            self::$_instance->define_constants();
            self::$_instance->init_cache();
        }
        
        return self::$_instance;
    }
    
    /**
     * Define plugin constants
     */
    public function define_constants() {
        // Maximum number of IP addresses to keep in memory cache
        if (!defined('GF_IPLOCATION_MAX_CACHE_SIZE')) {
            define('GF_IPLOCATION_MAX_CACHE_SIZE', 100);
        }
        
        $this->cache_max_size = GF_IPLOCATION_MAX_CACHE_SIZE;
    }
    
    /**
     * Initialize the cache data structure
     */
    public function init_cache() {
        // Use SplDoublyLinkedList for efficient LRU cache implementation
        $this->cache_lru = new SplDoublyLinkedList();
        $this->request_ip_cache = array();
    }
    
    /**
     * Plugin starting point. Handles hooks and loading of language files.
     */
    public function init() {
        parent::init();
        
        // Add filters and actions
        add_filter('gform_replace_merge_tags', array($this, 'replace_ip_location_merge_tags'), 10, 7);
        add_filter('gform_pre_submission_filter', array($this, 'set_ip_location_field_values'), 10, 1);
        
        // Use standard GF validation - ONLY use the new method, remove the old one
        add_filter('gform_validation', array($this, 'validate_country_for_form'), 20, 1);
        
        // Add admin notice about logging
        add_action('admin_notices', array($this, 'maybe_show_debug_notice'));
        
        // Enqueue the multiselect fix JS on admin pages
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add nonce verification to settings
        add_action('admin_init', array($this, 'add_nonce_to_settings_form'));
        add_action('admin_post_gf_iplocation_clear_cache', array($this, 'handle_clear_cache_request'));
        
        // Clear request cache periodically to prevent memory issues
        add_action('shutdown', array($this, 'clear_request_cache'));
        
        // Fixed: Updated merge tag filter - proper priority and parameter count
        add_filter('gform_custom_merge_tags', array($this, 'add_ip_location_merge_tags'), 10, 4);
    }

    /**
     * Add IP location merge tags to the Gravity Forms merge tag dropdown
     * Fixed implementation to match Gravity Forms expected structure
     * 
     * @param array $merge_tags Array of merge tags
     * @param int $form_id Current form ID
     * @return array Modified merge tags array
     */
    public function add_ip_location_merge_tags($merge_tags, $form_id, $fields, $element_id ) {
        // Defensive coding to prevent errors
        if (!is_array($merge_tags)) {
            $merge_tags = array();
        }
        
        try {
            // Create individual tags directly - NOT nested in a group
            $merge_tags[] = array(
                'label' => esc_html__('IP: User Country', 'gravityformsiplocation'),
                'tag'   => '{user:country}'
            );
            
            $merge_tags[] = array(
                'label' => esc_html__('IP: User City', 'gravityformsiplocation'),
                'tag'   => '{user:city}'
            );
            
            $merge_tags[] = array(
                'label' => esc_html__('IP: User Region/State', 'gravityformsiplocation'),
                'tag'   => '{user:region}'
            );
            
            $merge_tags[] = array(
                'label' => esc_html__('IP: User Continent', 'gravityformsiplocation'),
                'tag'   => '{user:continent}'
            );
            
            $merge_tags[] = array(
                'label' => esc_html__('IP: User Latitude', 'gravityformsiplocation'),
                'tag'   => '{user:latitude}'
            );
            
            $merge_tags[] = array(
                'label' => esc_html__('IP: User Longitude', 'gravityformsiplocation'),
                'tag'   => '{user:longitude}'
            );
        } catch (Exception $e) {
            // Log any errors but don't break the form
            if (method_exists($this, 'log_error')) {
                $this->log_error("Error adding merge tags: " . $e->getMessage());
            }
        }
        
        return $merge_tags;
    }
    
    /**
     * Enqueue admin scripts for multiselect fix - removed jQuery dependency
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on Gravity Forms pages
        if (strpos($hook, 'gf_edit_forms') !== false || strpos($hook, 'page_gf_edit_forms') !== false) {
            wp_enqueue_script(
                'gf-ip-location-multiselect-fix',
                plugin_dir_url(__FILE__) . 'js/multiselect-fix.js',
                array(), // Removed jQuery dependency
                $this->_version,
                true
            );
        }
    }
    
    /**
     * Clear request cache to prevent memory issues
     */
    public function clear_request_cache() {
        // Reset the cache to free memory
        $this->request_ip_cache = array();
        $this->cache_lru = new SplDoublyLinkedList();
    }
    
    /**
     * Unified method to handle cache access and updates
     * This combines the previous add_to_request_cache and mark_cache_key_accessed
     * 
     * @param string $key The cache key
     * @param mixed $data The data to cache (null if just accessing)
     * @param bool $is_access_only Whether this is just an access or a data update
     * @return mixed The cached data
     */
    private function cache_operation($key, $data = null, $is_access_only = false) {
        // If the key exists in cache
        if (isset($this->request_ip_cache[$key])) {
            // Find the key in the LRU list
            for ($this->cache_lru->rewind(); $this->cache_lru->valid(); $this->cache_lru->next()) {
                if ($this->cache_lru->current() === $key) {
                    // Remove from current position
                    $this->cache_lru->offsetUnset($this->cache_lru->key());
                    break;
                }
            }
            
            // If we're just accessing, keep the existing data
            if ($is_access_only) {
                $data = $this->request_ip_cache[$key];
            }
        } else {
            // New key - check if we need to remove oldest entry
            if (count($this->request_ip_cache) >= $this->cache_max_size && $this->cache_lru->count() > 0) {
                // Remove least recently used item
                $oldest_key = $this->cache_lru->shift();
                if ($oldest_key) {
                    unset($this->request_ip_cache[$oldest_key]);
                }
            }
        }
        
        // Update data if provided
        if (!$is_access_only) {
            $this->request_ip_cache[$key] = $data;
        }
        
        // Add to end of LRU list (most recently used)
        $this->cache_lru->push($key);
        
        return $data;
    }

    /**
     * Handle cache clearing requests from admin
     */
    public function handle_clear_cache_request() {
        // Verify nonce for security
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gf_iplocation_clear_cache')) {
            wp_die(__('Security check failed', 'gravityformsiplocation'));
        }
        
        // Verify user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action', 'gravityformsiplocation'));
        }
        
        $transients_cleared = 0;
        $object_cache_cleared = 0;
        
        // Get all transients and delete IP location ones
        global $wpdb;
        $transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '%_transient_ipstack_%'"
        );
        
        foreach ($transients as $transient) {
            $name = str_replace('_transient_', '', $transient);
            delete_transient($name);
            $transients_cleared++;
        }
        
        // Selectively clear object cache entries instead of flushing entire cache
        if (wp_using_ext_object_cache()) {
            // Get all cached IPs from memory cache to clear their object cache entries
            if ($this->request_ip_cache) {
                foreach (array_keys($this->request_ip_cache) as $ip) {
                    $object_cache_key = 'ipstack_' . $ip;
                    wp_cache_delete($object_cache_key, 'gf_iplocation');
                    $object_cache_cleared++;
                }
            }
            
            // Also clear any internal tracking
            $this->clear_request_cache();
        }
        
        // Also clean up expired transients for better database performance
        if ($transients_cleared > 0) {
            // Optional: helps maintain database performance
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_ipstack\_%' AND option_value < " . time());
        }
        
        // Redirect back with success message
        $redirect = add_query_arg(array(
            'page' => 'gf_settings',
            'subview' => $this->_slug,
            'cache_cleared' => $transients_cleared,
            'object_cache_cleared' => $object_cache_cleared,
        ), admin_url('admin.php'));
        
        wp_redirect($redirect);
        exit;
    }

    /**
     * Render the cache control settings field with improved UI
     */
    public function settings_cache_controls() {
        $cache_url = wp_nonce_url(
            admin_url('admin-post.php?action=gf_iplocation_clear_cache'),
            'gf_iplocation_clear_cache'
        );
        
        // Get cache statistics
        global $wpdb;
        $transient_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE '%_transient_ipstack_%'"
        );
        
        $memory_cache_count = count($this->request_ip_cache);
        
        echo '<div class="gf-iplocation-cache-controls">';
        
        // Display message if cache was cleared
        if (isset($_GET['cache_cleared']) || isset($_GET['object_cache_cleared'])) {
            $transients = isset($_GET['cache_cleared']) ? intval($_GET['cache_cleared']) : 0;
            $object_cache = isset($_GET['object_cache_cleared']) ? intval($_GET['object_cache_cleared']) : 0;
            
            echo '<div class="notice notice-success inline" style="margin:0 0 15px 0; padding:10px; display: block !important;">';
            echo '<p><strong>Cache Cleared Successfully</strong></p>';
            echo '<ul style="margin-top:5px; margin-bottom:0; padding-left:25px; list-style-type:disc;">';
            printf('<li>%s</li>', sprintf(esc_html__('Cleared %d persistent cache entries', 'gravityformsiplocation'), $transients));
            if ($object_cache > 0) {
                printf('<li>%s</li>', sprintf(esc_html__('Cleared %d object cache entries', 'gravityformsiplocation'), $object_cache));
            }
            echo '</ul></div>';
        }
        
        // Cache statistics block
        echo '<div class="gf-iplocation-cache-stats" style="margin-bottom:15px; background:#f8f8f8; border:1px solid #ddd; border-radius:3px; padding:12px;">';
        echo '<h4 style="margin-top:0; margin-bottom:10px;">' . esc_html__('Current Cache Status:', 'gravityformsiplocation') . '</h4>';
        echo '<ul style="margin:0; padding-left:20px; list-style-type:disc;">';
        printf(
            '<li>%s <strong>%d</strong></li>', 
            esc_html__('Persistent cache entries:', 'gravityformsiplocation'),
            intval($transient_count)
        );
        printf(
            '<li>%s <strong>%d</strong> <em>(%s)</em></li>', 
            esc_html__('Memory cache entries:', 'gravityformsiplocation'),
            intval($memory_cache_count),
            esc_html__('current request only', 'gravityformsiplocation')
        );
        
        // Show cache durations
        echo '<li>' . sprintf(
            esc_html__('Success cache duration: %s', 'gravityformsiplocation'),
            '<strong>' . human_time_diff(0, GF_IPLOCATION_SUCCESS_CACHE_DURATION) . '</strong>'
        ) . '</li>';
        echo '<li>' . sprintf(
            esc_html__('Error cache duration: %s', 'gravityformsiplocation'),
            '<strong>' . human_time_diff(0, GF_IPLOCATION_ERROR_CACHE_DURATION) . '</strong>'
        ) . '</li>';
        echo '</ul>';
        echo '</div>';
        
        // Action buttons
        echo '<div class="gf-iplocation-cache-actions">';
        echo '<a href="' . esc_url($cache_url) . '" onclick="return confirm(\'' . 
            esc_js(__('Are you sure you want to clear the IP location cache?', 'gravityformsiplocation')) . 
            '\');" class="button">';
        esc_html_e('Clear IP Location Cache', 'gravityformsiplocation');
        echo '</a>';
        
        echo '<p class="description" style="margin-top:8px;">';
        esc_html_e('This will clear cached IP location data, forcing new lookups on the next request. Use this if you\'re experiencing incorrect location data.', 'gravityformsiplocation');
        echo '</p>';
        echo '</div>';
        
        echo '</div>';
        
        // Add some basic styling
        echo '<style>
            .gf-iplocation-cache-controls {
                margin: 10px 0;
            }
        </style>';
    }

    /**
     * Define settings fields for the add-on
     */
    public function plugin_settings_fields() {
        return array(
            array(
                'title'  => esc_html__( 'IP Location Settings', 'gravityformsiplocation' ),
                'fields' => array(
                    array(
                        'name'              => 'ipstack_access_key',
                        'tooltip'           => esc_html__( 'Enter your IPStack API key', 'gravityformsiplocation' ),
                        'label'             => esc_html__( 'IPStack Access Key', 'gravityformsiplocation' ),
                        'type'              => 'text',
                        'class'             => 'medium',
                        'required'          => true,
                        'feedback_callback' => array( $this, 'validate_ipstack_key' ),
                    ),
                    array(
                        'type'  => 'cache_controls',
                        'name'  => 'cache_controls',
                        'label' => esc_html__( 'Cache Management', 'gravityformsiplocation' ),
                        'callback' => array($this, 'settings_cache_controls'),
                    ),
                )
            ),
        );
    }

    /**
     * Define form settings fields for the add-on
     */
    public function form_settings_fields($form) {
        return array(
            array(
                'title'  => esc_html__( 'Country Restriction Settings', 'gravityformsiplocation' ),
                'fields' => array(
                    array(
                        'name'    => 'enable_country_validation',
                        'tooltip' => esc_html__( 'When enabled, form submissions will be restricted to users from the selected countries only', 'gravityformsiplocation' ),
                        'label'   => esc_html__( 'Enable Country Validation', 'gravityformsiplocation' ),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label' => esc_html__( 'Restrict submissions to specific countries', 'gravityformsiplocation' ),
                                'name'  => 'enable_country_validation',
                            ),
                        ),
                    ),
                    array(
                        'name'              => 'allowed_countries',
                        'tooltip'           => esc_html__( 'Select which countries to allow form submissions from', 'gravityformsiplocation' ),
                        'label'             => esc_html__( 'Allowed Countries', 'gravityformsiplocation' ),
                        'type'              => 'select',
                        'multiple'          => true,
                        'choices'           => array(
                            array( 'label' => 'Australia', 'value' => 'Australia' ),
                            array( 'label' => 'New Zealand', 'value' => 'New Zealand' ),
                            array( 'label' => 'United States', 'value' => 'United States' ),
                            array( 'label' => 'Canada', 'value' => 'Canada' ),
                            array( 'label' => 'United Kingdom', 'value' => 'United Kingdom' ),
                        ),
                        'default_value'     => array('Australia'),
                        'enhanced_ui'       => true,
                        'class'             => 'medium',
                        'dependency'        => array(
                            'live'   => true,
                            'fields' => array(
                                array(
                                    'field'  => 'enable_country_validation',
                                    'values' => array( '1' ),
                                ),
                            ),
                        ),
                        'required' => true, // This will make it required when visible
                    ),
                    array(
                        'name'       => 'country_validation_message',
                        'tooltip'    => esc_html__( 'Message to display when a user from a non-allowed country attempts to submit a form', 'gravityformsiplocation' ),
                        'label'      => esc_html__( 'Validation Message', 'gravityformsiplocation' ),
                        'type'       => 'textarea',
                        'class'      => 'medium',
                        'default_value' => 'Sorry, this form is only available to users from allowed countries.',
                        'dependency' => array(
                            'live'   => true,
                            'fields' => array(
                                array(
                                    'field'  => 'enable_country_validation',
                                    'values' => array( '1' ),
                                ),
                            ),
                        ),
                    ),
                )
            ),
        );
    }
    
    /**
     * Custom validation callback for allowed_countries field
     * This ensures the value is always treated as an array and validates when required
     */
    public function validate_allowed_countries($value, $field) {
        // Convert string to array if needed
        if (is_string($value)) {
            $value = array($value);
        } elseif (!is_array($value)) {
            $value = array();
        }
        
        // If country validation is enabled, make sure countries are selected
        $settings = $this->get_current_settings();
        if (!empty($settings['enable_country_validation']) && empty($value)) {
            return false; // This will trigger the field as invalid
        }
        
        return true;
    }
    
    /**
     * Validate IPStack API Key
     */
    public function validate_ipstack_key( $value ) {
        if ( empty( $value ) ) {
            return false;
        }
        
        // You could add more validation here, like making a test API call
        return true;
    }
    
    /**
     * Replace IP location merge tags
     */
    public function replace_ip_location_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
        // Early return if text doesn't contain any of our merge tags - removed error tag from pattern
        if (!preg_match('/{user:(country|city|region|continent|latitude|longitude)}/', $text)) {
            return $text;
        }
        
        // Check if we have an entry before proceeding
        if ($entry === false) {
            return $text;
        }
        
        // Get IP data since we know tags are present
        $ip = GFFormsModel::get_ip();
        $ip_data = $this->get_location_data_from_ip($ip);
        
        // Replace each merge tag with corresponding data
        if (!empty($ip_data) && isset($ip_data['country_name'])) {
            $text = str_replace('{user:country}', $ip_data['country_name'], $text);
        }
        if (!empty($ip_data) && isset($ip_data['city'])) {
            $text = str_replace('{user:city}', $ip_data['city'], $text);
        }
        if (!empty($ip_data) && isset($ip_data['region_name'])) {
            $text = str_replace('{user:region}', $ip_data['region_name'], $text);
        }
        if (!empty($ip_data) && isset($ip_data['continent_name'])) {
            $text = str_replace('{user:continent}', $ip_data['continent_name'], $text);
        }
        if (!empty($ip_data) && isset($ip_data['latitude'])) {
            $text = str_replace('{user:latitude}', $ip_data['latitude'], $text);
        }
        if (!empty($ip_data) && isset($ip_data['longitude'])) {
            $text = str_replace('{user:longitude}', $ip_data['longitude'], $text);
        }
        
        return $text;
    }
    
    /**
     * Validate user's country for the entire form - integrates with GF validation
     */
    public function validate_country_for_form($validation_result) {
        // If form is already invalid, don't continue
        if (!$validation_result['is_valid']) {
            return $validation_result;
        }

        $form = $validation_result['form'];
        $form_id = absint($form['id']); // Sanitize with absint()
        
        // Get form settings
        $settings = $this->get_form_settings($form);
        
        // Only validate if country validation is enabled
        $enable_validation = (bool) rgar($settings, 'enable_country_validation');
        if (!$enable_validation) {
            return $validation_result;
        }
        
        // Check for API key
        $plugin_settings = $this->get_plugin_settings();
        $api_key = sanitize_text_field(rgar($plugin_settings, 'ipstack_access_key', ''));
        
        if (empty($api_key)) {
            // Add field error using Gravity Forms API
            $validation_result = $this->add_validation_error(
                $validation_result,
                'Configuration error: Missing API key'
            );
            return $validation_result;
        }
        
        // Get allowed countries
        $allowed_countries = (array) rgar($settings, 'allowed_countries', array());
        if (empty($allowed_countries)) {
            return $validation_result; // No restrictions
        }
        
        // Get user's IP and location
        $ip = GFFormsModel::get_ip();
        $ip_data = $this->get_location_data_from_ip($ip);
        
        // Check if we encountered an API error
        if (!empty($ip_data['is_error'])) {
            // FAIL OPEN: Allow the form submission despite API error
            $this->log_debug(__METHOD__ . "(): API error detected but allowing submission (fail open): " . 
                (isset($ip_data['error_message']) ? $ip_data['error_message'] : 'Unknown API error'));
                
            // Store error info for combined processing rather than adding note directly
            $form_id = absint($form['id']);
            if (!property_exists($this, 'pending_location_notes')) {
                $this->pending_location_notes = array();
            }
            
            if (!isset($this->pending_location_notes[$form_id])) {
                $this->pending_location_notes[$form_id] = array();
            }
            
            $this->pending_location_notes[$form_id]['ip_data'] = $ip_data;
            $this->pending_location_notes[$form_id]['has_validation'] = true;
            $this->pending_location_notes[$form_id]['has_api_error'] = true;
            
            add_action('gform_after_submission_' . $form_id, array($this, 'maybe_add_location_notes'), 10, 2);
            
            return $validation_result;
        }
        
        // Normal validation continues with valid data
        $user_country = isset($ip_data['country_name']) ? $ip_data['country_name'] : '';
        
        // Track validation data for entry notes
        $form_id = absint($form['id']);
        if (!property_exists($this, 'pending_location_notes')) {
            $this->pending_location_notes = array();
        }
        
        // Store validation data while preserving merge tags data if it exists
        if (!isset($this->pending_location_notes[$form_id])) {
            $this->pending_location_notes[$form_id] = array();
        }
        
        $this->pending_location_notes[$form_id]['ip_data'] = $ip_data;
        $this->pending_location_notes[$form_id]['allowed_countries'] = $allowed_countries;
        $this->pending_location_notes[$form_id]['has_validation'] = true;
        $this->pending_location_notes[$form_id]['type'] = isset($this->pending_location_notes[$form_id]['has_merge_tags']) ? 
            'combined' : 'validation';
        
        add_action('gform_after_submission_' . $form_id, array($this, 'maybe_add_location_notes'), 10, 2);
        
        // Check if country is allowed
        if (!empty($user_country) && !in_array($user_country, $allowed_countries, true)) {
            // Get custom validation message
            $validation_message = rgar(
                $settings, 
                'country_validation_message', 
                'Sorry, this form is only available to users from allowed countries.'
            );
            
            // Add form validation error
            $validation_result = $this->add_validation_error(
                $validation_result, 
                $validation_message
            );
        }
        
        return $validation_result;
    }
    
    /**
     * Set IP location values in hidden fields
     */
    public function set_ip_location_field_values($form) {
        // First check if there are any fields that need our merge tags
        $needs_location_data = false;
        $fields_with_tags = array();
        
        // Use modern WP array functions
        $form_fields = wp_list_filter($form['fields'], array('type' => 'hidden'));
        
        foreach ($form_fields as $field) {
            // Check if field uses any of our merge tags
            if (preg_match('/{user:(country|city|region|continent|latitude|longitude)}/', $field->defaultValue)) {
                $needs_location_data = true;
                
                // Track which fields use which tags for the note
                preg_match('/{user:(country|city|region|continent|latitude|longitude)}/', $field->defaultValue, $matches);
                if (!empty($matches[1])) {
                    $fields_with_tags[] = array(
                        'field_id' => $field->id,
                        'field_label' => !empty($field->label) ? $field->label : 'Hidden Field #' . $field->id,
                        'tag_type' => $matches[1]
                    );
                }
            }
        }
        
        // Early return if no fields need location data
        if (!$needs_location_data) {
            $this->log_debug(__METHOD__ . "(): No fields need location data, skipping API call");
            return $form;
        }
        
        // Get the user's IP and location data
        $ip = GFFormsModel::get_ip();
        $ip_data = $this->get_location_data_from_ip($ip);
        
        if (empty($ip_data)) {
            return $form;
        }

        // Check for API errors before processing fields
        if (!empty($ip_data['is_error'])) {
            // For API errors, store info for later note but continue with submission
            $form_id = absint($form['id']);
            
            if (!property_exists($this, 'pending_location_notes')) {
                $this->pending_location_notes = array();
            }
            
            if (!isset($this->pending_location_notes[$form_id])) {
                $this->pending_location_notes[$form_id] = array();
            }
            
            $this->pending_location_notes[$form_id]['ip_data'] = $ip_data;
            $this->pending_location_notes[$form_id]['fields_with_tags'] = $fields_with_tags;
            $this->pending_location_notes[$form_id]['has_merge_tags'] = true;
            $this->pending_location_notes[$form_id]['has_api_error'] = true;
            
            add_action('gform_after_submission_' . $form_id, array($this, 'maybe_add_location_notes'), 10, 2);
            
            // Continue with empty values
            return $form;
        }
    
        // Define field mappings
        $field_mappings = array(
            '{user:country}' => isset($ip_data['country_name']) ? $ip_data['country_name'] : '',
            '{user:city}' => isset($ip_data['city']) ? $ip_data['city'] : '',
            '{user:region}' => isset($ip_data['region_name']) ? $ip_data['region_name'] : '',
            '{user:continent}' => isset($ip_data['continent_name']) ? $ip_data['continent_name'] : '',
            '{user:latitude}' => isset($ip_data['latitude']) ? $ip_data['latitude'] : '',
            '{user:longitude}' => isset($ip_data['longitude']) ? $ip_data['longitude'] : ''
        );
    
        // Loop through the form fields and find hidden fields with matching merge tags
        foreach($form['fields'] as &$field) {
            if($field->type == 'hidden') {
                foreach($field_mappings as $merge_tag => $value) {
                    if($field->defaultValue == $merge_tag) {
                        $_POST['input_' . $field->id] = $value;
                    }
                }
            }
        }
        
        // Store merge tags data for entry notes
        if (!empty($fields_with_tags)) {
            $form_id = absint($form['id']);
            
            if (!property_exists($this, 'pending_location_notes')) {
                $this->pending_location_notes = array();
            }
            
            if (!isset($this->pending_location_notes[$form_id])) {
                $this->pending_location_notes[$form_id] = array();
            }
            
            $this->pending_location_notes[$form_id]['ip_data'] = $ip_data;
            $this->pending_location_notes[$form_id]['fields_with_tags'] = $fields_with_tags;
            $this->pending_location_notes[$form_id]['has_merge_tags'] = true;
            $this->pending_location_notes[$form_id]['type'] = isset($this->pending_location_notes[$form_id]['has_validation']) ? 
                'combined' : 'merge_tags';
            
            if (!has_action('gform_after_submission_' . $form_id, array($this, 'maybe_add_location_notes'))) {
                add_action('gform_after_submission_' . $form_id, array($this, 'maybe_add_location_notes'), 10, 2);
            }
        }
        
        return $form;
    }
    
    /**
     * Get location data from IP using IPStack API with improved cache handling
     */
    public function get_location_data_from_ip($ip) {
        // Bail early if IP is empty
        if (empty($ip)) {
            $this->log_error(__METHOD__ . "(): Empty IP address provided");
            return array(
                'country_name' => 'Invalid IP',
                'is_error' => true,
                'error_message' => 'Empty IP address provided'
            );
        }
        
        // Sanitize the IP address
        $ip = sanitize_text_field($ip);
        
        // First check request-level cache to prevent redundant API calls
        if (isset($this->request_ip_cache[$ip])) {
            // Access the cache and update LRU order
            return $this->cache_operation($ip, null, true);
        }
        
        // Check WordPress object cache first if available
        $object_cache_key = 'ipstack_' . $ip;
        $cached_data = wp_cache_get($object_cache_key, 'gf_iplocation');
        
        if (false !== $cached_data) {
            $this->log_debug(__METHOD__ . "(): Using object cached IP data for $ip");
            return $this->cache_operation($ip, $cached_data);
        }
        
        // Check transient cache next
        $transient_key = 'ipstack_' . str_replace('.', '_', $ip);
        $cached_data = get_transient($transient_key);
        
        if ($cached_data !== false) {
            $this->log_debug(__METHOD__ . "(): Using transient cached IP data for $ip");
            // Also cache in object cache for faster subsequent access
            wp_cache_set($object_cache_key, $cached_data, 'gf_iplocation', 3600);
            return $this->cache_operation($ip, $cached_data);
        }
        
        $this->log_debug(__METHOD__ . "(): Fetching IP data for $ip");
        
        // No cached data, fetch from API
        if(filter_var($ip, FILTER_VALIDATE_IP)) {
            $settings = $this->get_plugin_settings();
            $ipstack_access_key = rgar($settings, 'ipstack_access_key', '');
            
            if (empty($ipstack_access_key)) {
                $this->log_error(__METHOD__ . "(): Missing IPStack API key");
                $error_data = array(
                    'country_name' => 'API Key Missing', 
                    'is_error' => true,
                    'error_message' => 'IPStack API key is missing'
                );
                $cache_duration = GF_IPLOCATION_ERROR_CACHE_DURATION;
                return $this->store_ip_data($ip, $error_data, $cache_duration);
            }
            
            // Use HTTPS for secure API calls (requires paid plan on IPStack)
            $api_endpoint = is_ssl() ? "https://api.ipstack.com/" : "http://api.ipstack.com/";
            $url = "{$api_endpoint}{$ip}?access_key={$ipstack_access_key}";
            
            // Log URL without exposing the full API key in logs
            $masked_key = substr($ipstack_access_key, 0, 4) . '...' . substr($ipstack_access_key, -4);
            $masked_url = str_replace($ipstack_access_key, $masked_key, $url);
            $this->log_debug(__METHOD__ . "(): API Request URL: $masked_url");
            
            $response = wp_remote_get($url);
            
            if(is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->log_error(__METHOD__ . "(): API Error: " . $error_message);
                $error_data = array(
                    'country_name' => 'API Error', 
                    'is_error' => true,
                    'error_message' => $error_message
                );
                $cache_duration = GF_IPLOCATION_ERROR_CACHE_DURATION;
                return $this->store_ip_data($ip, $error_data, $cache_duration);
            }
    
            $ipdata = json_decode(wp_remote_retrieve_body($response), true);
            
            // Check for API error responses
            if (isset($ipdata['error']) && !empty($ipdata['error'])) {
                $error_message = isset($ipdata['error']['info']) ? $ipdata['error']['info'] : 'Unknown API Error';
                $this->log_error(__METHOD__ . "(): IPStack API Error: " . $error_message);
                $error_data = array(
                    'country_name' => "API Error", 
                    'is_error' => true,
                    'error_message' => $error_message
                );
                $cache_duration = GF_IPLOCATION_ERROR_CACHE_DURATION;
                return $this->store_ip_data($ip, $error_data, $cache_duration);
            }
    
            if($ipdata && isset($ipdata['country_name'])) {
                $ipdata['is_error'] = false;
                $cache_duration = GF_IPLOCATION_SUCCESS_CACHE_DURATION;
                $this->log_debug(__METHOD__ . "(): IP data fetched successfully");
                return $this->store_ip_data($ip, $ipdata, $cache_duration);
            } else {
                $error_message = 'Invalid or empty API response';
                $this->log_error(__METHOD__ . "(): " . $error_message);
                $error_data = array(
                    'country_name' => 'Data Error', 
                    'is_error' => true,
                    'error_message' => $error_message
                );
                $cache_duration = GF_IPLOCATION_ERROR_CACHE_DURATION;
                return $this->store_ip_data($ip, $error_data, $cache_duration);
            }
        } else {
            $error_message = 'Invalid IP format: ' . $ip;
            $this->log_error(__METHOD__ . "(): " . $error_message);
            $error_data = array(
                'country_name' => 'Invalid IP', 
                'is_error' => true,
                'error_message' => $error_message
            );
            $cache_duration = GF_IPLOCATION_ERROR_CACHE_DURATION;
            return $this->store_ip_data($ip, $error_data, $cache_duration);
        }
    }
    
    /**
     * Helper method to store IP data in all cache layers
     *
     * @param string $ip The IP address
     * @param array $data The IP location data
     * @param int $cache_duration How long to cache the data
     * @return array The IP data
     */
    private function store_ip_data($ip, $data, $cache_duration) {
        // Sanitize data before storing for security
        $sanitized_data = $this->sanitize_ip_data($data);
        
        // Store in memory cache
        $cached_data = $this->cache_operation($ip, $sanitized_data);
        
        // Store in WordPress object cache
        $object_cache_key = 'ipstack_' . $ip;
        wp_cache_set($object_cache_key, $sanitized_data, 'gf_iplocation', $cache_duration);
        
        // Store in transients for persistence
        $transient_key = 'ipstack_' . str_replace('.', '_', $ip);
        set_transient($transient_key, $sanitized_data, $cache_duration);
        
        return $cached_data;
    }
    
    /**
     * Process form settings before saving.
     * This specifically handles multi-select fields which need special processing.
     */
    public function process_form_settings_submission($form_id) {
        // Debug settings submission start
        $this->log_debug(__METHOD__ . "(): Processing form settings for form #$form_id");
        
        if (!$this->is_save_postback()) {
            return false;
        }
        
        // Get all settings through the parent method
        $settings = $this->get_posted_settings();
        
        // Let our external function handle the multiselect processing
        $settings = gf_iplocation_process_multiselect_values($settings);
        
        // Log the processed settings for debugging
        $this->log_debug(__METHOD__ . "(): Processed settings: " . print_r($settings, true));
        
        // Save all settings
        $result = $this->update_form_settings($form_id, $settings);
        
        return true;
    }

    /**
     * Extract multi-select values from POST data
     * This is no longer needed as we handle this directly in process_form_settings_submission
     * but keeping for backwards compatibility
     */
    public function extract_multiselect_values($field_name) {
        $values = array();
        $field_key = '_gaddon_setting_' . $field_name;
        
        if (isset($_POST[$field_key]) && is_array($_POST[$field_key])) {
            return $_POST[$field_key];
        }
        
        return array();
    }
    
    /**
     * Override the form settings initialization to make sure multi-select values are properly loaded
     */
    public function get_form_settings($form) {
        $settings = parent::get_form_settings($form);

        if (!is_array($settings)) {
            $this->log_debug(__METHOD__ . "(): parent::get_form_settings returned non-array, normalizing to empty array");
            $settings = array();
        }

        if (!empty($form['id'])) {
            $this->log_debug(__METHOD__ . "(): Getting form settings for form #{$form['id']}");
        }

        // Ensure allowed_countries is always an array
        if (isset($settings['allowed_countries'])) {
            if (is_string($settings['allowed_countries'])) {
                // If it's a comma-separated string, split it
                if (strpos($settings['allowed_countries'], ',') !== false) {
                    $settings['allowed_countries'] = array_map('trim', explode(',', $settings['allowed_countries']));
                } elseif (!empty($settings['allowed_countries'])) {
                    $settings['allowed_countries'] = array($settings['allowed_countries']);
                } else {
                    $settings['allowed_countries'] = array();
                }
            } elseif (!is_array($settings['allowed_countries'])) {
                $settings['allowed_countries'] = array();
            }
        } else {
            $settings['allowed_countries'] = array();
        }

        return $settings;
    }
    
    /**
     * Add an admin notice to inform users to set up logging for troubleshooting
     */
    public function maybe_show_debug_notice() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'gf_edit_forms') === false) {
            return;
        }
        
        // Only show on the IP Location settings page
        if (rgget('subview') !== 'gravityformsiplocation') {
            return;
        }
        
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>IP Location Add-On:</strong> For troubleshooting, enable Gravity Forms logging in Forms → Settings → Logging. Set logging to "Debug", save, then check the logs for "gravityformsiplocation".</p>';
        echo '</div>';
    }
    
    /**
     * Filter the settings before they are saved
     */
    public function filter_settings( $settings, $section ) {
        // Handle the allowed_countries field specifically to ensure it's always an array
        if ( isset( $settings['allowed_countries'] ) ) {
            if ( ! is_array( $settings['allowed_countries'] ) ) {
                if ( empty( $settings['allowed_countries'] ) ) {
                    $settings['allowed_countries'] = array(); // Changed: Empty array instead of Australia default
                } else {
                    $settings['allowed_countries'] = array( $settings['allowed_countries'] );
                }
            }
        }
        
        return $settings;
    }

    /**
     * Add nonce to settings forms for security
     */
    public function add_nonce_to_settings_form() {
        if (!is_admin()) return;
        
        $current_screen = get_current_screen();
        if (!$current_screen) return;
        
        // Add nonce only on our settings pages
        if (strpos($current_screen->id, 'gf_edit_forms') !== false) {
            add_filter('gform_addon_settings_fields', array($this, 'add_nonce_field'), 10, 2);
        }
    }

    /**
     * Add a nonce field to the settings form
     */
    public function add_nonce_field($fields, $addon) {
        if ($addon->get_slug() === $this->_slug) {
            // Verify this is our settings page
            // Add hidden field for nonce
            wp_nonce_field($this->_slug);
        }
        return $fields;
    }

    /**
     * Enable Gravity Forms logging system
     */
    public function is_logging_supported() {
        return true;
    }

    /**
     * Get the required capability for Gravity Forms settings
     */
    public function _get_plugin_settings_title() {
        return esc_html__('IP Location Add-On Settings', 'gravityformsiplocation');
    }

    /**
     * Helper method to add form validation errors in a standard way
     * Modified to align with Gravity Forms standard error display
     */
    private function add_validation_error($validation_result, $message) {
        $validation_result['is_valid'] = false;
        
        // Store the message for use in the filter
        $this->validation_message = $message;
        
        // Add filter only once
        if (!isset($this->validation_hook_added)) {
            add_filter('gform_validation_message', function($default_message, $form) {
                // Preserve the default message structure but modify the content
                return str_replace(
                    'There was a problem with your submission. Please review the fields below.',
                    esc_html($this->validation_message),
                    $default_message
                );
            }, 10, 2);
            $this->validation_hook_added = true;
        }
        
        $this->log_debug(__METHOD__ . "(): Validation failed: " . $message);
        
        return $validation_result;
    }

    /**
     * Helper method to format location data for display
     * 
     * @param array $ip_data The IP location data array
     * @return string Formatted location string
     */
    private function format_location_text($ip_data) {
        if (empty($ip_data['country_name'])) {
            return '';
        }
        
        $location_text = $ip_data['country_name'];
        
        if (!empty($ip_data['city'])) {
            $location_text = $ip_data['city'] . ', ' . $location_text;
        }
        
        if (!empty($ip_data['region_name']) && $ip_data['region_name'] != $ip_data['city']) {
            $location_text = $location_text . ' (' . $ip_data['region_name'] . ')';
        }
        
        return $location_text;
    }

    /**
     * Add IP location note to entry
     * 
     * @param int $entry_id The entry ID
     * @param array $ip_data The IP location data
     * @param string $note_type The note type (error, success, merge_tags, or merge_error)
     * @return void
     */
    private function add_location_note($entry_id, $ip_data, $note_type = 'success') {
        if ($note_type === 'error') {
            $error_message = isset($ip_data['error_message']) ? $ip_data['error_message'] : 'Unknown API error';
            
            $note = sprintf(
                __('IP Location service unavailable or error occurred: %s - Form submission was allowed to continue.', 'gravityformsiplocation'),
                $error_message
            );
            
            GFFormsModel::add_note(
                $entry_id,
                0,
                __('IP Location Add-on', 'gravityformsiplocation'),
                $note,
                'notification',
                'error'
            );
        } else if ($note_type === 'merge_error') {
            // Special handling for merge tag errors
            $error_message = isset($ip_data['error_message']) ? $ip_data['error_message'] : 'Unknown API error';
            $fields_text = isset($ip_data['fields_used']) ? $ip_data['fields_used'] : '';
            
            if (empty($fields_text)) {
                return;
            }
            
            $note = sprintf(
                __('IP Location service error when populating fields: %s. Error: %s - Default or empty values were used.', 'gravityformsiplocation'),
                $fields_text,
                $error_message
            );
            
            GFFormsModel::add_note(
                $entry_id,
                0,
                __('IP Location Add-on', 'gravityformsiplocation'),
                $note,
                'notification',
                'error'
            );
        } else if ($note_type === 'merge_tags') {
            // Handle the merge tags case specially
            $location_text = $this->format_location_text($ip_data);
            $fields_text = isset($ip_data['fields_used']) ? $ip_data['fields_used'] : '';
            
            if (empty($location_text) || empty($fields_text)) {
                return;
            }
            
            $note = sprintf(
                __('IP Location data auto-populated in fields: %s. Location detected: %s', 'gravityformsiplocation'),
                $fields_text,
                $location_text
            );
            
            GFFormsModel::add_note(
                $entry_id,
                0,
                __('IP Location Add-on', 'gravityformsiplocation'),
                $note,
                'notification',
                'success'
            );
        } else {
            $location_text = $this->format_location_text($ip_data);
            
            if (empty($location_text)) {
                return;
            }
            
            $note = sprintf(__('IP Location detected: %s', 'gravityformsiplocation'), $location_text);
            
            GFFormsModel::add_note(
                $entry_id,
                0,
                __('IP Location Add-on', 'gravityformsiplocation'),
                $note,
                'notification',
                'success'
            );
        }
    }

    // Add a method to get a better view of cached data for admins
    public function get_cache_stats() {
        global $wpdb;
        
        $stats = array(
            'memory_cache_size' => count($this->request_ip_cache),
            'memory_cache_max' => $this->cache_max_size,
            'transient_cache_count' => 0,
            'success_cache_duration' => GF_IPLOCATION_SUCCESS_CACHE_DURATION,
            'error_cache_duration' => GF_IPLOCATION_ERROR_CACHE_DURATION
        );
        
        // Get database transient count
        $stats['transient_cache_count'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%_transient_ipstack_%'"
        );
        
        return $stats;
    }

    /**
     * Clean up data for better security
     */
    private function sanitize_ip_data($ip_data) {
        if (!is_array($ip_data)) {
            return array();
        }
        
        $cleaned = array();
        
        // Whitelist specific fields
        $allowed_fields = array(
            'country_name', 'city', 'region_name', 'continent_name', 
            'latitude', 'longitude', 'country_code', 'zip', 'is_error',
            'error_message', 'test_mode'
        );
        
        foreach ($allowed_fields as $field) {
            if (isset($ip_data[$field])) {
                if (is_numeric($ip_data[$field])) {
                    $cleaned[$field] = $ip_data[$field];
                } else {
                    $cleaned[$field] = sanitize_text_field($ip_data[$field]);
                }
            }
        }
        
        return $cleaned;
    }

    /**
     * Add location notes to entry, combining if needed
     */
    public function maybe_add_location_notes($entry, $form) {
        $form_id = absint($form['id']);
        
        if (!property_exists($this, 'pending_location_notes') || empty($this->pending_location_notes[$form_id])) {
            return;
        }
        
        $note_info = $this->pending_location_notes[$form_id];
        $ip_data = $note_info['ip_data'];
        
        // Handle API errors - consolidated to avoid duplication
        if (!empty($ip_data['is_error'])) {
            $error_message = isset($ip_data['error_message']) ? $ip_data['error_message'] : 'Unknown API error';
            
            // Check if we have merge tags to create a combined error message
            if (!empty($note_info['fields_with_tags']) && isset($note_info['has_merge_tags'])) {
                $fields_text = $this->format_fields_text($note_info['fields_with_tags']);
                
                $note = sprintf(
                    __('IP Location service error: %s - Could not populate location data for fields: %s. Default or empty values were used.', 'gravityformsiplocation'),
                    $error_message,
                    $fields_text
                );
            } else {
                // Generic error without field details
                $note = sprintf(
                    __('IP Location service unavailable or error occurred: %s - Form submission was allowed to continue.', 'gravityformsiplocation'),
                    $error_message
                );
            }
            
            // Add a single error note with complete information
            $this->add_entry_note($entry['id'], $note, 'error');
            
            $this->log_error("Form #{$form_id} submitted with IP location error: " . $error_message);
            
            // Clean up
            unset($this->pending_location_notes[$form_id]);
            return;
        }
        
        // For success cases, determine what type of note to add
        $has_merge_tags = isset($note_info['has_merge_tags']) && $note_info['has_merge_tags'];
        $has_validation = isset($note_info['has_validation']) && $note_info['has_validation'];
        
        $location_text = $this->format_location_text($ip_data);
        
        // Build note based on what features were used
        if ($has_merge_tags && $has_validation) {
            // Combined note for both features
            $fields_text = $this->format_fields_text($note_info['fields_with_tags']);
            $user_country = isset($ip_data['country_name']) ? $ip_data['country_name'] : '';
            $allowed_countries = $note_info['allowed_countries'];
            $is_allowed = in_array($user_country, $allowed_countries, true);
            
            $note = sprintf(
                __('IP Location detected: %s. Data auto-populated in fields: %s. Country validation %s.', 'gravityformsiplocation'),
                $location_text,
                $fields_text,
                $is_allowed ? 'passed' : 'failed but submission was allowed'
            );
            
            $this->add_entry_note($entry['id'], $note, 'success');
        } else if ($has_merge_tags) {
            // Only merge tag note - simplified
            $fields_text = $this->format_fields_text($note_info['fields_with_tags']);
            
            $note = sprintf(
                __('IP Location detected: %s. Data auto-populated in fields: %s.', 'gravityformsiplocation'),
                $location_text,
                $fields_text
            );
            
            $this->add_entry_note($entry['id'], $note, 'success');
        } else if ($has_validation) {
            // Only validation note
            $user_country = isset($ip_data['country_name']) ? $ip_data['country_name'] : '';
            $allowed_countries = $note_info['allowed_countries'];
            $is_allowed = in_array($user_country, $allowed_countries, true);
            
            $note = sprintf(
                __('IP Location detected: %s. Country validation %s.', 'gravityformsiplocation'),
                $location_text,
                $is_allowed ? 'passed' : 'failed but submission was allowed'
            );
            
            $this->add_entry_note($entry['id'], $note, $is_allowed ? 'success' : 'warning');
        }
        
        // Clean up
        unset($this->pending_location_notes[$form_id]);
    }

    /**
     * Helper method to create merge tag error notes
     */
    private function create_merge_tag_error_note($entry_id, $ip_data, $note_info) {
        $fields_text = $this->format_fields_text($note_info['fields_with_tags']);
        $error_message = isset($ip_data['error_message']) ? $ip_data['error_message'] : 'Unknown API error';
        
        $note = sprintf(
            __('IP Location service error when populating fields: %s. Error: %s - Default or empty values were used.', 'gravityformsiplocation'),
            $fields_text,
            $error_message
        );
        
        $this->add_entry_note($entry_id, $note, 'error');
    }

    /**
     * Helper method to format fields text for notes - simplified to remove redundant data type info
     */
    private function format_fields_text($fields_with_tags) {
        // Simply return field labels without the data type information
        $field_labels = array_column($fields_with_tags, 'field_label');
        return implode(', ', $field_labels);
    }

    /**
     * Simplified method to add entry notes
     */
    private function add_entry_note($entry_id, $note, $type) {
        GFFormsModel::add_note(
            $entry_id,
            0, // System generated (not user)
            __('IP Location Add-on', 'gravityformsiplocation'),
            $note,
            'notification',
            $type
        );
    }
}

// Initialize the addon
function gf_iplocation_addon() {
    return GFIPLocationAddOn::get_instance();
}
gf_iplocation_addon();

// Backward compatibility function
function getCountryFromIP($ip) {
    $addon = gf_iplocation_addon();
    $ip_data = $addon->get_location_data_from_ip($ip);
    return isset($ip_data['country_name']) ? $ip_data['country_name'] : 'Not Found';
}

// Backwards compatibility function
function getLocationDataFromIP($ip) {
    $addon = gf_iplocation_addon();
    return $addon->get_location_data_from_ip($ip);
}
