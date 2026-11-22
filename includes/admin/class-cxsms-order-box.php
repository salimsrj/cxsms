<?php
/**
 * The order page: SMS sent, send an SMS, and whether a status change texts the customer.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * "SMS to customer" box on the order page, and the "text the customer" choice by the status.
 */
class CXSMS_Order_Box {

	/** Hook up. */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 30 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'status_choice' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_status_choice' ), 5, 1 );
	}

	/** The box, on the classic and the HPOS order screens. */
	public static function add_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box( 'cxsms-order', 'SMS to customer', array( __CLASS__, 'render_box' ), $screen, 'normal', 'default' );
	}

	/**
	 * Which order a box callback is for.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (classic) or order (HPOS).
	 * @return WC_Order|null
	 */
	private static function order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		$order = wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : 0 );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * SMS sent so far, and a form to send one.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render_box( $post_or_order ) {
		$order = self::order( $post_or_order );
		if ( ! $order || 'auto-draft' === $order->get_status() ) {
			echo '<p>Save the order first.</p>';
			return;
		}

		$sms      = new CXSMS_Order_SMS();
		$messages = CXSMS_Messages::for_order( $order->get_id() );
		$phone    = CXSMS_Order_SMS::customer_phone( $order );
		$enabled  = ( new CXSMS_Gateway() )->is_enabled();
		?>
		<div class="cxsms-order-box">
			<?php if ( $messages ) : ?>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $messages as $row ) : ?>
						<?php $user = $row->sent_by ? get_userdata( (int) $row->sent_by ) : null; ?>
						<tr>
							<td>
								<strong><?php echo esc_html( 'manual' === $row->event ? 'Sent by hand' : CXSMS_Order_SMS::event_label( $row->event ) ); ?></strong><br>
								<span class="description"><?php echo esc_html( CXSMS_Admin::time( $row->created_at ) . ( $user ? ' · ' . $user->display_name : '' ) ); ?></span>
							</td>
							<td>
								<?php echo esc_html( $row->message ); ?>
								<?php if ( $row->error ) : ?>
									<br><span class="cxsms-error"><?php echo esc_html( $row->error ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="cxsms-badge cxsms-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description">No SMS sent for this order yet.</p>
			<?php endif; ?>

			<?php if ( ! $enabled ) : ?>
				<p class="description">SMS is switched off. <a href="<?php echo esc_url( admin_url( 'admin.php?page=cxsms' ) ); ?>">SMS settings</a></p>
			<?php elseif ( '' === $phone ) : ?>
				<p class="description">This order has no phone number.</p>
			<?php elseif ( current_user_can( 'edit_shop_orders' ) ) : ?>
				<h4><?php echo esc_html( sprintf( 'Send an SMS to %s (%s)', CXSMS_Order_SMS::customer_name( $order ), $phone ) ); ?></h4>
				<?php
				// Start from the message for the order's current status.
				CXSMS_Admin::counted_textarea(
					'cxsms_message',
					$sms->render( $sms->template( $order->get_status() ), $order ),
					array(
						'rows'      => 3,
						'maxlength' => 1000,
					)
				);
				?>
				<p>
					<?php // The box sits inside the order's own form, so the button posts a separate form built in admin.js. ?>
					<button type="button" class="button cxsms-send-order-sms"
						data-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						data-order="<?php echo esc_attr( $order->get_id() ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'cxsms_order_sms_' . $order->get_id() ) ); ?>">Send SMS</button>
					<span class="description">Charged to your CloudXino balance. Unsaved order changes are lost.</span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Send the SMS typed in the box. */
	public static function handle_send() {
		$order_id = isset( $_POST['cxsms_order_id'] ) ? absint( $_POST['cxsms_order_id'] ) : 0;

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'cxsms_order_sms_' . $order_id, 'cxsms_nonce' );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( 'Order not found.' );
		}

		$message = isset( $_POST['cxsms_message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['cxsms_message'] ) ) ) : '';
		$phone   = CXSMS_Order_SMS::customer_phone( $order );

		if ( '' === $message ) {
			CXSMS_Admin::flash( 'error', 'SMS not sent: write a message.' );
		} elseif ( '' === $phone ) {
			CXSMS_Admin::flash( 'error', 'SMS not sent: the order has no phone number.' );
		} else {
			try {
				( new CXSMS_Order_SMS() )->send( $order, $phone, mb_substr( $message, 0, 1000 ), 'manual', get_current_user_id() );
				CXSMS_Admin::flash( 'success', 'SMS sent to ' . $phone . '.' );
			} catch ( RuntimeException $e ) {
				CXSMS_Admin::flash( 'error', 'SMS not sent. ' . CXSMS_Gateway::message( $e ) );
			}
		}

		CXSMS_Admin::redirect( $order->get_edit_order_url() );
	}

	/**
	 * By the order status: whether changing it texts the customer.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function status_choice( $order ) {
		if ( ! ( new CXSMS_Gateway() )->is_enabled() ) {
			return;
		}

		$on = array();
		foreach ( CXSMS_Order_SMS::events() as $event => $label ) {
			if ( CXSMS_Order_SMS::PLACED !== $event && ( new CXSMS_Order_SMS() )->would_send( $event, $order ) ) {
				$on[] = $label;
			}
		}
		?>
		<p class="form-field form-field-wide cxsms-status-choice">
			<input type="hidden" name="cxsms_status_choice" value="1">
			<?php wp_nonce_field( 'cxsms_status_choice', 'cxsms_status_nonce' ); ?>
			<label>
				<input type="checkbox" name="cxsms_status_sms" value="1" checked>
				Text the customer about a status change
			</label>
			<span class="description">
				<?php echo esc_html( $on ? 'SMS goes out for: ' . implode( ', ', $on ) . '.' : 'No status has an SMS switched on that wasn\'t already sent.' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Staff unticked the box: this save's status change doesn't text the customer.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function save_status_choice( $order_id ) {
		if ( empty( $_POST['cxsms_status_choice'] ) || ! isset( $_POST['cxsms_status_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cxsms_status_nonce'] ) ), 'cxsms_status_choice' ) ) {
			return;
		}

		if ( empty( $_POST['cxsms_status_sms'] ) ) {
			CXSMS_Order_SMS::skip_once( $order_id );
		}
	}
}
