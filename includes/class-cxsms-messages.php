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

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * An order's SMS, newest first.
	 *
	 * @param int $order_id Order ID.
	 * @return object[]
	 */
	public static function for_order( $order_id ) {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d ORDER BY id DESC', $order_id ) ); // phpcs:ignore WordPress.DB
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

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE order_id = %d AND event = %s AND status = %s LIMIT 1', $order_id, $event, self::SENT ) // phpcs:ignore WordPress.DB
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

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = $args['event'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(recipient LIKE %s OR message LIKE %s OR order_id = %d)';
			$params[] = $like;
			$params[] = $like;
			$params[] = (int) $args['search'];
		}

		$per_page = max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$sql      = ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $params ) : 'SELECT COUNT(*)' . $sql ); // phpcs:ignore WordPress.DB

		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;
		$items    = $wpdb->get_results( $wpdb->prepare( 'SELECT *' . $sql . ' ORDER BY id DESC LIMIT %d OFFSET %d', $params ) ); // phpcs:ignore WordPress.DB

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
