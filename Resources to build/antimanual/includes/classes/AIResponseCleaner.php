<?php
/**
 * AI Response Cleaner utility class.
 *
 * Provides methods to clean and sanitize AI-generated content,
 * removing unwanted characters, markdown formatting, and other artifacts.
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
 * AI Response Cleaner class.
 */
class AIResponseCleaner {

	/**
	 * Clean a raw AI response that should contain JSON.
	 *
	 * Removes markdown code fences, control characters, and extracts valid JSON.
	 *
	 * @param string $response The raw AI response.
	 * @return string The cleaned response ready for JSON parsing.
	 */
	public static function clean_json_response( string $response ): string {
		$cleaned = $response;

		// Remove UTF-8 BOM if present.
		$cleaned = preg_replace( '/^\xEF\xBB\xBF/', '', $cleaned );

		// Remove markdown code fences (```json, ```JSON, ```, etc.).
		// Pattern matches opening fence with optional language identifier.
		$cleaned = preg_replace( '/^```(?:json|JSON)?\s*\r?\n?/m', '', $cleaned );
		// Pattern matches closing fence.
		$cleaned = preg_replace( '/\r?\n?```\s*$/m', '', $cleaned );

		// Remove any text before the first { and after the last }.
		$first_brace = strpos( $cleaned, '{' );
		$last_brace  = strrpos( $cleaned, '}' );

		if ( false !== $first_brace && false !== $last_brace && $last_brace > $first_brace ) {
			$cleaned = substr( $cleaned, $first_brace, $last_brace - $first_brace + 1 );
		}

		// Remove control characters except for newlines and tabs within JSON strings.
		// This handles invisible characters that might break parsing.
		$cleaned = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned );

		// Trim whitespace.
		$cleaned = trim( $cleaned );

		return $cleaned;
	}

	/**
	 * Clean content field from unwanted characters.
	 *
	 * Removes zero-width characters, normalizes line breaks, and trims whitespace.
	 *
	 * @param string $content The content to clean.
	 * @return string The cleaned content.
	 */
	public static function clean_content( string $content ): string {
		$cleaned = $content;

		// Remove zero-width characters that might appear in AI responses.
		$cleaned = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $cleaned );

		// Remove smart quotes and replace with regular quotes.
		$cleaned = str_replace(
			array( "\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}" ),
			array( '"', '"', "'", "'" ),
			$cleaned
		);

		// Normalize line breaks to Unix style.
		$cleaned = str_replace( "\r\n", "\n", $cleaned );
		$cleaned = str_replace( "\r", "\n", $cleaned );

		// Remove excessive newlines (more than 2 consecutive).
		$cleaned = preg_replace( "/\n{3,}/", "\n\n", $cleaned );

		// Trim whitespace.
		$cleaned = trim( $cleaned );

		return $cleaned;
	}

	/**
	 * Clean slug field to ensure URL-safe format.
	 *
	 * @param string $slug The slug to clean.
	 * @return string The cleaned slug.
	 */
	public static function clean_slug( string $slug ): string {
		if ( empty( $slug ) ) {
			return '';
		}

		// Convert to lowercase.
		$cleaned = strtolower( $slug );

		// Remove any characters that aren't alphanumeric or hyphens.
		$cleaned = preg_replace( '/[^a-z0-9\-]/', '-', $cleaned );

		// Remove multiple consecutive hyphens.
		$cleaned = preg_replace( '/-+/', '-', $cleaned );

		// Trim hyphens from start and end.
		$cleaned = trim( $cleaned, '-' );

		return $cleaned;
	}

	/**
	 * Clean plain text response (for non-JSON AI responses like topic lists).
	 *
	 * @param string $response The AI response.
	 * @return string The cleaned response.
	 */
	public static function clean_plain_text( string $response ): string {
		$cleaned = $response;

		// Remove UTF-8 BOM if present.
		$cleaned = preg_replace( '/^\xEF\xBB\xBF/', '', $cleaned );

		// Remove markdown code fences.
		$cleaned = preg_replace( '/^```\w*\s*\r?\n?/m', '', $cleaned );
		$cleaned = preg_replace( '/\r?\n?```\s*$/m', '', $cleaned );

		// Remove zero-width characters.
		$cleaned = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $cleaned );

		// Remove control characters except newlines and tabs.
		$cleaned = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned );

		// Normalize line breaks.
		$cleaned = str_replace( "\r\n", "\n", $cleaned );
		$cleaned = str_replace( "\r", "\n", $cleaned );

		// Trim whitespace.
		$cleaned = trim( $cleaned );

		return $cleaned;
	}

	/**
	 * Clean Gutenberg block content.
	 *
	 * Ensures proper block formatting and removes invalid characters.
	 *
	 * @param string $content The Gutenberg block content.
	 * @return string The cleaned block content.
	 */
	public static function clean_gutenberg_content( string $content ): string {
		$cleaned = self::clean_content( $content );

		// Remove any markdown-style formatting that might have slipped through.
		// Remove markdown headers (## or ###) that aren't inside blocks.
		$cleaned = preg_replace( '/^#{1,6}\s+/m', '', $cleaned );

		// Remove markdown bullet points that aren't inside blocks.
		$cleaned = preg_replace( '/^[\*\-]\s+(?!\[)/m', '', $cleaned );

		// Ensure proper Gutenberg block comment format.
		// Fix malformed block comments that might have extra spaces or characters.
		$cleaned = preg_replace( '/<!--\s*wp:/', '<!-- wp:', $cleaned );
		$cleaned = preg_replace( '/\/wp:\s*-->/', '/wp: -->', $cleaned );

		return $cleaned;
	}
}
