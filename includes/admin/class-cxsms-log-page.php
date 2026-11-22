<?php
/**
 * SMS › SMS log.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every SMS sent or tried, newest first, filterable by status and event.
 */
class CXSMS_Log_Page {

	const PER_PAGE = 30;

	/** The page. */
	public static function render() {
		if ( ! current_user_can( CXSMS_Admin::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$event  = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$result = CXSMS_Messages::search(
			array(
				'status'   => $status,
				'event'    => $event,
				'search'   => $search,
				'per_page' => self::PER_PAGE,
				'page'     => $page,
			)
		);
		$events = array_merge(
			CXSMS_Order_SMS::events(),
			array(
				'manual' => 'Sent by hand',
				'test'   => 'Test',
			)
		);
		$users  = array();
		?>
		<div class="wrap cxsms">
			<h1>SMS log</h1>

			<form method="get">
				<input type="hidden" name="page" value="cxsms-log">
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Number, text or order ID">
					<button class="button">Search</button>
				</p>
				<div class="tablenav top">
					<select name="status">
						<option value="">All statuses</option>
						<option value="sent" <?php selected( $status, 'sent' ); ?>>Sent</option>
						<option value="failed" <?php selected( $status, 'failed' ); ?>>Failed</option>
					</select>
					<select name="event">
						<option value="">All messages</option>
						<?php foreach ( $events as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $event, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button">Filter</button>
					<span class="displaying-num"><?php echo esc_html( sprintf( '%d SMS', $result['total'] ) ); ?></span>
				</div>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Sent</th>
						<th>Order</th>
						<th>Message</th>
						<th>To</th>
						<th>Text</th>
						<th>Status</th>
						<th>Cost</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $result['items'] ) : ?>
					<tr><td colspan="7">No SMS yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $result['items'] as $row ) : ?>
					<?php
					if ( $row->sent_by && ! isset( $users[ $row->sent_by ] ) ) {
						$user                  = get_userdata( (int) $row->sent_by );
						$users[ $row->sent_by ] = $user ? $user->display_name : '';
					}
					$order = $row->order_id ? wc_get_order( (int) $row->order_id ) : null;
					?>
					<tr>
						<td>
							<?php echo esc_html( CXSMS_Admin::time( $row->created_at ) ); ?>
							<?php if ( $row->sent_by && $users[ $row->sent_by ] ) : ?>
								<br><span class="description"><?php echo esc_html( $users[ $row->sent_by ] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $order ) : ?>
								<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
							<?php elseif ( $row->order_id ) : ?>
								#<?php echo esc_html( $row->order_id ); ?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $events[ $row->event ] ) ? $events[ $row->event ] : CXSMS_Order_SMS::event_label( $row->event ) ); ?></td>
						<td><?php echo esc_html( $row->recipient ); ?></td>
						<td>
							<?php echo esc_html( $row->message ); ?>
							<?php if ( $row->error ) : ?>
								<br><span class="cxsms-error"><?php echo esc_html( $row->error ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="cxsms-badge cxsms-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
						<td>
							<?php echo null !== $row->cost ? esc_html( '৳' . $row->cost ) : '&mdash;'; ?>
							<?php if ( $row->segments ) : ?>
								<br><span class="description"><?php echo esc_html( sprintf( '%d SMS', $row->segments ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => (int) ceil( $result['total'] / self::PER_PAGE ),
							)
						)
					);
					?>
				</div>
			</div>
		</div>
		<?php
	}
}
