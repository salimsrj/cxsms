<?php
/**
 * Plugin Name:       CloudXino SMS for WooCommerce
 * Description:       Texts customers about their WooCommerce orders through the CloudXino SMS gateway (sms.cloudxino.com): one message per order status, manual and test SMS, an SMS log and SMS campaigns to past customers.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.1
 * WC tested up to:   11.1
 * Author:            Mahmudul Hasan
 * Author URI:        https://cloudxino.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cloudxino-sms-for-woocommerce
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

define( 'CXSMS_VERSION', '1.0.0' );
define( 'CXSMS_FILE', __FILE__ );
define( 'CXSMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CXSMS_URL', plugin_dir_url( __FILE__ ) );

require_once CXSMS_DIR . 'includes/class-cxsms-settings.php';
require_once CXSMS_DIR . 'includes/class-cxsms-install.php';
require_once CXSMS_DIR . 'includes/class-cxsms-gateway.php';
require_once CXSMS_DIR . 'includes/class-cxsms-messages.php';
require_once CXSMS_DIR . 'includes/class-cxsms-order-sms.php';
require_once CXSMS_DIR . 'includes/class-cxsms-queue.php';
require_once CXSMS_DIR . 'includes/class-cxsms-audience.php';
require_once CXSMS_DIR . 'includes/class-cxsms-campaigns.php';
require_once CXSMS_DIR . 'includes/class-cxsms-plugin.php';

register_activation_hook( __FILE__, array( 'CXSMS_Install', 'activate' ) );

// Works with WooCommerce's High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'CXSMS_Plugin', 'init' ), 20 );
