<?php
/**
 * Background sending through Action Scheduler (part of WooCommerce).
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order SMS and campaigns are sent in the background, so changing a status never waits on the
 * SMS gateway. An order SMS is tried three times (again after 1 and 5 minutes) and never sent
 * twice for the same order and event.
 */
class CXSMS_Queue {

	const GROUP = 'cxsms';

	const ORDER_HOOK    = 'cxsms_send_order_sms';
	const RETRY_HOOK    = 'cxsms_retry_order_sms';
	const CAMPAIGN_HOOK = 'cxsms_send_campaign';

	const TRIES = 3;

	/** Seconds before the 2nd and 3rd tries. */
	const BACKOFF = array( 60, 300 );

	/** Hook up the handlers. */
	public static function register() {
		add_action( self::ORDER_HOOK, array( __CLASS__, 'handle_order_sms' ), 10, 2 );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'handle_order_sms' ), 10, 3 );
		add_action( self::CAMPAIGN_HOOK, array( 'CXSMS_Campaigns', 'handle_batch' ), 10, 1 );
	}

	/**
	 * Queue an order SMS, once per order and event.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $event    Event.
	 */
	public static function queue_order_sms( $order_id, $event ) {
		$args = array( (int) $order_id, (string) $event );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::ORDER_HOOK, $args, self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::ORDER_HOOK, $args, self::GROUP );
	}

	/**
	 * Queue the next batch of a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $delay       Seconds from now.
	 */
	public static function queue_campaign( $campaign_id, $delay = 0 ) {
		$args = array( (int) $campaign_id );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::CAMPAIGN_HOOK, $args, self::GROUP ) ) {
			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::CAMPAIGN_HOOK, $args, self::GROUP );
		} else {
			as_enqueue_async_action( self::CAMPAIGN_HOOK, $args, self::GROUP );
		}
	}

	/**
	 * Text the customer that their order reached an event.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $event    Event.
	 * @param int    $attempt  Which try this is.
	 */
	public static function handle_order_sms( $order_id, $event, $attempt = 1 ) {
		$order   = wc_get_order( $order_id );
		$sms     = new CXSMS_Order_SMS();
		$gateway = new CXSMS_Gateway();

		// Switched off since it was queued, or already sent (e.g. processing, then processing again after a mix-up).
		if ( ! $order || ! $gateway->is_enabled() || ! $sms->is_on( $event ) || CXSMS_Messages::was_sent( $order->get_id(), $event ) ) {
			return;
		}

		$attempt = max( 1, (int) $attempt );

		try {
			$sms->send(
				$order,
				CXSMS_Order_SMS::customer_phone( $order ),
				$sms->render( $sms->template( $event ), $order ),
				$event,
				null,
				$attempt >= self::TRIES
			);
		} catch ( RuntimeException $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					sprintf( 'SMS for order %s (%s) failed, try %d of %d: %s', $order->get_order_number(), $event, $attempt, self::TRIES, $e->getMessage() ),
					array( 'source' => 'cxsms' )
				);
			}

			if ( $attempt < self::TRIES ) {
				as_schedule_single_action( time() + self::BACKOFF[ $attempt - 1 ], self::RETRY_HOOK, array( (int) $order_id, (string) $event, $attempt + 1 ), self::GROUP );
			}
		}
	}

	/**
	 * SMS and campaign batches due more than two minutes ago and still waiting: WP-Cron or the
	 * Action Scheduler runner isn't running.
	 *
	 * @return int
	 */
	public static function waiting_count() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		$ids = as_get_scheduled_actions(
			array(
				'group'        => self::GROUP,
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => gmdate( 'Y-m-d H:i:s', time() - 120 ),
				'date_compare' => '<=',
				'per_page'     => 100,
			),
			'ids'
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
