<?php
/**
 * Order SMS: one message per order status.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Texts customers about their order: one message per event (the order being placed, then each
 * WooCommerce status), each switched on and worded on the SMS settings page, with {placeholders}
 * filled from the order.
 */
class CXSMS_Order_SMS {

	/** The order being placed, before any status change. */
	const PLACED = 'placed';

	/**
	 * Placeholders shown on the settings page.
	 *
	 * @return array<string, string>
	 */
	public static function placeholders() {
		return array(
			'{name}'     => 'Customer name',
			'{order}'    => 'Order number',
			'{total}'    => 'Order total, e.g. 1,250',
			'{status}'   => 'Order status, e.g. Processing',
			'{courier}'  => 'Courier, e.g. Steadfast',
			'{tracking}' => 'Courier tracking code',
			'{store}'    => 'Store name',
			'{phone}'    => 'Store phone',
		);
	}

	/**
	 * Plain English keeps each SMS to 160 characters; Bangla fits 70 per SMS and costs more.
	 *
	 * @return array<string, string>
	 */
	public static function default_templates() {
		return array(
			self::PLACED => 'Hi {name}, we got your order {order} (Tk {total}). We will call you to confirm it. - {store}',
			'pending'    => 'Hi {name}, your order {order} (Tk {total}) is waiting for payment. - {store}',
			'on-hold'    => 'Hi {name}, your order {order} is on hold. We will contact you soon. - {store}',
			'processing' => 'Hi {name}, your order {order} is confirmed. Total Tk {total}. We will let you know when it ships. - {store}',
			'shipped'    => 'Hi {name}, your order {order} is on the way with {courier}. Tracking: {tracking}. - {store}',
			'completed'  => 'Hi {name}, your order {order} was delivered. Thank you for shopping with {store}!',
			'cancelled'  => 'Hi {name}, your order {order} has been cancelled. Questions? Call {phone}. - {store}',
			'refunded'   => 'Hi {name}, your order {order} has been refunded. - {store}',
			'failed'     => 'Hi {name}, the payment for your order {order} failed. Please try again or call {phone}. - {store}',
		);
	}

	/** For a custom status without its own default. */
	const GENERIC_TEMPLATE = 'Hi {name}, your order {order} is now {status}. - {store}';

	/** Switched on until changed: the ones customers most want. */
	const ON_BY_DEFAULT = array( 'processing', 'shipped', 'completed' );

	/**
	 * Order IDs whose next status change shouldn't text the customer (staff unticked the box).
	 *
	 * @var array<int, bool>
	 */
	private static $skip = array();

	/**
	 * Gateway.
	 *
	 * @var CXSMS_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param CXSMS_Gateway|null $gateway Gateway.
	 */
	public function __construct( $gateway = null ) {
		$this->gateway = $gateway ? $gateway : new CXSMS_Gateway();
	}

