<?php
/**
 * SMS campaigns to past customers.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts, stops and sends campaigns. A draft keeps a list of the customers who match, which staff
 * can untick; starting it sends to the ticked ones, so later orders don't change who gets it. The
 * messages go out in the background, a batch at a time.
 */
class CXSMS_Campaigns {

	const DRAFT   = 'draft';
	const SENDING = 'sending';
	const STOPPED = 'stopped';
	const SENT    = 'sent';

	const PENDING = 'pending';
	const FAILED  = 'failed';

	/** Messages per background batch. */
	const BATCH = 50;

	/** This many failures in a row stop the campaign: the balance ran out, or the key is wrong. */
	const FAILURES_IN_A_ROW = 5;

	/**
	 * Placeholders shown on the campaign form; filled in for each customer.
	 *
	 * @return array<string, string>
	 */
	public static function placeholders() {
		return array(
			'{name}'  => 'Customer name',
			'{store}' => 'Store name',
			'{phone}' => 'Store phone',
		);
	}

	/** Campaigns table. */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'cxsms_campaigns';
	}

	/** Recipients table. */
	public static function recipients_table() {
		global $wpdb;

		return $wpdb->prefix . 'cxsms_campaign_recipients';
	}

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::DRAFT   => 'Draft',
			self::SENDING => 'Sending',
			self::STOPPED => 'Stopped',
			self::SENT    => 'Sent',
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	/**
	 * One campaign, with its filters decoded.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		if ( $campaign ) {
			$filters           = json_decode( (string) $campaign->filters, true );
			$campaign->filters = is_array( $filters ) ? $filters : array();
		}

		return $campaign;
	}

	/**
	 * All campaigns, newest first.
	 *
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Create or update a draft.
	 *
	 * @param int|null $id      Campaign to update, or null for a new one.
	 * @param string   $name    Name.
	 * @param string   $message Message.
	 * @param array    $filters Who it's for.
	 * @return int The campaign ID.
	 * @throws RuntimeException When it was already sent.
	 */
	public static function save_draft( $id, $name, $message, array $filters ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = array(
			'name'       => mb_substr( $name, 0, 191 ),
			'message'    => $message,
			'filters'    => wp_json_encode( $filters ),
			'updated_at' => $now,
		);

		if ( $id ) {
			$campaign = self::find( $id );
			if ( ! $campaign || self::DRAFT !== $campaign->status ) {
				throw new RuntimeException( 'Only a draft can be changed.' );
			}
			$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		} else {
			$data['status']     = self::DRAFT;
			$data['created_by'] = get_current_user_id() ? get_current_user_id() : null;
			$data['created_at'] = $now;
			$wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB
			$id = (int) $wpdb->insert_id;
		}

		$campaign = self::find( $id );
		self::sync_draft( $campaign );

		return (int) $id;
	}

	/**
	 * Delete a campaign that isn't sending.
	 *
	 * @param int $id ID.
	 * @throws RuntimeException While it's sending.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$campaign = self::find( $id );
		if ( ! $campaign ) {
			return;
		}
		if ( self::SENDING === $campaign->status ) {
			throw new RuntimeException( 'Stop the campaign before deleting it.' );
		}

		$wpdb->delete( self::recipients_table(), array( 'campaign_id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Why the campaign can't go out now, or null when it can.
	 *
	 * @param object $campaign Campaign.
	 * @return string|null
	 */
	public static function problem( $campaign ) {
		if ( ! ( new CXSMS_Gateway() )->has_credentials() ) {
			return 'Add the CloudXino API key and secret on the SMS settings page first.';
		}
		if ( '' === trim( (string) $campaign->message ) ) {
			return 'Write the message first.';
		}

		return null;
	}

	/**
	 * A draft's list of customers, brought up to date with who matches now: new matches are added
	 * ticked, ones that no longer match are dropped, and the ones staff unticked stay unticked.
	 *
	 * @param object $campaign Campaign.
	 */
	public static function sync_draft( $campaign ) {
		global $wpdb;

		if ( ! $campaign || self::DRAFT !== $campaign->status ) {
			return;
		}

		$customers = ( new CXSMS_Audience() )->customers( $campaign->filters );
		$table     = self::recipients_table();
		$phones    = wp_list_pluck( $customers, 'phone' );

		if ( array() === $phones ) {
			$wpdb->delete( $table, array( 'campaign_id' => (int) $campaign->id ) ); // phpcs:ignore WordPress.DB
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE campaign_id = %d AND phone NOT IN ({$placeholders})", array_merge( array( (int) $campaign->id ), $phones ) ) ); // phpcs:ignore WordPress.DB
		}

		$now = current_time( 'mysql', true );
		foreach ( array_chunk( $customers, 200 ) as $chunk ) {
			$rows   = array();
			$params = array();
			foreach ( $chunk as $customer ) {
				$rows[] = '(%d, %s, %s, %d, 1, %s, %s, %s)';
				array_push( $params, (int) $campaign->id, $customer['phone'], '' !== $customer['name'] ? mb_substr( $customer['name'], 0, 191 ) : null, (int) $customer['orders'], self::PENDING, $now, $now );
			}

			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( // phpcs:ignore WordPress.DB
					"INSERT INTO {$table} (campaign_id, phone, name, orders_count, selected, status, created_at, updated_at) VALUES " . implode( ',', $rows ) // phpcs:ignore WordPress.DB
					. ' ON DUPLICATE KEY UPDATE name = VALUES(name), orders_count = VALUES(orders_count), updated_at = VALUES(updated_at)',
					$params
				)
			);
		}

		$wpdb->update( self::table(), array( 'recipients_count' => count( $customers ) ), array( 'id' => (int) $campaign->id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Tick or untick a draft's customers.
	 *
	 * @param object $campaign Campaign.
	 * @param int[]  $ids      Recipients shown on the page.
	 * @param int[]  $ticked   The ones of those left ticked.
	 */
	public static function set_selected( $campaign, array $ids, array $ticked ) {
		global $wpdb;

		if ( self::DRAFT !== $campaign->status ) {
			return;
		}

		foreach ( array_map( 'intval', $ids ) as $id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				self::recipients_table(),
				array( 'selected' => in_array( $id, array_map( 'intval', $ticked ), true ) ? 1 : 0 ),
				array(
					'id'          => $id,
					'campaign_id' => (int) $campaign->id,
				)
			);
		}
	}

	/**
	 * Tick or untick all of a draft's customers.
	 *
	 * @param object $campaign Campaign.
	 * @param bool   $selected Ticked.
	 */
	public static function select_all( $campaign, $selected ) {
		global $wpdb;

		if ( self::DRAFT === $campaign->status ) {
			$wpdb->update( self::recipients_table(), array( 'selected' => $selected ? 1 : 0 ), array( 'campaign_id' => (int) $campaign->id ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * How many of a draft's customers are ticked.
	 *
	 * @param object $campaign Campaign.
	 * @return int
	 */
	public static function selected_count( $campaign ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::recipients_table() . ' WHERE campaign_id = %d AND selected = 1', $campaign->id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A page of a campaign's customers.
	 *
	 * @param object $campaign Campaign.
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @param string $status   Only this status.
	 * @return array{items: object[], total: int}
	 */
	public static function recipients( $campaign, $page = 1, $per_page = 100, $status = '' ) {
		global $wpdb;

		$table = self::recipients_table();
		$where = $status ? $wpdb->prepare( 'campaign_id = %d AND status = %s', $campaign->id, $status ) : $wpdb->prepare( 'campaign_id = %d', $campaign->id );

		return array(
			'items' => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $per_page, ( max( 1, $page ) - 1 ) * $per_page ) ), // phpcs:ignore WordPress.DB
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ), // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * Start sending to the ticked customers. The unticked ones are dropped, so the list then shows
	 * only who it went to.
	 *
	 * @param int $id Campaign ID.
	 * @return int How many it goes to.
	 * @throws RuntimeException When it can't go out, or no customer is ticked.
	 */
	public static function start( $id ) {
		global $wpdb;

		$campaign = self::find( $id );
		if ( ! $campaign || self::DRAFT !== $campaign->status ) {
			throw new RuntimeException( 'This campaign was already sent.' );
		}

		$problem = self::problem( $campaign );
		if ( $problem ) {
			throw new RuntimeException( $problem );
		}

		self::sync_draft( $campaign );

		$count = self::selected_count( $campaign );
		if ( 0 === $count ) {
			$any = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::recipients_table() . ' WHERE campaign_id = %d', $id ) ); // phpcs:ignore WordPress.DB
			throw new RuntimeException( $any ? 'Tick at least one customer to send to.' : 'No customer matches who this campaign is for.' );
		}

		$wpdb->delete( // phpcs:ignore WordPress.DB
			self::recipients_table(),
			array(
				'campaign_id' => (int) $id,
				'selected'    => 0,
			)
		);
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'           => self::SENDING,
				'recipients_count' => $count,
				'sent_count'       => 0,
				'failed_count'     => 0,
				'error'            => null,
				'started_at'       => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);

		CXSMS_Queue::queue_campaign( $id );

		return $count;
	}

	/**
	 * Stop sending; the rest stay waiting.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function stop( $id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'     => self::STOPPED,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => (int) $id,
				'status' => self::SENDING,
			)
		);
	}

	/**
	 * Carry on after a stop, trying the failed ones again too when asked.
	 *
	 * @param int  $id           Campaign ID.
	 * @param bool $retry_failed Try the failed ones again.
	 * @throws RuntimeException When it can't go out.
	 */
	public static function resume( $id, $retry_failed = false ) {
		global $wpdb;

		$campaign = self::find( $id );
		if ( ! $campaign || self::DRAFT === $campaign->status || self::SENDING === $campaign->status ) {
			return;
		}

		$problem = self::problem( $campaign );
		if ( $problem ) {
			throw new RuntimeException( $problem );
		}

		if ( $retry_failed ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				self::recipients_table(),
				array(
					'status' => self::PENDING,
					'error'  => null,
				),
				array(
					'campaign_id' => (int) $id,
					'status'      => self::FAILED,
				)
			);
		}

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'      => self::SENDING,
				'error'       => null,
				'finished_at' => null,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
		self::refresh_counts( $id );

		CXSMS_Queue::queue_campaign( $id );
	}

	/**
	 * Update the sent and failed counts from the recipients.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function refresh_counts( $id ) {
		global $wpdb;

		$table = self::recipients_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE campaign_id = %d GROUP BY status", $id ) ); // phpcs:ignore WordPress.DB
		$count = array(
			self::SENT   => 0,
			self::FAILED => 0,
		);
		foreach ( $rows as $row ) {
			$count[ $row->status ] = (int) $row->n;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'sent_count'   => $count[ self::SENT ],
				'failed_count' => $count[ self::FAILED ],
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * The text with the customer's name and the store's details filled in.
	 *
	 * @param string      $text Text.
	 * @param string|null $name Customer name.
	 * @return string
	 */
	public static function render( $text, $name ) {
		$name = trim( (string) $name );

		return trim(
			strtr(
				(string) $text,
				array(
					'{name}'  => '' !== $name ? $name : 'Customer',
					'{store}' => CXSMS_Settings::store_name(),
					'{phone}' => CXSMS_Settings::store_phone(),
				)
			)
		);
	}

	/**
	 * Send one batch of a campaign, then queue the next, so a big campaign never runs into a time
	 * limit and stopping it takes effect within a batch. Several failures in a row (no balance, a
	 * wrong key) stop the campaign with the reason.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function handle_batch( $id ) {
		global $wpdb;

		$campaign = self::find( $id );
		if ( ! $campaign || self::SENDING !== $campaign->status ) {
			return;
		}

		$table      = self::recipients_table();
		$gateway    = new CXSMS_Gateway();
		$failures   = 0;
		$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d AND status = %s ORDER BY id ASC LIMIT %d", $id, self::PENDING, self::BATCH ) ); // phpcs:ignore WordPress.DB

		foreach ( $recipients as $recipient ) {
			// Stopped from the admin while this batch was going out.
			$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
			if ( self::SENDING !== $status ) {
				self::refresh_counts( $id );
				return;
			}

			try {
				$result = $gateway->send( $recipient->phone, self::render( $campaign->message, $recipient->name ), 'campaign-' . $id );
				$wpdb->update( // phpcs:ignore WordPress.DB
					$table,
					array(
						'status'              => self::SENT,
						'provider_message_id' => $result['id'],
						'error'               => null,
						'sent_at'             => current_time( 'mysql', true ),
						'updated_at'          => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $recipient->id )
				);
				$failures = 0;
			} catch ( RuntimeException $e ) {
				$wpdb->update( // phpcs:ignore WordPress.DB
					$table,
					array(
						'status'     => self::FAILED,
						'error'      => mb_substr( $e->getMessage(), 0, 255 ),
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $recipient->id )
				);

				if ( ++$failures >= self::FAILURES_IN_A_ROW ) {
					$wpdb->update( // phpcs:ignore WordPress.DB
						self::table(),
						array(
							'status' => self::STOPPED,
							'error'  => mb_substr( sprintf( 'Stopped after %d failures in a row: %s', $failures, $e->getMessage() ), 0, 255 ),
						),
						array( 'id' => (int) $id )
					);
					self::refresh_counts( $id );

					return;
				}
			}
		}

		self::refresh_counts( $id );

		$waiting = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND status = %s", $id, self::PENDING ) ); // phpcs:ignore WordPress.DB

		if ( $waiting > 0 ) {
			// This action is still running, so the "already queued" check would see it: schedule just after.
			as_schedule_single_action( time() + 1, CXSMS_Queue::CAMPAIGN_HOOK, array( (int) $id ), CXSMS_Queue::GROUP );
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'      => self::SENT,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}
}
