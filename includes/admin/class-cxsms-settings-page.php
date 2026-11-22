<?php
/**
 * SMS › Settings.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer SMS: the CloudXino account with its balance, and the message sent at each order
 * event. Also sends a test SMS.
 */
class CXSMS_Settings_Page {

	/** The page. */
	public static function render() {
		if ( ! current_user_can( CXSMS_Admin::CAPABILITY ) ) {
			return;
		}

		$sms     = new CXSMS_Order_SMS();
		$enabled = '1' === (string) CXSMS_Settings::get( CXSMS_Settings::ENABLED, '0' );
		$test    = 'Test SMS from ' . CXSMS_Settings::store_name() . '. SMS is working.';
		?>
		<div class="wrap cxsms">
			<h1>SMS</h1>

			<?php self::render_account_card(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cxsms_settings">
				<?php wp_nonce_field( 'cxsms_settings' ); ?>

				<h2>CloudXino account</h2>
				<p>
					Texts customers about their orders through sms.cloudxino.com. Find the API key and secret in your CloudXino account › Settings.
					Don't have an account? <a href="<?php echo esc_url( CXSMS_Settings::REGISTER_URL ); ?>" target="_blank" rel="noopener">Register at CloudXino</a> to get them.
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Send SMS to customers</th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>> On</label>
							<p class="description">On: customers get the messages switched on below when their order changes. Off: no SMS is sent; you can still send a test SMS.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cxsms-api-key">API key</label></th>
						<td>
							<input type="password" id="cxsms-api-key" name="api_key" class="regular-text cxsms-secret" autocomplete="off" maxlength="255" value="<?php echo esc_attr( CXSMS_Settings::secret( CXSMS_Settings::API_KEY ) ); ?>">
							<button type="button" class="button cxsms-reveal" data-target="cxsms-api-key">Show</button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cxsms-api-secret">API secret</label></th>
						<td>
							<input type="password" id="cxsms-api-secret" name="api_secret" class="regular-text cxsms-secret" autocomplete="off" maxlength="255" value="<?php echo esc_attr( CXSMS_Settings::secret( CXSMS_Settings::API_SECRET ) ); ?>">
							<button type="button" class="button cxsms-reveal" data-target="cxsms-api-secret">Show</button>
							<p class="description">Saved encrypted.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cxsms-sender">Sender ID (optional)</label></th>
						<td>
							<input type="text" id="cxsms-sender" name="sender_id" class="regular-text" maxlength="20" value="<?php echo esc_attr( (string) CXSMS_Settings::get( CXSMS_Settings::SENDER_ID, '' ) ); ?>">
							<p class="description">Leave empty to send from your default sender ID. Only fill it in to use another approved one.</p>
						</td>
					</tr>
				</table>

				<h2>Store details</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cxsms-store-name">Store name</label></th>
						<td>
							<input type="text" id="cxsms-store-name" name="store_name" class="regular-text" maxlength="100" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" value="<?php echo esc_attr( (string) CXSMS_Settings::get( 'store_name', '' ) ); ?>">
							<p class="description">For {store}. Leave empty to use the site title.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cxsms-store-phone">Store phone</label></th>
						<td>
							<input type="text" id="cxsms-store-phone" name="store_phone" class="regular-text" maxlength="30" value="<?php echo esc_attr( CXSMS_Settings::store_phone() ); ?>">
							<p class="description">For {phone}.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cxsms-courier-meta">Courier order field</label></th>
						<td>
							<input type="text" id="cxsms-courier-meta" name="courier_meta" class="regular-text code" maxlength="100" placeholder="_courier_name" value="<?php echo esc_attr( (string) CXSMS_Settings::get( 'courier_meta', '' ) ); ?>">
							<p class="description">The order meta key your courier plugin saves the courier in, for {courier}. Leave empty to read WooCommerce Shipment Tracking.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cxsms-tracking-meta">Tracking order field</label></th>
						<td>
							<input type="text" id="cxsms-tracking-meta" name="tracking_meta" class="regular-text code" maxlength="100" placeholder="_tracking_code" value="<?php echo esc_attr( (string) CXSMS_Settings::get( 'tracking_meta', '' ) ); ?>">
							<p class="description">The order meta key holding the tracking code, for {tracking}. Leave empty to read WooCommerce Shipment Tracking.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cxsms-blocked">Never send campaigns to</label></th>
						<td>
							<textarea id="cxsms-blocked" name="blocked_numbers" rows="3" class="large-text code" placeholder="01712345678"><?php echo esc_textarea( (string) CXSMS_Settings::get( 'blocked_numbers', '' ) ); ?></textarea>
							<p class="description">Mobile numbers left out of every campaign, one per line.</p>
						</td>
					</tr>
				</table>

				<h2>Messages</h2>
				<p>
					Switch on the messages to send and edit their text. These fill in from the order:
					<?php
					$tags = array();
					foreach ( CXSMS_Order_SMS::placeholders() as $tag => $label ) {
						$tags[] = '<code>' . esc_html( $tag ) . '</code> ' . esc_html( $label );
					}
					echo wp_kses( implode( ', ', $tags ), array( 'code' => array() ) );
					?>.
					English fits 160 characters in one SMS; Bangla fits 70, so it costs more.
				</p>

				<table class="form-table cxsms-messages" role="presentation">
					<?php foreach ( CXSMS_Order_SMS::events() as $event => $label ) : ?>
						<?php $on = $sms->is_on( $event ); ?>
						<tr>
							<th scope="row">
								<label><input type="checkbox" class="cxsms-toggle" name="on[<?php echo esc_attr( $event ); ?>]" value="1" <?php checked( $on ); ?>> <?php echo esc_html( $label ); ?></label>
								<p class="description"><?php echo esc_html( CXSMS_Order_SMS::event_help( $event ) ); ?></p>
							</th>
							<td>
								<?php CXSMS_Admin::counted_textarea( 'template[' . $event . ']', $sms->template( $event ), array( 'readonly' => ! $on ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<p class="submit">
					<button type="submit" name="do" value="save" class="button button-primary">Save</button>
				</p>

				<h2>Send a test SMS</h2>
				<p>Saves the settings above first. The SMS is charged to your CloudXino balance.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cxsms-test-phone">Mobile number</label></th>
						<td><input type="tel" id="cxsms-test-phone" name="test_phone" class="regular-text" placeholder="01XXXXXXXXX"></td>
					</tr>
					<tr>
						<th scope="row"><label>Message</label></th>
						<td><?php CXSMS_Admin::counted_textarea( 'test_message', $test, array( 'rows' => 3 ) ); ?></td>
					</tr>
				</table>
				<p><button type="submit" name="do" value="test" class="button">Send test SMS</button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Top of the page: the SMS balance once a key and secret are saved, or how to get them.
	 */
	private static function render_account_card() {
		if ( ! ( new CXSMS_Gateway() )->has_credentials() ) {
			?>
			<div class="cxsms-card cxsms-card-connect">
				<div>
					<h2>Connect your CloudXino account</h2>
					<p>To send SMS you need a CloudXino API key and secret. Register at CloudXino, then copy them from your account › Settings into the fields below.</p>
				</div>
				<a class="button button-primary button-hero" href="<?php echo esc_url( CXSMS_Settings::REGISTER_URL ); ?>" target="_blank" rel="noopener">Register at CloudXino</a>
			</div>
			<?php
			return;
		}

		$cached = get_transient( 'cxsms_balance' );
		$cached = is_array( $cached ) && isset( $cached['balance'], $cached['checked_at'] ) ? $cached : null;
		?>
		<div class="cxsms-card cxsms-balance" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cxsms_balance' ) ); ?>" data-load="<?php echo $cached ? '0' : '1'; ?>">
			<div>
				<span class="cxsms-balance-label">SMS balance</span>
				<strong class="cxsms-balance-value"><?php echo esc_html( $cached ? $cached['balance'] : 'Loading…' ); ?></strong>
				<span class="cxsms-balance-status description"><?php echo esc_html( $cached ? self::checked_ago( $cached['checked_at'] ) : 'Checking CloudXino…' ); ?></span>
			</div>
			<button type="button" class="button cxsms-balance-refresh">Refresh</button>
		</div>
		<?php
	}

	/**
	 * "Checked 2 minutes ago."
	 *
	 * @param int $time When.
	 * @return string
	 */
	public static function checked_ago( $time ) {
		$seconds = time() - (int) $time;

		return $seconds < 60 ? 'Checked just now.' : sprintf( 'Checked %s ago.', human_time_diff( (int) $time ) );
	}

	/** The balance for the card at the top, as JSON. */
	public static function ajax_balance() {
		if ( ! current_user_can( CXSMS_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		check_ajax_referer( 'cxsms_balance' );

		try {
			$balance = ( new CXSMS_Gateway() )->cached_balance( ! empty( $_POST['refresh'] ) );
		} catch ( RuntimeException $e ) {
			wp_send_json_error( array( 'message' => 'Not connected: ' . CXSMS_Gateway::message( $e ) ) );
		}

		wp_send_json_success(
			array(
				'balance' => $balance['balance'],
				'status'  => self::checked_ago( $balance['checked_at'] ),
			)
		);
	}

	/** Save, then send the test SMS when asked. */
	public static function handle() {
		if ( ! current_user_can( CXSMS_Admin::CAPABILITY ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'cxsms_settings' );

		$back = admin_url( 'admin.php?page=cxsms' );
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';
		$post = wp_unslash( $_POST );

		$enabled = ! empty( $post['enabled'] );
		$key     = isset( $post['api_key'] ) ? trim( sanitize_text_field( $post['api_key'] ) ) : '';
		$secret  = isset( $post['api_secret'] ) ? trim( sanitize_text_field( $post['api_secret'] ) ) : '';

		if ( $enabled && ( '' === $key || '' === $secret ) ) {
			CXSMS_Admin::flash( 'error', 'Add the API key and secret to switch SMS on.' );
			$enabled = false;
		}

		CXSMS_Settings::put( CXSMS_Settings::ENABLED, $enabled ? '1' : '0' );
		CXSMS_Settings::put_secret( CXSMS_Settings::API_KEY, $key );
		CXSMS_Settings::put_secret( CXSMS_Settings::API_SECRET, $secret );
		// The key may have changed: the card at the top reads the balance again.
		CXSMS_Gateway::forget_balance();

		foreach ( array( CXSMS_Settings::SENDER_ID, 'store_name', 'store_phone', 'courier_meta', 'tracking_meta' ) as $field ) {
			$value = isset( $post[ $field ] ) ? trim( sanitize_text_field( $post[ $field ] ) ) : '';
			CXSMS_Settings::put( $field, '' !== $value ? $value : null );
		}

		$blocked = isset( $post['blocked_numbers'] ) ? trim( sanitize_textarea_field( $post['blocked_numbers'] ) ) : '';
		CXSMS_Settings::put( 'blocked_numbers', '' !== $blocked ? $blocked : null );

		$on        = isset( $post['on'] ) && is_array( $post['on'] ) ? $post['on'] : array();
		$templates = isset( $post['template'] ) && is_array( $post['template'] ) ? $post['template'] : array();

		foreach ( array_keys( CXSMS_Order_SMS::events() ) as $event ) {
			CXSMS_Settings::put( CXSMS_Order_SMS::enabled_key( $event ), ! empty( $on[ $event ] ) ? '1' : '0' );

			$template = isset( $templates[ $event ] ) ? trim( sanitize_textarea_field( $templates[ $event ] ) ) : '';
			$template = mb_substr( $template, 0, 1000 );
			// Empty, or the same as the default: keep following the default.
			CXSMS_Settings::put( CXSMS_Order_SMS::template_key( $event ), '' !== $template && CXSMS_Order_SMS::default_template( $event ) !== $template ? $template : null );
		}

		if ( 'test' === $do ) {
			self::send_test(
				isset( $post['test_phone'] ) ? sanitize_text_field( $post['test_phone'] ) : '',
				isset( $post['test_message'] ) ? trim( sanitize_textarea_field( $post['test_message'] ) ) : ''
			);
		} else {
			CXSMS_Admin::flash( 'success', 'SMS settings saved.' );
		}

		CXSMS_Admin::redirect( $back );
	}

	/**
	 * Send the test SMS.
	 *
	 * @param string $phone   Number.
	 * @param string $message Text.
	 */
	private static function send_test( $phone, $message ) {
		if ( 1 !== preg_match( '/^(?:\+?88)?01[3-9]\d{8}$/', preg_replace( '/[\s-]/', '', $phone ) ) ) {
			CXSMS_Admin::flash( 'error', 'Test SMS not sent: enter a Bangladeshi mobile number, e.g. 01712345678.' );
			return;
		}
		if ( '' === $message ) {
			CXSMS_Admin::flash( 'error', 'Test SMS not sent: write a message.' );
			return;
		}

		try {
			$sent = ( new CXSMS_Order_SMS() )->send( null, $phone, mb_substr( $message, 0, 1000 ), 'test', get_current_user_id() );
		} catch ( RuntimeException $e ) {
			CXSMS_Admin::flash( 'error', 'Test SMS failed. ' . CXSMS_Gateway::message( $e ) );
			return;
		}

		CXSMS_Admin::flash( 'success', sprintf( 'Test SMS sent to %s.%s', $sent->recipient, null !== $sent->cost ? ' Cost ৳' . $sent->cost . '.' : '' ) );
	}
}
