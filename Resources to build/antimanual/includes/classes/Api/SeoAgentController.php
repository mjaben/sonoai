<?php
/**
 * SEO Agent API Controller.
 *
 * Handles SEO analysis requests by fetching page content and
 * using AI to evaluate SEO, performance, and accessibility.
 *
 * @package Antimanual
 * @since   1.6.0
 */

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;

/**
 * SEO Agent REST API controller.
 */
class SeoAgentController {
	/**
	 * Cache prefix for full AI audit responses.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'atml_seo_agent_cache_';

	/**
	 * Cache lifetime for full AI audits (seconds).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Post meta key: enforce loading="lazy" in rendered post content.
	 *
	 * @var string
	 */
	private const META_FORCE_LAZY_LOADING = '_atml_seo_force_lazy_loading';

	/**
	 * Post meta key: enforce rel="noopener noreferrer" on target="_blank" links.
	 *
	 * @var string
	 */
	private const META_FORCE_NOOPENER = '_atml_seo_force_noopener';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'the_content', array( $this, 'apply_runtime_content_auto_fixes' ), 99 );
	}

	/**
	 * Register REST routes for the SEO Agent.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace ) {
		register_rest_route(
			$namespace,
			'/seo-agent/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/seo-agent/auto-fix',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'auto_fix' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		register_rest_route(
			$namespace,
			'/seo-agent/bulk-audit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_audit' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 300,
			)
		);

		register_rest_route(
			$namespace,
			'/seo-agent/bulk-auto-fix',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_auto_fix' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 300,
			)
		);

		register_rest_route(
			$namespace,
			'/seo-agent/site-audit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'site_audit' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 300,
			)
		);
	}

	/**
	 * Apply runtime content fixes for posts that opted into safe auto-fixes.
	 *
	 * This avoids writing attributes directly into block markup, which can
	 * trigger Gutenberg validation mismatch warnings.
	 *
	 * @param string $content The rendered post content.
	 * @return string
	 */
	public function apply_runtime_content_auto_fixes( string $content ): string {
		if ( is_admin() || '' === trim( $content ) ) {
			return $content;
		}

		$post_id = (int) get_the_ID();
		global $post;
		if ( $post_id <= 0 && $post instanceof \WP_Post ) {
			$post_id = (int) $post->ID;
		}
		if ( $post_id <= 0 ) {
			$post_id = get_queried_object_id();
		}

		if ( $post_id <= 0 ) {
			return $content;
		}

		$force_lazy     = '1' === get_post_meta( $post_id, self::META_FORCE_LAZY_LOADING, true );
		$force_noopener = '1' === get_post_meta( $post_id, self::META_FORCE_NOOPENER, true );

		if ( ! $force_lazy && ! $force_noopener ) {
			return $content;
		}

		$updated = $content;
		if ( $force_lazy ) {
			$updated = $this->force_lazy_loading_attributes( $updated );
		}
		if ( $force_noopener ) {
			$updated = $this->force_noopener_attributes( $updated );
		}

		return $updated;
	}

	/**
	 * Analyze a URL for SEO issues.
	 *
	 * Fetches the page HTML, sends it to the AI provider for
	 * comprehensive SEO analysis, and returns structured results.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function analyze( $request ) {
		// Enforce SEO Plus plan requirement.
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The SEO Agent requires the SEO Plus plan. Please upgrade to access this feature.', 'antimanual' ),
				),
				403
			);
		}

		$payload = json_decode( $request->get_body(), true );
		$payload = is_array( $payload ) ? $payload : array();

		$url           = esc_url_raw( trim( $payload['url'] ?? '' ) );
		$analysis_mode = sanitize_key( $payload['mode'] ?? 'full' );
		$analysis_mode = in_array( $analysis_mode, array( 'quick', 'full' ), true ) ? $analysis_mode : 'full';
		$force_refresh = ! empty( $payload['force_refresh'] );

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Please provide a valid URL.', 'antimanual' ),
				)
			);
		}

		// Fetch the page HTML content.
		// For local posts that aren't publicly accessible (draft, pending,
		// private, future), build HTML directly from the post object to
		// avoid the 404 that wp_remote_get would return.
		$post_id       = intval( $payload['post_id'] ?? 0 );
		$local_post_id = $post_id > 0 ? $post_id : url_to_postid( $url );
		$local_post    = $local_post_id > 0 ? get_post( $local_post_id ) : null;

		if ( $local_post instanceof \WP_Post && 'publish' !== $local_post->post_status ) {
			$html = $this->build_html_from_post( $local_post, $url );
		} else {
			$html = $this->fetch_page_html( $url );
		}

		if ( is_wp_error( $html ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $html->get_error_message(),
				)
			);
		}

		$timestamp = gmdate( 'c' );

		// Quick mode skips AI usage and runs deterministic HTML checks only.
		if ( 'quick' === $analysis_mode ) {
			$result = $this->build_quick_audit( $url, $html );

			if ( is_wp_error( $result ) ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'message' => $result->get_error_message(),
					)
				);
			}

			$result['analysis_mode'] = 'quick';
			$result['cached']        = false;
			$result['analyzed_at']   = $timestamp;

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
				)
			);
		}

		$cache_key = $this->get_cache_key( $url );
		if ( ! $force_refresh ) {
			$cached_result = get_transient( $cache_key );
			if ( is_array( $cached_result ) ) {
				$cached_result['analysis_mode'] = 'full';
				$cached_result['cached']        = true;

				if ( empty( $cached_result['analyzed_at'] ) ) {
					$cached_result['analyzed_at'] = $timestamp;
				}

				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $cached_result,
					)
				);
			}
		}

		// Strip inline script and style content before truncating so that
		// body content (e.g. H1 tags) is not cut off by the token limit.
		// ld+json scripts are kept because they carry structured-data info.
		$html_for_ai = $html;
		$strip_dom   = new \DOMDocument();
		$strip_prev  = libxml_use_internal_errors( true );
		$strip_loaded = $strip_dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $strip_prev );

		if ( $strip_loaded ) {
			$strip_xpath = new \DOMXPath( $strip_dom );
			// XPath translate() is used for case-insensitive matching (XPath 1.0 has no lower-case()).
			$lc_alphabet    = 'abcdefghijklmnopqrstuvwxyz';
			$uc_alphabet    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
			$non_ld_scripts = $strip_xpath->query(
				"//script[not(contains(translate(@type,'{$uc_alphabet}','{$lc_alphabet}'),'application/ld+json'))]"
			);
			if ( $non_ld_scripts instanceof \DOMNodeList ) {
				foreach ( iterator_to_array( $non_ld_scripts ) as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}

			$style_nodes = $strip_xpath->query( '//style' );
			if ( $style_nodes instanceof \DOMNodeList ) {
				foreach ( iterator_to_array( $style_nodes ) as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}

			$html_for_ai = $strip_dom->saveHTML() ?: $html;
		}

		// Truncate HTML to avoid exceeding token limits.
		$max_chars    = 15000;
		$html_excerpt = mb_strlen( $html_for_ai ) > $max_chars
			? mb_substr( $html_for_ai, 0, $max_chars ) . "\n<!-- TRUNCATED -->"
			: $html_for_ai;

		// Build the AI prompt.
		$system_prompt = $this->build_system_prompt();
		$user_prompt   = $this->build_user_prompt( $url, $html_excerpt );

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

		$reply_data = AIProvider::get_reply( $messages );

		if ( isset( $reply_data['error'] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $reply_data['error'],
				)
			);
		}

		$reply = is_array( $reply_data ) && isset( $reply_data['reply'] ) ? $reply_data['reply'] : (string) $reply_data;

		if ( empty( $reply ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'AI analysis failed.', 'antimanual' ),
				)
			);
		}

		// Parse the JSON response from the AI.
		$result = $this->parse_ai_response( $reply, $url );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		$result['analysis_mode'] = 'full';
		$result['cached']        = false;
		$result['analyzed_at']   = $timestamp;

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Automatically fix common on-page SEO issues for a local post/page.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function auto_fix( $request ) {
		// Enforce SEO Plus plan requirement.
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The SEO Agent requires the SEO Plus plan. Please upgrade to access this feature.', 'antimanual' ),
				),
				403
			);
		}

		$payload = json_decode( $request->get_body(), true );
		$payload = is_array( $payload ) ? $payload : array();

		$post_id = intval( $payload['post_id'] ?? 0 );
		$url     = esc_url_raw( trim( $payload['url'] ?? '' ) );

		if ( $post_id <= 0 && ! empty( $url ) ) {
			$post_id = url_to_postid( $url );
		}

		if ( $post_id <= 0 ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'A valid internal post/page is required for auto-fix.', 'antimanual' ),
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Selected post could not be found.', 'antimanual' ),
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'You are not allowed to edit this post.', 'antimanual' ),
				)
			);
		}

		$applied_fixes     = array();
		$skipped_fixes     = array();
		$title_updated     = false;
		$meta_updated      = false;
		$content_updated   = false;
		$meta_keys_updated = 0;

		$current_title       = trim( (string) $post->post_title );
		$seo_meta_desc       = $this->get_seo_meta_description( $post );
		$has_seo_meta        = '' !== $seo_meta_desc;
		$current_description = $has_seo_meta ? $seo_meta_desc : trim( (string) $post->post_excerpt );
		$current_content     = (string) $post->post_content;
		$current_slug        = (string) $post->post_name;

		// Truncate content for the AI prompt to stay within token limits.
		$max_content_chars = 8000;
		$content_excerpt   = mb_strlen( $current_content ) > $max_content_chars
			? mb_substr( $current_content, 0, $max_content_chars ) . "\n<!-- TRUNCATED -->"
			: $current_content;

		$ai_prompt = "You are an expert SEO optimizer. Analyze the following WordPress post and fix its SEO issues.\n\n"
			. "Current Title: {$current_title}\n"
			. "Current Meta Description: {$current_description}\n"
			. "Current URL Slug: {$current_slug}\n\n"
			. "Post Content:\n"
			. "```html\n"
			. $content_excerpt
			. "\n```\n\n"
			. "You MUST respond with ONLY valid JSON (no markdown, no code fences) in this exact structure:\n"
			. "{\n"
			. "  \"focus_keyword\": \"<the single most relevant keyword or key phrase for this content>\",\n"
			. "  \"title\": \"<optimized SEO title, 50-60 characters, include the focus keyword near the beginning>\",\n"
			. "  \"meta_description\": \"<optimized meta description, 150-160 characters, compelling, include focus keyword naturally>\",\n"
			. "  \"slug\": \"<optimized URL slug, lowercase, hyphen-separated, 3-5 words, include focus keyword>\",\n"
			. "  \"content_fixes\": {\n"
			. "    \"alt_texts\": [{\"selector\": \"<img identifier>\", \"alt\": \"<descriptive alt text>\"}],\n"
			. "    \"summary\": \"<brief summary of content improvements made>\"\n"
			. "  },\n"
			. "  \"changes_made\": [\"<list of specific changes>\"]\n"
			. "}\n\n"
			. "Rules:\n"
			. "- Identify the primary focus keyword from the content and use it consistently.\n"
			. "- If the title is already well-optimized, return it unchanged.\n"
			. "- If the meta description is already good, return it unchanged.\n"
			. "- If the slug is already clean and keyword-rich, return it unchanged.\n"
			. "- The slug must be lowercase, use hyphens, contain no stop words, and be 3-5 words max.\n"
			. "- Generate meaningful, descriptive alt text for images that lack them.\n"
			. "- Keep the same language as the original content.\n"
			. "- Make changes that genuinely improve SEO, don't change things unnecessarily.";

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an SEO optimization expert. You fix on-page SEO issues for WordPress posts. Always respond with valid JSON only.',
			),
			array(
				'role'    => 'user',
				'content' => $ai_prompt,
			),
		);

		$reply_data = AIProvider::get_reply( $messages );

		if ( isset( $reply_data['error'] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $reply_data['error'],
				)
			);
		}

		$reply = is_array( $reply_data ) && isset( $reply_data['reply'] ) ? $reply_data['reply'] : (string) $reply_data;

		if ( empty( $reply ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'AI auto-fix failed. Please try again.', 'antimanual' ),
				)
			);
		}

		// Parse AI response.
		$reply = preg_replace( '/^```(?:json)?\s*/i', '', trim( $reply ) );
		$reply = preg_replace( '/\s*```$/', '', $reply );
		$ai_data = json_decode( $reply, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $ai_data ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Failed to parse AI fix response. Please try again.', 'antimanual' ),
				)
			);
		}

		$update_payload = array( 'ID' => $post_id );
		$slug_updated   = false;

		// Extract focus keyword identified by AI.
		$focus_keyword = sanitize_text_field( $ai_data['focus_keyword'] ?? '' );

		// Apply title fix.
		$ai_title = sanitize_text_field( $ai_data['title'] ?? '' );
		if ( ! empty( $ai_title ) && $ai_title !== $current_title ) {
			$update_payload['post_title'] = $ai_title;
			$title_updated                = true;
			$applied_fixes[]              = __( 'Title tag optimized by AI.', 'antimanual' );
		} else {
			$skipped_fixes[] = __( 'Title tag did not need changes.', 'antimanual' );
		}

		// Apply meta description fix.
		$ai_description = sanitize_text_field( $ai_data['meta_description'] ?? '' );
		if ( ! empty( $ai_description ) && $ai_description !== $current_description ) {
			if ( $ai_description !== trim( (string) $post->post_excerpt ) ) {
				$update_payload['post_excerpt'] = $ai_description;
			}
			$meta_updated    = true;
			$applied_fixes[] = __( 'Meta description optimized by AI.', 'antimanual' );
		} else {
			$skipped_fixes[] = __( 'Meta description did not need changes.', 'antimanual' );
		}

		// Apply slug fix.
		$ai_slug = sanitize_title( $ai_data['slug'] ?? '' );
		if ( ! empty( $ai_slug ) && $ai_slug !== $current_slug ) {
			$update_payload['post_name'] = $ai_slug;
			$slug_updated                = true;
			$applied_fixes[]             = __( 'URL slug optimized by AI.', 'antimanual' );
		} else {
			$skipped_fixes[] = __( 'URL slug did not need changes.', 'antimanual' );
		}

		// Apply content fixes (alt texts, lazy loading, noopener).
		$content_changes_applied = false;
		$content_for_runtime     = $current_content;

		// Apply AI-suggested alt texts.
		$alt_texts = $ai_data['content_fixes']['alt_texts'] ?? array();
		if ( ! empty( $alt_texts ) && is_array( $alt_texts ) ) {
			$modified_content = $current_content;
			$alt_count        = 0;
			foreach ( $alt_texts as $alt_fix ) {
				if ( empty( $alt_fix['alt'] ) ) {
					continue;
				}
				$alt_value = esc_attr( sanitize_text_field( $alt_fix['alt'] ) );
				// Add alt to images missing it.
				$modified_content = preg_replace(
					'/<img([^>]*?)alt\s*=\s*["\'][\s]*["\']([^>]*?)>/i',
					'<img$1alt="' . $alt_value . '"$2>',
					$modified_content,
					1,
					$count
				);
				$alt_count += $count;
			}
			if ( $alt_count > 0 ) {
				$update_payload['post_content'] = $modified_content;
				$content_updated                = true;
				$content_changes_applied        = true;
				$content_for_runtime            = $modified_content;
				$applied_fixes[]                = sprintf(
					/* translators: %d: number of alt texts added */
					__( '%d image alt text(s) added by AI.', 'antimanual' ),
					$alt_count
				);
			}
		}

		// Apply basic content fixes (lazy loading, noopener) via existing helpers.
		$content_result = $this->apply_content_auto_fixes( $content_for_runtime, $post_id );
		if ( ! empty( $content_result['changed'] ) ) {
			$update_payload['post_content'] = $content_result['content'];
			$content_updated                = true;
			$content_changes_applied        = true;
			$content_for_runtime            = (string) $content_result['content'];
			$applied_fixes[]                = __( 'Image and link attributes optimized.', 'antimanual' );
		}

		if ( count( $update_payload ) > 1 ) {
			$updated = wp_update_post( $update_payload, true );
			if ( is_wp_error( $updated ) ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'message' => $updated->get_error_message(),
					)
				);
			}
		}

		// Runtime fixes for lazy loading and noopener.
		$runtime_scan_content = $this->render_post_content_for_scan( $post, $content_for_runtime );

		$lazy_candidates = $this->count_missing_lazy_attributes( $runtime_scan_content );
		if ( $lazy_candidates > 0 && $this->enable_runtime_content_fix_flag( $post_id, self::META_FORCE_LAZY_LOADING ) ) {
			$content_updated         = true;
			$content_changes_applied = true;
			$applied_fixes[]         = __( 'Lazy-loading enforcement enabled.', 'antimanual' );
		}

		$noopener_candidates = $this->count_missing_noopener_attributes( $runtime_scan_content );
		if ( $noopener_candidates > 0 && $this->enable_runtime_content_fix_flag( $post_id, self::META_FORCE_NOOPENER ) ) {
			$content_updated         = true;
			$content_changes_applied = true;
			$applied_fixes[]         = __( 'Link security attributes added.', 'antimanual' );
		}

		if ( ! $content_changes_applied ) {
			$skipped_fixes[] = __( 'No content fixes needed.', 'antimanual' );
		}

		// Update SEO plugin meta keys.
		if ( $title_updated || $meta_updated || ! empty( $focus_keyword ) ) {
			$meta_keys_updated = $this->update_existing_seo_meta_keys(
				$post_id,
				$title_updated ? $ai_title : '',
				$meta_updated ? $ai_description : '',
				$focus_keyword
			);
			if ( ! empty( $focus_keyword ) ) {
				$applied_fixes[] = __( 'Focus keyword set for SEO plugins.', 'antimanual' );
			}
		}

		$post_url = get_permalink( $post_id ) ?: $url;
		$this->clear_cached_analyses( array_filter( array( $url, $post_url ) ) );

		if ( empty( $applied_fixes ) ) {
			$applied_fixes[] = __( 'No automatic fixes were necessary for this content.', 'antimanual' );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'post_id'           => $post_id,
					'url'               => esc_url( $post_url ),
					'focus_keyword'     => $focus_keyword,
					'applied_fixes'     => array_values( array_unique( array_map( 'sanitize_text_field', $applied_fixes ) ) ),
					'skipped_fixes'     => array_values( array_unique( array_map( 'sanitize_text_field', $skipped_fixes ) ) ),
					'updated_fields'    => array(
						'title'            => (bool) $title_updated,
						'meta_description' => (bool) $meta_updated,
						'slug'             => (bool) $slug_updated,
						'content'          => (bool) $content_updated,
					),
					'content_changes'   => array(
						'alt_added'      => count( $alt_texts ),
						'lazy_added'     => $lazy_candidates ?? 0,
						'noopener_added' => $noopener_candidates ?? 0,
					),
					'meta_keys_updated' => $meta_keys_updated,
				),
			)
		);
	}

	/**
	 * Run quick SEO audits on multiple posts at once.
	 *
	 * Accepts an array of post IDs and returns per-post scores
	 * and issue summaries. Only quick (no-AI) audits are used
	 * to keep bulk operations fast and cost-free.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function bulk_audit( $request ) {
		// Enforce SEO Plus plan requirement.
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The SEO Agent requires the SEO Plus plan. Please upgrade to access this feature.', 'antimanual' ),
				),
				403
			);
		}

		$payload  = json_decode( $request->get_body(), true );
		$payload  = is_array( $payload ) ? $payload : array();
		$post_ids = isset( $payload['post_ids'] ) && is_array( $payload['post_ids'] )
			? array_map( 'intval', $payload['post_ids'] )
			: array();

		$analysis_mode = sanitize_key( $payload['mode'] ?? 'quick' );
		$analysis_mode = in_array( $analysis_mode, array( 'quick', 'full' ), true ) ? $analysis_mode : 'quick';

		$post_ids = array_values( array_unique( array_filter( $post_ids, fn( $id ) => $id > 0 ) ) );

		if ( empty( $post_ids ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'No valid post IDs provided.', 'antimanual' ),
				)
			);
		}

		if ( count( $post_ids ) > 50 ) {
			$post_ids = array_slice( $post_ids, 0, 50 );
		}

		$results   = array();
		$timestamp = gmdate( 'c' );

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				$results[] = array(
					'post_id' => $post_id,
					'status'  => 'error',
					'message' => __( 'Post not found.', 'antimanual' ),
				);
				continue;
			}

			$url = get_permalink( $post_id );
			if ( empty( $url ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => __( 'Could not resolve URL for this post.', 'antimanual' ),
				);
				continue;
			}

			// Build HTML directly from the post to avoid loopback HTTP requests
			// that can time out when PHP workers are limited.
			$html = $this->build_html_from_post( $post, $url );
			if ( is_wp_error( $html ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'url'     => $url,
					'status'  => 'error',
					'message' => $html->get_error_message(),
				);
				continue;
			}

			$audit = $this->run_single_audit( $url, $html, $analysis_mode );
			if ( is_wp_error( $audit ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'url'     => $url,
					'status'  => 'error',
					'message' => $audit->get_error_message(),
				);
				continue;
			}

			$issue_counts = array(
				'critical' => 0,
				'warning'  => 0,
				'info'     => 0,
				'passed'   => 0,
			);
			foreach ( $audit['issues'] as $issue ) {
				$sev = $issue['severity'] ?? 'info';
				if ( isset( $issue_counts[ $sev ] ) ) {
					++$issue_counts[ $sev ];
				}
			}

			$results[] = array(
				'post_id'             => $post_id,
				'title'               => $post->post_title,
				'url'                 => esc_url( $url ),
				'edit_link'           => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'status'              => 'success',
				'seo_score'           => $audit['seo_score'],
				'performance_score'   => $audit['performance_score'],
				'accessibility_score' => $audit['accessibility_score'],
				'issue_counts'        => $issue_counts,
				'issues'              => $audit['issues'],
				'top_issue'           => ! empty( $audit['issues'] ) ? $audit['issues'][0]['title'] : '',
				'analyzed_at'         => $timestamp,
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'results'     => $results,
					'total'       => count( $results ),
					'analyzed_at' => $timestamp,
				),
			)
		);
	}

	/**
	 * Run a full site SEO audit based on filtering criteria.
	 *
	 * Accepts post_types (array), post_statuses (array), and check_duplicates
	 * flag. Queries matching posts directly and runs quick audits
	 * without requiring the user to load / select posts first.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function site_audit( $request ) {
		// Enforce SEO Plus plan requirement.
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The SEO Agent requires the SEO Plus plan. Please upgrade to access this feature.', 'antimanual' ),
				),
				403
			);
		}

		$payload = json_decode( $request->get_body(), true );
		$payload = is_array( $payload ) ? $payload : array();

		$raw_types = isset( $payload['post_types'] ) && is_array( $payload['post_types'] )
			? $payload['post_types']
			: array( 'post' );

		$post_types = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $raw_types )
				)
			)
		);

		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		}

		$raw_statuses = isset( $payload['post_statuses'] ) && is_array( $payload['post_statuses'] )
			? $payload['post_statuses']
			: array( 'publish' );

		$analysis_mode = sanitize_key( $payload['mode'] ?? 'quick' );
		$analysis_mode = in_array( $analysis_mode, array( 'quick', 'full' ), true ) ? $analysis_mode : 'quick';

		$check_duplicates = ! empty( $payload['check_duplicates'] );

		// Map frontend status names to WP query statuses.
		$status_map = array(
			'publish'            => 'publish',
			'draft'              => 'draft',
			'private'            => 'private',
			'password-protected' => 'publish', // Password-protected posts are published with a password.
		);

		$wp_statuses = array();
		$need_password_filter = false;
		foreach ( $raw_statuses as $status ) {
			$status = sanitize_key( $status );
			if ( 'password-protected' === $status ) {
				$need_password_filter = true;
			}
			if ( isset( $status_map[ $status ] ) ) {
				$wp_statuses[] = $status_map[ $status ];
			}
		}

		if ( empty( $wp_statuses ) ) {
			$wp_statuses = array( 'publish' );
		}

		$wp_statuses = array_unique( $wp_statuses );

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => $wp_statuses,
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// If only password-protected is selected, filter to posts with passwords.
		if ( $need_password_filter && count( $raw_statuses ) === 1 ) {
			$query_args['has_password'] = true;
		}

		$query = new \WP_Query( $query_args );
		$posts = $query->posts;

		if ( empty( $posts ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'results'     => array(),
						'duplicates'  => array(),
						'total'       => 0,
						'analyzed_at' => gmdate( 'c' ),
					),
				)
			);
		}

		// If only password-protected is checked alongside others, filter post-query.
		if ( $need_password_filter && count( $raw_statuses ) > 1 ) {
			// Keep all matched posts (password-protected are included because 'publish' is in the status list).
		} elseif ( $need_password_filter && count( $raw_statuses ) === 1 ) {
			// Already filtered via has_password.
		}

		$results   = array();
		$timestamp = gmdate( 'c' );

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$url = get_permalink( $post->ID );
			if ( empty( $url ) ) {
				$results[] = array(
					'post_id' => $post->ID,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => __( 'Could not resolve URL for this post.', 'antimanual' ),
				);
				continue;
			}

			// Build HTML directly from the post to avoid loopback HTTP requests
			// that can time out when PHP workers are limited.
			$html = $this->build_html_from_post( $post, $url );
			if ( is_wp_error( $html ) ) {
				$results[] = array(
					'post_id' => $post->ID,
					'title'   => $post->post_title,
					'url'     => $url,
					'status'  => 'error',
					'message' => $html->get_error_message(),
				);
				continue;
			}

			$audit = $this->run_single_audit( $url, $html, $analysis_mode );
			if ( is_wp_error( $audit ) ) {
				$results[] = array(
					'post_id' => $post->ID,
					'title'   => $post->post_title,
					'url'     => $url,
					'status'  => 'error',
					'message' => $audit->get_error_message(),
				);
				continue;
			}

			$issue_counts = array(
				'critical' => 0,
				'warning'  => 0,
				'info'     => 0,
				'passed'   => 0,
			);
			foreach ( $audit['issues'] as $issue ) {
				$sev = $issue['severity'] ?? 'info';
				if ( isset( $issue_counts[ $sev ] ) ) {
					++$issue_counts[ $sev ];
				}
			}

			$results[] = array(
				'post_id'             => $post->ID,
				'title'               => $post->post_title,
				'url'                 => esc_url( $url ),
				'edit_link'           => get_edit_post_link( $post->ID, 'raw' ) ?: '',
				'post_status'         => $post->post_status,
				'status'              => 'success',
				'seo_score'           => $audit['seo_score'],
				'performance_score'   => $audit['performance_score'],
				'accessibility_score' => $audit['accessibility_score'],
				'issue_counts'        => $issue_counts,
				'issues'              => $audit['issues'],
				'top_issue'           => ! empty( $audit['issues'] ) ? $audit['issues'][0]['title'] : '',
				'analyzed_at'         => $timestamp,
			);
		}

		// Duplicate content detection.
		$duplicates = array();
		if ( $check_duplicates && ! empty( $posts ) ) {
			$duplicates = $this->detect_duplicate_content( $posts );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'results'     => $results,
					'duplicates'  => $duplicates,
					'total'       => count( $results ),
					'analyzed_at' => $timestamp,
				),
			)
		);
	}

	/**
	 * Detect duplicate content across a set of posts.
	 *
	 * Checks for duplicate titles, meta descriptions, and slugs.
	 * Returns groups of posts that share the same value.
	 *
	 * @param \WP_Post[] $posts Array of post objects.
	 * @return array Array of duplicate groups.
	 */
	private function detect_duplicate_content( array $posts ): array {
		$title_map       = array();
		$description_map = array();
		$slug_map        = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$post_data = array(
				'post_id'   => $post->ID,
				'title'     => $post->post_title,
				'url'       => get_permalink( $post->ID ) ?: '',
				'edit_link' => get_edit_post_link( $post->ID, 'raw' ) ?: '',
			);

			// Check titles.
			$title_key = mb_strtolower( trim( $post->post_title ) );
			if ( '' !== $title_key ) {
				$title_hash = md5( $title_key );
				if ( ! isset( $title_map[ $title_hash ] ) ) {
					$title_map[ $title_hash ] = array();
				}
				$title_map[ $title_hash ][] = array_merge( $post_data, array( 'value' => $post->post_title ) );
			}

			// Check meta descriptions.
			$seo_desc = $this->get_seo_meta_description( $post );
			$desc     = '' !== $seo_desc ? $seo_desc : trim( (string) $post->post_excerpt );
			if ( '' !== $desc ) {
				$desc_key  = mb_strtolower( $desc );
				$desc_hash = md5( $desc_key );
				if ( ! isset( $description_map[ $desc_hash ] ) ) {
					$description_map[ $desc_hash ] = array();
				}
				$description_map[ $desc_hash ][] = array_merge( $post_data, array( 'value' => $desc ) );
			}

			// Check slugs.
			$slug = $post->post_name;
			if ( '' !== $slug ) {
				$slug_key = mb_strtolower( $slug );
				if ( ! isset( $slug_map[ $slug_key ] ) ) {
					$slug_map[ $slug_key ] = array();
				}
				$slug_map[ $slug_key ][] = array_merge( $post_data, array( 'value' => $slug ) );
			}
		}

		$duplicates = array();

		foreach ( $title_map as $hash => $group ) {
			if ( count( $group ) > 1 ) {
				$duplicates[] = array(
					'title_hash'    => $hash,
					'matched_field' => 'title',
					'posts'         => $group,
				);
			}
		}

		foreach ( $description_map as $hash => $group ) {
			if ( count( $group ) > 1 ) {
				$duplicates[] = array(
					'title_hash'    => $hash,
					'matched_field' => 'meta_description',
					'posts'         => $group,
				);
			}
		}

		foreach ( $slug_map as $key => $group ) {
			if ( count( $group ) > 1 ) {
				$duplicates[] = array(
					'title_hash'    => md5( $key ),
					'matched_field' => 'slug',
					'posts'         => $group,
				);
			}
		}

		return $duplicates;
	}

	/**
	 * Apply auto-fix to multiple posts at once.
	 *
	 * Accepts an array of post IDs and applies the same fixes
	 * as the single auto_fix endpoint to each one.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function bulk_auto_fix( $request ) {
		// Enforce SEO Plus plan requirement.
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The SEO Agent requires the SEO Plus plan. Please upgrade to access this feature.', 'antimanual' ),
				),
				403
			);
		}

		$payload  = json_decode( $request->get_body(), true );
		$payload  = is_array( $payload ) ? $payload : array();
		$post_ids = isset( $payload['post_ids'] ) && is_array( $payload['post_ids'] )
			? array_map( 'intval', $payload['post_ids'] )
			: array();

		$post_ids = array_values( array_unique( array_filter( $post_ids, fn( $id ) => $id > 0 ) ) );

		if ( empty( $post_ids ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'No valid post IDs provided.', 'antimanual' ),
				)
			);
		}

		if ( count( $post_ids ) > 50 ) {
			$post_ids = array_slice( $post_ids, 0, 50 );
		}

		$results        = array();
		$total_fixed    = 0;
		$total_skipped  = 0;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'status'  => 'error',
					'message' => __( 'Post not found or no permission.', 'antimanual' ),
				);
				continue;
			}

			$applied_fixes = array();
			$skipped_fixes = array();
			$title_updated     = false;
			$meta_updated      = false;
			$content_updated   = false;
			$meta_keys_updated = 0;

			$current_title       = trim( (string) $post->post_title );
			$seo_meta_desc       = $this->get_seo_meta_description( $post );
			$has_seo_meta        = '' !== $seo_meta_desc;
			$current_description = $has_seo_meta ? $seo_meta_desc : trim( (string) $post->post_excerpt );
			$current_content     = (string) $post->post_content;
			$current_slug        = (string) $post->post_name;

			// Truncate content for AI prompt.
			$max_content_chars = 8000;
			$content_excerpt   = mb_strlen( $current_content ) > $max_content_chars
				? mb_substr( $current_content, 0, $max_content_chars ) . "\n<!-- TRUNCATED -->"
				: $current_content;

			$ai_prompt = "You are an expert SEO optimizer. Analyze this WordPress post and fix its SEO issues.\n\n"
				. "Current Title: {$current_title}\n"
				. "Current Meta Description: {$current_description}\n"
				. "Current URL Slug: {$current_slug}\n\n"
				. "Post Content:\n"
				. "```html\n"
				. $content_excerpt
				. "\n```\n\n"
				. "Respond with ONLY valid JSON (no markdown, no code fences):\n"
				. "{\n"
				. "  \"focus_keyword\": \"<the single most relevant keyword or key phrase>\",\n"
				. "  \"title\": \"<optimized SEO title, 50-60 characters>\",\n"
				. "  \"meta_description\": \"<optimized meta description, 150-160 characters>\",\n"
				. "  \"slug\": \"<optimized URL slug, lowercase, hyphen-separated, 3-5 words>\",\n"
				. "  \"changes_made\": [\"<list of changes>\"]\n"
				. "}\n\n"
				. "Rules:\n"
				. "- Identify the primary focus keyword and use it in title, description, and slug.\n"
				. "- If the title is already well-optimized, return it unchanged.\n"
				. "- If the meta description is already good, return it unchanged.\n"
				. "- If the slug is already clean and keyword-rich, return it unchanged.\n"
				. "- Keep the same language as the original content.";

			$messages = array(
				array(
					'role'    => 'system',
					'content' => 'You are an SEO optimization expert. Fix on-page SEO issues. Respond with valid JSON only.',
				),
				array(
					'role'    => 'user',
					'content' => $ai_prompt,
				),
			);

			$reply_data = AIProvider::get_reply( $messages );

			if ( isset( $reply_data['error'] ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => $reply_data['error'],
				);
				continue;
			}

			$reply = is_array( $reply_data ) && isset( $reply_data['reply'] ) ? $reply_data['reply'] : (string) $reply_data;

			if ( empty( $reply ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => __( 'AI auto-fix failed.', 'antimanual' ),
				);
				continue;
			}

			// Parse AI response.
			$reply   = preg_replace( '/^```(?:json)?\s*/i', '', trim( $reply ) );
			$reply   = preg_replace( '/\s*```$/', '', $reply );
			$ai_data = json_decode( $reply, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $ai_data ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => __( 'Failed to parse AI response.', 'antimanual' ),
				);
				continue;
			}

			$update_payload = array( 'ID' => $post_id );

			// Apply title fix.
			$ai_title = sanitize_text_field( $ai_data['title'] ?? '' );
			if ( ! empty( $ai_title ) && $ai_title !== $current_title ) {
				$update_payload['post_title'] = $ai_title;
				$title_updated                = true;
				$applied_fixes[]              = __( 'Title tag optimized by AI.', 'antimanual' );
			} else {
				$skipped_fixes[] = __( 'Title tag did not need changes.', 'antimanual' );
			}

			// Apply meta description fix.
			$ai_description = sanitize_text_field( $ai_data['meta_description'] ?? '' );
			if ( ! empty( $ai_description ) && $ai_description !== $current_description ) {
				if ( $ai_description !== trim( (string) $post->post_excerpt ) ) {
					$update_payload['post_excerpt'] = $ai_description;
				}
				$meta_updated    = true;
				$applied_fixes[] = __( 'Meta description optimized by AI.', 'antimanual' );
			} else {
				$skipped_fixes[] = __( 'Meta description did not need changes.', 'antimanual' );
			}

			// Apply slug fix.
			$ai_slug = sanitize_title( $ai_data['slug'] ?? '' );
			if ( ! empty( $ai_slug ) && $ai_slug !== $current_slug ) {
				$update_payload['post_name'] = $ai_slug;
				$applied_fixes[]             = __( 'URL slug optimized by AI.', 'antimanual' );
			} else {
				$skipped_fixes[] = __( 'URL slug did not need changes.', 'antimanual' );
			}

			// Apply content fixes via existing helpers.
			$content_changes_applied = false;
			$content_for_runtime     = $current_content;
			$content_result          = $this->apply_content_auto_fixes( $current_content, $post_id );
			if ( ! empty( $content_result['changed'] ) ) {
				$update_payload['post_content'] = $content_result['content'];
				$content_updated                = true;
				$content_changes_applied        = true;
				$content_for_runtime            = (string) $content_result['content'];
				$applied_fixes[]                = __( 'Image and link attributes optimized.', 'antimanual' );
			}

			if ( count( $update_payload ) > 1 ) {
				$updated = wp_update_post( $update_payload, true );
				if ( is_wp_error( $updated ) ) {
					$results[] = array(
						'post_id' => $post_id,
						'title'   => $post->post_title,
						'status'  => 'error',
						'message' => $updated->get_error_message(),
					);
					continue;
				}
			}

			$runtime_scan_content = $this->render_post_content_for_scan( $post, $content_for_runtime );

			$lazy_candidates = $this->count_missing_lazy_attributes( $runtime_scan_content );
			if ( $lazy_candidates > 0 && $this->enable_runtime_content_fix_flag( $post_id, self::META_FORCE_LAZY_LOADING ) ) {
				$content_updated         = true;
				$content_changes_applied = true;
				$applied_fixes[]         = __( 'Lazy-loading enforcement enabled.', 'antimanual' );
			}

			$noopener_candidates = $this->count_missing_noopener_attributes( $runtime_scan_content );
			if ( $noopener_candidates > 0 && $this->enable_runtime_content_fix_flag( $post_id, self::META_FORCE_NOOPENER ) ) {
				$content_updated         = true;
				$content_changes_applied = true;
				$applied_fixes[]         = __( 'Link security attributes added.', 'antimanual' );
			}

			if ( ! $content_changes_applied ) {
				$skipped_fixes[] = __( 'No content fixes needed.', 'antimanual' );
			}

			// Extract and save focus keyword.
			$focus_keyword = sanitize_text_field( $ai_data['focus_keyword'] ?? '' );

			if ( $title_updated || $meta_updated || ! empty( $focus_keyword ) ) {
				$meta_keys_updated = $this->update_existing_seo_meta_keys(
					$post_id,
					$title_updated ? $ai_title : '',
					$meta_updated ? $ai_description : '',
					$focus_keyword
				);
				if ( ! empty( $focus_keyword ) ) {
					$applied_fixes[] = __( 'Focus keyword set for SEO plugins.', 'antimanual' );
				}
			}

			$post_url = get_permalink( $post_id );
			if ( $post_url ) {
				$this->clear_cached_analyses( array( $post_url ) );
			}

			$fixes_count = ( $title_updated ? 1 : 0 ) + ( $meta_updated ? 1 : 0 ) + ( $content_updated ? 1 : 0 );
			$total_fixed  += $fixes_count;
			$total_skipped += count( $skipped_fixes );

			$results[] = array(
				'post_id'       => $post_id,
				'title'         => $post->post_title,
				'url'           => esc_url( $post_url ?: '' ),
				'status'        => 'success',
				'fixes_applied' => count( $applied_fixes ),
				'fixes_skipped' => count( $skipped_fixes ),
				'applied_fixes' => $applied_fixes,
				'updated_fields' => array(
					'title'            => $title_updated,
					'meta_description' => $meta_updated,
					'content'          => $content_updated,
				),
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'results'       => $results,
					'total'         => count( $results ),
					'total_fixed'   => $total_fixed,
					'total_skipped' => $total_skipped,
				),
			)
		);
	}

	/**
	 * Run a single SEO audit using either the quick deterministic method or full AI analysis.
	 *
	 * @param string $url  The URL to audit.
	 * @param string $html The fetched HTML content.
	 * @param string $mode 'quick' for deterministic checks, 'full' for AI analysis.
	 * @return array|\WP_Error The audit result or error.
	 */
	private function run_single_audit( string $url, string $html, string $mode = 'quick' ) {
		if ( 'full' === $mode ) {
			// Truncate HTML to avoid exceeding token limits.
			$max_chars    = 15000;
			$html_excerpt = mb_strlen( $html ) > $max_chars
				? mb_substr( $html, 0, $max_chars ) . "\n<!-- TRUNCATED -->"
				: $html;

			$system_prompt = $this->build_system_prompt();
			$user_prompt   = $this->build_user_prompt( $url, $html_excerpt );

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

			$reply_data = AIProvider::get_reply( $messages );

			if ( isset( $reply_data['error'] ) ) {
				return new \WP_Error(
					'ai_failed',
					$reply_data['error']
				);
			}

			$reply = is_array( $reply_data ) && isset( $reply_data['reply'] ) ? $reply_data['reply'] : (string) $reply_data;

			if ( empty( $reply ) ) {
				return new \WP_Error( 'ai_failed', __( 'AI analysis failed.', 'antimanual' ) );
			}

			return $this->parse_ai_response( $reply, $url );
		}

		return $this->build_quick_audit( $url, $html );
	}

	/**
	 * Fetch page HTML content via HTTP.
	 *
	 * @param string $url The URL to fetch.
	 * @return string|\WP_Error The HTML content or error.
	 */
	private function fetch_page_html( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'Mozilla/5.0 (compatible; AntimanualSEOBot/1.0)',
				'sslverify'  => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not fetch the page: %s', 'antimanual' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 400 ) {
			return new \WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Page returned HTTP status %d.', 'antimanual' ),
					$status_code
				)
			);
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Build a full HTML document from a WP_Post object.
	 *
	 * This avoids loopback HTTP requests (wp_remote_get to the same server)
	 * that often time out in local/limited-worker environments.
	 * The generated HTML mirrors what the theme would output, including
	 * title, meta description, canonical URL, and rendered post content.
	 *
	 * @param \WP_Post $post The post to render.
	 * @param string   $url  The permalink for canonical/meta tags.
	 * @return string|\WP_Error The rendered HTML document or error.
	 */
	private function build_html_from_post( \WP_Post $post, string $url ) {
		$content = (string) $post->post_content;
		if ( '' === trim( $content ) && '' === trim( (string) $post->post_title ) ) {
			return new \WP_Error(
				'empty_post',
				__( 'Post has no content or title to audit.', 'antimanual' )
			);
		}

		// Render post content through WordPress filters (shortcodes, blocks, etc.).
		$rendered_content = $this->render_post_content_for_scan( $post, $content );

		// Apply runtime fixes if they were previously enabled.
		if ( '1' === get_post_meta( $post->ID, self::META_FORCE_LAZY_LOADING, true ) ) {
			$rendered_content = $this->force_lazy_loading_attributes( $rendered_content );
		}
		if ( '1' === get_post_meta( $post->ID, self::META_FORCE_NOOPENER, true ) ) {
			$rendered_content = $this->force_noopener_attributes( $rendered_content );
		}

		// Build the title the same way WordPress would.
		$title = trim( (string) $post->post_title );
		$site_name = get_bloginfo( 'name' );
		if ( '' !== $site_name ) {
			$title .= ' - ' . $site_name;
		}

		// Get meta description from SEO plugins or excerpt.
		$meta_description = $this->get_seo_meta_description( $post );
		if ( '' === $meta_description ) {
			$meta_description = trim( (string) $post->post_excerpt );
		}

		// Get the document language.
		$lang = get_bloginfo( 'language' );
		if ( '' === $lang ) {
			$lang = 'en-US';
		}

		// Build a complete HTML document for the audit engine.
		$html = '<!DOCTYPE html>' . "\n";
		$html .= '<html lang="' . esc_attr( $lang ) . '">' . "\n";
		$html .= '<head>' . "\n";
		$html .= '<meta charset="UTF-8">' . "\n";
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		$html .= '<title>' . esc_html( $title ) . '</title>' . "\n";

		if ( '' !== $meta_description ) {
			$html .= '<meta name="description" content="' . esc_attr( $meta_description ) . '">' . "\n";
		}

		$html .= '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";

		// Add Open Graph tags if available.
		$html .= '<meta property="og:title" content="' . esc_attr( $post->post_title ) . '">' . "\n";
		$html .= '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		$html .= '<meta property="og:type" content="article">' . "\n";
		if ( '' !== $meta_description ) {
			$html .= '<meta property="og:description" content="' . esc_attr( $meta_description ) . '">' . "\n";
		}

		// Featured image.
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id ) {
			$img_url = wp_get_attachment_url( $thumbnail_id );
			if ( $img_url ) {
				$html .= '<meta property="og:image" content="' . esc_url( $img_url ) . '">' . "\n";
			}
		}

		$html .= '</head>' . "\n";
		$html .= '<body>' . "\n";
		$html .= '<h1>' . esc_html( $post->post_title ) . '</h1>' . "\n";
		$html .= '<div class="entry-content">' . "\n";
		$html .= $rendered_content . "\n";
		$html .= '</div>' . "\n";
		$html .= '</body>' . "\n";
		$html .= '</html>';

		return $html;
	}

	/**
	 * Build the system prompt for AI SEO analysis.
	 *
	 * @return string The system prompt.
	 */
	private function build_system_prompt(): string {
		return "You are an expert SEO auditor and web performance analyst. Your task is to analyze a webpage's HTML and provide a comprehensive SEO audit.\n\n"
			. "You MUST respond with ONLY valid JSON (no markdown, no code fences, no explanation) in this exact structure:\n\n"
			. "{\n"
			. "  \"seo_score\": <number 0-100>,\n"
			. "  \"performance_score\": <number 0-100>,\n"
			. "  \"accessibility_score\": <number 0-100>,\n"
			. "  \"title\": \"<extracted page title>\",\n"
			. "  \"meta_description\": \"<extracted meta description>\",\n"
			. "  \"issues\": [\n"
			. "    {\n"
			. "      \"title\": \"<short issue title>\",\n"
			. "      \"description\": \"<detailed explanation and how to fix>\",\n"
			. "      \"severity\": \"<critical|warning|info|passed>\",\n"
			. "      \"category\": \"<On-Page SEO|Technical SEO|Performance|Accessibility|Security|Mobile>\"\n"
			. "    }\n"
			. "  ],\n"
			. "  \"recommendations\": [\n"
			. "    \"<actionable recommendation string>\"\n"
			. "  ]\n"
			. "}\n\n"
			. "Analysis criteria:\n"
			. "1. **On-Page SEO**: Title tag (length, keywords), meta description (length, relevance), heading hierarchy (H1-H6), image alt text, internal/external links, keyword density, canonical tags.\n"
			. "2. **Technical SEO**: Schema markup, Open Graph tags, Twitter cards, robots meta, sitemap reference, hreflang, URL structure.\n"
			. "3. **Performance**: Inline styles/scripts, render-blocking resources, image optimization hints, lazy loading, CSS/JS minification indicators.\n"
			. "4. **Accessibility**: ARIA labels, form labels, color contrast hints, keyboard navigation, semantic HTML, lang attribute.\n"
			. "5. **Security**: HTTPS usage, mixed content hints, CSP headers indication.\n"
			. "6. **Mobile**: Viewport meta tag, responsive design indicators, touch target sizes.\n\n"
			. "Include at least 5-10 issues covering different categories. Include both problems and passed checks. Provide 3-5 actionable top recommendations.";
	}

	/**
	 * Build the user prompt with the page data.
	 *
	 * @param string $url  The analyzed URL.
	 * @param string $html The page HTML content.
	 * @return string The user prompt.
	 */
	private function build_user_prompt( string $url, string $html ): string {
		return "Analyze the following page for SEO issues:\n\n"
			. "URL: {$url}\n\n"
			. "HTML Content:\n"
			. "```html\n"
			. $html
			. "\n```\n\n"
			. "Provide a complete SEO audit as JSON.";
	}

	/**
	 * Parse the AI response into structured data.
	 *
	 * @param string $reply The raw AI response.
	 * @param string $url   The analyzed URL.
	 * @return array|\WP_Error Parsed result or error.
	 */
	private function parse_ai_response( string $reply, string $url ) {
		// Strip markdown code fences if present.
		$reply = preg_replace( '/^```(?:json)?\s*/i', '', trim( $reply ) );
		$reply = preg_replace( '/\s*```$/', '', $reply );

		$data = json_decode( $reply, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error(
				'parse_failed',
				__( 'Failed to parse the AI analysis response. Please try again.', 'antimanual' )
			);
		}

		// Sanitize and validate the response structure.
		return array(
			'url'                 => esc_url( $url ),
			'seo_score'           => $this->clamp_score( $data['seo_score'] ?? 0 ),
			'performance_score'   => $this->clamp_score( $data['performance_score'] ?? 0 ),
			'accessibility_score' => $this->clamp_score( $data['accessibility_score'] ?? 0 ),
			'title'               => sanitize_text_field( $data['title'] ?? '' ),
			'meta_description'    => sanitize_text_field( $data['meta_description'] ?? '' ),
			'issues'              => $this->sanitize_issues( $data['issues'] ?? array() ),
			'recommendations'     => $this->sanitize_recommendations( $data['recommendations'] ?? array() ),
		);
	}

	/**
	 * Build a deterministic, no-AI SEO audit from raw HTML.
	 *
	 * @param string $url  URL being analyzed.
	 * @param string $html Raw HTML content.
	 * @return array|\WP_Error
	 */
	private function build_quick_audit( string $url, string $html ) {
		$dom = new \DOMDocument();

		$libxml_previous = libxml_use_internal_errors( true );
		$loaded          = $dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous );

		if ( ! $loaded ) {
			return new \WP_Error(
				'html_parse_failed',
				__( 'Unable to parse the page HTML for quick analysis.', 'antimanual' )
			);
		}

		$xpath = new \DOMXPath( $dom );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// For internal post/page URLs, scope fixable content checks (img/link)
		// to rendered post content so audit findings align with auto-fix scope.
		$content_scope_xpath = $xpath;
		$scoped_content      = $this->get_scoped_content_for_internal_audit( $url );
		if ( '' !== $scoped_content ) {
			$scope_dom       = new \DOMDocument();
			$scoped_previous = libxml_use_internal_errors( true );
			$scope_loaded    = $scope_dom->loadHTML(
				'<?xml encoding="utf-8" ?><html><body>' . $scoped_content . '</body></html>',
				LIBXML_NOERROR | LIBXML_NOWARNING
			);
			libxml_clear_errors();
			libxml_use_internal_errors( $scoped_previous );

			if ( $scope_loaded ) {
				$content_scope_xpath = new \DOMXPath( $scope_dom );
			}
		}

		$issues = array();
		$scores = array(
			'seo'           => 100,
			'performance'   => 100,
			'accessibility' => 100,
		);

		$add_issue = function ( string $title, string $description, string $severity, string $category ) use ( &$issues, &$scores ) {
			$severity = in_array( $severity, array( 'critical', 'warning', 'info', 'passed' ), true ) ? $severity : 'info';
			$issues[] = array(
				'title'       => sanitize_text_field( $title ),
				'description' => sanitize_text_field( $description ),
				'severity'    => $severity,
				'category'    => sanitize_text_field( $category ),
			);

			if ( 'passed' === $severity ) {
				return;
			}

			$penalty_by_severity = array(
				'critical' => 24,
				'warning'  => 10,
				'info'     => 4,
			);

			$penalty = $penalty_by_severity[ $severity ] ?? 0;

			if ( in_array( $category, array( 'Performance' ), true ) ) {
				$scores['performance'] -= $penalty;
				return;
			}

			if ( in_array( $category, array( 'Accessibility', 'Mobile' ), true ) ) {
				$scores['accessibility'] -= $penalty;
				return;
			}

			$scores['seo'] -= $penalty;
		};

		$title_nodes = $dom->getElementsByTagName( 'title' );
		$title       = $title_nodes->length > 0 ? trim( $title_nodes->item( 0 )->textContent ) : '';
		$title_len   = mb_strlen( $title );

		if ( empty( $title ) ) {
			$add_issue(
				__( 'Missing title tag', 'antimanual' ),
				__( 'Add a unique title tag around 50 to 60 characters and include the primary keyword near the beginning.', 'antimanual' ),
				'critical',
				'On-Page SEO'
			);
		} elseif ( $title_len < 30 || $title_len > 60 ) {
			$add_issue(
				__( 'Title length can be improved', 'antimanual' ),
				sprintf(
					/* translators: %d: title length */
					__( 'Current title length is %d characters. Keep it between 30 and 60 for better SERP visibility.', 'antimanual' ),
					$title_len
				),
				'warning',
				'On-Page SEO'
			);
		} else {
			$add_issue(
				__( 'Title length is in range', 'antimanual' ),
				__( 'The title tag length is within a healthy SEO range.', 'antimanual' ),
				'passed',
				'On-Page SEO'
			);
		}

		$meta_description = '';
		$meta_desc_nodes  = $xpath->query( "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='description']" );
		if ( $meta_desc_nodes && $meta_desc_nodes->length > 0 ) {
			$meta_description = trim( (string) $meta_desc_nodes->item( 0 )->getAttribute( 'content' ) );
		}
		$meta_len = mb_strlen( $meta_description );

		if ( empty( $meta_description ) ) {
			$add_issue(
				__( 'Missing meta description', 'antimanual' ),
				__( 'Add a compelling meta description (120 to 160 characters) to improve click-through rates.', 'antimanual' ),
				'warning',
				'On-Page SEO'
			);
		} elseif ( $meta_len < 120 || $meta_len > 160 ) {
			$add_issue(
				__( 'Meta description length can be improved', 'antimanual' ),
				sprintf(
					/* translators: %d: meta description length */
					__( 'Current meta description is %d characters. Aim for 120 to 160 characters.', 'antimanual' ),
					$meta_len
				),
				'info',
				'On-Page SEO'
			);
		} else {
			$add_issue(
				__( 'Meta description length is in range', 'antimanual' ),
				__( 'Meta description length is suitable for most search snippets.', 'antimanual' ),
				'passed',
				'On-Page SEO'
			);
		}

		$h1_nodes = $xpath->query( '//h1' );
		$h1_count = $h1_nodes instanceof \DOMNodeList ? $h1_nodes->length : 0;
		if ( 0 === $h1_count ) {
			$add_issue(
				__( 'Missing H1 heading', 'antimanual' ),
				__( 'Add a single descriptive H1 heading to clarify the page topic for search engines and users.', 'antimanual' ),
				'critical',
				'On-Page SEO'
			);
		} elseif ( $h1_count > 1 ) {
			$add_issue(
				__( 'Multiple H1 headings detected', 'antimanual' ),
				sprintf(
					/* translators: %d: number of H1 tags */
					__( 'Found %d H1 tags. Use one primary H1 and structure the rest with H2/H3 headings.', 'antimanual' ),
					$h1_count
				),
				'warning',
				'On-Page SEO'
			);
		} else {
			$add_issue(
				__( 'Heading hierarchy starts correctly', 'antimanual' ),
				__( 'Exactly one H1 tag was found.', 'antimanual' ),
				'passed',
				'On-Page SEO'
			);
		}

		$canonical_nodes = $xpath->query( "//link[translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='canonical']" );
		$canonical       = '';
		if ( $canonical_nodes && $canonical_nodes->length > 0 ) {
			$canonical = trim( (string) $canonical_nodes->item( 0 )->getAttribute( 'href' ) );
		}

		if ( empty( $canonical ) ) {
			$add_issue(
				__( 'Canonical URL missing', 'antimanual' ),
				__( 'Add a canonical tag to reduce duplicate-content risk and consolidate ranking signals.', 'antimanual' ),
				'warning',
				'Technical SEO'
			);
		} else {
			$add_issue(
				__( 'Canonical URL detected', 'antimanual' ),
				__( 'Canonical tag is present.', 'antimanual' ),
				'passed',
				'Technical SEO'
			);
		}

		$robots_nodes = $xpath->query( "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='robots']" );
		$robots       = '';
		if ( $robots_nodes && $robots_nodes->length > 0 ) {
			$robots = strtolower( trim( (string) $robots_nodes->item( 0 )->getAttribute( 'content' ) ) );
		}

		if ( ! empty( $robots ) && false !== strpos( $robots, 'noindex' ) ) {
			$add_issue(
				__( 'Page is set to noindex', 'antimanual' ),
				__( 'The robots meta tag includes noindex, which prevents this page from appearing in search results.', 'antimanual' ),
				'critical',
				'Technical SEO'
			);
		} else {
			$add_issue(
				__( 'No blocking noindex directive detected', 'antimanual' ),
				__( 'No noindex directive was found in the robots meta tag.', 'antimanual' ),
				'passed',
				'Technical SEO'
			);
		}

		$img_nodes           = $content_scope_xpath->query( '//img' );
		$total_images        = $img_nodes instanceof \DOMNodeList ? $img_nodes->length : 0;
		$images_without_alt  = 0;
		$images_without_lazy = 0;
		if ( $img_nodes ) {
			foreach ( $img_nodes as $img_node ) {
				$alt = trim( (string) $img_node->getAttribute( 'alt' ) );
				if ( '' === $alt ) {
					++$images_without_alt;
				}

				$loading = strtolower( trim( (string) $img_node->getAttribute( 'loading' ) ) );
				if ( 'lazy' !== $loading ) {
					++$images_without_lazy;
				}
			}
		}

		if ( $total_images > 0 ) {
			$missing_alt_ratio = $images_without_alt / $total_images;
			if ( 0 === $images_without_alt ) {
				$add_issue(
					__( 'Images have alt text', 'antimanual' ),
					__( 'All images include alt text.', 'antimanual' ),
					'passed',
					'Accessibility'
				);
			} elseif ( $missing_alt_ratio >= 0.5 ) {
				$add_issue(
					__( 'Many images are missing alt text', 'antimanual' ),
					sprintf(
						/* translators: 1: missing alt count, 2: total image count */
						__( '%1$d of %2$d images are missing alt text. Add descriptive alt attributes for accessibility and image SEO.', 'antimanual' ),
						$images_without_alt,
						$total_images
					),
					'critical',
					'Accessibility'
				);
			} else {
				$add_issue(
					__( 'Some images are missing alt text', 'antimanual' ),
					sprintf(
						/* translators: 1: missing alt count, 2: total image count */
						__( '%1$d of %2$d images are missing alt text.', 'antimanual' ),
						$images_without_alt,
						$total_images
					),
					'warning',
					'Accessibility'
				);
			}

			if ( $total_images >= 3 && $images_without_lazy >= 3 ) {
				$add_issue(
					__( 'Images are not consistently lazy-loaded', 'antimanual' ),
					sprintf(
						/* translators: 1: images without lazy loading, 2: total image count */
						__( '%1$d of %2$d images do not use loading=\"lazy\".', 'antimanual' ),
						$images_without_lazy,
						$total_images
					),
					'warning',
					'Performance'
				);
			} else {
				$add_issue(
					__( 'Image lazy-loading looks healthy', 'antimanual' ),
					__( 'Most images use native lazy loading.', 'antimanual' ),
					'passed',
					'Performance'
				);
			}
		} else {
			$add_issue(
				__( 'No images found to audit', 'antimanual' ),
				__( 'No image tags were found on this page.', 'antimanual' ),
				'info',
				'On-Page SEO'
			);
		}

		$viewport_nodes = $xpath->query( "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='viewport']" );
		if ( $viewport_nodes && $viewport_nodes->length > 0 ) {
			$add_issue(
				__( 'Viewport meta tag detected', 'antimanual' ),
				__( 'Viewport is configured for mobile rendering.', 'antimanual' ),
				'passed',
				'Mobile'
			);
		} else {
			$add_issue(
				__( 'Missing viewport meta tag', 'antimanual' ),
				__( 'Add a viewport meta tag to improve mobile rendering and usability.', 'antimanual' ),
				'warning',
				'Mobile'
			);
		}

		$doc_element = $dom->documentElement;
		$lang        = null !== $doc_element ? trim( strtolower( (string) $doc_element->getAttribute( 'lang' ) ) ) : '';
		if ( '' === $lang ) {
			$add_issue(
				__( 'Missing html lang attribute', 'antimanual' ),
				__( 'Set the html lang attribute (for example, lang=\"en\") to improve accessibility and indexing context.', 'antimanual' ),
				'warning',
				'Accessibility'
			);
		} else {
			$add_issue(
				__( 'Language attribute detected', 'antimanual' ),
				sprintf(
					/* translators: %s: language code */
					__( 'The html lang attribute is set to "%s".', 'antimanual' ),
					$lang
				),
				'passed',
				'Accessibility'
			);
		}

		$json_ld_nodes = $xpath->query( "//script[translate(@type,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='application/ld+json']" );
		if ( $json_ld_nodes && $json_ld_nodes->length > 0 ) {
			$add_issue(
				__( 'Structured data detected', 'antimanual' ),
				__( 'JSON-LD schema markup appears to be present.', 'antimanual' ),
				'passed',
				'Technical SEO'
			);
		} else {
			$add_issue(
				__( 'No JSON-LD structured data detected', 'antimanual' ),
				__( 'Consider adding schema markup to improve rich result eligibility.', 'antimanual' ),
				'warning',
				'Technical SEO'
			);
		}

		$og_title_nodes = $xpath->query( "//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='og:title']" );
		$og_desc_nodes  = $xpath->query( "//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='og:description']" );
		if ( $og_title_nodes && $og_title_nodes->length > 0 && $og_desc_nodes && $og_desc_nodes->length > 0 ) {
			$add_issue(
				__( 'Open Graph tags detected', 'antimanual' ),
				__( 'Open Graph title and description tags are present.', 'antimanual' ),
				'passed',
				'Technical SEO'
			);
		} else {
			$add_issue(
				__( 'Open Graph tags are incomplete', 'antimanual' ),
				__( 'Add og:title and og:description tags for better social sharing previews.', 'antimanual' ),
				'info',
				'Technical SEO'
			);
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' !== $scheme ) {
			$add_issue(
				__( 'URL is not using HTTPS', 'antimanual' ),
				__( 'Serve the page over HTTPS to improve trust and search ranking signals.', 'antimanual' ),
				'critical',
				'Security'
			);
		} else {
			$add_issue(
				__( 'Page uses HTTPS', 'antimanual' ),
				__( 'The URL is served over HTTPS.', 'antimanual' ),
				'passed',
				'Security'
			);
		}

		$links              = $content_scope_xpath->query( '//a[@href]' );
		$internal_links     = 0;
		$external_links     = 0;
		$unsafe_blank_links = 0;
			if ( $links ) {
				foreach ( $links as $link ) {
					$href = trim( (string) $link->getAttribute( 'href' ) );
					if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) ) {
						continue;
					}

					$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );
					if ( in_array( $scheme, array( 'mailto', 'tel' ), true ) ) {
						continue;
					}

					$is_external = $this->is_external_link_href( $href, $host );
					if ( $is_external ) {
						++$external_links;
					} else {
						++$internal_links;
					}

					$target = strtolower( trim( (string) $link->getAttribute( 'target' ) ) );
					$rel    = strtolower( trim( (string) $link->getAttribute( 'rel' ) ) );
					if ( $is_external && '_blank' === $target && false === strpos( $rel, 'noopener' ) ) {
						++$unsafe_blank_links;
					}
				}
			}

		if ( $unsafe_blank_links > 0 ) {
			$add_issue(
				__( 'External links opened in new tabs need rel=\"noopener\"', 'antimanual' ),
				sprintf(
					/* translators: %d: unsafe links count */
					__( '%d links using target=\"_blank\" are missing rel=\"noopener\".', 'antimanual' ),
					$unsafe_blank_links
				),
				'warning',
				'Security'
			);
		} else {
			$add_issue(
				__( 'New-tab link security looks good', 'antimanual' ),
				__( 'No insecure target=\"_blank\" links were detected.', 'antimanual' ),
				'passed',
				'Security'
			);
		}

		$html_size_bytes = strlen( $html );
		if ( $html_size_bytes > 3000000 ) {
			$add_issue(
				__( 'HTML payload is very large', 'antimanual' ),
				__( 'The page HTML is very large, which can hurt load performance. Consider reducing DOM size and inline markup.', 'antimanual' ),
				'critical',
				'Performance'
			);
		} elseif ( $html_size_bytes > 1500000 ) {
			$add_issue(
				__( 'HTML payload is larger than ideal', 'antimanual' ),
				__( 'Large HTML payloads can slow down first render. Consider simplifying the markup.', 'antimanual' ),
				'warning',
				'Performance'
			);
		} else {
			$add_issue(
				__( 'HTML payload size is reasonable', 'antimanual' ),
				__( 'HTML size appears to be within a reasonable range.', 'antimanual' ),
				'passed',
				'Performance'
			);
		}

		// --- Content length / thin content ---
		$body_nodes   = $xpath->query( '//body' );
		$body_text    = $body_nodes && $body_nodes->length > 0 ? trim( $body_nodes->item( 0 )->textContent ) : '';
		$word_count   = str_word_count( $body_text );

		if ( $word_count < 300 ) {
			$add_issue(
				__( 'Thin content detected', 'antimanual' ),
				sprintf(
					/* translators: %d: word count */
					__( 'The page contains only %d words. Aim for at least 300 words of useful, original content to satisfy user intent.', 'antimanual' ),
					$word_count
				),
				$word_count < 100 ? 'critical' : 'warning',
				'On-Page SEO'
			);
		} else {
			$add_issue(
				__( 'Content length is sufficient', 'antimanual' ),
				sprintf(
					/* translators: %d: word count */
					__( 'The page has %d words, which is above the recommended minimum.', 'antimanual' ),
					$word_count
				),
				'passed',
				'On-Page SEO'
			);
		}

		// --- Internal links ---
		if ( 0 === $internal_links ) {
			$add_issue(
				__( 'No internal links found', 'antimanual' ),
				__( 'No internal links were found. Use the Internal Linking tool (SEO Plus → Internal Linking) to automatically add contextual internal links.', 'antimanual' ),
				'warning',
				'On-Page SEO'
			);
		} elseif ( $internal_links < 2 ) {
			$add_issue(
				__( 'Few internal links', 'antimanual' ),
				sprintf(
					/* translators: %d: internal link count */
					__( 'Only %d internal link found. Use the Internal Linking tool (SEO Plus → Internal Linking) to add more contextual links.', 'antimanual' ),
					$internal_links
				),
				'info',
				'On-Page SEO'
			);
		} else {
			$add_issue(
				__( 'Internal linking looks healthy', 'antimanual' ),
				sprintf(
					/* translators: %d: internal link count */
					__( 'The page has %d internal links.', 'antimanual' ),
					$internal_links
				),
				'passed',
				'On-Page SEO'
			);
		}

		// --- External / outbound links ---
		if ( 0 === $external_links ) {
			$add_issue(
				__( 'No external links found', 'antimanual' ),
				__( 'Add 1\u20133 outbound links to authoritative, relevant sources. This signals content quality and helps establish topical context.', 'antimanual' ),
				'info',
				'On-Page SEO'
			);
		} else {
			$add_issue(
				__( 'Outbound links detected', 'antimanual' ),
				sprintf(
					/* translators: %d: external link count */
					__( 'The page has %d outbound links.', 'antimanual' ),
					$external_links
				),
				'passed',
				'On-Page SEO'
			);
		}

		// --- Heading hierarchy (H2 / H3 structure) ---
		$h2_nodes = $xpath->query( '//h2' );
		$h2_count = $h2_nodes instanceof \DOMNodeList ? $h2_nodes->length : 0;

		if ( 0 === $h2_count && $word_count > 300 ) {
			$add_issue(
				__( 'No H2 sub-headings found', 'antimanual' ),
				__( 'Use H2 headings to break long content into scannable sections. This improves readability and helps search engines understand content structure.', 'antimanual' ),
				'warning',
				'On-Page SEO'
			);
		} elseif ( $h2_count > 0 ) {
			$add_issue(
				__( 'Content uses sub-headings', 'antimanual' ),
				sprintf(
					/* translators: %d: H2 count */
					__( 'Found %d H2 sub-heading(s) for content structure.', 'antimanual' ),
					$h2_count
				),
				'passed',
				'On-Page SEO'
			);
		}

		// --- URL / slug length ---
		$url_path    = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$slug_length = mb_strlen( $url_path );

		if ( $slug_length > 75 ) {
			$add_issue(
				__( 'URL slug is too long', 'antimanual' ),
				sprintf(
					/* translators: %d: slug length */
					__( 'The URL path is %d characters. Keep slugs short, descriptive, and under 75 characters for best SEO performance.', 'antimanual' ),
					$slug_length
				),
				'warning',
				'On-Page SEO'
			);
		} else {
			$add_issue(
				__( 'URL slug length is good', 'antimanual' ),
				__( 'The URL path length is within a healthy range.', 'antimanual' ),
				'passed',
				'On-Page SEO'
			);
		}

		// --- Rank Math focus keyword checks ---
		// Detect focus keyword from Rank Math, Yoast, or AIOSEO meta.
		$focus_keyword = '';
		$focus_post_id = url_to_postid( $url );
		if ( $focus_post_id > 0 ) {
			$fk_meta_keys = [
				'rank_math_focus_keyword',
				'_yoast_wpseo_focuskw',
				'_atml_focus_keyword',
			];
			foreach ( $fk_meta_keys as $fk_key ) {
				$fk_val = get_post_meta( $focus_post_id, $fk_key, true );
				if ( is_string( $fk_val ) && '' !== trim( $fk_val ) ) {
					$focus_keyword = strtolower( trim( $fk_val ) );
					break;
				}
			}
		}

		if ( '' !== $focus_keyword ) {
			$lc_title    = strtolower( $title );
			$lc_meta     = strtolower( $meta_description );
			$lc_url_path = strtolower( $url_path );

			// Focus keyword in SEO title.
			if ( false !== strpos( $lc_title, $focus_keyword ) ) {
				$add_issue(
					__( 'Focus keyword found in SEO title', 'antimanual' ),
					sprintf(
						/* translators: %s: focus keyword */
						__( 'The focus keyword "%s" appears in the SEO title.', 'antimanual' ),
						$focus_keyword
					),
					'passed',
					'On-Page SEO'
				);
			} else {
				$add_issue(
					__( 'Focus keyword missing from SEO title', 'antimanual' ),
					sprintf(
						/* translators: %s: focus keyword */
						__( 'Add the focus keyword "%s" to the SEO title to improve search visibility.', 'antimanual' ),
						$focus_keyword
					),
					'critical',
					'On-Page SEO'
				);
			}

			// Focus keyword in meta description.
			if ( '' !== $lc_meta && false !== strpos( $lc_meta, $focus_keyword ) ) {
				$add_issue(
					__( 'Focus keyword found in meta description', 'antimanual' ),
					__( 'The focus keyword appears in the meta description.', 'antimanual' ),
					'passed',
					'On-Page SEO'
				);
			} else {
				$add_issue(
					__( 'Focus keyword missing from meta description', 'antimanual' ),
					sprintf(
						/* translators: %s: focus keyword */
						__( 'Include the focus keyword "%s" in your meta description for better relevance signaling.', 'antimanual' ),
						$focus_keyword
					),
					'warning',
					'On-Page SEO'
				);
			}

			// Focus keyword in URL.
			$fk_slug = str_replace( ' ', '-', $focus_keyword );
			if ( false !== strpos( $lc_url_path, $fk_slug ) || false !== strpos( $lc_url_path, str_replace( '-', '', $fk_slug ) ) ) {
				$add_issue(
					__( 'Focus keyword found in URL', 'antimanual' ),
					__( 'The focus keyword appears in the URL slug.', 'antimanual' ),
					'passed',
					'On-Page SEO'
				);
			} else {
				$add_issue(
					__( 'Focus keyword missing from URL', 'antimanual' ),
					sprintf(
						/* translators: %s: focus keyword */
						__( 'Include the focus keyword "%s" in the URL slug for improved search rankings.', 'antimanual' ),
						$focus_keyword
					),
					'warning',
					'On-Page SEO'
				);
			}

			// Focus keyword at the beginning of content (first 10%).
			$body_text_lc    = strtolower( $body_text );
			$content_10_pct  = mb_substr( $body_text_lc, 0, max( 200, (int) ( mb_strlen( $body_text_lc ) * 0.10 ) ) );
			if ( false !== strpos( $content_10_pct, $focus_keyword ) ) {
				$add_issue(
					__( 'Focus keyword appears in the beginning of content', 'antimanual' ),
					__( 'The focus keyword is present in the first 10% of the content.', 'antimanual' ),
					'passed',
					'On-Page SEO'
				);
			} else {
				$add_issue(
					__( 'Focus keyword not in the beginning of content', 'antimanual' ),
					sprintf(
						/* translators: %s: focus keyword */
						__( 'Place the focus keyword "%s" in the first paragraph or opening section of your content.', 'antimanual' ),
						$focus_keyword
					),
					'critical',
					'On-Page SEO'
				);
			}

			// Focus keyword found in overall content.
			if ( false !== strpos( $body_text_lc, $focus_keyword ) ) {
				$add_issue(
					__( 'Focus keyword found in content', 'antimanual' ),
					__( 'The focus keyword is used in the page content.', 'antimanual' ),
					'passed',
					'On-Page SEO'
				);
			} else {
				$add_issue(
					__( 'Focus keyword not found in content', 'antimanual' ),
					sprintf(
						/* translators: %s: focus keyword */
						__( 'The focus keyword "%s" was not found in the content. Use it naturally throughout the text.', 'antimanual' ),
						$focus_keyword
					),
					'critical',
					'On-Page SEO'
				);
			}

			// Focus keyword in subheadings (H2/H3).
			$fk_in_subheading = false;
			$subheading_nodes = $xpath->query( '//h2|//h3' );
			if ( $subheading_nodes ) {
				foreach ( $subheading_nodes as $sh_node ) {
					if ( false !== strpos( strtolower( $sh_node->textContent ), $focus_keyword ) ) {
						$fk_in_subheading = true;
						break;
					}
				}
			}

			if ( $fk_in_subheading ) {
				$add_issue(
					__( 'Focus keyword found in subheading', 'antimanual' ),
					__( 'The focus keyword appears in at least one H2/H3 subheading.', 'antimanual' ),
					'passed',
					'On-Page SEO'
				);
			} else {
				$add_issue(
					__( 'Focus keyword not in any subheading', 'antimanual' ),
					sprintf(
						/* translators: %s: focus keyword */
						__( 'Include the focus keyword "%s" in at least one H2 or H3 subheading.', 'antimanual' ),
						$focus_keyword
					),
					'warning',
					'On-Page SEO'
				);
			}
		} elseif ( $focus_post_id > 0 ) {
			$add_issue(
				__( 'No focus keyword set', 'antimanual' ),
				__( 'Set a focus keyword in your SEO plugin to enable keyword-specific SEO analysis. Use Auto Fix to automatically identify and set one.', 'antimanual' ),
				'critical',
				'On-Page SEO'
			);
		}

		// --- Image dimension attributes (CLS prevention) ---
		$images_missing_dimensions = 0;
		if ( $img_nodes ) {
			foreach ( $img_nodes as $img_node ) {
				$width  = trim( (string) $img_node->getAttribute( 'width' ) );
				$height = trim( (string) $img_node->getAttribute( 'height' ) );
				if ( '' === $width || '' === $height ) {
					++$images_missing_dimensions;
				}
			}
		}

		if ( $total_images > 0 && $images_missing_dimensions > 0 ) {
			$add_issue(
				__( 'Images missing width/height attributes', 'antimanual' ),
				sprintf(
					/* translators: 1: missing count, 2: total count */
					__( '%1$d of %2$d images lack explicit width and height attributes, which can cause layout shifts (CLS).', 'antimanual' ),
					$images_missing_dimensions,
					$total_images
				),
				$images_missing_dimensions >= $total_images ? 'warning' : 'info',
				'Performance'
			);
		} elseif ( $total_images > 0 ) {
			$add_issue(
				__( 'Images have dimension attributes', 'antimanual' ),
				__( 'All images include explicit width and height attributes, preventing layout shifts.', 'antimanual' ),
				'passed',
				'Performance'
			);
		}

		// --- Focus keyword diversity in title ---
		if ( ! empty( $title ) ) {
			$title_words  = array_filter( str_word_count( strtolower( $title ), 1 ) );
			$stop_words   = array( 'a', 'an', 'the', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'is', 'it', 'by', 'with', 'from', 'as', 'are', 'was', 'be' );
			$title_words  = array_diff( $title_words, $stop_words );
			$unique_words = array_unique( $title_words );

			if ( count( $title_words ) > 0 && count( $title_words ) !== count( $unique_words ) ) {
				$add_issue(
					__( 'Title contains repeated words', 'antimanual' ),
					__( 'The title tag has repeated words. Use diverse, descriptive keywords for a stronger search presence.', 'antimanual' ),
					'info',
					'On-Page SEO'
				);
			}
		}

		$recommendations = array();
		foreach ( $issues as $issue ) {
			if ( ! in_array( $issue['severity'], array( 'critical', 'warning' ), true ) ) {
				continue;
			}
			$recommendations[] = $issue['description'];
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = __( 'No critical blockers found. Keep monitoring title, metadata, and performance as content changes.', 'antimanual' );
		}

		$recommendations = array_slice( array_values( array_unique( $recommendations ) ), 0, 5 );

		// Sort by severity so critical and warnings appear first.
		$severity_order = array(
			'critical' => 0,
			'warning'  => 1,
			'info'     => 2,
			'passed'   => 3,
		);
		usort(
			$issues,
			function ( $a, $b ) use ( $severity_order ) {
				$a_rank = $severity_order[ $a['severity'] ] ?? 99;
				$b_rank = $severity_order[ $b['severity'] ] ?? 99;
				return $a_rank - $b_rank;
			}
		);

		return array(
			'url'                 => esc_url( $url ),
			'seo_score'           => $this->clamp_score( $scores['seo'] ),
			'performance_score'   => $this->clamp_score( $scores['performance'] ),
			'accessibility_score' => $this->clamp_score( $scores['accessibility'] ),
			'title'               => sanitize_text_field( $title ),
			'meta_description'    => sanitize_text_field( $meta_description ),
			'issues'              => $issues,
			'recommendations'     => $this->sanitize_recommendations( $recommendations ),
			'page_stats'          => array(
				'h1_count'           => $h1_count,
				'h2_count'           => $h2_count,
				'image_count'        => $total_images,
				'images_without_alt' => $images_without_alt,
				'internal_links'     => $internal_links,
				'external_links'     => $external_links,
				'word_count'         => $word_count,
				'slug_length'        => $slug_length,
				'html_size_kb'       => round( $html_size_bytes / 1024, 1 ),
				'focus_keyword'      => $focus_keyword,
			),
		);
	}

	/**
	 * Determine whether a title needs auto-fix based on SEO length heuristics.
	 *
	 * @param string $title Post title.
	 * @return bool
	 */
	private function title_needs_fix( string $title ): bool {
		$length = mb_strlen( trim( $title ) );
		return $length < 30 || $length > 60;
	}

	/**
	 * Determine whether a meta description needs auto-fix.
	 *
	 * @param string $description Meta description.
	 * @return bool
	 */
	private function meta_description_needs_fix( string $description ): bool {
		$length = mb_strlen( trim( $description ) );
		return $length < 120 || $length > 160;
	}

	/**
	 * Build an SEO-friendly title candidate.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function build_auto_title( \WP_Post $post ): string {
		$title = trim( (string) $post->post_title );

		if ( '' === $title ) {
			if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', (string) $post->post_content, $matches ) ) {
				$title = trim( wp_strip_all_tags( $matches[1] ?? '' ) );
			}
		}

		if ( '' === $title ) {
			$title = trim( (string) get_the_title( $post->ID ) );
		}

		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %d: post id */
				__( 'Page %d', 'antimanual' ),
				$post->ID
			);
		}

		$site_name = trim( (string) get_bloginfo( 'name' ) );
		$candidate = $title;
		if ( mb_strlen( $candidate ) < 30 && ! empty( $site_name ) && false === stripos( $candidate, $site_name ) ) {
			$candidate = $candidate . ' | ' . $site_name;
		}

		if ( mb_strlen( $candidate ) < 30 ) {
			$candidate .= ' - ' . __( 'Complete Guide', 'antimanual' );
		}

		return $this->truncate_plain_text( $candidate, 60 );
	}

	/**
	 * Build an SEO-friendly meta description candidate.
	 *
	 * @param \WP_Post $post         Post object.
	 * @param string   $title_fallback Title fallback.
	 * @return string
	 */
	private function build_auto_meta_description( \WP_Post $post, string $title_fallback = '' ): string {
		$raw_text = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		$raw_text = preg_replace( '/\s+/u', ' ', $raw_text );
		$raw_text = trim( (string) $raw_text );

		if ( '' === $raw_text ) {
			$raw_text = trim( $title_fallback );
		}

		if ( '' === $raw_text ) {
			return '';
		}

		$description = $this->truncate_plain_text( $raw_text, 160 );

		if ( mb_strlen( $description ) < 120 ) {
			$suffix = __( 'Read more on our site.', 'antimanual' );
			if ( ! empty( $title_fallback ) && false === stripos( $description, $title_fallback ) ) {
				$suffix = sprintf(
					/* translators: %s: title */
					__( 'Learn more about %s.', 'antimanual' ),
					$title_fallback
				);
			}

			$description = trim( $description . ' ' . $suffix );
			$description = $this->truncate_plain_text( $description, 160 );
		}

		if ( mb_strlen( $description ) < 120 ) {
			$site_name = trim( (string) get_bloginfo( 'name' ) );
			if ( ! empty( $site_name ) && false === stripos( $description, $site_name ) ) {
				$description = trim( $description . ' | ' . $site_name );
				$description = $this->truncate_plain_text( $description, 160 );
			}
		}

		return $description;
	}

	/**
	 * Apply safe content-level SEO fixes to img and anchor tags.
	 *
	 * Uses AI to generate descriptive alt text for images missing it.
	 * Falls back to filename-based alt text if the AI call fails.
	 *
	 * @param string $content Post content.
	 * @param int    $post_id Post ID.
	 * @return array
	 */
	private function apply_content_auto_fixes( string $content, int $post_id ): array {
		$updated = $content;
		$stats   = array(
			'alt_added'      => 0,
			'lazy_added'     => 0,
			'noopener_added' => 0,
		);

		// --- Pass 1: collect images that need alt text ---
		$images_needing_alt = array();
		$image_index        = 0;
		preg_replace_callback(
			'/<img\b[^>]*\/?>/i',
			function ( $matches ) use ( &$images_needing_alt, &$image_index ) {
				$tag = $matches[0];
				$alt = trim( $this->get_tag_attribute( $tag, 'alt' ) );
				if ( '' === $alt ) {
					$src = $this->get_tag_attribute( $tag, 'src' );
					$images_needing_alt[ $image_index ] = $src;
				}
				++$image_index;
				return $tag;
			},
			$updated
		);

		// --- Generate AI alt texts in a single batch call ---
		$ai_alt_texts = array();
		if ( ! empty( $images_needing_alt ) ) {
			$ai_alt_texts = $this->generate_ai_alt_texts( $images_needing_alt, $post_id );
		}

		// --- Pass 2: apply alt text ---
		// Note: loading="lazy" is NOT added here because WordPress handles it
		// server-side. Adding it to the saved post_content causes Gutenberg
		// block validation errors ("Attempt recovery" button).
		$image_index = 0;
		$updated     = preg_replace_callback(
			'/<img\b[^>]*\/?>/i',
			function ( $matches ) use ( &$stats, &$image_index, $post_id, $ai_alt_texts ) {
				$tag = $matches[0];

				$alt = trim( $this->get_tag_attribute( $tag, 'alt' ) );
				if ( '' === $alt ) {
					$alt_text = $ai_alt_texts[ $image_index ] ?? $this->build_alt_text_from_src(
						$this->get_tag_attribute( $tag, 'src' ),
						$post_id,
						$image_index + 1
					);
					$tag = $this->upsert_tag_attribute( $tag, 'alt', $alt_text );
					++$stats['alt_added'];
				}

				++$image_index;
				return $tag;
			},
			$updated
		);

		$updated = preg_replace_callback(
			'/<a\b[^>]*>/i',
			function ( $matches ) use ( &$stats ) {
				$tag    = $matches[0];
				$target = strtolower( trim( $this->get_tag_attribute( $tag, 'target' ) ) );
				$href   = trim( $this->get_tag_attribute( $tag, 'href' ) );

				if ( '_blank' !== $target ) {
					return $tag;
				}
				if ( ! $this->is_external_link_href( $href ) ) {
					return $tag;
				}

				$current_rel = trim( strtolower( $this->get_tag_attribute( $tag, 'rel' ) ) );
				$tokens      = array_values( array_filter( preg_split( '/\s+/', $current_rel ?: '' ) ) );
				$original    = $tokens;

				if ( ! in_array( 'noopener', $tokens, true ) ) {
					$tokens[] = 'noopener';
				}
				if ( ! in_array( 'noreferrer', $tokens, true ) ) {
					$tokens[] = 'noreferrer';
				}

				if ( $tokens !== $original ) {
					$tag = $this->upsert_tag_attribute( $tag, 'rel', implode( ' ', $tokens ) );
					++$stats['noopener_added'];
				}

				return $tag;
			},
			$updated
		);

		// Sync Gutenberg block comment JSON with modified HTML attributes.
		$updated = $this->sync_block_comment_attributes( $updated );

		return array(
			'content' => $updated,
			'changed' => $updated !== $content,
			'stats'   => $stats,
		);
	}

	/**
	 * Resolve rendered post content for internal URL quick-audit checks.
	 *
	 * @param string $url URL being audited.
	 * @return string
	 */
	private function get_scoped_content_for_internal_audit( string $url ): string {
		$post_id = url_to_postid( $url );
		if ( $post_id <= 0 ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$content = (string) $post->post_content;
		if ( '' === trim( $content ) ) {
			return '';
		}

		$scoped = $this->render_post_content_for_scan( $post, $content );

		if ( '1' === get_post_meta( $post_id, self::META_FORCE_LAZY_LOADING, true ) ) {
			$scoped = $this->force_lazy_loading_attributes( $scoped );
		}

		if ( '1' === get_post_meta( $post_id, self::META_FORCE_NOOPENER, true ) ) {
			$scoped = $this->force_noopener_attributes( $scoped );
		}

		return $scoped;
	}

	/**
	 * Render post content in the context of a specific post.
	 *
	 * @param \WP_Post $scan_post Post object for rendering context.
	 * @param string   $content Raw post content.
	 * @return string
	 */
	private function render_post_content_for_scan( \WP_Post $scan_post, string $content ): string {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return '';
		}

		global $post;
		$previous_post = $post instanceof \WP_Post ? $post : null;
		$post          = $scan_post;

		if ( function_exists( 'setup_postdata' ) ) {
			setup_postdata( $scan_post );
		}

		$rendered = apply_filters( 'the_content', $content );

		if ( $previous_post instanceof \WP_Post ) {
			$post = $previous_post;
			if ( function_exists( 'setup_postdata' ) ) {
				setup_postdata( $previous_post );
			}
		} elseif ( function_exists( 'wp_reset_postdata' ) ) {
			wp_reset_postdata();
		}

		if ( is_string( $rendered ) && '' !== trim( $rendered ) ) {
			return $rendered;
		}

		return $content;
	}

	/**
	 * Determine whether an href points to an external host.
	 *
	 * @param string $href      Link href.
	 * @param string $base_host Optional host to compare against.
	 * @return bool
	 */
	private function is_external_link_href( string $href, string $base_host = '' ): bool {
		$href = trim( $href );
		if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );
		if ( in_array( $scheme, array( 'mailto', 'tel' ), true ) ) {
			return false;
		}

		$link_host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( '' === $link_host ) {
			return false;
		}

		$base_host = strtolower( trim( $base_host ) );
		if ( '' === $base_host ) {
			$base_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		}

		if ( '' === $base_host ) {
			return true;
		}

		return $link_host !== $base_host;
	}

	/**
	 * Enable a runtime content fix flag for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key to set.
	 * @return bool True when the flag changed.
	 */
	private function enable_runtime_content_fix_flag( int $post_id, string $meta_key ): bool {
		$current = (string) get_post_meta( $post_id, $meta_key, true );
		if ( '1' === $current ) {
			return false;
		}

		update_post_meta( $post_id, $meta_key, '1' );
		return true;
	}

	/**
	 * Count img tags that do not explicitly use loading="lazy".
	 *
	 * @param string $content HTML content.
	 * @return int
	 */
	private function count_missing_lazy_attributes( string $content ): int {
		$missing = 0;

		preg_replace_callback(
			'/<img\b[^>]*\/?>/i',
			function ( $matches ) use ( &$missing ) {
				$loading = strtolower( trim( $this->get_tag_attribute( $matches[0], 'loading' ) ) );
				if ( 'lazy' !== $loading ) {
					++$missing;
				}
				return $matches[0];
			},
			$content
		);

		return $missing;
	}

	/**
	 * Count target="_blank" links missing rel="noopener".
	 *
	 * @param string $content HTML content.
	 * @return int
	 */
	private function count_missing_noopener_attributes( string $content ): int {
		$missing = 0;

		preg_replace_callback(
			'/<a\b[^>]*>/i',
			function ( $matches ) use ( &$missing ) {
				$target = strtolower( trim( $this->get_tag_attribute( $matches[0], 'target' ) ) );
				$href   = trim( $this->get_tag_attribute( $matches[0], 'href' ) );
				$rel    = strtolower( trim( $this->get_tag_attribute( $matches[0], 'rel' ) ) );
				if ( '_blank' === $target && $this->is_external_link_href( $href ) && false === strpos( $rel, 'noopener' ) ) {
					++$missing;
				}
				return $matches[0];
			},
			$content
		);

		return $missing;
	}

	/**
	 * Force loading="lazy" on rendered img tags.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	private function force_lazy_loading_attributes( string $content ): string {
		return preg_replace_callback(
			'/<img\b[^>]*\/?>/i',
			function ( $matches ) {
				$tag     = $matches[0];
				$loading = strtolower( trim( $this->get_tag_attribute( $tag, 'loading' ) ) );
				if ( 'lazy' === $loading ) {
					return $tag;
				}

				return $this->upsert_tag_attribute( $tag, 'loading', 'lazy' );
			},
			$content
		) ?? $content;
	}

	/**
	 * Force rel="noopener noreferrer" for target="_blank" links.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	private function force_noopener_attributes( string $content ): string {
		return preg_replace_callback(
			'/<a\b[^>]*>/i',
			function ( $matches ) {
				$tag    = $matches[0];
				$target = strtolower( trim( $this->get_tag_attribute( $tag, 'target' ) ) );
				$href   = trim( $this->get_tag_attribute( $tag, 'href' ) );

				if ( '_blank' !== $target ) {
					return $tag;
				}
				if ( ! $this->is_external_link_href( $href ) ) {
					return $tag;
				}

				$current_rel = trim( strtolower( $this->get_tag_attribute( $tag, 'rel' ) ) );
				$tokens      = array_values( array_filter( preg_split( '/\s+/', $current_rel ?: '' ) ) );

				if ( ! in_array( 'noopener', $tokens, true ) ) {
					$tokens[] = 'noopener';
				}
				if ( ! in_array( 'noreferrer', $tokens, true ) ) {
					$tokens[] = 'noreferrer';
				}

				return $this->upsert_tag_attribute( $tag, 'rel', implode( ' ', $tokens ) );
			},
			$content
		) ?? $content;
	}

	/**
	 * Sync Gutenberg block comment JSON with actual img tag attributes.
	 *
	 * WordPress Gutenberg blocks store metadata in HTML comments
	 * (e.g. <!-- wp:image {"id":123} -->). When we modify the img tag
	 * attributes directly, the block comment JSON must also be updated
	 * to prevent the editor from showing an "Attempt recovery" button.
	 *
	 * @param string $content Post content with potentially modified img tags.
	 * @return string Content with synced block comments.
	 */
	private function sync_block_comment_attributes( string $content ): string {
		return preg_replace_callback(
			'/(?P<comment><!-- wp:image\s+(?P<json>\{(?:[^{}]|\{[^{}]*\})*\})\s*-->)(?P<inner>.*?)(?P<close><!-- \/wp:image -->)/is',
			function ( $matches ) {
				$comment_json = $matches['json'];
				$inner        = $matches['inner'];
				$close        = $matches['close'];

				// Extract the alt attribute from the img tag inside this block.
				if ( ! preg_match( '/<img\b[^>]*\/?>/i', $inner, $img_match ) ) {
					return $matches[0];
				}

				$img_alt = $this->get_tag_attribute( $img_match[0], 'alt' );
				$attrs   = json_decode( $comment_json, true );

				if ( ! is_array( $attrs ) ) {
					return $matches[0];
				}

				$changed = false;

				// Sync alt text.
				$current_alt = $attrs['alt'] ?? '';
				if ( $img_alt !== $current_alt ) {
					if ( '' === $img_alt ) {
						unset( $attrs['alt'] );
					} else {
						$attrs['alt'] = $img_alt;
					}
					$changed = true;
				}

				if ( ! $changed ) {
					return $matches[0];
				}

				$new_json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				return '<!-- wp:image ' . $new_json . ' -->' . $inner . $close;
			},
			$content
		) ?? $content;
	}

	/**
	 * Generate AI-powered alt texts for images in a single batch request.
	 *
	 * Sends image URLs and post context to the AI provider, which returns
	 * descriptive, SEO-friendly alt text for each image.
	 *
	 * @param array $images  Associative array of image_index => src URL.
	 * @param int   $post_id Post ID for context.
	 * @return array Associative array of image_index => alt text string.
	 */
	private function generate_ai_alt_texts( array $images, int $post_id ): array {
		$post_title = trim( (string) get_the_title( $post_id ) );
		$context    = ! empty( $post_title ) ? $post_title : 'Untitled post';

		$image_lines = array();
		foreach ( $images as $index => $src ) {
			$image_lines[] = $index . '. ' . esc_url( $src );
		}
		$image_list = implode( "\n", $image_lines );

		$instructions = 'You are an SEO specialist that writes concise, descriptive image alt text. '
			. 'Alt text should be clear, specific, and under 125 characters. '
			. 'Do not start with "Image of" or "Photo of". '
			. 'Be descriptive about what the image likely shows based on its filename and the page context. '
			. 'Respond with ONLY a valid JSON object. No markdown, no code fences, no explanation.';

		$user_content = 'Generate alt text for the following images on a page titled "' . $context . '".' . "\n\n"
			. 'Images:' . "\n" . $image_list . "\n\n"
			. 'Return a JSON object mapping each image index (as string key) to its alt text string. '
			. 'Example format: {"0": "Sunset over a calm lake", "1": "Team collaborating in a meeting room"}';

		$reply_data = AIProvider::get_reply( $user_content, '', $instructions );

		if ( isset( $reply_data['error'] ) || ( ! is_string( $reply_data ) && ! isset( $reply_data['reply'] ) ) ) {
			return array();
		}

		$reply = is_array( $reply_data ) && isset( $reply_data['reply'] ) ? $reply_data['reply'] : (string) $reply_data;

		$reply = trim( $reply );

		// Strip markdown code fences the AI may wrap around the JSON.
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $reply, $fence_match ) ) {
			$reply = trim( $fence_match[1] );
		}

		$parsed = json_decode( $reply, true );

		if ( ! is_array( $parsed ) ) {
			return array();
		}

		$result = array();
		foreach ( $parsed as $key => $value ) {
			$idx = intval( $key );
			if ( isset( $images[ $idx ] ) && is_string( $value ) && '' !== trim( $value ) ) {
				$result[ $idx ] = sanitize_text_field( trim( $value ) );
			}
		}

		return $result;
	}

	/**
	 * Build a fallback alt text string from image src.
	 *
	 * Used when AI alt text generation is unavailable or fails.
	 *
	 * @param string $src         Image src URL.
	 * @param int    $post_id     Post ID.
	 * @param int    $image_index Image index fallback.
	 * @return string
	 */
	private function build_alt_text_from_src( string $src, int $post_id, int $image_index ): string {
		$fallback = sprintf(
			/* translators: %d: image number */
			__( 'Image %d', 'antimanual' ),
			$image_index
		);

		$src = trim( $src );
		if ( '' === $src ) {
			return $fallback;
		}

		$src_without_query = strtok( $src, '?' );
		$src_without_query = is_string( $src_without_query ) ? $src_without_query : $src;

		$path = (string) wp_parse_url( $src_without_query, PHP_URL_PATH );
		$file = basename( $path );
		$stem = pathinfo( $file, PATHINFO_FILENAME );
		$stem = preg_replace( '/[-_]+/', ' ', (string) $stem );
		$stem = preg_replace( '/\s+/', ' ', (string) $stem );
		$stem = trim( (string) $stem );

		if ( '' === $stem ) {
			$post_title = trim( (string) get_the_title( $post_id ) );
			if ( '' !== $post_title ) {
				return sanitize_text_field( $post_title );
			}
			return $fallback;
		}

		return sanitize_text_field( ucwords( $stem ) );
	}

	/**
	 * Get an HTML tag attribute value.
	 *
	 * @param string $tag  HTML tag text.
	 * @param string $attr Attribute name.
	 * @return string
	 */
	private function get_tag_attribute( string $tag, string $attr ): string {
		$attr_escaped = preg_quote( $attr, '/' );

		if ( preg_match( '/\b' . $attr_escaped . '\s*=\s*([\'"])(.*?)\1/i', $tag, $matches ) ) {
			return html_entity_decode( $matches[2], ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		}

		if ( preg_match( '/\b' . $attr_escaped . '\s*=\s*([^\s>"\']+)/i', $tag, $matches ) ) {
			return (string) $matches[1];
		}

		return '';
	}

	/**
	 * Upsert an HTML tag attribute value.
	 *
	 * @param string $tag   HTML tag text.
	 * @param string $attr  Attribute name.
	 * @param string $value Attribute value.
	 * @return string
	 */
	private function upsert_tag_attribute( string $tag, string $attr, string $value ): string {
		$attr_escaped = preg_quote( $attr, '/' );
		$replacement  = sprintf( '%s="%s"', $attr, esc_attr( $value ) );

		if ( preg_match( '/\b' . $attr_escaped . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', $tag ) ) {
			return (string) preg_replace(
				'/\b' . $attr_escaped . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
				$replacement,
				$tag,
				1
			);
		}

		if ( preg_match( '/\/>\s*$/', $tag ) ) {
			return (string) preg_replace( '/\/>\s*$/', ' ' . $replacement . ' />', $tag, 1 );
		}

		if ( preg_match( '/>\s*$/', $tag ) ) {
			return (string) preg_replace( '/>\s*$/', ' ' . $replacement . '>', $tag, 1 );
		}

		return $tag . ' ' . $replacement;
	}

	/**
	 * Truncate plain text without breaking words abruptly.
	 *
	 * @param string $text      Text to truncate.
	 * @param int    $max_chars Max chars.
	 * @return string
	 */
	private function truncate_plain_text( string $text, int $max_chars ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}

		$slice      = trim( mb_substr( $text, 0, $max_chars ) );
		$last_space = mb_strrpos( $slice, ' ' );
		if ( false !== $last_space && $last_space > (int) floor( $max_chars * 0.6 ) ) {
			$slice = mb_substr( $slice, 0, $last_space );
		}

		return trim( $slice );
	}

	/**
	 * Update common SEO plugin meta keys when available.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $title         SEO title.
	 * @param string $description   SEO description.
	 * @param string $focus_keyword Focus keyword.
	 * @return int Number of meta keys updated.
	 */
	private function update_existing_seo_meta_keys( int $post_id, string $title, string $description, string $focus_keyword = '' ): int {
		$meta_keys = [];

		if ( '' !== $title ) {
			$meta_keys['_yoast_wpseo_title']     = $title;
			$meta_keys['rank_math_title']        = $title;
			$meta_keys['_aioseo_title']          = $title;
			$meta_keys['_seopress_titles_title'] = $title;
		}

		if ( '' !== $description ) {
			$meta_keys['_yoast_wpseo_metadesc']  = $description;
			$meta_keys['rank_math_description']  = $description;
			$meta_keys['_aioseo_description']    = $description;
			$meta_keys['_seopress_titles_desc']  = $description;
		}

		if ( '' !== $focus_keyword ) {
			$meta_keys['rank_math_focus_keyword'] = $focus_keyword;
			$meta_keys['_yoast_wpseo_focuskw']   = $focus_keyword;
			$meta_keys['_atml_focus_keyword']     = $focus_keyword;
		}

		// Detect which SEO plugin is active so we can create meta keys
		// even when they don't exist yet (e.g. "missing meta description").
		$active_prefixes = $this->get_active_seo_meta_prefixes();

		$updated_count = 0;
		foreach ( $meta_keys as $meta_key => $meta_value ) {
			$key_belongs_to_active = empty( $active_prefixes ) || $this->meta_key_matches_prefixes( $meta_key, $active_prefixes );

			// Always upsert _atml_ keys since they belong to our own plugin.
			$is_own_key = 0 === strpos( $meta_key, '_atml_' );

			if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $meta_value ) );
				++$updated_count;
			} elseif ( $key_belongs_to_active || $is_own_key ) {
				add_post_meta( $post_id, $meta_key, sanitize_text_field( $meta_value ), true );
				++$updated_count;
			}
		}

		return $updated_count;
	}

	/**
	 * Get the current meta description from SEO plugin meta only.
	 *
	 * Returns the value from the first active SEO plugin meta key
	 * that has a non-empty value, or empty string if none found.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string The SEO plugin meta description, or empty string.
	 */
	private function get_seo_meta_description( \WP_Post $post ): string {
		$seo_meta_keys = array(
			'rank_math_description',
			'_yoast_wpseo_metadesc',
			'_aioseo_description',
			'_seopress_titles_desc',
		);

		foreach ( $seo_meta_keys as $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * Detect which SEO plugins are active and return their meta key prefixes.
	 *
	 * @return string[] Array of meta key prefixes for active SEO plugins.
	 */
	private function get_active_seo_meta_prefixes(): array {
		$prefixes = array();

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\RankMath' ) ) {
			$prefixes[] = 'rank_math_';
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$prefixes[] = '_yoast_wpseo_';
		}

		if ( defined( 'AIOSEO_VERSION' ) || defined( 'AIOSEO_PHP_VERSION_DIR' ) ) {
			$prefixes[] = '_aioseo_';
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$prefixes[] = '_seopress_';
		}

		return $prefixes;
	}

	/**
	 * Check whether a meta key belongs to any of the given SEO plugin prefixes.
	 *
	 * @param string   $meta_key Meta key to check.
	 * @param string[] $prefixes Active SEO plugin prefixes.
	 * @return bool
	 */
	private function meta_key_matches_prefixes( string $meta_key, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clear cached SEO analysis results for a list of URLs.
	 *
	 * @param array $urls URLs to clear.
	 * @return void
	 */
	private function clear_cached_analyses( array $urls ): void {
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( empty( $url ) ) {
				continue;
			}
			delete_transient( $this->get_cache_key( $url ) );
		}
	}

	/**
	 * Build a transient cache key for a URL.
	 *
	 * @param string $url URL to hash.
	 * @return string
	 */
	private function get_cache_key( string $url ): string {
		return self::CACHE_PREFIX . md5( strtolower( trim( $url ) ) );
	}

	/**
	 * Clamp a score value between 0 and 100.
	 *
	 * @param mixed $value The score value.
	 * @return int The clamped score.
	 */
	private function clamp_score( $value ): int {
		return max( 0, min( 100, intval( $value ) ) );
	}

	/**
	 * Sanitize the issues array from the AI response.
	 *
	 * @param array $issues Raw issues array.
	 * @return array Sanitized issues.
	 */
	private function sanitize_issues( array $issues ): array {
		$valid_severities = array( 'critical', 'warning', 'info', 'passed' );
		$sanitized        = array();

		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}

			$severity = sanitize_text_field( $issue['severity'] ?? 'info' );
			if ( ! in_array( $severity, $valid_severities, true ) ) {
				$severity = 'info';
			}

			$sanitized[] = array(
				'title'       => sanitize_text_field( $issue['title'] ?? '' ),
				'description' => sanitize_text_field( $issue['description'] ?? '' ),
				'severity'    => $severity,
				'category'    => sanitize_text_field( $issue['category'] ?? 'General' ),
			);
		}

		// Sort by severity: critical first, then warning, info, passed.
		$severity_order = array_flip( $valid_severities );
		usort(
			$sanitized,
			function ( $a, $b ) use ( $severity_order ) {
				return ( $severity_order[ $a['severity'] ] ?? 99 ) - ( $severity_order[ $b['severity'] ] ?? 99 );
			}
		);

		return $sanitized;
	}

	/**
	 * Sanitize the recommendations array from the AI response.
	 *
	 * @param array $recommendations Raw recommendations array.
	 * @return array Sanitized recommendations.
	 */
	private function sanitize_recommendations( array $recommendations ): array {
		$sanitized = array();

		foreach ( $recommendations as $rec ) {
			if ( is_string( $rec ) && ! empty( trim( $rec ) ) ) {
				$sanitized[] = sanitize_text_field( $rec );
			}
		}

		return $sanitized;
	}
}
