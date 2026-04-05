<?php
/**
 * RepurposeStudio Class
 *
 * Generates multichannel repurposed assets from a single post.
 *
 * @package Antimanual
 * @since 2.3.0
 */

namespace Antimanual;

use Antimanual\AIProvider;
use Antimanual\AIResponseCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RepurposeStudio {
	/**
	 * Generate repurposed assets from a WordPress post.
	 *
	 * @param int    $post_id              Post ID.
	 * @param string $tone                 Tone description.
	 * @param array  $channels             Enabled output channels.
	 * @param array  $platforms            Social platforms to generate for.
	 * @param string $custom_instructions  Custom instructions from the user.
	 * @param string $target_audience      Target audience description.
	 * @param string $content_length       Content length preset (short, medium, long).
	 * @param string $output_language      Language code for output content.
	 * @param bool   $include_hashtags     Whether to include hashtags for social posts.
	 * @return array|\WP_Error
	 */
	public static function generate(
		int $post_id,
		string $tone = 'professional',
		array $channels = array( 'email', 'social', 'video', 'docs' ),
		array $platforms = array( 'X', 'LinkedIn', 'Facebook' ),
		string $custom_instructions = '',
		string $target_audience = '',
		string $content_length = 'medium',
		string $output_language = '',
		bool $include_hashtags = false
	) {
		if ( ! AIProvider::has_api_key() ) {
			return new \WP_Error( 'no_api_key', __( 'AI API Key is not configured.', 'antimanual' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'antimanual' ) );
		}

		$title   = sanitize_text_field( $post->post_title );
		$excerpt = wp_strip_all_tags( $post->post_excerpt );
		$content = wp_strip_all_tags( $post->post_content );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		if ( empty( $content ) ) {
			return new \WP_Error( 'empty_content', __( 'Post content is empty.', 'antimanual' ) );
		}

		$content = wp_trim_words( $content, 1200, '...' );
		$tone    = sanitize_text_field( $tone );
		$tone    = $tone ?: 'professional';

		// Sanitize channels.
		$valid_channels = array( 'email', 'social', 'video', 'docs' );
		$channels       = array_intersect( array_map( 'sanitize_text_field', $channels ), $valid_channels );

		if ( empty( $channels ) ) {
			$channels = $valid_channels;
		}

		// Sanitize platforms.
		$valid_platforms = array( 'X', 'LinkedIn', 'Facebook', 'Instagram', 'Threads', 'TikTok' );
		$platforms       = array_intersect( array_map( 'sanitize_text_field', $platforms ), $valid_platforms );

		if ( empty( $platforms ) ) {
			$platforms = array( 'X', 'LinkedIn', 'Facebook' );
		}

		$custom_instructions = sanitize_textarea_field( $custom_instructions );
		$target_audience     = sanitize_text_field( $target_audience );

		// Sanitize content length.
		$valid_lengths  = array( 'short', 'medium', 'long' );
		$content_length = in_array( $content_length, $valid_lengths, true ) ? $content_length : 'medium';

		// Sanitize output language.
		$output_language = sanitize_text_field( $output_language );

		// Sanitize hashtags flag.
		$include_hashtags = (bool) $include_hashtags;

		// Build length instructions.
		$length_instructions = self::get_length_instructions( $content_length );

		// Build channel-specific output format instructions.
		$output_sections = array();

		if ( in_array( 'email', $channels, true ) ) {
			$output_sections[] = '"email_copy": {
    "subject": "string",
    "preview_text": "string",
    "body": "string"
  }';
		}

		if ( in_array( 'social', $channels, true ) ) {
			$platform_entries = array_map(
				function ( $p ) use ( $include_hashtags ) {
					if ( $include_hashtags ) {
						return sprintf( '{ "platform": "%s", "content": "string", "hashtags": ["string"] }', $p );
					}
					return sprintf( '{ "platform": "%s", "content": "string" }', $p );
				},
				$platforms
			);
			$output_sections[] = '"social_posts": [
    ' . implode( ",\n    ", $platform_entries ) . '
  ]';
		}

		if ( in_array( 'video', $channels, true ) ) {
			$output_sections[] = '"video_scripts": [
    { "title": "string", "hook": "string", "script": "string", "cta": "string", "duration_seconds": 45 },
    { "title": "string", "hook": "string", "script": "string", "cta": "string", "duration_seconds": 60 }
  ]';
		}

		if ( in_array( 'docs', $channels, true ) ) {
			$output_sections[] = '"docs_snippets": [
    { "title": "Overview", "content": "string" },
    { "title": "Key Steps", "content": "string" },
    { "title": "Tips", "content": "string" },
    { "title": "FAQ", "content": "string" }
  ]';
		}

		$json_format = "{\n  " . implode( ",\n  ", $output_sections ) . "\n}";

		// Build additional context lines.
		$additional_context = '';

		if ( ! empty( $target_audience ) ) {
			$additional_context .= "\n- Target audience: {$target_audience}. Tailor the language and examples to this audience.";
		}

		if ( ! empty( $custom_instructions ) ) {
			$additional_context .= "\n- Additional instructions: {$custom_instructions}";
		}

		// Add content length instructions.
		$additional_context .= "\n- Content length: {$length_instructions}";

		// Add language instructions if specified.
		if ( ! empty( $output_language ) ) {
			$additional_context .= "\n- IMPORTANT: Write ALL output content in {$output_language}. Translate the original content into this language.";
		}

		// Add hashtag instructions.
		if ( $include_hashtags && in_array( 'social', $channels, true ) ) {
			$additional_context .= "\n- Include 3-5 relevant hashtags for each social post in the 'hashtags' array field. Hashtags should be popular and relevant to the content.";
		}

		$system_prompt = "You are a multichannel content repurposing assistant.\n\n"
			. "TASK:\n"
			. "Transform the given WordPress post into the requested output types.\n\n"
			. "RULES:\n"
			. "- Preserve the original meaning and avoid adding new facts.\n"
			. "- Keep the same language as the source post.\n"
			. "- Use a {$tone} tone.\n"
			. "- Keep outputs concise and ready to use.{$additional_context}\n"
			. "- Return ONLY valid JSON. No markdown, no code fences, no commentary.\n\n"
			. "OUTPUT JSON FORMAT:\n"
			. $json_format;

		$user_prompt = "POST TITLE:\n"
			. "{$title}\n\n"
			. "POST EXCERPT:\n"
			. "{$excerpt}\n\n"
			. "POST CONTENT:\n"
			. $content;

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
			array(
				'role'    => 'user',
				'content' => $user_prompt,
			),
		);

		$response = AIProvider::get_reply( $messages );

		if ( ! is_string( $response ) ) {
			return new \WP_Error(
				'ai_error',
				$response['error'] ?? __( 'Failed to generate repurposed content.', 'antimanual' )
			);
		}

		$cleaned = AIResponseCleaner::clean_json_response( $response );
		$data    = json_decode( $cleaned, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid AI response format.', 'antimanual' ) );
		}

		return self::normalize_response( $data, $channels, $include_hashtags );
	}

	/**
	 * Get human-readable length instructions for the AI prompt.
	 *
	 * @param string $content_length Content length preset.
	 * @return string Description of how long the content should be.
	 */
	private static function get_length_instructions( string $content_length ): string {
		switch ( $content_length ) {
			case 'short':
				return 'Keep all outputs SHORT and concise. Email body should be 2-3 short paragraphs. Social posts should use around half the platform character limit. Video scripts should target 30 seconds. Docs snippets should be 1-2 paragraphs each.';
			case 'long':
				return 'Make all outputs DETAILED and comprehensive. Email body should be a thorough 5-6 paragraphs with full details. Social posts should maximize the platform character limit. Video scripts should target 90+ seconds. Docs snippets should be thorough and detailed, 3-4 paragraphs each.';
			default:
				return 'Use a MEDIUM length for all outputs. Email body should be 3-4 solid paragraphs. Social posts should use a reasonable portion of the platform character limit. Video scripts should target 45-60 seconds. Docs snippets should be 2-3 paragraphs each.';
		}
	}

	/**
	 * Normalize and sanitize AI response.
	 *
	 * @param array $data             Raw response data.
	 * @param array $channels         Enabled channels.
	 * @param bool  $include_hashtags Whether hashtags were requested.
	 * @return array Sanitized response.
	 */
	private static function normalize_response( array $data, array $channels = array(), bool $include_hashtags = false ): array {
		$result = array();

		// Email copy.
		if ( empty( $channels ) || in_array( 'email', $channels, true ) ) {
			$email = is_array( $data['email_copy'] ?? null ) ? $data['email_copy'] : array();

			$result['email_copy'] = array(
				'subject'      => sanitize_text_field( $email['subject'] ?? '' ),
				'preview_text' => sanitize_text_field( $email['preview_text'] ?? '' ),
				'body'         => AIResponseCleaner::clean_content( $email['body'] ?? '' ),
			);
		}

		// Social posts.
		if ( empty( $channels ) || in_array( 'social', $channels, true ) ) {
			$social_posts = array();
			if ( isset( $data['social_posts'] ) && is_array( $data['social_posts'] ) ) {
				foreach ( $data['social_posts'] as $index => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$content = AIResponseCleaner::clean_content( $item['content'] ?? '' );
					if ( '' === $content ) {
						continue;
					}

					$post_data = array(
						/* translators: %d: social post number */
						'platform' => sanitize_text_field( $item['platform'] ?? sprintf( __( 'Social Post %d', 'antimanual' ), $index + 1 ) ),
						'content'  => $content,
					);

					// Include hashtags if requested.
					if ( $include_hashtags && isset( $item['hashtags'] ) && is_array( $item['hashtags'] ) ) {
						$post_data['hashtags'] = array_map( 'sanitize_text_field', array_slice( $item['hashtags'], 0, 10 ) );
					}

					$social_posts[] = $post_data;
				}
			}
			$result['social_posts'] = $social_posts;
		}

		// Video scripts.
		if ( empty( $channels ) || in_array( 'video', $channels, true ) ) {
			$video_scripts = array();
			if ( isset( $data['video_scripts'] ) && is_array( $data['video_scripts'] ) ) {
				foreach ( $data['video_scripts'] as $index => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$script = AIResponseCleaner::clean_content( $item['script'] ?? '' );
					if ( '' === $script ) {
						continue;
					}

					$video_scripts[] = array(
						/* translators: %d: video script number */
						'title'            => sanitize_text_field( $item['title'] ?? sprintf( __( 'Video Script %d', 'antimanual' ), $index + 1 ) ),
						'hook'             => AIResponseCleaner::clean_content( $item['hook'] ?? '' ),
						'script'           => $script,
						'cta'              => AIResponseCleaner::clean_content( $item['cta'] ?? '' ),
						'duration_seconds' => intval( $item['duration_seconds'] ?? 0 ),
					);
				}
			}
			$result['video_scripts'] = $video_scripts;
		}

		// Docs snippets.
		if ( empty( $channels ) || in_array( 'docs', $channels, true ) ) {
			$docs_snippets = array();
			if ( isset( $data['docs_snippets'] ) && is_array( $data['docs_snippets'] ) ) {
				foreach ( $data['docs_snippets'] as $index => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$content = AIResponseCleaner::clean_content( $item['content'] ?? '' );
					if ( '' === $content ) {
						continue;
					}

					$docs_snippets[] = array(
						/* translators: %d: snippet number */
						'title'   => sanitize_text_field( $item['title'] ?? sprintf( __( 'Snippet %d', 'antimanual' ), $index + 1 ) ),
						'content' => $content,
					);
				}
			}
			$result['docs_snippets'] = $docs_snippets;
		}

		return $result;
	}
}
