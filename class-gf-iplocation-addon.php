<?php
/**
 * The GFIPLocationAddOn class.
 * 
 * Class moved to main file for simplicity.
 * This file is kept for backwards compatibility.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

if (!class_exists('GFIPLocationAddOn')) {
    require_once(plugin_dir_path(__FILE__) . 'gravityformsiplocation.php');
}