	/** Hooks the automatic messages up to WooCommerce. */
	public static function register() {
		$self = new self();

		add_action( 'woocommerce_checkout_order_processed', array( $self, 'on_checkout' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $self, 'on_checkout' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $self, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * The events customers can be texted about: the order being placed, then every order status
	 * (custom ones too, e.g. "Shipped" from another plugin).
	 *
	 * @return array<string, string> event => label
	 */
	public static function events() {
		$events = array( self::PLACED => 'Order placed' );

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$slug = 0 === strpos( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;
			if ( 'checkout-draft' === $slug ) {
				continue;
			}
			$events[ $slug ] = $label;
		}

		return apply_filters( 'cxsms_events', $events );
	}

	/**
	 * What the SMS page calls an event.
	 *
	 * @param string $event Event.
	 * @return string
	 */
	public static function event_label( $event ) {
		$events = self::events();

		if ( isset( $events[ $event ] ) ) {
			return $events[ $event ];
		}

		return ucfirst( str_replace( '-', ' ', (string) $event ) );
	}

	/**
	 * When an event is sent, for the settings page.
	 *
	 * @param string $event Event.
	 * @return string
	 */
	public static function event_help( $event ) {
		return self::PLACED === $event
			? 'When the customer places the order.'
			: sprintf( 'When the order is marked %s.', self::event_label( $event ) );
	}

	/**
	 * Option name for the on/off switch.
	 *
	 * @param string $event Event.
	 */
	public static function enabled_key( $event ) {
		return 'on_' . $event;
	}

	/**
	 * Option name for the wording.
	 *
	 * @param string $event Event.
	 */
	public static function template_key( $event ) {
		return 'template_' . $event;
	}

	/**
	 * The default wording for an event.
	 *
	 * @param string $event Event.
	 * @return string
	 */
	public static function default_template( $event ) {
		$defaults = self::default_templates();

		return isset( $defaults[ $event ] ) ? $defaults[ $event ] : self::GENERIC_TEMPLATE;
	}

	/**
	 * Whether this message is switched on.
	 *
	 * @param string $event Event.
	 * @return bool
	 */
	public function is_on( $event ) {
		if ( ! array_key_exists( $event, self::events() ) ) {
			return false;
		}

		$default = in_array( $event, self::ON_BY_DEFAULT, true ) ? '1' : '0';

		return '1' === (string) CXSMS_Settings::get( self::enabled_key( $event ), $default );
	}

	/**
	 * The wording for an event.
	 *
	 * @param string $event Event.
	 * @return string
	 */
	public function template( $event ) {
		$saved = (string) CXSMS_Settings::get( self::template_key( $event ), '' );

		return '' !== $saved ? $saved : self::default_template( $event );
	}

	/**
	 * Whether an order reaching this event texts the customer: SMS and this message are switched
	 * on, and (given the order) it wasn't already sent, say before the order went back a step.
	 *
	 * @param string        $event Event.
	 * @param WC_Order|null $order Order.
	 * @return bool
	 */
	public function would_send( $event, $order = null ) {
		return $this->gateway->is_enabled() && $this->is_on( $event )
			&& ! ( $order && CXSMS_Messages::was_sent( $order->get_id(), $event ) );
	}

	/**
	 * Don't text about this order's next status change in this request.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function skip_once( $order_id ) {
		self::$skip[ (int) $order_id ] = true;
	}

	/**
	 * Checkout finished (classic or block checkout).
	 *
	 * @param int|WC_Order $order_or_id Order or its ID.
	 */
	public function on_checkout( $order_or_id ) {
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );

		if ( $order instanceof WC_Order ) {
			$this->notify( $order, self::PLACED );
		}
	}

	/**
	 * An order changed status.
	 *
	 * @param int           $order_id Order ID.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! empty( self::$skip[ (int) $order_id ] ) ) {
			unset( self::$skip[ (int) $order_id ] );
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$this->notify( $order, $to );
		}
	}

	/**
	 * Queue the SMS for an order reaching this event, if SMS and this message are switched on.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $event Event.
	 */
	public function notify( WC_Order $order, $event ) {
		if ( ! $this->would_send( $event ) || '' === self::customer_phone( $order ) ) {
			return;
		}

		if ( ! apply_filters( 'cxsms_should_send', true, $order, $event ) ) {
			return;
		}

		CXSMS_Queue::queue_order_sms( $order->get_id(), $event );
	}

	/**
	 * The customer's mobile: the billing phone, or the shipping phone.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function customer_phone( WC_Order $order ) {
		$phone = trim( (string) $order->get_billing_phone() );

		if ( '' === $phone && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = trim( (string) $order->get_shipping_phone() );
		}

		return $phone;
	}

	/**
	 * The customer's name.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function customer_name( WC_Order $order ) {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		if ( '' === $name ) {
			$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}

		return '' !== $name ? $name : 'Customer';
	}

	/**
	 * The message with the order's details filled in.
	 *
	 * @param string   $template Wording.
	 * @param WC_Order $order    Order.
	 * @return string
	 */
	public function render( $template, WC_Order $order ) {
		$courier  = self::meta_value( $order, (string) CXSMS_Settings::get( 'courier_meta', '' ), 'tracking_provider' );
		$tracking = self::meta_value( $order, (string) CXSMS_Settings::get( 'tracking_meta', '' ), 'tracking_number' );

		$values = array(
			'{name}'     => self::customer_name( $order ),
			'{order}'    => $order->get_order_number(),
			'{total}'    => number_format( (float) $order->get_total() ),
			'{status}'   => wc_get_order_status_name( $order->get_status() ),
			'{courier}'  => '' !== $courier ? ucfirst( $courier ) : 'our courier',
			'{tracking}' => '' !== $tracking ? $tracking : '-',
			'{store}'    => CXSMS_Settings::store_name(),
			'{phone}'    => '' !== CXSMS_Settings::store_phone() ? CXSMS_Settings::store_phone() : 'us',
		);

		$values = apply_filters( 'cxsms_order_placeholders', $values, $order );

		return trim( strtr( $template, $values ) );
	}

	/**
	 * An order meta value as text. Without a meta key set, read from WooCommerce Shipment
	 * Tracking when it's used.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key set on the settings page.
	 * @param string   $field Shipment Tracking field: tracking_number or tracking_provider.
	 * @return string
	 */
	private static function meta_value( WC_Order $order, $key, $field ) {
		$key   = trim( $key );
		$value = $order->get_meta( '' !== $key ? $key : '_wc_shipment_tracking_items' );

		if ( is_array( $value ) ) {
			$first = reset( $value );
			if ( is_array( $first ) ) {
				if ( 'tracking_provider' === $field && ! empty( $first['custom_tracking_provider'] ) ) {
					$first = $first['custom_tracking_provider'];
				} else {
					$first = isset( $first[ $field ] ) ? $first[ $field ] : '';
				}
			}
			$value = $first;
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Send now and keep a record of it (and an order note).
	 *
	 * @param WC_Order|null $order          Order, or null for a test SMS.
	 * @param string        $to             Mobile number.
	 * @param string        $message        Text.
	 * @param string        $event          Event, "manual" or "test".
	 * @param int|null      $sent_by        User who sent it by hand.
	 * @param bool          $record_failure False while a queued send will still be retried, so one SMS leaves one record.
	 * @return object The record.
	 * @throws RuntimeException When the gateway refuses it (recorded first, as failed).
	 */
	public function send( $order, $to, $message, $event, $sent_by = null, $record_failure = true ) {
		$record = array(
			'order_id'  => $order ? $order->get_id() : null,
			'event'     => $event,
			'recipient' => CXSMS_Gateway::normalize_number( $to ),
			'message'   => $message,
			'sent_by'   => $sent_by ? (int) $sent_by : null,
		);

		try {
			$result = $this->gateway->send( $to, $message, $order ? 'order-' . $order->get_order_number() . '-' . $event : null );
		} catch ( RuntimeException $e ) {
			if ( $record_failure ) {
				$record['status'] = CXSMS_Messages::FAILED;
				$record['error']  = mb_substr( $e->getMessage(), 0, 255 );
				CXSMS_Messages::insert( $record );

				if ( $order ) {
					$order->add_order_note( sprintf( 'SMS "%s" to %s failed: %s', self::event_label( $event ), $record['recipient'], $e->getMessage() ) );
				}
			}

			throw $e;
		}

		$record['status']              = CXSMS_Messages::SENT;
		$record['provider_message_id'] = $result['id'];
		$record['segments']            = $result['segments'];
		$record['cost']                = $result['cost'];

		$id = CXSMS_Messages::insert( $record );

		if ( $order ) {
			$order->add_order_note( sprintf( 'SMS "%s" sent to %s: %s', self::event_label( $event ), $record['recipient'], $message ) );
		}

		do_action( 'cxsms_sent', $id, $order, $event );

		return CXSMS_Messages::find( $id );
	}

	/**
	 * "92 characters · about 1 SMS", counted before the placeholders are filled in.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function length( $text ) {
		$text    = (string) $text;
		$length  = mb_strlen( $text );
		$unicode = 1 === preg_match( '/[^\x20-\x7E\n\r]/u', $text );
		$single  = $unicode ? 70 : 160;
		$multi   = $unicode ? 67 : 153;
		$parts   = $length <= $single ? 1 : (int) ceil( $length / $multi );

		return sprintf( '%d characters · about %d SMS%s, before the details are filled in.', $length, $parts, $unicode ? ' (Bangla/Unicode)' : '' );
	}
}
