<?php
/**
 * Bootstrap.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts the plugin once WooCommerce is loaded.
 */
class CXSMS_Plugin {

	/** On plugins_loaded. */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		CXSMS_Install::maybe_upgrade();
		CXSMS_Order_SMS::register();
		CXSMS_Queue::register();

		if ( is_admin() ) {
			require_once CXSMS_DIR . 'includes/admin/class-cxsms-admin.php';
			require_once CXSMS_DIR . 'includes/admin/class-cxsms-settings-page.php';
			require_once CXSMS_DIR . 'includes/admin/class-cxsms-log-page.php';
			require_once CXSMS_DIR . 'includes/admin/class-cxsms-campaigns-page.php';
			require_once CXSMS_DIR . 'includes/admin/class-cxsms-order-box.php';

			CXSMS_Admin::register();
			CXSMS_Order_Box::register();
		}

		add_filter( 'plugin_action_links_' . plugin_basename( CXSMS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Shown while WooCommerce isn't active. */
	public static function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p><strong>CX SMS for WooCommerce</strong> needs WooCommerce. Install and activate WooCommerce to send SMS.</p></div>';
	}

	/**
	 * "Settings" next to the plugin on the Plugins page.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=cxsms' ) ) . '">Settings</a>' );

		return $links;
	}
}
