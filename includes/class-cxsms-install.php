<?php
/**
 * Database tables.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 */
class CXSMS_Install {

	const DB_VERSION = '1.0.0';

	/** On activation. */
	public static function activate() {
		self::create_tables();
	}

	/** Create or upgrade the tables after a plugin update. */
	public static function maybe_upgrade() {
		if ( get_option( 'cxsms_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	/** Tables: every SMS sent, campaigns and their recipients. */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// Every SMS the store sends a customer, with the gateway's answer.
		// event: the order status it announces ("placed" = order placed), or "manual" / "test".
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}cxsms_messages (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NULL,
				event varchar(40) NOT NULL,
				recipient varchar(20) NOT NULL,
				message text NOT NULL,
				status varchar(20) NOT NULL,
				provider_message_id varchar(191) NULL,
				segments smallint(5) unsigned NULL,
				cost decimal(8,4) NULL,
				error varchar(255) NULL,
				sent_by bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_event (order_id,event),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}cxsms_campaigns (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				message text NULL,
				filters longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				recipients_count int(10) unsigned NOT NULL DEFAULT 0,
				sent_count int(10) unsigned NOT NULL DEFAULT 0,
				failed_count int(10) unsigned NOT NULL DEFAULT 0,
				error varchar(255) NULL,
				created_by bigint(20) unsigned NULL,
				started_at datetime NULL,
				finished_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}cxsms_campaign_recipients (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				phone varchar(20) NOT NULL,
				name varchar(191) NULL,
				orders_count int(10) unsigned NOT NULL DEFAULT 0,
				selected tinyint(1) NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'pending',
				provider_message_id varchar(191) NULL,
				error varchar(255) NULL,
				sent_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_phone (campaign_id,phone),
				KEY campaign_status (campaign_id,status)
			) {$charset};"
		);

		update_option( 'cxsms_db_version', self::DB_VERSION, false );
	}
}
