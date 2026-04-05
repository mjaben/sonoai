<?php
/**
 * Encryption utility class for secure API key storage.
 *
 * Uses OpenSSL for AES-256-CBC encryption with WordPress LOGGED_IN_SALT and LOGGED_IN_KEY
 * as the encryption key and salt for additional security.
 *
 * @package Antimanual
 * @since 2.2.0
 */

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption class for securely storing sensitive data like API keys.
 */
class Encryption {

	/**
	 * The encryption method to use.
	 *
	 * @var string
	 */
	private static $method = 'aes-256-cbc';

	/**
	 * Get the encryption key.
	 *
	 * Uses WordPress LOGGED_IN_KEY constant for the encryption key.
	 * Falls back to AUTH_KEY if LOGGED_IN_KEY is not available.
	 *
	 * @return string The encryption key.
	 */
	private static function get_key(): string {
		if ( defined( 'LOGGED_IN_KEY' ) && LOGGED_IN_KEY ) {
			return LOGGED_IN_KEY;
		}

		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return AUTH_KEY;
		}

		// Fallback (not recommended, but ensures plugin works).
		return 'antimanual-default-encryption-key-change-me';
	}

	/**
	 * Get the salt for encryption.
	 *
	 * Uses WordPress LOGGED_IN_SALT constant for additional security.
	 * Falls back to AUTH_SALT if LOGGED_IN_SALT is not available.
	 *
	 * @return string The salt value.
	 */
	private static function get_salt(): string {
		if ( defined( 'LOGGED_IN_SALT' ) && LOGGED_IN_SALT ) {
			return LOGGED_IN_SALT;
		}

		if ( defined( 'AUTH_SALT' ) && AUTH_SALT ) {
			return AUTH_SALT;
		}

		// Fallback (not recommended, but ensures plugin works).
		return 'antimanual-default-salt-change-me';
	}

	/**
	 * Generate a derived key from the key and salt.
	 *
	 * Derives a secure key using hash_pbkdf2 for consistent key length.
	 *
	 * @return string The derived key.
	 */
	private static function get_derived_key(): string {
		$key  = self::get_key();
		$salt = self::get_salt();

		// Use PBKDF2 to derive a key of the correct length for AES-256.
		return hash_pbkdf2( 'sha256', $key, $salt, 10000, 32, true );
	}

	/**
	 * Encrypt a string value.
	 *
	 * @param string $value The plain text value to encrypt.
	 * @return string|false The encrypted value (base64 encoded), or false on failure.
	 */
	public static function encrypt( string $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Check if OpenSSL is available.
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// OpenSSL not available, return original value.
			// This is a fail-safe to ensure the plugin works even without OpenSSL.
			return $value;
		}

		$key       = self::get_derived_key();
		$iv_length = openssl_cipher_iv_length( self::$method );
		$iv        = openssl_random_pseudo_bytes( $iv_length );

		if ( false === $iv ) {
			return false;
		}

		$encrypted = openssl_encrypt( $value, self::$method, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return false;
		}

		// Combine IV and encrypted data, then base64 encode.
		// Format: base64(iv + encrypted_data).
		$combined = $iv . $encrypted;

		// Add a prefix to identify encrypted values.
		return 'enc:' . base64_encode( $combined );
	}

	/**
	 * Decrypt an encrypted string value.
	 *
	 * @param string $encrypted_value The encrypted value (base64 encoded with 'enc:' prefix).
	 * @return string|false The decrypted plain text value, or false on failure.
	 */
	public static function decrypt( string $encrypted_value ) {
		if ( empty( $encrypted_value ) ) {
			return '';
		}

		// Check if this is an encrypted value (has our prefix).
		if ( 0 !== strpos( $encrypted_value, 'enc:' ) ) {
			// Not encrypted, return as-is (backwards compatibility).
			return $encrypted_value;
		}

		// Check if OpenSSL is available.
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			// OpenSSL not available, can't decrypt.
			// Return the value without the prefix (best effort).
			return substr( $encrypted_value, 4 );
		}

		// Remove the prefix.
		$data = substr( $encrypted_value, 4 );

		// Base64 decode.
		$combined = base64_decode( $data, true );

		if ( false === $combined ) {
			return false;
		}

		$key       = self::get_derived_key();
		$iv_length = openssl_cipher_iv_length( self::$method );

		// Extract IV and encrypted data.
		$iv        = substr( $combined, 0, $iv_length );
		$encrypted = substr( $combined, $iv_length );

		if ( false === $iv || false === $encrypted || strlen( $iv ) !== $iv_length ) {
			return false;
		}

		$decrypted = openssl_decrypt( $encrypted, self::$method, $key, OPENSSL_RAW_DATA, $iv );

		return $decrypted;
	}

	/**
	 * Check if a value is encrypted.
	 *
	 * @param string $value The value to check.
	 * @return bool True if the value is encrypted, false otherwise.
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, 'enc:' );
	}

	/**
	 * Checks if encryption is available on this system.
	 *
	 * @return bool True if encryption is available, false otherwise.
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Mask an API key for display purposes.
	 *
	 * Shows only the first and last few characters for identification.
	 *
	 * @param string $api_key The API key to mask.
	 * @param int    $visible_chars Number of characters to show at start and end (default 4).
	 * @return string The masked API key.
	 */
	public static function mask_api_key( ?string $api_key, int $visible_chars = 4 ): string {
		if ( empty( $api_key ) ) {
			return '';
		}

		$length = strlen( $api_key );

		if ( $length <= ( $visible_chars * 2 ) ) {
			return str_repeat( '*', $length );
		}

		$start  = substr( $api_key, 0, $visible_chars );
		$end    = substr( $api_key, -$visible_chars );
		$middle = str_repeat( '*', min( $length - ( $visible_chars * 2 ), 16 ) );

		return $start . $middle . $end;
	}
}
