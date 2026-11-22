<?php
/**
 * CloudXino SMS gateway.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * CloudXino SMS gateway (sms.cloudxino.com). The API key and secret are saved encrypted on the
 * SMS settings page; messages go out from the account's default sender ID unless another
 * approved one is set there.
 */
class CXSMS_Gateway {

	/** Switched on with a key and secret saved. */
	public function is_enabled() {
		return '1' === (string) CXSMS_Settings::get( CXSMS_Settings::ENABLED, '0' ) && $this->has_credentials();
	}

	/** A key and secret are saved. */
	public function has_credentials() {
		return '' !== CXSMS_Settings::secret( CXSMS_Settings::API_KEY ) && '' !== CXSMS_Settings::secret( CXSMS_Settings::API_SECRET );
	}

	/**
	 * Send one SMS. Bangla (Unicode) is detected by the gateway.
	 *
	 * @param string      $to         Mobile number.
	 * @param string      $message    Text.
	 * @param string|null $client_ref Our reference.
	 * @return array{id: string|null, segments: int|null, cost: string|null, balance: string|null}
	 * @throws RuntimeException With the gateway's reason when it isn't accepted.
	 */
	public function send( $to, $message, $client_ref = null ) {
		$body = array(
			'to'      => array( self::normalize_number( $to ) ),
			'message' => $message,
		);

		$sender = trim( (string) CXSMS_Settings::get( CXSMS_Settings::SENDER_ID, '' ) );
		if ( '' !== $sender ) {
			$body['from'] = $sender;
		}
		if ( $client_ref ) {
			$body['client_ref'] = $client_ref;
		}

		$json = $this->request( 'POST', '/sms/send', $body );

		$rejected = isset( $json['rejected'][0] ) ? $json['rejected'][0] : null;
		if ( $rejected ) {
			if ( is_array( $rejected ) ) {
				$reason = isset( $rejected['reason'] ) ? $rejected['reason'] : ( isset( $rejected['error'] ) ? $rejected['error'] : wp_json_encode( $rejected ) );
			} else {
				$reason = $rejected;
			}
			throw new RuntimeException( 'The SMS was rejected: ' . $reason );
		}

		$cost = isset( $json['messages'][0]['cost'] ) ? $json['messages'][0]['cost'] : ( isset( $json['total_cost'] ) ? $json['total_cost'] : null );

		return array(
			'id'       => isset( $json['messages'][0]['id'] ) ? (string) $json['messages'][0]['id'] : null,
			'segments' => isset( $json['segments'] ) ? (int) $json['segments'] : null,
			'cost'     => null === $cost ? null : (string) $cost,
			'balance'  => isset( $json['balance'] ) ? (string) $json['balance'] : null,
		);
	}

	/**
	 * The account balance, e.g. "99.6500 BDT".
	 *
	 * @return string
	 * @throws RuntimeException When it can't be read.
	 */
	public function balance() {
		$json = $this->request( 'GET', '/balance' );

		return trim( ( isset( $json['balance'] ) ? $json['balance'] : '?' ) . ' ' . ( isset( $json['currency'] ) ? $json['currency'] : '' ) );
	}

	/**
	 * 8801XXXXXXXXX, from 01XXXXXXXXX, +8801XXXXXXXXX or with spaces and dashes.
	 *
	 * @param string $number Number as typed.
	 * @return string
	 */
	public static function normalize_number( $number ) {
		$digits = (string) preg_replace( '/\D/', '', (string) $number );

		return 0 === strpos( $digits, '01' ) ? '88' . $digits : $digits;
	}

	/**
	 * A Bangladeshi mobile number (after normalizing).
	 *
	 * @param string $number Number.
	 * @return bool
	 */
	public static function is_valid_number( $number ) {
		return 1 === preg_match( '/^8801[3-9]\d{8}$/', self::normalize_number( $number ) );
	}

	/**
	 * Call the API.
	 *
	 * @param string     $method GET or POST.
	 * @param string     $path   API path.
	 * @param array|null $body   JSON body.
	 * @return array Decoded JSON.
	 * @throws RuntimeException On any failure.
	 */
	private function request( $method, $path, $body = null ) {
		$key    = CXSMS_Settings::secret( CXSMS_Settings::API_KEY );
		$secret = CXSMS_Settings::secret( CXSMS_Settings::API_SECRET );

		if ( '' === $key || '' === $secret ) {
			throw new RuntimeException( 'Add the CloudXino API key and secret first.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'X-API-Key'    => $key,
				'X-API-Secret' => $secret,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( CXSMS_Settings::base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Couldn\'t reach CloudXino. Check the internet connection and try again.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$json   = is_array( $json ) ? $json : array();

		if ( $status >= 200 && $status < 300 ) {
			return $json;
		}

		throw new RuntimeException( self::error_message( $status, $json ) );
	}

	/**
	 * A plain reason for a refused request.
	 *
	 * @param int   $status HTTP status.
	 * @param array $json   Response body.
	 * @return string
	 */
	private static function error_message( $status, array $json ) {
		$code = null;
		if ( isset( $json['error'] ) && is_array( $json['error'] ) && isset( $json['error']['code'] ) ) {
			$code = $json['error']['code'];
		} elseif ( isset( $json['error'] ) ) {
			$code = $json['error'];
		} elseif ( isset( $json['code'] ) ) {
			$code = $json['code'];
		}

		$message = null;
		if ( isset( $json['error'] ) && is_array( $json['error'] ) && isset( $json['error']['message'] ) ) {
			$message = $json['error']['message'];
		} elseif ( isset( $json['message'] ) ) {
			$message = $json['message'];
		}

		if ( 401 === $status ) {
			return 'The CloudXino API key or secret is wrong.';
		}
		if ( 402 === $status ) {
			return 'Not enough SMS balance. Top up your CloudXino account.';
		}
		if ( 'ip_not_whitelisted' === $code ) {
			return 'CloudXino refused this server\'s IP address. Add it to the whitelist in CloudXino settings, or turn the whitelist off.';
		}
		if ( 'sender_id_not_allowed' === $code || 'sender_id_blocked' === $code ) {
			return 'This sender ID isn\'t approved for your CloudXino account.';
		}
		if ( 429 === $status ) {
			return 'CloudXino rate or daily limit reached. Try again later.';
		}

		if ( is_string( $message ) ) {
			return 'CloudXino: ' . $message;
		}

		return 'CloudXino: ' . ( is_string( $code ) ? $code : 'HTTP ' . $status );
	}
}
