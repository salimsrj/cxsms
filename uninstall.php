<?php
/**
 * Deleting the plugin removes its tables, settings and queued SMS.
 *
 * @package CXSMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cxsms_campaign_recipients" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cxsms_campaigns" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cxsms_messages" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'cxsms_' ) . '%' ) );
// phpcs:enable

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'cxsms' );
}
