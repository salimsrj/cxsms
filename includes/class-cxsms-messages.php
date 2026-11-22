<?php
/**
 * The SMS log.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every SMS sent (or tried) to a customer.
 */
class CXSMS_Messages {

	const SENT   = 'sent';
	const FAILED = 'failed';

	/** Table name. */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'cxsms_messages';
	}

	/**
	 * Keep a record.
	 *
	 * @param array $data Columns.
	 * @return int The new ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data = array_merge(
			array(
				'order_id'            => null,
				'event'               => 'manual',
				'recipient'           => '',
				'message'             => '',
				'status'              => self::SENT,
				'provider_message_id' => null,
				'segments'            => null,
				'cost'                => null,
				'error'               => null,
				'sent_by'             => null,
				'created_at'          => current_time( 'mysql', true ),
			),
			$data
		);

		$wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * One record.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * An order's SMS, newest first.
	 *
	 * @param int $order_id Order ID.
	 * @return object[]
	 */
	public static function for_order( $order_id ) {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC', self::table(), $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether this order was already texted about this event.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $event    Event.
	 * @return bool
	 */
	public static function was_sent( $order_id, $event ) {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE order_id = %d AND event = %s AND status = %s LIMIT 1', self::table(), $order_id, $event, self::SENT )
		);
	}

	/**
	 * A page of the log, newest first.
	 *
	 * @param array $args status, event, search, per_page, page.
	 * @return array{items: object[], total: int}
	 */
	public static function search( array $args ) {
		global $wpdb;

		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$event    = isset( $args['event'] ) ? (string) $args['event'] : '';
		$search   = isset( $args['search'] ) ? (string) $args['search'] : '';
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$per_page = max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		// An empty filter matches every row.
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR event = %s) AND (%s = '' OR recipient LIKE %s OR message LIKE %s OR order_id = %d)",
				self::table(),
				$status,
				$status,
				$event,
				$event,
				$search,
				$like,
				$like,
				(int) $search
			)
		);

		$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR event = %s) AND (%s = '' OR recipient LIKE %s OR message LIKE %s OR order_id = %d) ORDER BY id DESC LIMIT %d OFFSET %d",
				self::table(),
				$status,
				$status,
				$event,
				$event,
				$search,
				$like,
				$like,
				(int) $search,
				$per_page,
				( $page - 1 ) * $per_page
			)
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
