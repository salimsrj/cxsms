<?php
/**
 * Admin menu, notices and assets.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * The "SMS" admin menu, flash notices and the queue warning.
 */
class CXSMS_Admin {

	/** Who manages SMS settings and campaigns. */
	const CAPABILITY = 'manage_woocommerce';

	/** Hook up. */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_post_cxsms_settings', array( 'CXSMS_Settings_Page', 'handle' ) );
		add_action( 'admin_post_cxsms_campaign', array( 'CXSMS_Campaigns_Page', 'handle' ) );
		add_action( 'admin_post_cxsms_order_sms', array( 'CXSMS_Order_Box', 'handle_send' ) );
		add_action( 'wp_ajax_cxsms_balance', array( 'CXSMS_Settings_Page', 'ajax_balance' ) );
	}

	/** SMS › Settings, SMS log, Campaigns. */
	public static function menu() {
		add_menu_page( 'SMS', 'SMS', self::CAPABILITY, 'cxsms', array( 'CXSMS_Settings_Page', 'render' ), 'dashicons-format-chat', 56 );
		add_submenu_page( 'cxsms', 'SMS settings', 'Settings', self::CAPABILITY, 'cxsms', array( 'CXSMS_Settings_Page', 'render' ) );
		add_submenu_page( 'cxsms', 'SMS log', 'SMS log', self::CAPABILITY, 'cxsms-log', array( 'CXSMS_Log_Page', 'render' ) );
		add_submenu_page( 'cxsms', 'SMS campaigns', 'Campaigns', self::CAPABILITY, 'cxsms-campaigns', array( 'CXSMS_Campaigns_Page', 'render' ) );
	}

	/**
	 * Character counter and product search on our pages and the order page.
	 *
	 * @param string $hook Page hook.
	 */
	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = false !== strpos( (string) $hook, 'cxsms' );
		$order  = $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );

		if ( ! $ours && ! $order ) {
			return;
		}

		wp_enqueue_style( 'cxsms-admin', CXSMS_URL . 'assets/admin.css', array(), CXSMS_VERSION );
		wp_enqueue_script( 'cxsms-admin', CXSMS_URL . 'assets/admin.js', array( 'jquery' ), CXSMS_VERSION, true );

		if ( false !== strpos( (string) $hook, 'cxsms-campaigns' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	/**
	 * Show a message on the next page load, for this user.
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Text.
	 */
	public static function flash( $type, $message ) {
		$key      = 'cxsms_flash_' . get_current_user_id();
		$messages = get_transient( $key );
		$messages = is_array( $messages ) ? $messages : array();

		$messages[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $messages, 120 );
	}

	/** Flash messages, and a warning while SMS are stuck in the queue. */
	public static function notices() {
		$key      = 'cxsms_flash_' . get_current_user_id();
		$messages = get_transient( $key );

		if ( is_array( $messages ) ) {
			delete_transient( $key );
			foreach ( $messages as $flash ) {
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $flash['type'] ), esc_html( $flash['message'] ) );
			}
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = $screen ? (string) $screen->id : '';
		if ( ! current_user_can( self::CAPABILITY ) || ( false === strpos( $page, 'cxsms' ) && ! in_array( $page, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) ) {
			return;
		}

		$waiting = CXSMS_Queue::waiting_count();
		if ( $waiting > 0 ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html( sprintf( '%d %s waiting to be sent.', $waiting, 1 === $waiting ? 'message' : 'messages' ) ),
				esc_html( 'WP-Cron isn\'t running, so automatic SMS and campaigns aren\'t going out. Make sure WP-Cron runs (or a real cron job calls wp-cron.php).' ),
				esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=cxsms&status=pending' ) ),
				esc_html( 'See the queue' )
			);
		}
	}

	/**
	 * A <textarea> with the character / SMS counter.
	 *
	 * @param string $name  Field name.
	 * @param string $value Value.
	 * @param array  $attrs Extra attributes.
	 */
	public static function counted_textarea( $name, $value, array $attrs = array() ) {
		$attrs = array_merge(
			array(
				'rows'      => 2,
				'maxlength' => 1000,
				'class'     => 'large-text cxsms-count',
			),
			$attrs
		);

		$html = '';
		foreach ( $attrs as $attr => $attr_value ) {
			if ( true === $attr_value ) {
				$html .= ' ' . esc_attr( $attr );
			} elseif ( false !== $attr_value && null !== $attr_value ) {
				$html .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $attr_value ) );
			}
		}

		printf( '<textarea name="%s"%s>%s</textarea>', esc_attr( $name ), $html, esc_textarea( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p class="description cxsms-counter">%s</p>', esc_html( CXSMS_Order_SMS::length( $value ) ) );
	}

	/**
	 * A UTC database time in the site's time zone.
	 *
	 * @param string|null $utc UTC datetime.
	 * @return string
	 */
	public static function time( $utc ) {
		if ( ! $utc ) {
			return '';
		}

		return get_date_from_gmt( $utc, get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
	}

	/**
	 * Where to go after a form, with no output yet.
	 *
	 * @param string $url URL.
	 */
	public static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
