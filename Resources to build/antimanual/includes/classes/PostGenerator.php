<?php
/**
 * PostGenerator Class
 *
 * Handles single post generation with AI.
 *
 * @package Antimanual
 * @since 2.3.0
 */

namespace Antimanual;

use Antimanual\AIProvider;
use Antimanual\AIResponseCleaner;
use Antimanual\PostPromptBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PostGenerator
 *
 * Generates posts with AI including title, content, slug, excerpt, and taxonomies.
 *
 * @since 2.3.0
 */
class PostGenerator {

	/**
	 * Meta key to identify AI-generated posts.
	 *
	 * @var string
	 */
	public static $meta_key = '_atml_generated_post';

	/**
	 * Meta key for generation timestamp.
	 *
	 * @var string
	 */
	public static $meta_timestamp_key = '_atml_generated_at';

	/**
	 * Meta key for the original prompt.
	 *
	 * @var string
	 */
	public static $meta_prompt_key = '_atml_generation_prompt';

	/**
	 * Generate a single post with AI.
	 *
	 * @since 2.3.0
	 *
	 * @param array $params {
	 *     Generation parameters.
	 *
	 *     @type string $prompt           Required. The topic/prompt for the post.
	 *     @type string $tone             Optional. Writing tone. Default 'blog-style'.
	 *     @type string $language         Optional. Article language. Default 'English'.
	 *     @type int    $min_length       Optional. Minimum word count. Default 800.
	 *     @type int    $max_length       Optional. Maximum word count. Default 1200.
	 *     @type string $slug_language    Optional. Slug language. Default 'English'.
	 *     @type int    $slug_max_length  Optional. Max slug characters. Default 50.
	 *     @type string $post_type        Optional. Post type. Default 'post'.
	 *     @type int    $author           Optional. Author user ID. Default current user.
	 *     @type string $status           Optional. Post status. Default 'draft'.
	 *     @type int    $taxonomy_count   Optional. Number of taxonomy terms to generate. Default 3.
	 *     @type int    $parent           Optional. Parent post ID. Default 0.
	 *     @type bool   $generate_excerpt Optional. Auto-generate excerpt. Default true.
	 *     @type int    $excerpt_length   Optional. Excerpt word count. Default 50.
	 *     @type array  $attachments      Optional. Attachment IDs for AI context.
	 * }
	 * @return array|WP_Error {
	 *     Generated post data on success.
	 *
	 *     @type int    $post_id   The created post ID.
	 *     @type string $title     The generated title.
	 *     @type string $slug      The generated slug.
	 *     @type string $excerpt   The generated excerpt (if enabled).
	 *     @type array  $terms     The generated taxonomy terms.
	 *     @type string $edit_link URL to edit the post.
	 *     @type string $view_link URL to view the post.
	 * }
	 */
	public static function generate( array $params ) {
		// Extract and validate parameters.
		$prompt          = $params['prompt'] ?? '';
		$tone            = $params['tone'] ?? $GLOBALS['ATML_STORE']['tones']['blog-style'];
		$language        = $params['language'] ?? 'English';
		$length_range    = PostPromptBuilder::normalize_word_length_range(
			intval( $params['min_length'] ?? 800 ),
			intval( $params['max_length'] ?? 1200 )
		);
		$min_length      = $length_range['min'];
		$max_length      = $length_range['max'];
		$slug_language   = $params['slug_language'] ?? 'English';
		$slug_max_length = intval( $params['slug_max_length'] ?? 50 );
		$post_type       = $params['post_type'] ?? 'post';
		$author          = intval( $params['author'] ?? get_current_user_id() );
		$status          = sanitize_key( (string) ( $params['status'] ?? 'draft' ) );
		$scheduled_date  = sanitize_text_field( (string) ( $params['scheduled_date'] ?? '' ) );
		// Per-taxonomy settings: { "category": { "enabled": true, "count": "3" }, ... }
		// Falls back to the legacy flat generate_taxonomies/taxonomy_count params.
		$raw_taxonomy_settings = $params['taxonomy_settings'] ?? null;
		if ( is_string( $raw_taxonomy_settings ) ) {
			$raw_taxonomy_settings = json_decode( $raw_taxonomy_settings, true );
		}

		$has_per_taxonomy_settings = is_array( $raw_taxonomy_settings ) && ! empty( $raw_taxonomy_settings );

		// Legacy / fallback path.
		$generate_taxonomies = filter_var( $params['generate_taxonomies'] ?? true, FILTER_VALIDATE_BOOLEAN );
		$taxonomy_count      = $generate_taxonomies ? intval( $params['taxonomy_count'] ?? 3 ) : 0;
		$taxonomy_count      = $taxonomy_count > 0 ? max( 1, min( 10, $taxonomy_count ) ) : 0;
		$parent          = intval( $params['parent'] ?? 0 );
		$generate_excerpt = filter_var( $params['generate_excerpt'] ?? true, FILTER_VALIDATE_BOOLEAN );
		$excerpt_length   = intval( $params['excerpt_length'] ?? 50 );
		$attachments      = $params['attachments'] ?? [];
		$use_existing_knowledge = filter_var( $params['use_existing_knowledge'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$pov                = sanitize_text_field( (string) ( $params['pov'] ?? 'third_person' ) );
		$target_audience    = sanitize_text_field( (string) ( $params['target_audience'] ?? 'general' ) );
		$include_conclusion = filter_var( $params['include_conclusion'] ?? true, FILTER_VALIDATE_BOOLEAN );

		$focus_keyword                    = sanitize_text_field( (string) ( $params['focus_keyword'] ?? '' ) );
		$generate_meta_description        = filter_var( $params['generate_meta_description'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$optimize_for_seo                 = filter_var( $params['optimize_for_seo'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$generate_featured_image          = filter_var( $params['generate_featured_image'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$use_post_title_in_featured_image = filter_var( $params['use_post_title_in_featured_image'] ?? true, FILTER_VALIDATE_BOOLEAN );
		$featured_image_inspiration_attachment = intval( $params['featured_image_inspiration_attachment'] ?? 0 );
		$include_image_caption            = filter_var( $params['include_image_caption'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$suggest_internal_links           = filter_var( $params['suggest_internal_links'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$show_internal_links_pro_tip      = filter_var( $params['show_internal_links_pro_tip'] ?? true, FILTER_VALIDATE_BOOLEAN );
		$internal_links_pro_tip_label     = sanitize_text_field( (string) ( $params['internal_links_pro_tip_label'] ?? $params['internal_links_pro_tip_text'] ?? '' ) );
		$internal_links_pro_tip_bg_color  = sanitize_hex_color( (string) ( $params['internal_links_pro_tip_bg_color'] ?? '' ) ) ?: '#f5f1dd';
		$include_toc               = filter_var( $params['include_toc'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$include_faq               = filter_var( $params['include_faq'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$faq_block_type            = in_array( $params['faq_block_type'] ?? 'default', [ 'default', 'advanced' ], true ) ? $params['faq_block_type'] : 'default';
		$include_content_images    = filter_var( $params['include_content_images'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$tone_is_custom            = filter_var( $params['tone_is_custom'] ?? false, FILTER_VALIDATE_BOOLEAN );

		// Custom outline provided by the user (reviewed / edited before generation).
		$custom_outline_raw = $params['custom_outline'] ?? '';
		$custom_outline     = null;
		if ( ! empty( $custom_outline_raw ) ) {
			$decoded = is_string( $custom_outline_raw ) ? json_decode( $custom_outline_raw, true ) : $custom_outline_raw;
			if ( is_array( $decoded ) && ! empty( $decoded['title'] ) && ! empty( $decoded['sections'] ) ) {
				$custom_outline = [
					'title'    => sanitize_text_field( $decoded['title'] ),
					'sections' => array_map( function ( $section ) {
						return [
							'heading' => sanitize_text_field( $section['heading'] ?? '' ),
							'points'  => array_map( 'sanitize_text_field', $section['points'] ?? [] ),
						];
					}, $decoded['sections'] ?? [] ),
					'estimated_words' => intval( $decoded['estimated_words'] ?? 0 ),
				];
			}
		}

		$allowed_statuses = [ 'draft', 'publish', 'private', 'pending', 'future' ];
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$author_user = get_user_by( 'id', $author );
		if ( ! $author_user instanceof \WP_User ) {
			$author = get_current_user_id();
		}

		$scheduled_datetime = null;
		if ( 'future' === $status ) {
			$scheduled_datetime = \DateTime::createFromFormat( 'Y-m-d H:i:s', $scheduled_date, wp_timezone() );

			if ( ! ( $scheduled_datetime instanceof \DateTime ) ) {
				return new \WP_Error( 'invalid_schedule_date', __( 'Please provide a valid future date and time.', 'antimanual' ) );
			}

			if ( $scheduled_datetime->getTimestamp() <= current_time( 'timestamp' ) ) {
				return new \WP_Error( 'invalid_schedule_date', __( 'Scheduled date/time must be in the future.', 'antimanual' ) );
			}
		}

		// Validate required fields.
		if ( empty( $prompt ) ) {
			return new \WP_Error( 'empty_prompt', __( 'Please provide a topic or prompt for the post.', 'antimanual' ) );
		}

		$knowledge_context = '';
		if ( $use_existing_knowledge ) {
			$knowledge_context = self::build_knowledge_context( $prompt );

			if ( empty( $knowledge_context ) ) {
				return new \WP_Error(
					'knowledge_context_empty',
					__( 'No knowledge base content found. Add content to Knowledge Base or disable "Use Existing Knowledge Base".', 'antimanual' )
				);
			}
		}

		// Get available taxonomies for this post type.
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$taxonomy_info = [];

		foreach ( $taxonomies as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}

			$taxonomy_info[] = [
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => $tax->hierarchical,
			];
		}

		// Apply per-taxonomy settings when present.
		// When the new taxonomy_settings param is sent from the frontend, it overrides
		// the legacy generate_taxonomies/taxonomy_count params.
		if ( $has_per_taxonomy_settings ) {
			$filtered_taxonomy_info = [];

			foreach ( $taxonomy_info as $tax ) {
				$slug    = $tax['name'];
				$setting = $raw_taxonomy_settings[ $slug ] ?? null;

				if ( ! is_array( $setting ) ) {
					// Taxonomy not found in settings — skip (user didn't configure it).
					continue;
				}

				$enabled = filter_var( $setting['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN );
				if ( ! $enabled ) {
					continue;
				}

				$per_count          = intval( $setting['count'] ?? 3 );
				$per_count          = max( 1, min( 10, $per_count ) );
				$tax['count']       = $per_count;
				$filtered_taxonomy_info[] = $tax;
			}

			// Use the highest per-taxonomy count as the global $taxonomy_count so
			// existing AI generation functions stay compatible; they receive the filtered
			// list and generate that many terms for each included taxonomy.
			if ( ! empty( $filtered_taxonomy_info ) ) {
				$taxonomy_info  = $filtered_taxonomy_info;
				$taxonomy_count = max( array_column( $taxonomy_info, 'count' ) );
			} else {
				$taxonomy_info  = [];
				$taxonomy_count = 0;
			}
		}

		// Generate content with AI.
		$ai_result = self::generate_ai_content(
			$prompt,
			$tone,
			$language,
			$min_length,
			$max_length,
			$slug_language,
			$slug_max_length,
			$taxonomy_info,
			$taxonomy_count,
			$generate_excerpt,
			$excerpt_length,
			$attachments,
			$focus_keyword,
			$generate_meta_description,
			$optimize_for_seo,
			$generate_featured_image,
			$suggest_internal_links,
			$include_toc,
			$include_faq,
			$faq_block_type,
			$knowledge_context,
			$pov,
			$target_audience,
			$include_conclusion,
			$include_content_images,
			$tone_is_custom,
			$custom_outline
		);

		if ( is_wp_error( $ai_result ) ) {
			return $ai_result;
		}

		// Extract generated content.
		$title       = $ai_result['title'] ?? '';
		$content     = $ai_result['content'] ?? '';
		$slug        = $ai_result['slug'] ?? '';
		$excerpt     = $ai_result['excerpt'] ?? '';
		$terms       = $ai_result['taxonomies'] ?? [];

		if ( empty( $title ) || empty( $content ) ) {
			return new \WP_Error( 'generation_failed', __( 'AI failed to generate valid content. Please try again.', 'antimanual' ) );
		}

		// Resolve [INTERNAL_LINK: topic] placeholders with real links.
		if ( $suggest_internal_links ) {
			$content = self::resolve_internal_links( $content );

			if ( $show_internal_links_pro_tip ) {
				$content = self::insert_internal_links_pro_tip_block(
					$content,
					$title,
					$internal_links_pro_tip_label,
					$internal_links_pro_tip_bg_color
				);
			}
		}

		// Ensure TOC links point to real heading anchors so scrolling works reliably.
		if ( $include_toc ) {
			$content = self::enforce_toc_scroll_targets( $content );
			$content = self::enforce_toc_unordered_list( $content );
		}
		if ( ! $include_content_images ) {
			$content = self::strip_content_image_placeholders( $content );
		}

		// Create the post.
		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_name'    => $slug,
			'post_status'  => $status,
			'post_type'    => $post_type,
			'post_author'  => $author,
			'post_parent'  => $parent,
			'meta_input'   => [
				self::$meta_key           => true,
				self::$meta_timestamp_key => current_time( 'mysql' ),
				self::$meta_prompt_key    => $prompt,
			],
		];

		if ( 'future' === $status && $scheduled_datetime instanceof \DateTime ) {
			$post_data['post_date']     = $scheduled_datetime->format( 'Y-m-d H:i:s' );
			$post_data['post_date_gmt'] = get_gmt_from_date( $post_data['post_date'] );
		}

		if ( $generate_excerpt && ! empty( $excerpt ) ) {
			$post_data['post_excerpt'] = $excerpt;
		}

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to create the post.', 'antimanual' ) );
		}

		// Handle Featured Image generation.
		$image_id        = 0;
		$content_updated = false;
		if ( $generate_featured_image ) {
			$featured_image_prompt = trim( (string) ( $ai_result['image_prompt'] ?? '' ) );
			$title_context         = trim( wp_strip_all_tags( (string) $title ) );

			if ( $use_post_title_in_featured_image ) {
				if ( '' !== $title_context ) {
					$short_title_text = self::build_featured_image_title_text( $title_context );
					$title_text_instruction = sprintf(
						'The image MUST include short, meaningful title text that is derived from the post title but NOT the exact full post title. Use this short text in the image: "%s". Keep the typography clear, legible, and high-contrast.',
						$short_title_text
					);
					$featured_image_prompt = '' !== $featured_image_prompt
						? sprintf(
							'Create a professional featured image for this article. %1$s Additional visual guidance: %2$s',
							$title_text_instruction,
							$featured_image_prompt
						)
						: sprintf(
							'Create a professional featured image for this article. %s',
							$title_text_instruction
						);
				}
			}

			$inspiration_guidance = self::build_featured_image_inspiration_guidance(
				$featured_image_inspiration_attachment,
				'' !== $title_context ? $title_context : trim( wp_strip_all_tags( (string) $prompt ) )
			);
			if ( '' !== $inspiration_guidance ) {
				$featured_image_prompt = '' !== $featured_image_prompt
					? sprintf(
						'%1$s Follow this inspiration direction (style/composition/colors), while creating an original design: %2$s',
						$featured_image_prompt,
						$inspiration_guidance
					)
					: sprintf(
						'Create a clean, professional featured image using this inspiration direction (style/composition/colors), while creating an original design: %s',
						$inspiration_guidance
					);
			}

			if ( '' === $featured_image_prompt ) {
				$featured_image_prompt = sprintf(
					'Create a clean, professional featured image for this post topic: %s',
					trim( wp_strip_all_tags( (string) $prompt ) )
				);
			}

			$image_url = AIProvider::generate_image( $featured_image_prompt );

			if ( ! is_wp_error( $image_url ) ) {
				$image_id = self::sideload_image( $image_url, $post_id, $title );
				if ( ! is_wp_error( $image_id ) ) {
					set_post_thumbnail( $post_id, $image_id );
				}
			}
		}

		// Handle Content Images — replace [CONTENT_IMAGE: description] placeholders.
		if ( $include_content_images ) {
			$content = self::resolve_content_images( $content, $post_id, $include_image_caption, $language );
			$content = self::strip_content_image_placeholders( $content );
			$content_updated = true;
		}

		if ( $content_updated ) {
			// Update the post with generated image blocks and/or resolved placeholders.
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $content,
			] );
		}

		// Handle SEO Meta.
		if ( ! empty( $ai_result['meta_description'] ) ) {
			update_post_meta( $post_id, '_atml_meta_description', $ai_result['meta_description'] );
			// Support popular SEO plugins if active.
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $ai_result['meta_description'] );
			update_post_meta( $post_id, 'rank_math_description', $ai_result['meta_description'] );
		}

		if ( ! empty( $focus_keyword ) ) {
			update_post_meta( $post_id, '_atml_focus_keyword', $focus_keyword );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_keyword );
		}

		// Set SEO Title for Rank Math and Yoast (ensures keyword appears in SEO title).
		if ( $optimize_for_seo && ! empty( $title ) ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
		}

		// Update featured image alt text to include focus keyword for SEO.
		if ( $optimize_for_seo && ! empty( $focus_keyword ) && ! empty( $image_id ) && ! is_wp_error( $image_id ) ) {
			$existing_alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			if ( empty( $existing_alt ) || false === stripos( $existing_alt, $focus_keyword ) ) {
				$seo_seed = ! empty( $existing_alt ) ? $existing_alt : $title;
				if ( false === stripos( $seo_seed, $focus_keyword ) ) {
					$seo_seed = $focus_keyword . ' ' . $seo_seed;
				}
				$seo_alt = self::build_generated_image_alt_text( $seo_seed );
				update_post_meta( $image_id, '_wp_attachment_image_alt', sanitize_text_field( $seo_alt ) );
			}
		}

		// Assign generated taxonomy terms.
		$assigned_terms = [];

		if ( ! empty( $terms ) && is_array( $terms ) ) {
			foreach ( $terms as $taxonomy => $term_names ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}

				$tax_obj = get_taxonomy( $taxonomy );
				$term_ids = [];

				foreach ( $term_names as $term_name ) {
					$term_name = sanitize_text_field( $term_name );

					if ( empty( $term_name ) ) {
						continue;
					}

					// Check if term exists.
					$existing_term = get_term_by( 'name', $term_name, $taxonomy );

					if ( $existing_term ) {
						$term_ids[] = $existing_term->term_id;
					} else {
						// Create new term.
						$new_term = wp_insert_term( $term_name, $taxonomy );

						if ( ! is_wp_error( $new_term ) ) {
							$term_ids[] = $new_term['term_id'];
						}
					}
				}

				if ( ! empty( $term_ids ) ) {
					wp_set_object_terms( $post_id, $term_ids, $taxonomy );
					$assigned_terms[ $taxonomy ] = $term_ids;
				}
			}
		}

		return [
			'post_id'        => $post_id,
			'title'          => $title,
			'slug'           => get_post_field( 'post_name', $post_id ),
			'excerpt'        => $excerpt,
			'terms'          => $assigned_terms,
			'edit_link'      => get_edit_post_link( $post_id, '&' ),
			'view_link'      => get_permalink( $post_id ),
			'image_id'       => $image_id,
			'post_status'    => get_post_status( $post_id ),
			'scheduled_date' => ( 'future' === $status && $scheduled_datetime instanceof \DateTime )
				? $scheduled_datetime->format( 'Y-m-d H:i:s' )
				: '',
		];
	}

	/**
	 * Download an image from a URL and sideload it into the media library.
	 *
	 * @param string $url     The image URL.
	 * @param int    $post_id The post ID to attach the image to.
	 * @param string $desc    Optional description/alt text.
	 * @return int|\WP_Error   Attachment ID on success, or WP_Error.
	 */
	private static function sideload_image( $url, $post_id, $desc = null ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$alt_text = self::build_generated_image_alt_text( (string) ( $desc ?? '' ) );

		// Determine file extension from URL, default to png.
		$url_path  = wp_parse_url( $url, PHP_URL_PATH );
		$extension = $url_path ? pathinfo( $url_path, PATHINFO_EXTENSION ) : 'png';
		if ( empty( $extension ) ) {
			$extension = 'png';
		}

		// Check if this is a local file URL (e.g. Gemini saves images locally).
		// download_url() may fail on local loopback URLs in some hosting environments.
		$upload_dir = wp_get_upload_dir();
		$base_url   = set_url_scheme( $upload_dir['baseurl'] );
		$check_url  = set_url_scheme( $url );

		if ( strpos( $check_url, $base_url ) === 0 ) {
			// Convert URL to local path.
			$local_path = str_replace( $base_url, $upload_dir['basedir'], $check_url );
			$real_base  = realpath( $upload_dir['basedir'] );
			$real_path  = realpath( $local_path );

			if ( $real_base && $real_path && strpos( $real_path, $real_base ) === 0 && file_exists( $real_path ) ) {
				// Copy to a temp file for media_handle_sideload.
				$tmp = \wp_tempnam( $real_path );
				copy( $real_path, $tmp );

				$file_array = [
					'name'     => sanitize_title( $desc ?: 'featured-image' ) . '.' . $extension,
					'tmp_name' => $tmp,
				];

				$id = \media_handle_sideload( $file_array, $post_id, $desc );

				if ( is_wp_error( $id ) ) {
					\wp_delete_file( $tmp );
				} else {
					update_post_meta( $id, '_wp_attachment_image_alt', $alt_text );
				}

				// Clean up the temp image file.
				\wp_delete_file( $real_path );

				return $id;
			}
		}

		// Fallback: download from a remote image URL when the provider returns one.
		$tmp = \download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_array = [
			'name'     => sanitize_title( $desc ?: 'featured-image' ) . '.' . $extension,
			'tmp_name' => $tmp,
		];

		$id = \media_handle_sideload( $file_array, $post_id, $desc );

		if ( is_wp_error( $id ) ) {
			\wp_delete_file( $tmp );
		} else {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt_text );
		}

		return $id;
	}

	/**
	 * Build generated image alt text constrained to 8-12 words.
	 *
	 * @param string $source Source text used to derive the alt text.
	 * @return string
	 */
	private static function build_generated_image_alt_text( string $source ): string {
		$source = html_entity_decode( wp_strip_all_tags( $source ), ENT_QUOTES, 'UTF-8' );
		$source = preg_replace( '/[^\p{L}\p{N}\s\'-]+/u', ' ', $source );
		$words  = preg_split( '/\s+/u', trim( (string) $source ), -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $words ) ) {
			$words = [ 'Generated', 'illustration', 'for', 'this', 'article', 'section', 'with', 'visual', 'context' ];
		}

		$padding = [ 'for', 'this', 'article', 'section', 'with', 'clear', 'visual', 'context' ];
		$pad_i   = 0;

		while ( count( $words ) < 8 ) {
			$words[] = $padding[ $pad_i % count( $padding ) ];
			$pad_i++;
		}

		if ( count( $words ) > 12 ) {
			$words = array_slice( $words, 0, 12 );
		}

		return sanitize_text_field( implode( ' ', $words ) );
	}

	/**
	 * Build a short, meaningful overlay text from the full post title.
	 *
	 * Ensures the returned text is not exactly the same as the full title.
	 *
	 * @param string $title Full post title.
	 * @return string
	 */
	public static function build_featured_image_title_text( string $title ): string {
		$title = trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $title ) {
			return __( 'Featured Guide', 'antimanual' );
		}

		$segments = preg_split( '/\s*[:\-–—\|\?!.]\s*/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		$base     = trim( (string) ( $segments[0] ?? $title ) );
		if ( '' === $base ) {
			$base = $title;
		}

		$short = trim( wp_trim_words( $base, 6, '' ) );
		if ( '' === $short ) {
			$short = trim( wp_trim_words( $title, 6, '' ) );
		}

		$normalize = static function ( string $text ): string {
			$text = mb_strtolower( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ), 'UTF-8' );
			$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
			$text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );
			return (string) $text;
		};

		if ( $normalize( $short ) === $normalize( $title ) ) {
			$parts = preg_split( '/\s+/u', $short, -1, PREG_SPLIT_NO_EMPTY );
			if ( count( $parts ) >= 4 ) {
				$short = implode( ' ', array_slice( $parts, 0, 4 ) );
			} else {
				$short = trim( $short . ' ' . __( 'Guide', 'antimanual' ) );
			}
		}

		$short = trim( preg_replace( '/\s+/u', ' ', (string) $short ) );

		return sanitize_text_field( $short );
	}

	/**
	 * Build prompt guidance from a user-provided inspiration image.
	 *
	 * @param int    $attachment_id Inspiration image attachment ID.
	 * @param string $topic         Topic/title context for analysis.
	 * @return string
	 */
	private static function build_featured_image_inspiration_guidance( int $attachment_id, string $topic ): string {
		if ( $attachment_id <= 0 || ! atml_is_public_site() ) {
			return '';
		}

		$image_url = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return '';
		}

		$instructions = '
			Analyze the reference image and return concise visual direction for creating a new featured image.
			Rules:
			- Return only one sentence, 12-28 words.
			- Describe style, composition, lighting, and color cues.
			- Do not copy the image exactly; use it only as inspiration.
			- No markdown, bullets, labels, or extra commentary.
		';

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $instructions,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => sprintf(
							'Create one-sentence inspiration guidance for this article topic: %s',
							sanitize_text_field( $topic )
						),
					],
					[
						'type'     => 'input_file',
						'file_url' => esc_url_raw( $image_url ),
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input, '', '', 220 );
		if ( ! is_string( $response ) || '' === trim( $response ) ) {
			return '';
		}

		$guidance = trim( wp_strip_all_tags( $response ) );
		$guidance = trim( $guidance, "\"'` \t\n\r\0\x0B" );
		$guidance = preg_replace( '/\s+/', ' ', $guidance );
		$guidance = sanitize_text_field( (string) $guidance );

		if ( '' === $guidance ) {
			return '';
		}

		return rtrim( $guidance, '.' ) . '.';
	}

	/**
	 * Ensure heading anchors exist and TOC hash links target them.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private static function enforce_toc_scroll_targets( string $content ): string {
		if ( false === stripos( $content, 'wp:heading' ) ) {
			return $content;
		}

		$used_anchors   = [];
		$heading_ids    = [];
		$heading_lookup = [];
		$site_host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$heading_pattern = '/<!--\s*wp:heading(?P<attrs>.*?)-->\s*<h(?P<level>[1-6])(?P<hattrs>[^>]*)>(?P<label>.*?)<\/h(?P=level)>\s*<!--\s*\/wp:heading\s*-->/si';

		$content = preg_replace_callback(
			$heading_pattern,
			function ( $match ) use ( &$used_anchors, &$heading_ids, &$heading_lookup ) {
				$attrs = [];
				$raw_attrs = trim( (string) $match['attrs'] );
				if ( '' !== $raw_attrs && '{' === substr( $raw_attrs, 0, 1 ) ) {
					$decoded = json_decode( $raw_attrs, true );
					if ( is_array( $decoded ) ) {
						$attrs = $decoded;
					}
				}

				$heading_text = trim( wp_strip_all_tags( html_entity_decode( $match['label'], ENT_QUOTES, 'UTF-8' ) ) );
				if ( '' === $heading_text ) {
					return $match[0];
				}

				$current_anchor = sanitize_title( (string) ( $attrs['anchor'] ?? '' ) );
				if ( '' === $current_anchor && preg_match( '/\sid=("|\')([^"\']+)\1/i', (string) $match['hattrs'], $id_match ) ) {
					$current_anchor = sanitize_title( (string) $id_match[2] );
				}
				if ( '' === $current_anchor ) {
					$current_anchor = sanitize_title( $heading_text );
				}
				if ( '' === $current_anchor ) {
					$current_anchor = 'section';
				}

				$base_anchor = $current_anchor;
				$suffix      = 2;
				while ( isset( $used_anchors[ $current_anchor ] ) ) {
					$current_anchor = $base_anchor . '-' . $suffix;
					$suffix++;
				}
				$used_anchors[ $current_anchor ] = true;
				$heading_ids[ $current_anchor ]  = true;

				$lookup_key = self::normalize_toc_lookup_key( $heading_text );
				if ( '' !== $lookup_key && ! isset( $heading_lookup[ $lookup_key ] ) ) {
					$heading_lookup[ $lookup_key ] = $current_anchor;
				}

				$attrs['anchor'] = $current_anchor;

				$hattrs = preg_replace( '/\sid=("|\')[^"\']*\1/i', '', (string) $match['hattrs'] );
				$hattrs = trim( $hattrs );
				$hattrs = ( '' !== $hattrs ? ' ' . $hattrs : '' ) . ' id="' . esc_attr( $current_anchor ) . '"';

				$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

				return sprintf(
					'<!-- wp:heading %1$s --><h%2$s%3$s>%4$s</h%2$s><!-- /wp:heading -->',
					$attrs_json,
					$match['level'],
					$hattrs,
					$match['label']
				);
			},
			$content
		);

		if ( empty( $heading_ids ) || false === stripos( $content, 'href=' ) ) {
			return $content;
		}

		$link_pattern = '/<a(?P<attrs>[^>]*\shref=("|\')(?P<href>[^"\']+)\2[^>]*)>(?P<label>.*?)<\/a>/si';

		return preg_replace_callback(
			$link_pattern,
			function ( $match ) use ( $heading_ids, $heading_lookup, $site_host ) {
				$href = html_entity_decode( (string) $match['href'], ENT_QUOTES, 'UTF-8' );
				if ( false === strpos( $href, '#' ) ) {
					return $match[0];
				}

				$hash_only = ( 0 === strpos( $href, '#' ) );
				if ( ! $hash_only ) {
					$link_host = wp_parse_url( $href, PHP_URL_HOST );
					if ( ! empty( $link_host ) && ! empty( $site_host ) && 0 !== strcasecmp( (string) $link_host, (string) $site_host ) ) {
						return $match[0];
					}
				}

				$raw_target = (string) wp_parse_url( $href, PHP_URL_FRAGMENT );
				$target     = sanitize_title( rawurldecode( $raw_target ) );
				if ( '' !== $target && isset( $heading_ids[ $target ] ) ) {
					$canonical_href = '#' . $target;
					if ( $hash_only && $href === $canonical_href ) {
						return $match[0];
					}
					$new_attrs = preg_replace(
						'/\shref=("|\')[^"\']*\1/i',
						' href="' . esc_attr( $canonical_href ) . '"',
						(string) $match['attrs'],
						1
					);
					return '<a' . $new_attrs . '>' . $match['label'] . '</a>';
				}

				$link_text = trim( wp_strip_all_tags( html_entity_decode( (string) $match['label'], ENT_QUOTES, 'UTF-8' ) ) );
				$lookup    = self::normalize_toc_lookup_key( $link_text );
				if ( '' === $lookup || ! isset( $heading_lookup[ $lookup ] ) ) {
					return $match[0];
				}

				$resolved = $heading_lookup[ $lookup ];
				$new_attrs = preg_replace(
					'/\shref=("|\')[^"\']*\1/i',
					' href="#' . esc_attr( $resolved ) . '"',
					(string) $match['attrs'],
					1
				);

				return '<a' . $new_attrs . '>' . $match['label'] . '</a>';
			},
			$content
		);
	}

	/**
	 * Normalize text for TOC link/headline lookup.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function normalize_toc_lookup_key( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/^\s*(?:\d+[\.\)\-:]\s*)+/u', '', $text );
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		return (string) $text;
	}

	/**
	 * Ensure TOC list uses unordered bullets (<ul>) instead of ordered numbering (<ol>).
	 *
	 * Targets list blocks that contain internal hash links, which correspond to TOC output.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private static function enforce_toc_unordered_list( string $content ): string {
		if ( false === stripos( $content, 'wp:list' ) || false === stripos( $content, 'href="#' ) ) {
			return $content;
		}

		$list_pattern = '/<!--\s*wp:list(?P<attrs>\s+\{.*?\})?\s*-->\s*(?P<body><(?:ul|ol)\b[\s\S]*?<\/(?:ul|ol)>)\s*<!--\s*\/wp:list\s*-->/i';

		return preg_replace_callback(
			$list_pattern,
			static function ( $match ) {
				$body = (string) $match['body'];
				if ( false === stripos( $body, 'href="#' ) ) {
					return $match[0];
				}

				$attrs = [];
				if ( ! empty( $match['attrs'] ) ) {
					$decoded = json_decode( trim( (string) $match['attrs'] ), true );
					if ( is_array( $decoded ) ) {
						$attrs = $decoded;
					}
				}

				unset( $attrs['ordered'] );

				$updated_body = preg_replace( '/<\s*ol\b/i', '<ul', $body );
				$updated_body = preg_replace( '/<\s*\/\s*ol\s*>/i', '</ul>', (string) $updated_body );

				$attrs_json = ! empty( $attrs ) ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';

				return '<!-- wp:list' . $attrs_json . ' -->' . $updated_body . '<!-- /wp:list -->';
			},
			$content
		);
	}

	/**
	 * Generate AI content for the post.
	 *
	 * @since 2.3.0
	 *
	 * @param string $prompt           The user's topic/prompt.
	 * @param string $tone             The writing tone.
	 * @param string $language         The article language.
	 * @param int    $min_length       Minimum word count.
	 * @param int    $max_length       Maximum word count.
	 * @param string $slug_language    The slug language.
	 * @param int    $slug_max_length  Maximum slug characters.
	 * @param array  $taxonomy_info    Available taxonomies for this post type.
	 * @param int    $taxonomy_count   Number of terms to generate per taxonomy.
	 * @param bool   $generate_excerpt Whether to generate excerpt.
	 * @param int    $excerpt_length   Excerpt word count.
	 * @param array  $attachments      Attachment IDs for context.
	 * @return array|WP_Error Generated content array or error.
	 */
	private static function generate_ai_content(
		$prompt,
		$tone,
		$language,
		$min_length,
		$max_length,
		$slug_language,
		$slug_max_length,
		$taxonomy_info,
		$taxonomy_count,
		$generate_excerpt,
		$excerpt_length,
		$attachments = [],
		$focus_keyword = '',
		$generate_meta_description = false,
		$optimize_for_seo = false,
		$generate_featured_image = false,
		$suggest_internal_links = false,
		$include_toc = false,
		$include_faq = false,
		$faq_block_type = 'default',
		$knowledge_context = '',
		$pov = 'third_person',
		$target_audience = 'general',
		$include_conclusion = true,
		$include_content_images = false,
		$tone_is_custom = false,
		$custom_outline = null
	) {
		// Use chunked generation for longer articles (800+ words minimum).
		// This generates an outline first, then each section separately for better word count.
		if ( $min_length >= 800 || $custom_outline ) {
			return self::generate_ai_content_chunked(
				$prompt,
				$tone,
				$language,
				$min_length,
				$max_length,
				$slug_language,
				$slug_max_length,
				$taxonomy_info,
				$taxonomy_count,
				$generate_excerpt,
				$excerpt_length,
				$attachments,
				$focus_keyword,
				$generate_meta_description,
				$generate_featured_image,
				$optimize_for_seo,
				$suggest_internal_links,
				$include_toc,
				$include_faq,
				$faq_block_type,
				$knowledge_context,
				$pov,
				$target_audience,
				$include_conclusion,
				$include_content_images,
				$tone_is_custom,
				$custom_outline
			);
		}

		// For shorter articles, use single API call (original logic below).
		// Build taxonomy instructions.
		$taxonomy_instructions = '';
		$taxonomy_json_example = '';
		$excerpt_instructions  = '';
		$excerpt_json          = '';


		if ( ! empty( $taxonomy_info ) && $taxonomy_count > 0 ) {
			$taxonomy_list = [];
			$taxonomy_examples = [];

			$example_terms = [];
			for ( $i = 1; $i <= $taxonomy_count; $i++ ) {
				$example_terms[] = '"Term ' . $i . '"';
			}
			$terms_str = implode( ', ', $example_terms );

			foreach ( $taxonomy_info as $tax ) {
				$taxonomy_list[] = sprintf( '- %s (%s)', $tax['label'], $tax['name'] );
				$taxonomy_examples[] = sprintf( '"%s": [%s]', $tax['name'], $terms_str );
			}

			$taxonomy_instructions = sprintf(
				'Generate exactly %d relevant terms for EACH of the following taxonomies:
%s

The terms should be contextually relevant to the article content and written in "%s".',
				$taxonomy_count,
				implode( "\n", $taxonomy_list ),
				$language
			);

			$taxonomy_json_example = implode( ",\n\t\t\t\t\t", $taxonomy_examples );
		}

		if ( $generate_excerpt ) {
			$excerpt_instructions = sprintf(
				'Generate a compelling excerpt/summary of exactly %d words that captures the essence of the article.',
				$excerpt_length
			);
			$excerpt_json = '"excerpt": "A compelling summary of the article...",';
		}

		// Build Advanced + SEO instructions via the shared PostPromptBuilder.
		$seo_result = PostPromptBuilder::build_seo_instructions( [
			'focus_keyword'             => $focus_keyword,
			'optimize_for_seo'          => $optimize_for_seo,
			'generate_meta_description' => $generate_meta_description,
			'generate_featured_image'   => $generate_featured_image,
		] );

		$adv_result = PostPromptBuilder::build_advanced_instructions( [
			'include_toc'            => $include_toc,
			'include_faq'            => $include_faq,
			'faq_block_type'         => $faq_block_type,
			'include_conclusion'     => $include_conclusion,
			'suggest_internal_links' => $suggest_internal_links,
			'include_content_images' => $include_content_images,
		] );

		$advanced_instructions = $seo_result['instructions'] . $adv_result['instructions'];
		$advanced_json         = $seo_result['json_fields']  . $adv_result['json_fields'];

		// Dynamic section count and depth requirements.
		// Divide min_length by 5 to get a per-section target based on ~5 sections in the article.
		$section_min  = max( 80, intval( $min_length / 5 ) );
		$rec_sections = '3-5';
		$depth_req    = 'Each section MUST have at least ' . $section_min . ' words with detailed explanations, examples, and context. DO NOT write brief or shallow summaries.';
		if ( $min_length >= 800 ) {
			$rec_sections = '5-8';
			$depth_req    = 'High depth with multiple sub-points per section.';
		}
		if ( $min_length >= 1200 ) {
			$rec_sections = '8-12';
			$depth_req    = 'Extremely detailed with deep analysis, case studies, and extensive examples.';
		}

		if ( ! empty( $language ) && strtolower( $language ) !== 'english' ) {
			$language_note = sprintf(
				'⚠️ CRITICAL for %s: You MUST reach the minimum word count of %d words in %s. Do not truncate the content. Expand on every point to ensure the article is long and comprehensive.',
				$language,
				$min_length,
				$language
			);
		} else {
			$language_note = sprintf(
				'⚠️ CRITICAL: You MUST write at least %d words. DO NOT stop early. Expand every point with detailed explanations, examples, and context.',
				$min_length
			);
		}

		$instructions = '
			You are a professional WordPress content writer, SEO specialist, and taxonomy expert.

			Your job is to generate a **complete JSON response** for creating a new WordPress post.
			The response must contain ALL required fields with high-quality, SEO-optimized content.

			**Your entire response must be a single valid JSON object.**
			- No text, comments, explanations, or formatting outside the JSON object.
			- Do NOT wrap the JSON in code fences (no ```json or similar).
			- Every value inside JSON must be valid and properly escaped.

			---

			### JSON Response Format (STRICT - NO DEVIATION)
			{
				"title": "Your SEO-optimized article title here",
				"content": "<!-- wp:paragraph --><p>Your Gutenberg-formatted article content...</p><!-- /wp:paragraph --> ...",
				"slug": "your-seo-friendly-slug-here",
				' . $excerpt_json . '
				' . $advanced_json . '
				"taxonomies": {
					' . $taxonomy_json_example . '
				}
			}

			---

			### Content Generation Rules (NON-NEGOTIABLE)
			- Write in **pure Gutenberg block format** - NO EXCEPTIONS.
			- Only use `<!-- wp:... -->` blocks (with proper JSON attributes where needed).
			- Do **NOT** use Markdown, raw HTML tags outside blocks, or inline styling.
			- Output only Gutenberg blocks inside the `content` field.
			- The content must be rich, engaging, detailed, and SEO-friendly.
			- **Variety of Blocks**: Use a healthy mix of paragraphs, headings (H2-H4), lists (ordered and unordered), and where appropriate, tables or pull-quotes to ensure a premium reading experience.

			---

			### Taxonomy Generation Rules
			' . $taxonomy_instructions . '

			---

			### Advanced Features
			' . $advanced_instructions . '

			---

			Only output the JSON object, nothing else.
		';

		$user_prompt = '
			⚠️⚠️⚠️ CRITICAL: ALL REQUIREMENTS BELOW ARE MANDATORY ⚠️⚠️⚠️

			═══════════════════════════════════════════════════════════════
			📋 MANDATORY ARTICLE PARAMETERS
			═══════════════════════════════════════════════════════════════

			1. ✍️ TONE: "' . $tone . '"
				➜ The ENTIRE article must be written in this EXACT tone.
				' . ( $tone_is_custom ? '⚠️ This is a USER-DEFINED CUSTOM TONE INSTRUCTION. It overrides all defaults. Follow it precisely throughout every sentence of the article.' : '➜ Maintain this tone consistently from introduction to conclusion.' ) . '
			
			* 🎯 TARGET AUDIENCE: "' . $target_audience . '"
			* 👁️ POINT OF VIEW: "' . $pov . '"

			2. 🌐 ARTICLE LANGUAGE: "' . $language . '"
				➜ Write the ENTIRE article (title, content, headings, excerpt, taxonomy terms) in "' . $language . '" ONLY.

			3. 📏 WORD COUNT: ' . $min_length . ' - ' . $max_length . ' words (STRICTLY ENFORCED)
				➜ MINIMUM: ' . $min_length . ' words - DO NOT WRITE LESS.
				➜ MAXIMUM: ' . $max_length . ' words - DO NOT EXCEED.
				➜ ' . $language_note . '

			4. 🔗 SLUG LANGUAGE: "' . $slug_language . '"
				➜ Generate the slug in "' . $slug_language . '" ONLY.

			5. ⚖️ SLUG LENGTH: Maximum ' . $slug_max_length . ' characters
				➜ The slug must be SEO-friendly and derived from the title.
				➜ Keep it under ' . $slug_max_length . ' characters.

			' . ( $generate_excerpt ? '6. 📝 EXCERPT: ' . $excerpt_instructions : '' ) . '

			═══════════════════════════════════════════════════════════════
			📝 CONTENT STRUCTURE REQUIREMENTS
			═══════════════════════════════════════════════════════════════

			1. **Detailed Introduction** (at least ' . max( 60, intval( $min_length * 0.15 ) ) . ' words)
			' . ( $include_toc ? '2. **Table of Contents** — immediately after the first paragraph, include a Table of Contents using a Gutenberg `core/list` block with an unordered bullet list (`<ul>`) only. Do NOT use an ordered/numbered list (`<ol>`).' : '' ) . '
			' . ( $include_toc ? '3' : '2' ) . '. **' . $rec_sections . ' Main Sections** (with clear headings)
			' . ( $include_toc ? '4' : '3' ) . '. **' . $depth_req . '**
			' . ( $include_conclusion ? ( $include_toc ? '5' : '4' ) . '. **Comprehensive Conclusion** (at least ' . max( 50, intval( $min_length * 0.12 ) ) . ' words)' : '' ) . '
			' . ( $include_conclusion ? ( $include_toc ? '6' : '5' ) : ( $include_toc ? '5' : '4' ) ) . '. **Total content MUST be at least ' . $min_length . ' words. DO NOT submit content that is shorter.**

			═══════════════════════════════════════════════════════════════
			🎯 USER\'S TOPIC/PROMPT
			═══════════════════════════════════════════════════════════════

			' . $prompt . '

			═══════════════════════════════════════════════════════════════
			✅ FINAL CHECKLIST
			═══════════════════════════════════════════════════════════════

			✓ Article is in "' . $language . '"
			✓ Tone matches "' . $tone . '"
			✓ Word count is ' . $min_length . '-' . $max_length . '
			✓ Slug is in "' . $slug_language . '" and ≤' . $slug_max_length . ' chars
			✓ Content is in Gutenberg block format
			✓ Taxonomies are generated with relevant terms
			' . ( $include_toc ? '✓ Table of Contents is included after the introduction' : '' ) . '
			' . ( $generate_excerpt ? '✓ Excerpt is exactly ' . $excerpt_length . ' words' : '' ) . '
			✓ Output is valid JSON

			Remember: Output ONLY the JSON object, nothing else.
		';

		if ( ! empty( $knowledge_context ) ) {
			$user_prompt .= '

			═══════════════════════════════════════════════════════════════
			📚 KNOWLEDGE BASE CONTEXT
			═══════════════════════════════════════════════════════════════

			Use this context as a primary source of truth:
			' . $knowledge_context . '
			';
		}

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $instructions,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $user_prompt,
					],
				],
			],
		];

		// Add attachments if available and site is public.
		if ( atml_is_public_site() && ! empty( $attachments ) ) {
			foreach ( $attachments as $attachment_id ) {
				$attachment_url = wp_get_attachment_url( $attachment_id );

				if ( $attachment_url ) {
					$input[1]['content'][] = [
						'type'     => 'input_file',
						'file_url' => esc_url_raw( $attachment_url ),
					];
				}
			}
		}

		// Calculate max output tokens based on article length.
		// Reasoning models (like GPT-5) use a significant portion of tokens for internal "thinking"
		// before generating actual content. Based on testing, we need to account for:
		// - Reasoning tokens: ~8000-12000 tokens (observed in logs)
		// - Content tokens: ~1.5 tokens per word
		// - JSON structure overhead: ~500 tokens
		$reasoning_overhead = 12000;
		$content_tokens     = intval( $max_length * 1.5 );
		$max_output_tokens  = $reasoning_overhead + $content_tokens + 500;

		$response = AIProvider::get_reply( $input, '', '', $max_output_tokens );

		if ( ! is_string( $response ) ) {
			if ( ! empty( $response['error'] ) ) {
				return new \WP_Error( 'ai_failed', $response['error'] );
			}

			return new \WP_Error( 'ai_failed', __( 'Failed to get response from AI provider. Please check your API key and try again.', 'antimanual' ) );
		}

		// Clean the AI response.
		$response = AIResponseCleaner::clean_json_response( $response );
		$parsed   = json_decode( $response, true );

		if ( empty( $parsed ) ) {
			return new \WP_Error( 'ai_parse_failed', __( 'AI returned an invalid response. The content could not be parsed.', 'antimanual' ) );
		}

		if ( empty( $parsed['title'] ) || empty( $parsed['content'] ) ) {
			return new \WP_Error( 'ai_incomplete', __( 'AI returned incomplete content. Missing title or content.', 'antimanual' ) );
		}

		// Clean the individual fields.
		$parsed['title']   = AIResponseCleaner::clean_content( $parsed['title'] );
		$parsed['content'] = AIResponseCleaner::clean_gutenberg_content( $parsed['content'] );
		$parsed['slug']    = AIResponseCleaner::clean_slug( $parsed['slug'] ?? '' );

		if ( isset( $parsed['excerpt'] ) ) {
			$parsed['excerpt'] = AIResponseCleaner::clean_content( $parsed['excerpt'] );
		}

		$adjusted_content = PostPromptBuilder::fit_content_to_word_range(
			$parsed['content'],
			$min_length,
			$max_length,
			[
				'tone'          => $tone,
				'language'      => $language,
				'focus_keyword' => $focus_keyword,
			]
		);

		if ( is_wp_error( $adjusted_content ) ) {
			return $adjusted_content;
		}

		$parsed['content'] = $adjusted_content;
		$word_count        = \Antimanual\Utils\count_post_words( $parsed['content'] );

		if ( $word_count < $min_length || $word_count > $max_length ) {
			return new \WP_Error(
				'content_length_out_of_range',
				sprintf(
					/* translators: 1: actual word count, 2: minimum words, 3: maximum words */
					__( 'Generated content is %1$d words, which is outside the requested %2$d-%3$d word range. Please try again.', 'antimanual' ),
					$word_count,
					$min_length,
					$max_length
				)
			);
		}

		return $parsed;
	}

	/**
	 * Generate AI content using chunked approach for longer articles.
	 *
	 * This method generates content in multiple steps:
	 * 1. Generate outline with section headings and metadata
	 * 2. Generate each section content separately
	 * 3. Combine all sections into final article
	 *
	 * @since 2.6.0
	 *
	 * @param string $prompt           The user's topic/prompt.
	 * @param string $tone             The writing tone.
	 * @param string $language         The article language.
	 * @param int    $min_length       Minimum word count.
	 * @param int    $max_length       Maximum word count.
	 * @param string $slug_language    The slug language.
	 * @param int    $slug_max_length  Maximum slug characters.
	 * @param array  $taxonomy_info    Available taxonomies.
	 * @param int    $taxonomy_count   Terms per taxonomy.
	 * @param bool   $generate_excerpt Whether to generate excerpt.
	 * @param int    $excerpt_length   Excerpt word count.
	 * @param array  $attachments      Attachment IDs.
	 * @param string $focus_keyword    Focus keyword.
	 * @param bool   $generate_meta_description Whether to generate meta.
	 * @param bool   $generate_featured_image Whether to generate image prompt.
	 * @return array|WP_Error Generated content array or error.
	 */
	private static function generate_ai_content_chunked(
		$prompt,
		$tone,
		$language,
		$min_length,
		$max_length,
		$slug_language,
		$slug_max_length,
		$taxonomy_info,
		$taxonomy_count,
		$generate_excerpt,
		$excerpt_length,
		$attachments,
		$focus_keyword,
		$generate_meta_description,
		$generate_featured_image,
		$optimize_for_seo,
		$suggest_internal_links,
		$include_toc,
		$include_faq,
		$faq_block_type = 'default',
		$knowledge_context = '',
		$pov = 'third_person',
		$target_audience = 'general',
		$include_conclusion = true,
		$include_content_images = false,
		$tone_is_custom = false,
		$custom_outline = null
	) {
		// Calculate number of sections based on target word count.
		$target_words      = intval( ( $min_length + $max_length ) / 2 );
		$words_per_section = 400; // Aim for ~400 words per section.
		$num_sections      = max( 3, intval( ceil( $target_words / $words_per_section ) ) );

		// Step 1: Generate outline with metadata — or use the custom outline.
		if ( $custom_outline ) {
			// User has provided a reviewed/edited outline. Build the outline structure
			// that the rest of the pipeline expects.
			$outline = [
				'title'    => $custom_outline['title'] ?? '',
				'sections' => array_map( function ( $section ) {
					return [
						'heading'    => $section['heading'] ?? '',
						'key_points' => implode( ', ', $section['points'] ?? [] ),
					];
				}, $custom_outline['sections'] ?? [] ),
			];
		} else {
			$outline = self::generate_article_outline(
				$prompt,
				$tone,
				$language,
				$num_sections,
				$slug_language,
				$slug_max_length,
				$taxonomy_info,
				$taxonomy_count,
				$generate_excerpt,
				$excerpt_length,
				$attachments,
				$focus_keyword,
				$generate_meta_description,
				$generate_featured_image,
				$optimize_for_seo,
				$suggest_internal_links,
				$include_toc,
				$include_faq,
				$faq_block_type,
				$knowledge_context,
				$pov,
				$target_audience,
				$include_conclusion,
				$include_content_images
			);
		}

		if ( is_wp_error( $outline ) ) {
			return $outline;
		}

		// Step 2: Generate each section content.
		$sections_content = [];
		$section_headings = $outline['sections'] ?? [];

		foreach ( $section_headings as $index => $section ) {
			$section_content = self::generate_section_content(
				$prompt,
				$tone,
				$language,
				$section,
				$index,
				count( $section_headings ),
				$words_per_section,
				$outline['title'] ?? '',
				$focus_keyword,
				$optimize_for_seo,
				$suggest_internal_links,
				$include_toc,
				$include_faq,
				$faq_block_type,
				$knowledge_context,
				$section_headings,
				$pov,
				$target_audience,
				$include_content_images,
				$tone_is_custom
			);

			if ( is_wp_error( $section_content ) ) {
				return $section_content;
			}

			$sections_content[] = $section_content;
		}

		// Step 3: Combine all sections into final content.
		$full_content = implode( "\n\n", $sections_content );

		// Build the final result.
		$result = [
			'title'      => $outline['title'] ?? '',
			'content'    => $full_content,
			'slug'       => $outline['slug'] ?? '',
			'taxonomies' => $outline['taxonomies'] ?? [],
		];

		if ( ! empty( $outline['excerpt'] ) ) {
			$result['excerpt'] = $outline['excerpt'];
		}

		if ( ! empty( $outline['meta_description'] ) ) {
			$result['meta_description'] = $outline['meta_description'];
		}

		if ( ! empty( $outline['image_prompt'] ) ) {
			$result['image_prompt'] = $outline['image_prompt'];
		}

		// Clean the content.
		$result['title']   = AIResponseCleaner::clean_content( $result['title'] );
		$result['content'] = AIResponseCleaner::clean_gutenberg_content( $result['content'] );
		$result['slug']    = AIResponseCleaner::clean_slug( $result['slug'] );

		if ( isset( $result['excerpt'] ) ) {
			$result['excerpt'] = AIResponseCleaner::clean_content( $result['excerpt'] );
		}

		$adjusted_content = PostPromptBuilder::fit_content_to_word_range(
			$result['content'],
			$min_length,
			$max_length,
			[
				'tone'          => $tone,
				'language'      => $language,
				'focus_keyword' => $focus_keyword,
			]
		);

		if ( is_wp_error( $adjusted_content ) ) {
			return $adjusted_content;
		}

		$result['content'] = $adjusted_content;
		$word_count        = \Antimanual\Utils\count_post_words( $result['content'] );

		if ( $word_count < $min_length || $word_count > $max_length ) {
			return new \WP_Error(
				'content_length_out_of_range',
				sprintf(
					/* translators: 1: actual word count, 2: minimum words, 3: maximum words */
					__( 'Generated content is %1$d words, which is outside the requested %2$d-%3$d word range. Please try again.', 'antimanual' ),
					$word_count,
					$min_length,
					$max_length
				)
			);
		}

		return $result;
	}

	/**
	 * Generate article outline with metadata.
	 *
	 * @since 2.6.0
	 *
	 * @param string $prompt           The user's topic/prompt.
	 * @param string $tone             The writing tone.
	 * @param string $language         The article language.
	 * @param int    $num_sections     Number of sections to generate.
	 * @param string $slug_language    The slug language.
	 * @param int    $slug_max_length  Maximum slug characters.
	 * @param array  $taxonomy_info    Available taxonomies.
	 * @param int    $taxonomy_count   Terms per taxonomy.
	 * @param bool   $generate_excerpt Whether to generate excerpt.
	 * @param int    $excerpt_length   Excerpt word count.
	 * @param array  $attachments      Attachment IDs.
	 * @param string $focus_keyword    Focus keyword.
	 * @param bool   $generate_meta_description Whether to generate meta.
	 * @param bool   $generate_featured_image Whether to generate image prompt.
	 * @return array|WP_Error Outline data or error.
	 */
	private static function generate_article_outline(
		$prompt,
		$tone,
		$language,
		$num_sections,
		$slug_language,
		$slug_max_length,
		$taxonomy_info,
		$taxonomy_count,
		$generate_excerpt,
		$excerpt_length,
		$attachments,
		$focus_keyword,
		$generate_meta_description,
		$generate_featured_image,
		$optimize_for_seo,
		$suggest_internal_links,
		$include_toc,
		$include_faq,
		$faq_block_type = 'default',
		$knowledge_context = '',
		$pov = 'third_person',
		$target_audience = 'general',
		$include_conclusion = true,
		$include_content_images = false
	) {
		// Build taxonomy instructions.
		$taxonomy_json_example = '';
		$taxonomy_instruction  = '';

		if ( ! empty( $taxonomy_info ) && $taxonomy_count > 0 ) {
			$example_terms = [];
			for ( $i = 1; $i <= $taxonomy_count; $i++ ) {
				$example_terms[] = '"Term ' . $i . '"';
			}
			$terms_str = implode( ', ', $example_terms );

			$taxonomy_examples = [];
			foreach ( $taxonomy_info as $tax ) {
				$taxonomy_examples[] = sprintf( '"%s": [%s]', $tax['name'], $terms_str );
			}
			$taxonomy_json_example = implode( ', ', $taxonomy_examples );
			$taxonomy_instruction  = sprintf( 'Generate exactly %d contextually relevant terms per taxonomy.', $taxonomy_count );
		}

		// Build optional fields.
		$optional_fields = [];
		if ( $generate_excerpt ) {
			$optional_fields[] = '"excerpt": "Summary here..."';
		}
		if ( $generate_meta_description ) {
			$optional_fields[] = '"meta_description": "SEO description..."';
		}
		if ( $generate_featured_image ) {
			$optional_fields[] = '"image_prompt": "Professional image showing..."';
		}

		$seo_instruction = '';
		if ( $optimize_for_seo ) {
			$seo_instruction = 'Ensure section flow and headings are SEO-friendly.';
			if ( ! empty( $focus_keyword ) ) {
				$seo_instruction .= sprintf(
					' Focus keyword: "%1$s". The title MUST start with or contain the keyword in its first 50%%. Include a sentiment word (e.g. ultimate, proven, essential) AND a power word in the title. Include a number in the title when relevant. The slug MUST contain "%1$s". Include the keyword in at least one section heading.',
					$focus_keyword
				);
			}
		}

		$advanced_outline_instruction = '';
		if ( $include_toc ) {
			$advanced_outline_instruction .= ' The opening section should support a short table of contents.';
		}
		if ( $include_faq ) {
			$advanced_outline_instruction .= ' Make the final section a FAQ section.';
		}
		if ( $include_conclusion ) {
			$advanced_outline_instruction .= ' Include a dedicated conclusion section.';
		}
		if ( $suggest_internal_links ) {
			$advanced_outline_instruction .= ' Plan opportunities for internal-link placeholders across sections.';
		}

		if ( ! empty( $taxonomy_json_example ) ) {
			$optional_fields[] = '"taxonomies": { ' . $taxonomy_json_example . ' }';
		}

		$optional_json_str = ! empty( $optional_fields ) ? ",\n\t" . implode( ",\n\t", $optional_fields ) : '';

		$system_prompt = 'You are an expert content strategist. Create an article outline.

**Output ONLY valid JSON. No text, no code fences, no explanations.**

JSON Format:
{
	"title": "SEO-optimized article title",
	"slug": "seo-friendly-slug",
	"sections": [
		{"heading": "Section Heading", "description": "What this section covers"}
	]' . $optional_json_str . '
}

Rules:
- Create exactly ' . $num_sections . ' sections
- Write in "' . $language . '"
- Slug in "' . $slug_language . '" (max ' . $slug_max_length . ' chars)
' . $taxonomy_instruction . '
' . $seo_instruction . '
' . $advanced_outline_instruction;

		$user_prompt = 'Create an article outline for:

TOPIC: ' . $prompt . '
TONE: ' . $tone . '
SECTIONS: ' . $num_sections . '
' . ( ! empty( $focus_keyword ) ? 'KEYWORD: ' . $focus_keyword : '' );

		if ( ! empty( $knowledge_context ) ) {
			$user_prompt .= '

KNOWLEDGE BASE CONTEXT:
' . $knowledge_context . '
Use this context as a primary source of truth while creating the outline.';
		}

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $system_prompt,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $user_prompt,
					],
				],
			],
		];

		// Add attachments if available.
		if ( atml_is_public_site() && ! empty( $attachments ) ) {
			foreach ( $attachments as $attachment_id ) {
				$attachment_url = wp_get_attachment_url( $attachment_id );
				if ( $attachment_url ) {
					$input[1]['content'][] = [ 'type' => 'input_file', 'file_url' => esc_url_raw( $attachment_url ) ];
				}
			}
		}

		$response = AIProvider::get_reply( $input, '', '', 8000 );

		if ( ! is_string( $response ) ) {
			if ( ! empty( $response['error'] ) ) {
				return new \WP_Error( 'ai_failed', $response['error'] );
			}
			return new \WP_Error( 'ai_failed', __( 'Failed to generate article outline.', 'antimanual' ) );
		}

		$response = AIResponseCleaner::clean_json_response( $response );
		$parsed   = json_decode( $response, true );

		if ( empty( $parsed ) || empty( $parsed['sections'] ) ) {
			return new \WP_Error( 'ai_parse_failed', __( 'Failed to parse article outline.', 'antimanual' ) );
		}

		return $parsed;
	}

	/**
	 * Generate content for a single section.
	 *
	 * @since 2.6.0
	 *
	 * @param string $original_prompt      The original article topic.
	 * @param string $tone                 The writing tone.
	 * @param string $language             The article language.
	 * @param array  $section              Section data (heading, description).
	 * @param int    $section_index        Current section index (0-based).
	 * @param int    $total_sections       Total number of sections.
	 * @param int    $target_words         Target words for this section.
	 * @param string $article_title        The article title for context.
	 * @param string $focus_keyword        Optional. Focus keyword for SEO.
	 * @param bool   $optimize_for_seo     Optional. Whether to optimize for SEO.
	 * @param bool   $suggest_internal_links Optional. Whether to add link placeholders.
	 * @param bool   $include_toc          Optional. Whether to include table of contents.
	 * @param bool   $include_faq          Optional. Whether to make last section a FAQ.
	 * @param string $knowledge_context    Optional. Knowledge base context.
	 * @param array  $all_sections         Optional. All section headings for TOC generation.
	 * @return string|WP_Error Section content in Gutenberg format or error.
	 */
	private static function generate_section_content(
		$original_prompt,
		$tone,
		$language,
		$section,
		$section_index,
		$total_sections,
		$target_words,
		$article_title,
		$focus_keyword = '',
		$optimize_for_seo = false,
		$suggest_internal_links = false,
		$include_toc = false,
		$include_faq = false,
		$faq_block_type = 'default',
		$knowledge_context = '',
		$all_sections = [],
		$pov = 'third_person',
		$target_audience = 'general',
		$include_content_images = false,
		$tone_is_custom = false
	) {
		$heading     = $section['heading'] ?? '';
		$description = $section['description'] ?? '';
		$is_first    = 0 === $section_index;
		$is_last     = $section_index === $total_sections - 1;

		$section_context = 'BODY section - provide detailed, valuable content.';
		if ( $is_first ) {
			$section_context = 'INTRODUCTION - start with an engaging hook.';
		} elseif ( $is_last ) {
			$section_context = 'CONCLUSION - summarize key points, provide closing.';
		}
		if ( $include_faq && $is_last ) {
			$section_context = PostPromptBuilder::build_faq_section_context( $faq_block_type );
		}
		$seo_instruction = '';
		if ( $optimize_for_seo ) {
			if ( ! empty( $focus_keyword ) ) {
				$seo_instruction = sprintf(
					'SEO optimization for keyword "%1$s":
- Use the keyword "%1$s" naturally 1-2 times in this section (aim for ~1-1.5%% density).
- Include "%1$s" in the H2 heading of this section if it fits naturally (at least one section heading across the article must contain it).
- Keep every paragraph under 120 words for readability.
- If this is the FIRST section, the keyword MUST appear in the very first paragraph.
- Include 1 external link to an authoritative source using a proper <a href="..."> tag within a paragraph block.',
					$focus_keyword
				);
			} else {
				$seo_instruction = 'Use SEO-friendly heading structure. Keep paragraphs under 120 words. Include 1 external link to an authoritative source.';
			}
		}

		// Build TOC instruction with actual section headings from the outline.
		$toc_instruction = '';
		if ( $include_toc && $is_first && ! empty( $all_sections ) ) {
			$toc_headings = [];
			foreach ( $all_sections as $idx => $s ) {
				if ( $idx === 0 ) {
					continue; // Skip the introduction section itself.
				}
				$toc_headings[] = $s['heading'] ?? '';
			}
			$toc_headings_list = implode( ', ', array_filter( $toc_headings ) );
			$toc_instruction   = 'After the first paragraph, include a table of contents as a Gutenberg core/list block using an unordered bullet list (<ul>, not <ol>) linking to these sections: ' . $toc_headings_list . '.';
		}

		$link_instruction = $suggest_internal_links
			? 'Include up to one [INTERNAL_LINK: topic] placeholder where contextually relevant in this section.'
			: '';

		$image_instruction = $include_content_images
			? 'If it visually enhances this section, include ONE [CONTENT_IMAGE: detailed description] placeholder as its OWN standalone paragraph block — do NOT place it inline within a sentence. Use this exact format:
<!-- wp:paragraph --><p>[CONTENT_IMAGE: detailed description]</p><!-- /wp:paragraph -->
The description should be specific enough to generate a relevant image.'
			: '';

		$system_prompt = 'You are an expert content writer creating a section of a larger article.

**Output ONLY Gutenberg blocks. No JSON, no explanations, no code fences.**

Start with: <!-- wp:heading --><h2>' . esc_html( $heading ) . '</h2><!-- /wp:heading -->

Then use paragraphs: <!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->
And lists where appropriate: <!-- wp:list --><ul><li>Item</li></ul><!-- /wp:list -->

Write approximately ' . $target_words . ' words.
' . ( $tone_is_custom ? '🎯 STRICT CUSTOM TONE INSTRUCTION (user-defined, must be followed exactly throughout this section):
"' . $tone . '"
Do not revert to a generic or default tone. Every sentence must reflect this exact instruction.' : 'Tone: "' . $tone . '"' ) . '
Target Audience: "' . $target_audience . '"
Point of View: "' . $pov . '"
Language: "' . $language . '"
' . $seo_instruction . '
' . $toc_instruction . '
' . $link_instruction . '
' . $image_instruction . '

' . $section_context;

		$user_prompt = 'Article: "' . $article_title . '"
Topic: ' . $original_prompt . '

Section ' . ( $section_index + 1 ) . '/' . $total_sections . ': "' . $heading . '"
Description: ' . $description . '

Write ~' . $target_words . ' words. Output ONLY Gutenberg blocks.';

		if ( ! empty( $knowledge_context ) ) {
			$user_prompt .= '

Knowledge base context (use as a primary source of truth):
' . $knowledge_context;
		}

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $system_prompt,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $user_prompt,
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input, '', '', 8000 );

		if ( ! is_string( $response ) ) {
			if ( ! empty( $response['error'] ) ) {
				return new \WP_Error( 'ai_section_failed', $response['error'] );
			}
			return new \WP_Error(
				'ai_section_failed',
				/* translators: %s: section heading that failed to generate */
				sprintf( __( 'Failed to generate section: %s', 'antimanual' ), $heading )
			);
		}

		// Clean any code fences.
		$response = preg_replace( '/^```[\w]*\n?/', '', $response );
		$response = preg_replace( '/\n?```$/', '', $response );

		return trim( $response );
	}

	/**
	 * Resolve [CONTENT_IMAGE: description] placeholders in content.
	 *
	 * Generates an AI image for each placeholder and replaces it with a
	 * proper Gutenberg wp:image block. The image block is always inserted as
	 * a standalone top-level block. If the placeholder sits inside a paragraph
	 * block, the paragraph is split so the image appears between two valid
	 * paragraph blocks. Unresolved placeholders are removed.
	 *
	 * @since 2.8.0
	 *
	 * @param string $content         The post content with potential placeholders.
	 * @param int    $post_id         The post ID (used for sideloading).
	 * @param bool   $include_caption Whether to render captions on generated content images.
	 * @param string $language        The article language for generating captions.
	 * @return string The content with resolved or removed placeholders.
	 */
	public static function resolve_content_images( string $content, int $post_id, bool $include_caption = false, string $language = 'English' ): string {
		// Match all [CONTENT_IMAGE: description] patterns.
		if ( ! preg_match_all( '/\[CONTENT_IMAGE:\s*([^\]]+)\]/', $content, $matches, PREG_SET_ORDER ) ) {
			return $content;
		}

		foreach ( $matches as $match ) {
			$full_placeholder = $match[0];
			$description      = trim( $match[1] );

			// Generate the image via AI provider.
			$image_url = AIProvider::generate_image( $description );

			if ( is_wp_error( $image_url ) ) {
				// Remove the placeholder cleanly (may be inside a paragraph).
				$content = self::remove_content_image_placeholder( $content, $full_placeholder );
				continue;
			}

			// Sideload the image into the media library.
			$image_id = self::sideload_image( $image_url, $post_id, $description );

			if ( is_wp_error( $image_id ) ) {
				// Remove the placeholder cleanly (may be inside a paragraph).
				$content = self::remove_content_image_placeholder( $content, $full_placeholder );
				continue;
			}

			$image_url_local = wp_get_attachment_url( $image_id );
			$alt_text        = esc_attr( self::build_generated_image_alt_text( $description ) );
			$caption         = '';

			if ( $include_caption ) {
				$caption = self::build_content_image_caption( $description, $language );
				if ( '' !== $caption ) {
					wp_update_post(
						[
							'ID'           => $image_id,
							'post_excerpt' => $caption,
						]
					);
				}
			}

			// Build the Gutenberg wp:image block.
			$image_block = sprintf(
				"\n\n" . '<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->' . "\n" .
				'<figure class="wp-block-image size-large"><img src="%s" alt="%s" class="wp-image-%d"/>%s</figure>' . "\n" .
				'<!-- /wp:image -->' . "\n\n",
				$image_id,
				esc_url( $image_url_local ),
				$alt_text,
				$image_id,
				'' !== $caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : ''
			);

			$content = self::insert_content_image_block( $content, $full_placeholder, $image_block );
		}

		return $content;
	}

	/**
	 * Build a caption for generated content images.
	 *
	 * When the article language is not English the description is sent to AI
	 * to produce a short, natural caption in the target language.
	 *
	 * @param string $description Image description used for generation.
	 * @param string $language    The article language.
	 * @return string
	 */
	private static function build_content_image_caption( string $description, string $language = 'English' ): string {
		$description = sanitize_text_field( $description );

		$is_english = '' === $language || 0 === strpos( strtolower( $language ), 'english' );

		if ( '' === $description ) {
			if ( $is_english ) {
				return __( 'AI-generated relevant image', 'antimanual' );
			}

			// Generate a short fallback caption in the target language.
			return self::translate_caption( __( 'AI-generated relevant image', 'antimanual' ), $language );
		}

		if ( $is_english ) {
			return $description;
		}

		// Generate a caption in the target language from the description.
		return self::translate_caption( $description, $language );
	}

	/**
	 * Translate / rewrite a caption into the target language via AI.
	 *
	 * Returns the original text on failure so captions never disappear.
	 *
	 * @param string $text     The source caption text.
	 * @param string $language Target language name (e.g. "Spanish").
	 * @return string
	 */
	private static function translate_caption( string $text, string $language ): string {
		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => 'You translate image captions. Return ONLY the translated text — no quotes, no explanation, no markdown.',
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => sprintf(
							"Translate this image caption into %s. Keep it short (under 15 words).\n\n%s",
							$language,
							$text
						),
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input, '', '', 120 );

		if ( ! is_string( $response ) || '' === trim( $response ) ) {
			return $text;
		}

		$translated = trim( wp_strip_all_tags( $response ) );
		$translated = trim( $translated, "\"'` \t\n\r\0\x0B" );
		$translated = sanitize_text_field( $translated );

		return '' !== $translated ? $translated : $text;
	}

	/**
	 * Insert an image block in place of a placeholder, handling nesting.
	 *
	 * If the placeholder is inside a wp:paragraph block, the paragraph is
	 * split into two separate paragraphs with the image block placed between
	 * them. Empty resulting paragraphs are removed.
	 *
	 * @since 2.8.0
	 *
	 * @param string $content     The full post content.
	 * @param string $placeholder The placeholder string to replace.
	 * @param string $image_block The Gutenberg image block markup.
	 * @return string The updated content.
	 */
	private static function insert_content_image_block( string $content, string $placeholder, string $image_block ): string {
		$escaped = preg_quote( $placeholder, '/' );

		// Check if placeholder is inside a wp:paragraph block.
		$pattern = '/<!-- wp:paragraph(?:\s+\{[^}]*\})?\s*-->\s*<p>(.*?)' . $escaped . '(.*?)<\/p>\s*<!-- \/wp:paragraph -->/s';

		if ( preg_match( $pattern, $content, $m ) ) {
			$before_text = trim( $m[1] );
			$after_text  = trim( $m[2] );

			$replacement = '';

			// Build a paragraph for text before the placeholder (if any).
			if ( '' !== $before_text ) {
				$replacement .= '<!-- wp:paragraph -->' . "\n" . '<p>' . $before_text . '</p>' . "\n" . '<!-- /wp:paragraph -->';
			}

			$replacement .= $image_block;

			// Build a paragraph for text after the placeholder (if any).
			if ( '' !== $after_text ) {
				$replacement .= '<!-- wp:paragraph -->' . "\n" . '<p>' . $after_text . '</p>' . "\n" . '<!-- /wp:paragraph -->';
			}

			return preg_replace( $pattern, $replacement, $content, 1 );
		}

		// Placeholder is not inside a paragraph — simple replacement.
		// Still ensure it's on its own line for valid block grammar.
		return str_replace( $placeholder, $image_block, $content );
	}

	/**
	 * Remove a content image placeholder cleanly from the content.
	 *
	 * If the placeholder is the only content in a paragraph block, the entire
	 * paragraph block is removed. Otherwise just the placeholder text is stripped.
	 *
	 * @since 2.8.0
	 *
	 * @param string $content     The full post content.
	 * @param string $placeholder The placeholder string to remove.
	 * @return string The cleaned content.
	 */
	private static function remove_content_image_placeholder( string $content, string $placeholder ): string {
		$escaped = preg_quote( $placeholder, '/' );

		// If the placeholder is the only content in a paragraph block, remove the entire block.
		$solo_pattern = '/<!-- wp:paragraph(?:\s+\{[^}]*\})?\s*-->\s*<p>\s*' . $escaped . '\s*<\/p>\s*<!-- \/wp:paragraph -->/s';

		if ( preg_match( $solo_pattern, $content ) ) {
			return preg_replace( $solo_pattern, '', $content, 1 );
		}

		// Otherwise just strip the placeholder text.
		return str_replace( $placeholder, '', $content );
	}

	/**
	 * Remove any unresolved [CONTENT_IMAGE: ...] placeholders from content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private static function strip_content_image_placeholders( string $content ): string {
		// Remove paragraph blocks that contain only a placeholder token.
		$content = preg_replace(
			'/<!-- wp:paragraph(?:\s+\{[^}]*\})?\s*-->\s*<p>\s*\[CONTENT_IMAGE:\s*[^\]]+\]\s*<\/p>\s*<!-- \/wp:paragraph -->/si',
			'',
			$content
		);

		// Remove any remaining placeholder tokens inline.
		$content = preg_replace( '/\s*\[CONTENT_IMAGE:\s*[^\]]+\]\s*/si', ' ', $content );

		// Remove now-empty paragraph blocks.
		$content = preg_replace(
			'/<!-- wp:paragraph(?:\s+\{[^}]*\})?\s*-->\s*<p>\s*<\/p>\s*<!-- \/wp:paragraph -->/si',
			'',
			$content
		);

		return (string) $content;
	}

	/**
	 * Resolve [INTERNAL_LINK: topic] placeholders in content.
	 *
	 * Searches for published posts matching each placeholder topic and replaces
	 * the placeholder with a real link. Unmatched placeholders are removed cleanly.
	 *
	 * @since 2.6.0
	 *
	 * @param string $content The post content with potential placeholders.
	 * @return string The content with resolved or removed placeholders.
	 */
	public static function resolve_internal_links( string $content ): string {
		// Match all [INTERNAL_LINK: topic] patterns.
		if ( ! preg_match_all( '/\[INTERNAL_LINK:\s*([^\]]+)\]/', $content, $matches, PREG_SET_ORDER ) ) {
			return $content;
		}

		foreach ( $matches as $match ) {
			$full_placeholder = $match[0];
			$topic            = trim( $match[1] );

			// Search for a published post matching the topic.
			$query = new \WP_Query( [
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				's'              => $topic,
				'posts_per_page' => 1,
				'orderby'        => 'relevance',
			] );

			if ( $query->have_posts() ) {
				$linked_post = $query->posts[0];
				$url         = get_permalink( $linked_post->ID );
				$anchor_text = esc_html( $linked_post->post_title );

				// Replace the placeholder with a proper anchor tag.
				$link    = '<a href="' . esc_url( $url ) . '">' . $anchor_text . '</a>';
				$content = str_replace( $full_placeholder, $link, $content );
			} else {
				// No matching post found — remove the placeholder cleanly.
				$content = str_replace( $full_placeholder, '', $content );
			}

			wp_reset_postdata();
		}

		return $content;
	}

	/**
	 * Extract internal links from generated content for Pro Tip rendering.
	 *
	 * @param string $content Post content with resolved links.
	 * @param int    $limit   Maximum number of links to return.
	 * @return array<int, array{url:string,text:string}>
	 */
	private static function extract_internal_links_for_pro_tip( string $content, int $limit = 3 ): array {
		if ( ! preg_match_all( '/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return [];
		}

		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$site_host = is_string( $site_host ) ? strtolower( preg_replace( '/^www\./', '', $site_host ) ) : '';
		$links     = [];
		$seen      = [];

		foreach ( $matches as $match ) {
			if ( count( $links ) >= $limit ) {
				break;
			}

			$raw_href = html_entity_decode( trim( (string) ( $match[2] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
			if ( '' === $raw_href || 0 === strpos( $raw_href, '#' ) || 0 === stripos( $raw_href, 'mailto:' ) || 0 === stripos( $raw_href, 'tel:' ) ) {
				continue;
			}

			$href = esc_url_raw( $raw_href );
			if ( '' === $href ) {
				continue;
			}

			$resolved_url = '';
			$href_host    = wp_parse_url( $href, PHP_URL_HOST );

			if ( is_string( $href_host ) && '' !== $href_host ) {
				$normalized_host = strtolower( preg_replace( '/^www\./', '', $href_host ) );
				if ( '' !== $site_host && $normalized_host !== $site_host ) {
					continue;
				}
				$resolved_url = $href;
			} elseif ( 0 === strpos( $href, '/' ) || 0 === strpos( $href, '?' ) ) {
				$resolved_url = home_url( $href );
			} else {
				continue;
			}

			$resolved_url = esc_url_raw( $resolved_url );
			if ( '' === $resolved_url ) {
				continue;
			}

			$unique_key = strtolower( untrailingslashit( $resolved_url ) );
			if ( isset( $seen[ $unique_key ] ) ) {
				continue;
			}
			$seen[ $unique_key ] = true;

			$anchor_text = trim( wp_strip_all_tags( (string) ( $match[3] ?? '' ) ) );
			if ( '' === $anchor_text ) {
				$anchor_text = __( 'Learn more', 'antimanual' );
			}

			$links[] = [
				'url'  => $resolved_url,
				'text' => $anchor_text,
			];
		}

		return $links;
	}

	/**
	 * Generate AI sentence for the internal-links Pro Tip section.
	 *
	 * Returns one sentence containing exactly one [LINK] placeholder
	 * so the caller can inject a single anchor tag.
	 *
	 * @param string $post_title Post title for context.
	 * @param string $link_title Link title for context.
	 * @return string
	 */
	private static function generate_internal_links_pro_tip_sentence( string $post_title, string $link_title ): string {
		$fallback = __( 'If you want to dive deeper into this topic, check out [LINK] for more insights.', 'antimanual' );

		$instructions = '
			You write one short sentence for a WordPress Pro Tip callout.
			Rules:
			- Return exactly one sentence only.
			- 10-24 words.
			- Include the token [LINK] exactly once where the clickable link should appear.
			- Do NOT include colons anywhere in the sentence.
			- Do not include markdown, quotes, bullets, numbering, or emojis.
			- Do not mention "AI".
		';

		$user_prompt = sprintf(
			"Post title: %s\nLink title: %s\n\nWrite one natural sentence that references this link.",
			sanitize_text_field( $post_title ),
			sanitize_text_field( $link_title )
		);

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $instructions,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $user_prompt,
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input, '', '', 180 );
		if ( ! is_string( $response ) || '' === trim( $response ) ) {
			return $fallback;
		}

		$sentence = trim( wp_strip_all_tags( $response ) );
		$sentence = trim( $sentence, "\"'` \t\n\r\0\x0B" );
		$sentence = preg_replace( '/\s+/', ' ', $sentence );
		$sentence = sanitize_text_field( (string) $sentence );

		if ( '' === $sentence ) {
			return $fallback;
		}

		// Ensure formatting constraints even if model drifts.
		$sentence = str_replace( ':', '', $sentence );
		if ( 1 !== substr_count( $sentence, '[LINK]' ) ) {
			$sentence = $fallback;
		}

		return $sentence;
	}

	/**
	 * Insert a Pro Tip block with important internal links.
	 *
	 * @param string $content   Post content.
	 * @param string $post_title Generated post title.
	 * @param string $tip_label Custom label text (for example: "Pro Tip:").
	 * @param string $bg_color  Hex background color.
	 * @return string
	 */
	public static function insert_internal_links_pro_tip_block( string $content, string $post_title, string $tip_label, string $bg_color ): string {
		$links = self::extract_internal_links_for_pro_tip( $content, 1 );
		if ( empty( $links ) ) {
			return $content;
		}

		$tip_label = trim( sanitize_text_field( $tip_label ) );
		if ( '' === $tip_label ) {
			$tip_label = __( 'Pro Tip:', 'antimanual' );
		}

		$background = sanitize_hex_color( $bg_color );
		if ( '' === $background || null === $background ) {
			$background = '#f5f1dd';
		}
		$primary_link = $links[0];
		$link_markup  = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $primary_link['url'] ),
			esc_html( $primary_link['text'] )
		);
		$sentence = self::generate_internal_links_pro_tip_sentence( $post_title, (string) $primary_link['text'] );
		$sentence_with_link = str_replace( '[LINK]', $link_markup, esc_html( $sentence ) );

		$tip_line = sprintf(
			'<strong>%s</strong> %s',
			esc_html( $tip_label ),
			$sentence_with_link
		);

		$pro_tip_block  = "\n\n" . '<!-- wp:group {"style":{"color":{"background":"' . esc_attr( $background ) . '"},"spacing":{"padding":{"top":"22px","right":"24px","bottom":"22px","left":"24px"},"margin":{"bottom":"30px"}},"border":{"radius":"12px"}}} -->' . "\n";
		$pro_tip_block .= '<div class="wp-block-group has-background" style="border-radius:12px;background-color:' . esc_attr( $background ) . ';padding-top:22px;padding-right:24px;padding-bottom:22px;padding-left:24px;margin-bottom:30px">' . "\n";
		$pro_tip_block .= '<!-- wp:paragraph -->' . "\n";
		$pro_tip_block .= '<p style="margin-bottom:0">' . $tip_line . '</p>' . "\n";
		$pro_tip_block .= '<!-- /wp:paragraph -->' . "\n";
		$pro_tip_block .= '</div>' . "\n";
		$pro_tip_block .= '<!-- /wp:group -->' . "\n\n";

		$first_paragraph_pattern = '/(<!-- wp:paragraph(?:\s+\{[^}]*\})?\s*-->.*?<!-- \/wp:paragraph -->)/s';
		if ( preg_match( $first_paragraph_pattern, $content ) ) {
			$updated = preg_replace_callback(
				$first_paragraph_pattern,
				static function ( $match ) use ( $pro_tip_block ) {
					return ( $match[1] ?? '' ) . $pro_tip_block;
				},
				$content,
				1
			);

			if ( is_string( $updated ) ) {
				return $updated;
			}
		}

		return $pro_tip_block . $content;
	}

	/**
	 * Build knowledge context from embeddings for generation.
	 *
	 * @param string $query Optional semantic query.
	 * @return string
	 */
	private static function build_knowledge_context( string $query = '' ): string {
		return KnowledgeContextBuilder::build_context( [], $query );
	}

	/**
	 * Get list of posts generated via this feature.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $page     Page number (1-indexed).
	 * @param int    $per_page Posts per page.
	 * @param string $search   Optional search query.
	 * @param string $status   Optional status filter (publish, draft, private, etc.).
	 * @param string $orderby  Optional order by field (date, title).
	 * @param string $order    Optional order direction (ASC, DESC).
	 * @return array {
	 *     @type array $posts      Array of post data.
	 *     @type int   $total      Total posts count.
	 *     @type int   $pages      Total pages count.
	 *     @type int   $page       Current page.
	 *     @type int   $per_page   Posts per page.
	 * }
	 */
	public static function get_generated_posts( $page = 1, $per_page = 10, $search = '', $status = '', $orderby = 'date', $order = 'DESC' ) {
		$args = [
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => [
				[
					'key'     => self::$meta_key,
					'value'   => '1',
					'compare' => '=',
				],
			],
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		// Apply search filter.
		if ( ! empty( $search ) ) {
			$args['s'] = sanitize_text_field( $search );
		}

		// Apply status filter.
		if ( ! empty( $status ) && 'any' !== $status ) {
			$args['post_status'] = sanitize_text_field( $status );
		}

		// Apply sorting.
		$orderby_map = [
			'date'  => 'date',
			'title' => 'title',      // WP_Query understands 'title'
		];
		$allowed_order = [ 'ASC', 'DESC' ];

		if ( ! empty( $orderby ) && isset( $orderby_map[ $orderby ] ) ) {
			$args['orderby'] = $orderby_map[ $orderby ];
		}

		if ( ! empty( $order ) && in_array( strtoupper( $order ), $allowed_order, true ) ) {
			$args['order'] = strtoupper( $order );
		}

		$query = new \WP_Query( $args );
		$posts = [];

		foreach ( $query->posts as $post ) {
			// Calculate word count from post content using utility function.
			$word_count = \Antimanual\Utils\count_post_words( $post->post_content );

			$posts[] = [
				'id'           => $post->ID,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'type'         => $post->post_type,
				'date'         => $post->post_date,
				'date_gmt'     => $post->post_date_gmt,
				'prompt'       => get_post_meta( $post->ID, self::$meta_prompt_key, true ),
				'word_count'   => $word_count,
				'edit_link'    => get_edit_post_link( $post->ID, '&' ),
				'view_link'    => get_permalink( $post->ID ),
			];
		}

		return [
			'posts'    => $posts,
			'total'    => $query->found_posts,
			'pages'    => $query->max_num_pages,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Generate a detailed prompt/topic suggestion using AI.
	 *
	 * This generates a comprehensive writing prompt that can be used for
	 * the single post generation feature, providing better context for AI
	 * to create high-quality content.
	 *
	 * @since 2.3.0
	 *
	 * @return string|WP_Error The generated prompt or error.
	 */
	public static function generate_prompt_suggestion() {
		// Get site context for better suggestions.
		$site_name        = get_bloginfo( 'name' );
		$site_description = get_bloginfo( 'description' );

		// Get recent post titles for context.
		$recent_posts = get_posts( [
			'numberposts' => 10,
			'post_status' => 'publish',
		] );

		$recent_titles = array_map( function( $post ) {
			return $post->post_title;
		}, $recent_posts );

		$recent_context = ! empty( $recent_titles )
			? 'Recent content on this site includes: ' . implode( ', ', array_slice( $recent_titles, 0, 5 ) )
			: 'This is a new site with no published content yet.';

		$instructions = '
			You are a content strategist helping a WordPress site owner come up with engaging article ideas.

			Your task is to generate ONE detailed writing prompt/topic for a blog post or article.

			The prompt should be:
			- Specific and actionable (not vague like "write about marketing")
			- Detailed enough to guide content creation (2-4 sentences)
			- Include the target audience, angle, and key points to cover
			- Relevant to the site\'s niche and existing content
			- Fresh and engaging, not a rehash of common topics

			**IMPORTANT**: Return ONLY the prompt text, nothing else. No explanations, no quotation marks, no labels.

			Example format:
			Create a comprehensive beginner\'s guide to [specific topic] targeting [audience]. Cover the fundamentals including [key point 1], [key point 2], and [key point 3]. Include practical examples and actionable tips that readers can implement immediately.

			Site Context:
			- Site Name: ' . esc_html( $site_name ) . '
			- Site Description: ' . esc_html( $site_description ) . '
			- ' . $recent_context . '
		';

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $instructions,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => 'Generate a detailed, creative writing prompt/topic for a new article on this site. The prompt should be unique.  Do NOT repeat or rehash the recent content topics, but take a unique approach. AVOID topics that are similar to the recent content.',
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input );

		if ( ! is_string( $response ) || empty( $response ) ) {
			return new \WP_Error( 'ai_failed', isset( $response['error'] ) ? $response['error'] : __( 'Failed to generate prompt suggestion. Please try again.', 'antimanual' ) );
		}

		// Clean the response - remove quotes, extra whitespace, etc.
		$prompt = trim( $response );
		$prompt = trim( $prompt, '"\'`' );
		$prompt = preg_replace( '/^(Prompt|Topic|Suggestion):?\s*/i', '', $prompt );

		return $prompt;
	}

	/**
	 * Generate topic ideas grounded in the existing knowledge base.
	 *
	 * Reads knowledge base content and instructs the AI to derive specific,
	 * actionable post topic ideas that can be written from that material.
	 *
	 * @since 2.8.0
	 *
	 * @param int    $count      Number of topic ideas/titles to generate.
	 * @param string $seed_topic Optional. Focus topic to guide title generation.
	 * @return array|\WP_Error Array of topic objects or error.
	 */
	public static function generate_kb_topics( int $count = 6, string $seed_topic = '' ) {
		$count = max( 1, min( 20, $count ) );
		$seed_topic = sanitize_text_field( $seed_topic );
		$existing_title_map = self::get_existing_site_title_map();

		// Build knowledge base context.
		$kb_context = self::build_knowledge_context( '' );

		if ( empty( $kb_context ) ) {
			return new \WP_Error(
				'no_knowledge_base',
				__( 'No knowledge base content found. Please add content to your Knowledge Base first.', 'antimanual' )
			);
		}

		// Trim context to a reasonable length to avoid token limits.
		if ( strlen( $kb_context ) > 6000 ) {
			$kb_context = substr( $kb_context, 0, 6000 ) . "\n[...more content in knowledge base...]";
		}

		$site_name = get_bloginfo( 'name' );
		$is_topic_focused = ! empty( $seed_topic );
		$topic_focus_note = $is_topic_focused ? "Focus Topic: {$seed_topic}" : '';
		$user_request     = $is_topic_focused
			? "Generate {$count} blog post title suggestions focused on \"{$seed_topic}\" and grounded in the knowledge base. Return ONLY a JSON array."
			: "Generate {$count} blog post topic ideas based on the knowledge base content. Return ONLY a JSON array.";

		$instructions = "You are a content strategist helping generate blog post ideas based on a knowledge base.

Your task is to generate {$count} specific, actionable blog post " . ( $is_topic_focused ? 'titles' : 'topics' ) . " derived directly from the knowledge base content below.

Each suggestion should:
- Be directly related to the knowledge base content
- Be specific and interesting (not generic)
- Include a brief 1-sentence description of the angle or focus (description field)
- Be suitable for a full blog post or article
" . ( $is_topic_focused ? '- Stay tightly aligned to the provided focus topic while varying angles and intent' : '' ) . "

Respond ONLY with a valid JSON array. No explanation, no markdown fences, no extra text.

Format:
[
  {\"title\": \"Topic title here\", \"description\": \"One sentence describing the angle and key focus of this post.\"},
  ...
]

Site: {$site_name}
" . ( $topic_focus_note ? "\n{$topic_focus_note}" : '' ) . "

Knowledge Base Content:
{$kb_context}";

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $instructions,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $user_request,
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input );

		if ( ! is_string( $response ) || empty( $response ) ) {
			$error_msg = isset( $response['error'] ) ? $response['error'] : __( 'Failed to generate topic ideas. Please try again.', 'antimanual' );
			return new \WP_Error( 'ai_failed', $error_msg );
		}

		// Strip markdown code fences if present.
		$response = preg_replace( '/^```(?:json)?\s*/i', '', trim( $response ) );
		$response = preg_replace( '/\s*```$/', '', $response );

		$topics = json_decode( trim( $response ), true );

		if ( ! is_array( $topics ) || empty( $topics ) ) {
			return new \WP_Error( 'parse_failed', __( 'Failed to parse topic suggestions. Please try again.', 'antimanual' ) );
		}

		// Sanitize, de-duplicate, and skip titles that already exist on the site.
		$sanitized = [];
		$seen      = [];
		foreach ( $topics as $topic ) {
			if ( ! is_array( $topic ) ) {
				continue;
			}

			$title       = sanitize_text_field( (string) ( $topic['title'] ?? '' ) );
			$description = sanitize_text_field( (string) ( $topic['description'] ?? '' ) );
			$normalized  = self::normalize_topic_suggestion_title( $title );

			if ( '' === $title || '' === $normalized ) {
				continue;
			}
			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( isset( $existing_title_map[ $normalized ] ) ) {
				continue;
			}

			$seen[ $normalized ] = true;
			$sanitized[] = [
				'title'       => $title,
				'description' => $description,
			];

			if ( count( $sanitized ) >= $count ) {
				break;
			}
		}

		return array_values( $sanitized );
	}

	/**
	 * Build a normalized set of existing site post titles for de-duplication.
	 *
	 * @return array<string, bool>
	 */
	private static function get_existing_site_title_map(): array {
		global $wpdb;

		$post_types = get_post_types(
			[
				'public' => true,
			],
			'names'
		);

		if ( empty( $post_types ) ) {
			return [];
		}

		// Attachments are not article-like content for this suggestion use case.
		$post_types = array_values( array_diff( $post_types, [ 'attachment' ] ) );
		if ( empty( $post_types ) ) {
			return [];
		}

		$statuses = [ 'publish', 'future', 'draft', 'pending', 'private' ];

		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$params = array_merge( $post_types, $statuses );
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_title
				FROM %i
				WHERE post_title <> ''
				  AND post_type IN ({$type_placeholders})
				  AND post_status IN ({$status_placeholders})",
				array_merge( [ $wpdb->posts ], $params )
			)
		);

		if ( ! is_array( $titles ) || empty( $titles ) ) {
			return [];
		}

		$normalized_map = [];
		foreach ( $titles as $title ) {
			$normalized = self::normalize_topic_suggestion_title( (string) $title );
			if ( '' !== $normalized ) {
				$normalized_map[ $normalized ] = true;
			}
		}

		return $normalized_map;
	}

	/**
	 * Normalize topic/title text for robust duplicate checks.
	 *
	 * @param string $title Title or topic text.
	 * @return string
	 */
	private static function normalize_topic_suggestion_title( string $title ): string {
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' );
		$title = remove_accents( $title );
		$title = mb_strtolower( $title, 'UTF-8' );
		$title = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $title );
		$title = preg_replace( '/\s+/u', ' ', trim( $title ) );
		return (string) $title;
	}

	/**
	 * Generate a content outline for preview.
	 *
	 * @since 2.6.0
	 * @param string $prompt     The topic/prompt for the content.
	 * @param string $language   The language for the content.
	 * @param int    $min_length Minimum word count.
	 * @param int    $max_length Maximum word count.
	 * @return array|\WP_Error The outline data or error.
	 */
	public static function generate_content_outline( string $prompt, string $language = 'English', int $min_length = 800, int $max_length = 1200, string $pov = 'third_person', string $target_audience = 'general' ) {
		$instructions = '
			You are a content strategist and outline generator. Your task is to create a detailed content outline for an article.

			**Requirements**:
			1. Generate a compelling, SEO-friendly title for the article
			2. Create 4-6 main sections with clear headings
			3. For each section, provide 2-4 key points that will be covered
			4. The outline should be structured for an article of approximately ' . $min_length . '-' . $max_length . ' words
			5. Target Audience: ' . $target_audience . '
			6. Point of View: ' . $pov . '
			7. Write the outline in ' . $language . '

			**Response Format** (JSON):
			{
				"title": "The article title",
				"sections": [
					{
						"heading": "Section Heading",
						"points": ["Key point 1", "Key point 2", "Key point 3"]
					}
				],
				"estimated_words": 1000
			}

			IMPORTANT: Return ONLY valid JSON, no markdown formatting, no explanations.
		';

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $instructions,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => 'Generate a detailed outline for the following topic: ' . $prompt,
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input );

		if ( ! is_string( $response ) || empty( $response ) ) {
			return new \WP_Error( 'ai_failed', isset( $response['error'] ) ? $response['error'] : __( 'Failed to generate outline. Please try again.', 'antimanual' ) );
		}

		// Parse JSON response.
		$outline = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $outline ) ) {
			// Try to extract JSON from the response.
			if ( preg_match( '/\{.*\}/s', $response, $matches ) ) {
				$outline = json_decode( $matches[0], true );
			}

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $outline ) ) {
				return new \WP_Error( 'parse_failed', __( 'Failed to parse outline response. Please try again.', 'antimanual' ) );
			}
		}

		// Ensure required fields exist.
		if ( ! isset( $outline['title'] ) || ! isset( $outline['sections'] ) ) {
			return new \WP_Error( 'invalid_outline', __( 'Invalid outline structure. Please try again.', 'antimanual' ) );
		}

		return [
			'title'           => sanitize_text_field( $outline['title'] ),
			'sections'        => array_map( function ( $section ) {
				return [
					'heading' => sanitize_text_field( $section['heading'] ?? '' ),
					'points'  => array_map( 'sanitize_text_field', $section['points'] ?? [] ),
				];
			}, $outline['sections'] ?? [] ),
			'estimated_words' => intval( $outline['estimated_words'] ?? ( $min_length + $max_length ) / 2 ),
		];
	}

