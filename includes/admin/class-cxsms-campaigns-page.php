<?php
/**
 * SMS › Campaigns.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * SMS campaigns to past customers: write a message, pick who it's for, untick anyone to leave
 * out, then send. Sending can be stopped, resumed and the failed ones tried again.
 */
class CXSMS_Campaigns_Page {

	const PER_PAGE = 100;

	/** List, new campaign, or one campaign. */
	public static function render() {
		if ( ! current_user_can( CXSMS_Admin::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$id  = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
		$new = isset( $_GET['new'] );
		// phpcs:enable

		if ( $new ) {
			self::render_campaign( null );
		} elseif ( $id ) {
			$campaign = CXSMS_Campaigns::find( $id );
			if ( ! $campaign ) {
				echo '<div class="wrap"><h1>Campaign not found</h1></div>';
				return;
			}
			// Bring a draft's list up to date with who matches now.
			CXSMS_Campaigns::sync_draft( $campaign );
			self::render_campaign( CXSMS_Campaigns::find( $id ) );
		} else {
			self::render_list();
		}
	}

	/**
	 * Link to a campaign, or the list.
	 *
	 * @param int|null $id    Campaign ID.
	 * @param array    $extra More query args.
	 * @return string
	 */
	public static function url( $id = null, array $extra = array() ) {
		$args = array( 'page' => 'cxsms-campaigns' );
		if ( $id ) {
			$args['campaign'] = (int) $id;
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/** All campaigns. */
	private static function render_list() {
		$campaigns = CXSMS_Campaigns::all();
		?>
		<div class="wrap cxsms">
			<h1 class="wp-heading-inline">SMS campaigns</h1>
			<a href="<?php echo esc_url( self::url( null, array( 'new' => 1 ) ) ); ?>" class="page-title-action">New campaign</a>
			<hr class="wp-header-end">
			<p>Text a message to past customers, picked from their orders. Each mobile number gets it once.</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Name</th>
						<th>Status</th>
						<th>Customers</th>
						<th>Sent</th>
						<th>Failed</th>
						<th>Started</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $campaigns ) : ?>
					<tr><td colspan="6">No campaigns yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $campaigns as $campaign ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( $campaign->id ) ); ?>"><strong><?php echo esc_html( $campaign->name ); ?></strong></a></td>
						<td><span class="cxsms-badge cxsms-badge-<?php echo esc_attr( $campaign->status ); ?>"><?php echo esc_html( CXSMS_Campaigns::status_label( $campaign->status ) ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( (int) $campaign->recipients_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $campaign->sent_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $campaign->failed_count ) ); ?></td>
						<td><?php echo esc_html( $campaign->started_at ? CXSMS_Admin::time( $campaign->started_at ) : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * A new campaign, a draft (editable, with its customers to tick) or one already sent.
	 *
	 * @param object|null $campaign Campaign, or null for a new one.
	 */
	private static function render_campaign( $campaign ) {
		$draft   = ! $campaign || CXSMS_Campaigns::DRAFT === $campaign->status;
		$filters = $campaign ? $campaign->filters : array();
		$name    = $campaign ? $campaign->name : '';
		$message = $campaign ? (string) $campaign->message : 'Hi {name}, ';
		?>
		<div class="wrap cxsms">
			<h1><?php echo esc_html( $campaign ? $campaign->name : 'New SMS campaign' ); ?></h1>
			<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; All campaigns</a></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cxsms_campaign">
				<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign ? $campaign->id : 0 ); ?>">
				<?php wp_nonce_field( 'cxsms_campaign' ); ?>

				<?php if ( $campaign && ! $draft ) : ?>
					<?php self::render_progress( $campaign ); ?>
				<?php endif; ?>

				<h2>Message</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cxsms-name">Name</label></th>
						<td><input type="text" id="cxsms-name" name="name" class="regular-text" maxlength="191" required value="<?php echo esc_attr( $name ); ?>" <?php disabled( ! $draft ); ?> placeholder="Eid offer"></td>
					</tr>
					<tr>
						<th scope="row"><label>Message</label></th>
						<td>
							<?php CXSMS_Admin::counted_textarea( 'message', $message, array( 'rows' => 4, 'readonly' => ! $draft ) ); ?>
							<p class="description">
								Filled in for each customer:
								<?php
								$tags = array();
								foreach ( CXSMS_Campaigns::placeholders() as $tag => $label ) {
									$tags[] = '<code>' . esc_html( $tag ) . '</code> ' . esc_html( $label );
								}
								echo wp_kses( implode( ', ', $tags ), array( 'code' => array() ) );
								?>.
							</p>
						</td>
					</tr>
				</table>

				<h2>Who it's for</h2>
				<?php self::render_filters( $filters, $draft ); ?>

				<?php if ( $draft ) : ?>
					<p class="submit">
						<button type="submit" name="do" value="save" class="button button-primary"><?php echo $campaign ? 'Save and update the customers' : 'Save draft and find customers'; ?></button>
						<?php if ( $campaign ) : ?>
							<button type="submit" name="do" value="delete" class="button button-link-delete" onclick="return confirm('Delete this draft?');">Delete draft</button>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $campaign ) : ?>
					<?php self::render_recipients( $campaign, $draft ); ?>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Counts and the stop / resume buttons.
	 *
	 * @param object $campaign Campaign.
	 */
	private static function render_progress( $campaign ) {
		$sending = CXSMS_Campaigns::SENDING === $campaign->status;
		$waiting = max( 0, (int) $campaign->recipients_count - (int) $campaign->sent_count - (int) $campaign->failed_count );
		?>
		<div class="cxsms-progress<?php echo $sending ? ' cxsms-refresh' : ''; ?>">
			<p>
				<span class="cxsms-badge cxsms-badge-<?php echo esc_attr( $campaign->status ); ?>"><?php echo esc_html( CXSMS_Campaigns::status_label( $campaign->status ) ); ?></span>
				<?php
				echo esc_html(
					sprintf(
						'%s sent, %s failed, %s waiting, of %s customers.',
						number_format_i18n( (int) $campaign->sent_count ),
						number_format_i18n( (int) $campaign->failed_count ),
						number_format_i18n( $waiting ),
						number_format_i18n( (int) $campaign->recipients_count )
					)
				);
				?>
				<?php if ( $campaign->started_at ) : ?>
					<span class="description"><?php echo esc_html( 'Started ' . CXSMS_Admin::time( $campaign->started_at ) . ( $campaign->finished_at ? ', finished ' . CXSMS_Admin::time( $campaign->finished_at ) : '' ) . '.' ); ?></span>
				<?php endif; ?>
			</p>
			<?php if ( $campaign->error ) : ?>
				<p class="cxsms-error"><?php echo esc_html( $campaign->error ); ?></p>
			<?php endif; ?>
			<p>
				<?php if ( $sending ) : ?>
					<button type="submit" name="do" value="stop" class="button">Stop sending</button>
					<span class="description">This page refreshes while it sends.</span>
				<?php else : ?>
					<?php if ( $waiting > 0 ) : ?>
						<button type="submit" name="do" value="resume" class="button button-primary">Resume</button>
					<?php endif; ?>
					<?php if ( (int) $campaign->failed_count > 0 ) : ?>
						<button type="submit" name="do" value="retry" class="button">Try the failed ones again</button>
					<?php endif; ?>
					<button type="submit" name="do" value="delete" class="button button-link-delete" onclick="return confirm('Delete this campaign and its list?');">Delete</button>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The filters.
	 *
	 * @param array $filters  Saved filters.
	 * @param bool  $editable A draft.
	 */
	private static function render_filters( array $filters, $editable ) {
		$statuses     = isset( $filters['statuses'] ) ? (array) $filters['statuses'] : CXSMS_Audience::DEFAULT_STATUSES;
		$product_ids  = isset( $filters['product_ids'] ) ? array_map( 'intval', (array) $filters['product_ids'] ) : array();
		$category_ids = isset( $filters['category_ids'] ) ? array_map( 'intval', (array) $filters['category_ids'] ) : array();
		$categories   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Orders that count</th>
				<td>
					<?php foreach ( wc_get_order_statuses() as $slug => $label ) : ?>
						<?php
						$slug = 0 === strpos( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;
						if ( 'checkout-draft' === $slug ) {
							continue;
						}
						?>
						<label class="cxsms-inline"><input type="checkbox" name="filters[statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $statuses, true ) ); ?> <?php disabled( ! $editable ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<p class="description">Customers with orders in these statuses. Tick none to count every order.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cxsms-products">Bought these products</label></th>
				<td>
					<select id="cxsms-products" class="wc-product-search" multiple="multiple" style="width: 50%;" name="filters[product_ids][]" data-placeholder="Any product" data-action="woocommerce_json_search_products_and_variations" <?php disabled( ! $editable ); ?>>
						<?php foreach ( $product_ids as $product_id ) : ?>
							<?php $product = wc_get_product( $product_id ); ?>
							<?php if ( $product ) : ?>
								<option value="<?php echo esc_attr( $product_id ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cxsms-categories">Or something in these categories</label></th>
				<td>
					<select id="cxsms-categories" class="wc-enhanced-select" multiple="multiple" style="width: 50%;" name="filters[category_ids][]" data-placeholder="Any category" <?php disabled( ! $editable ); ?>>
						<?php foreach ( is_array( $categories ) ? $categories : array() as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $category_ids, true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cxsms-min">At least this many orders</label></th>
				<td><input type="number" id="cxsms-min" name="filters[min_orders]" min="1" class="small-text" value="<?php echo esc_attr( isset( $filters['min_orders'] ) ? (int) $filters['min_orders'] : 1 ); ?>" <?php disabled( ! $editable ); ?>></td>
			</tr>
			<tr>
				<th scope="row"><label for="cxsms-within">Ordered in the last</label></th>
				<td>
					<input type="number" id="cxsms-within" name="filters[ordered_within_days]" min="0" class="small-text" value="<?php echo esc_attr( ! empty( $filters['ordered_within_days'] ) ? (int) $filters['ordered_within_days'] : '' ); ?>" <?php disabled( ! $editable ); ?>> days
					<p class="description">Any order. Leave empty for any time.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cxsms-not-for">No order in the last</label></th>
				<td>
					<input type="number" id="cxsms-not-for" name="filters[not_ordered_for_days]" min="0" class="small-text" value="<?php echo esc_attr( ! empty( $filters['not_ordered_for_days'] ) ? (int) $filters['not_ordered_for_days'] : '' ); ?>" <?php disabled( ! $editable ); ?>> days
					<p class="description">To win back customers who haven't ordered for a while.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The customers: tickable on a draft, with how it went once sent.
	 *
	 * @param object $campaign Campaign.
	 * @param bool   $draft    A draft.
	 */
	private static function render_recipients( $campaign, $draft ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$status = ! $draft && isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable

		$result   = CXSMS_Campaigns::recipients( $campaign, $page, self::PER_PAGE, $status );
		$selected = $draft ? CXSMS_Campaigns::selected_count( $campaign ) : 0;
		?>
		<h2>Customers</h2>

		<?php if ( $draft ) : ?>
			<p>
				<?php echo esc_html( sprintf( '%s of %s customers ticked. Untick anyone to leave out, then save the ticks.', number_format_i18n( $selected ), number_format_i18n( (int) $campaign->recipients_count ) ) ); ?>
			</p>
			<p>
				<button type="submit" name="do" value="select" class="button">Save ticks</button>
				<button type="submit" name="do" value="select_all" class="button">Tick all</button>
				<button type="submit" name="do" value="select_none" class="button">Untick all</button>
				<button type="submit" name="do" value="start" class="button button-primary" <?php disabled( 0 === $selected ); ?> onclick="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( 'Send this SMS to %d customers now? It is charged to your CloudXino balance.', $selected ) ) ); ?>);">
					<?php echo esc_html( sprintf( 'Start sending to %s customers', number_format_i18n( $selected ) ) ); ?>
				</button>
			</p>
		<?php else : ?>
			<ul class="subsubsub">
				<?php
				$links = array(
					''                         => 'All',
					CXSMS_Campaigns::SENT    => 'Sent',
					CXSMS_Campaigns::FAILED  => 'Failed',
					CXSMS_Campaigns::PENDING => 'Waiting',
				);
				$items = array();
				foreach ( $links as $key => $label ) {
					$items[] = sprintf( '<li><a href="%s"%s>%s</a></li>', esc_url( self::url( $campaign->id, $key ? array( 'status' => $key ) : array() ) ), $status === $key ? ' class="current"' : '', esc_html( $label ) );
				}
				echo wp_kses_post( implode( ' | ', $items ) );
				?>
			</ul>
			<br class="clear">
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<?php if ( $draft ) : ?>
						<td class="check-column"><input type="checkbox" class="cxsms-check-all"></td>
					<?php endif; ?>
					<th>Name</th>
					<th>Mobile</th>
					<th>Orders</th>
					<?php if ( ! $draft ) : ?>
						<th>Status</th>
						<th>Sent</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $result['items'] ) : ?>
				<tr><td colspan="5"><?php echo $draft ? 'No customer matches who this campaign is for.' : 'No customers here.'; ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['items'] as $recipient ) : ?>
				<tr>
					<?php if ( $draft ) : ?>
						<th class="check-column">
							<input type="hidden" name="shown[]" value="<?php echo esc_attr( $recipient->id ); ?>">
							<input type="checkbox" name="ticked[]" value="<?php echo esc_attr( $recipient->id ); ?>" <?php checked( (int) $recipient->selected, 1 ); ?>>
						</th>
					<?php endif; ?>
					<td><?php echo esc_html( $recipient->name ? $recipient->name : '—' ); ?></td>
					<td><?php echo esc_html( $recipient->phone ); ?></td>
					<td><?php echo esc_html( (int) $recipient->orders_count ); ?></td>
					<?php if ( ! $draft ) : ?>
						<td>
							<span class="cxsms-badge cxsms-badge-<?php echo esc_attr( $recipient->status ); ?>"><?php echo esc_html( CXSMS_Campaigns::PENDING === $recipient->status ? 'Waiting' : ucfirst( $recipient->status ) ); ?></span>
							<?php if ( $recipient->error ) : ?>
								<br><span class="cxsms-error"><?php echo esc_html( $recipient->error ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( CXSMS_Admin::time( $recipient->sent_at ) ); ?></td>
					<?php endif; ?>
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
							'base'    => add_query_arg( 'paged', '%#%', self::url( $campaign->id, $status ? array( 'status' => $status ) : array() ) ),
							'format'  => '',
							'current' => $page,
							'total'   => (int) ceil( $result['total'] / self::PER_PAGE ),
						)
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/** The campaign form's buttons. */
	public static function handle() {
		if ( ! current_user_can( CXSMS_Admin::CAPABILITY ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'cxsms_campaign' );

		$post = wp_unslash( $_POST );
		$id   = isset( $post['campaign'] ) ? absint( $post['campaign'] ) : 0;
		$do   = isset( $post['do'] ) ? sanitize_key( $post['do'] ) : 'save';

		$campaign = $id ? CXSMS_Campaigns::find( $id ) : null;
		if ( $id && ! $campaign ) {
			CXSMS_Admin::redirect( self::url() );
		}

		try {
			// Ticks on the page shown are kept whichever button was pressed.
			if ( $campaign && CXSMS_Campaigns::DRAFT === $campaign->status && isset( $post['shown'] ) ) {
				CXSMS_Campaigns::set_selected( $campaign, (array) $post['shown'], isset( $post['ticked'] ) ? (array) $post['ticked'] : array() );
			}

			switch ( $do ) {
				case 'delete':
					CXSMS_Campaigns::delete( $id );
					CXSMS_Admin::flash( 'success', 'Campaign deleted.' );
					CXSMS_Admin::redirect( self::url() );
					break;

				case 'select':
					CXSMS_Admin::flash( 'success', 'Ticks saved.' );
					break;

				case 'select_all':
				case 'select_none':
					if ( $campaign ) {
						CXSMS_Campaigns::select_all( $campaign, 'select_all' === $do );
					}
					break;

				case 'start':
					self::save( $id, $post );
					$count = CXSMS_Campaigns::start( $id );
					CXSMS_Admin::flash( 'success', sprintf( 'Sending to %d customers. It goes out in the background.', $count ) );
					break;

				case 'stop':
					CXSMS_Campaigns::stop( $id );
					CXSMS_Admin::flash( 'success', 'Sending stopped. Resume it any time.' );
					break;

				case 'resume':
				case 'retry':
					CXSMS_Campaigns::resume( $id, 'retry' === $do );
					CXSMS_Admin::flash( 'success', 'Sending again.' );
					break;

				default:
					$id = self::save( $id, $post );
					CXSMS_Admin::flash( 'success', 'Campaign saved.' );
			}
		} catch ( RuntimeException $e ) {
			CXSMS_Admin::flash( 'error', $e->getMessage() );
		}

		CXSMS_Admin::redirect( $id ? self::url( $id ) : self::url( null, array( 'new' => 1 ) ) );
	}

	/**
	 * Save the draft from the form.
	 *
	 * @param int   $id   Campaign ID, or 0.
	 * @param array $post Form.
	 * @return int The campaign ID.
	 * @throws RuntimeException When it can't be saved.
	 */
	private static function save( $id, array $post ) {
		$name    = isset( $post['name'] ) ? trim( sanitize_text_field( $post['name'] ) ) : '';
		$message = isset( $post['message'] ) ? trim( sanitize_textarea_field( $post['message'] ) ) : '';
		$raw     = isset( $post['filters'] ) && is_array( $post['filters'] ) ? $post['filters'] : array();

		if ( '' === $name ) {
			throw new RuntimeException( 'Give the campaign a name.' );
		}

		$filters = array(
			'statuses'             => array_values( array_map( 'sanitize_key', isset( $raw['statuses'] ) ? (array) $raw['statuses'] : array() ) ),
			'product_ids'          => array_values( array_filter( array_map( 'absint', isset( $raw['product_ids'] ) ? (array) $raw['product_ids'] : array() ) ) ),
			'category_ids'         => array_values( array_filter( array_map( 'absint', isset( $raw['category_ids'] ) ? (array) $raw['category_ids'] : array() ) ) ),
			'min_orders'           => max( 1, isset( $raw['min_orders'] ) ? absint( $raw['min_orders'] ) : 1 ),
			'ordered_within_days'  => isset( $raw['ordered_within_days'] ) ? absint( $raw['ordered_within_days'] ) : 0,
			'not_ordered_for_days' => isset( $raw['not_ordered_for_days'] ) ? absint( $raw['not_ordered_for_days'] ) : 0,
		);

		return CXSMS_Campaigns::save_draft( $id ? $id : null, $name, mb_substr( $message, 0, 1000 ), $filters );
	}
}
