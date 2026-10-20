<?php
/**
 * Plugin Name: Holosun Phoenix Catalog
 * Description: Renders current Holosun products and pricing from Phoenix as a responsive dealer-routing catalog.
 * Version: 2.1.2
 * Author: HolosunDeals
 * License: GPL-2.0-or-later
 * Text Domain: holosun-rsr-catalog
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HRC_PLUGIN_FILE', __FILE__);
define('HRC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HRC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HRC_PLUGIN_DIR . 'includes/class-holosun-rsr-catalog-plugin.php';

Holosun_RSR_Catalog_Plugin::boot();