	/**
	 * Enhance / expand a rough user prompt into a detailed writing brief.
	 *
	 * Takes a short or vague topic and returns a structured, detailed prompt
	 * that will produce better AI-generated content. This is a lightweight
	 * single-call operation designed to save tokens and failed generation attempts.
	 *
	 * @since 2.7.0
	 *
	 * @param string $rough_prompt The user's rough topic or idea.
	 * @return string|WP_Error The enhanced prompt or WP_Error on failure.
	 */
	public static function generate_prompt_enhancement( string $rough_prompt ) {
		$site_name = get_bloginfo( 'name' );

		$instructions = '
			You are a professional content brief writer. Your task is to transform a rough topic or idea into a detailed, well-structured writing prompt.

			The enhanced prompt should be 2-4 sentences and must include:
			1. The main topic and specific angle to take
			2. The target audience
			3. 2-3 key points or sections to cover
			4. The desired tone or goal (e.g., educate, persuade, inspire)

			**RULES**:
			- Return ONLY the enhanced prompt text. Nothing else.
			- No labels, no quotation marks, no explanations.
			- Keep it concise but specific - 40-80 words is ideal.
			- Preserve the original topic\'s intent, just make it richer.

			Site: ' . esc_html( $site_name ) . '
		';

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $instructions,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => 'Enhance this rough topic into a detailed writing prompt: ' . $rough_prompt,
					],
				],
			],
		];

		$response = AIProvider::get_reply( $input );

		if ( ! is_string( $response ) || empty( $response ) ) {
			return new \WP_Error(
				'ai_failed',
				__( 'Failed to enhance prompt. Please try again.', 'antimanual' )
			);
		}

		// Clean the response.
		$enhanced = trim( $response );
		$enhanced = trim( $enhanced, '"\'`' );
		$enhanced = preg_replace( '/^(Enhanced|Prompt|Topic|Brief|Result):?\s*/i', '', $enhanced );

		return $enhanced;
	}
}
