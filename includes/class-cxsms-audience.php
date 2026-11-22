<?php
/**
 * Who a campaign goes to.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Past customers a campaign goes to, picked from their orders: each mobile number once (however it
 * was typed), named as on their latest order. Blocked numbers and ones that aren't Bangladeshi
 * mobiles are left out.
 *
 * Filters, all optional:
 *  - statuses: orders that count, e.g. only completed ones (default)
 *  - product_ids / category_ids: bought one of these products, or something in these categories
 *  - min_orders: at least this many orders that count
 *  - ordered_within_days: ordered something (any order) in the last N days
 *  - not_ordered_for_days: no order at all in the last N days, to win back old customers
 */
class CXSMS_Audience {

	const DEFAULT_STATUSES = array( 'completed' );

	/** Orders read at a time. */
	const PAGE = 200;

	/**
	 * The customers who match.
	 *
	 * @param array|null $filters Filters.
	 * @return array<int, array{phone: string, name: string, orders: int}>
	 */
	public function customers( $filters ) {
		$filters      = is_array( $filters ) ? $filters : array();
		$statuses     = array_values( array_filter( (array) ( isset( $filters['statuses'] ) ? $filters['statuses'] : self::DEFAULT_STATUSES ) ) );
		$product_ids  = array_map( 'intval', array_filter( (array) ( isset( $filters['product_ids'] ) ? $filters['product_ids'] : array() ) ) );
		$category_ids = array_map( 'intval', array_filter( (array) ( isset( $filters['category_ids'] ) ? $filters['category_ids'] : array() ) ) );
		$min_orders   = max( 1, (int) ( isset( $filters['min_orders'] ) ? $filters['min_orders'] : 1 ) );
		$within       = (int) ( isset( $filters['ordered_within_days'] ) ? $filters['ordered_within_days'] : 0 );
		$not_for      = (int) ( isset( $filters['not_ordered_for_days'] ) ? $filters['not_ordered_for_days'] : 0 );

		$customers    = array();
		$last_ordered = array();
		$term_cache   = array();
		$page         = 1;

		do {
			$orders = wc_get_orders(
				array(
					'type'    => 'shop_order',
					'status'  => array_keys( wc_get_order_statuses() ),
					'limit'   => self::PAGE,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$phone = CXSMS_Gateway::normalize_number( CXSMS_Order_SMS::customer_phone( $order ) );
				if ( '' === $phone ) {
					continue;
				}

				$created = $order->get_date_created();
				$at      = $created ? $created->getTimestamp() : 0;

				// Any order counts for "ordered within" / "not ordered for".
				$last_ordered[ $phone ] = max( isset( $last_ordered[ $phone ] ) ? $last_ordered[ $phone ] : 0, $at );

				if ( array() !== $statuses && ! in_array( $order->get_status(), $statuses, true ) ) {
					continue;
				}

				if ( ( array() !== $product_ids || array() !== $category_ids ) && ! self::bought( $order, $product_ids, $category_ids, $term_cache ) ) {
					continue;
				}

				if ( ! isset( $customers[ $phone ] ) ) {
					$customers[ $phone ] = array(
						'orders'  => 0,
						'last_id' => 0,
						'name'    => '',
					);
				}

				++$customers[ $phone ]['orders'];
				if ( $order->get_id() > $customers[ $phone ]['last_id'] ) {
					$customers[ $phone ]['last_id'] = $order->get_id();
					$customers[ $phone ]['name']    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				}
			}

			++$page;
		} while ( count( $orders ) === self::PAGE );

		$blocked = array_flip( self::blocked_numbers() );
		$now     = time();
		$result  = array();

		foreach ( $customers as $phone => $customer ) {
			$phone = (string) $phone;

			if ( $customer['orders'] < $min_orders || ! CXSMS_Gateway::is_valid_number( $phone ) || isset( $blocked[ $phone ] ) ) {
				continue;
			}

			$last = isset( $last_ordered[ $phone ] ) ? $last_ordered[ $phone ] : 0;
			if ( $within > 0 && ! ( $last && $last >= $now - $within * DAY_IN_SECONDS ) ) {
				continue;
			}
			if ( $not_for > 0 && $last && $last >= $now - $not_for * DAY_IN_SECONDS ) {
				continue;
			}

			$result[] = array(
				'phone'  => $phone,
				'name'   => $customer['name'],
				'orders' => $customer['orders'],
			);
		}

		return $result;
	}

	/**
	 * How many customers match.
	 *
	 * @param array|null $filters Filters.
	 * @return int
	 */
	public function count( $filters ) {
		return count( $this->customers( $filters ) );
	}

	/**
	 * Numbers never to text, from the settings page.
	 *
	 * @return string[]
	 */
	public static function blocked_numbers() {
		$lines = preg_split( '/[\s,]+/', (string) CXSMS_Settings::get( 'blocked_numbers', '' ) );

		return array_values( array_filter( array_map( array( 'CXSMS_Gateway', 'normalize_number' ), (array) $lines ) ) );
	}

	/**
	 * Whether the order has one of these products, or a product in these categories.
	 *
	 * @param WC_Order $order        Order.
	 * @param int[]    $product_ids  Products.
	 * @param int[]    $category_ids Categories.
	 * @param array    $term_cache   Product categories looked up so far.
	 * @return bool
	 */
	private static function bought( WC_Order $order, array $product_ids, array $category_ids, array &$term_cache ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();

			if ( in_array( $product_id, $product_ids, true ) || ( $variation_id && in_array( $variation_id, $product_ids, true ) ) ) {
				return true;
			}

			if ( array() !== $category_ids && $product_id ) {
				if ( ! isset( $term_cache[ $product_id ] ) ) {
					$term_cache[ $product_id ] = array_map( 'intval', wc_get_product_term_ids( $product_id, 'product_cat' ) );
				}
				if ( array_intersect( $term_cache[ $product_id ], $category_ids ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
