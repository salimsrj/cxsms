<?php
/**
 * Plugin options, with the CloudXino key and secret stored encrypted.
 *
 * @package CXSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's options (all prefixed "cxsms_").
 */
class CXSMS_Settings {

	const ENABLED    = 'enabled';
	const API_KEY    = 'api_key';
	const API_SECRET = 'api_secret';
	const SENDER_ID  = 'sender_id';
	const BASE_URL   = 'base_url';

	const DEFAULT_BASE_URL = 'https://sms.cloudxino.com/api/v1';

	/** Sign up at CloudXino to get an API key and secret (with the plugin author's referral code). */
	const REGISTER_URL = 'https://cloudxino.com/register?ref=R2SD92GZ';

	/**
	 * A plain option.
	 *
	 * @param string $key     Option name without the prefix.
	 * @param mixed  $default Returned when it isn't saved.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$value = get_option( 'cxsms_' . $key, null );

		return ( null === $value || false === $value ) ? $default : $value;
	}

	/**
	 * Save an option; null deletes it, so the default applies again.
	 *
	 * @param string $key   Option name without the prefix.
	 * @param mixed  $value Value.
	 */
	public static function put( $key, $value ) {
		if ( null === $value ) {
			delete_option( 'cxsms_' . $key );
			return;
		}

		update_option( 'cxsms_' . $key, $value, false );
	}

	/**
	 * A secret option, decrypted.
	 *
	 * @param string $key Option name without the prefix.
	 * @return string
	 */
	public static function secret( $key ) {
		$stored = (string) self::get( $key, '' );

		return '' === $stored ? '' : self::decrypt( $stored );
	}

	/**
	 * Save a secret option encrypted; empty deletes it.
	 *
	 * @param string      $key   Option name without the prefix.
	 * @param string|null $value Plain value.
	 */
	public static function put_secret( $key, $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		self::put( $key, '' === $value ? null : self::encrypt( $value ) );
	}

	/** The store's name, used for {store}. */
	public static function store_name() {
		$name = trim( (string) self::get( 'store_name', '' ) );

		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** The store's phone, used for {phone}. */
	public static function store_phone() {
		return trim( (string) self::get( 'store_phone', '' ) );
	}

	/** The CloudXino API URL. */
	public static function base_url() {
		$url = trim( (string) self::get( self::BASE_URL, '' ) );

		return rtrim( '' !== $url ? $url : self::DEFAULT_BASE_URL, '/' );
	}

	/**
	 * Encrypt with a key derived from this site's salts.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	private static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );

		return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt what encrypt() stored; empty when the salts changed.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	private static function decrypt( $stored ) {
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );

		return false === $plain ? '' : $plain;
	}

	/** 32-byte key from the site's auth salt. */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'cxsms', true );
	}
}
