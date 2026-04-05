<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\PostGenerator;

/**
 * Generate Post API endpoints.
 */
class PostGeneratorController {
	/**
	 * Option name for storing prompt templates.
	 *
	 * @var string
	 */
	private const TEMPLATES_OPTION = 'atml_prompt_templates';

	/**
	 * Register REST routes for Generate Post.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace ) {
		register_rest_route(
			$namespace,
			'/generate-post',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_single_post' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'timeout'             => 300,
			)
		);

		register_rest_route(
			$namespace,
			'/generated-posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_generated_posts' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'timeout'             => 120,
				'args'                => array(
					'page'     => array(
						'required'          => false,
						'default'           => 1,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && intval( $param ) > 0;
						},
					),
					'per_page' => array(
						'required'          => false,
						'default'           => 10,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && intval( $param ) > 0 && intval( $param ) <= 100;
						},
					),
					'search'   => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'   => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby'  => array(
						'required'          => false,
						'default'           => 'date',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order'    => array(
						'required'          => false,
						'default'           => 'DESC',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/generated-posts/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_generated_posts_stats' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			$namespace,
			'/generate-post/suggest-prompt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'suggest_post_prompt' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'timeout'             => 60,
			)
		);

			register_rest_route(
				$namespace,
				'/generate-post/kb-topics',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'suggest_kb_topics' ),
					'permission_callback' => fn() => current_user_can( 'edit_posts' ),
					'timeout'             => 60,
					'args'                => array(
						'count' => array(
							'required'          => false,
							'default'           => 6,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && intval( $param ) >= 1 && intval( $param ) <= 20;
							},
						),
						'topic' => array(
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
				);

		register_rest_route(
			$namespace,
			'/generate-post/enhance-prompt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enhance_post_prompt' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'timeout'             => 60,
				'args'                => array(
					'prompt' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Prompt templates routes.
		register_rest_route(
			$namespace,
			'/generate-post/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_templates' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			$namespace,
			'/generate-post/templates',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_template' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'name'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'prompt' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/generate-post/templates/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_template' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		// Recent prompts route.
		register_rest_route(
			$namespace,
			'/generate-post/recent',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_recent_prompts' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'limit' => array(
						'required'          => false,
						'default'           => 5,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && intval( $param ) > 0 && intval( $param ) <= 20;
						},
					),
				),
			)
		);

		// Outline generation route.
		register_rest_route(
			$namespace,
			'/generate-post/outline',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_outline' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'timeout'             => 60,
				'args'                => array(
					'prompt'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'language'   => array(
						'required'          => false,
						'default'           => 'English',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'min_length' => array(
						'required'          => false,
						'default'           => 800,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'max_length' => array(
						'required'          => false,
						'default'           => 1200,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'pov'        => array(
						'required'          => false,
						'default'           => 'third_person',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'target_audience' => array(
						'required'          => false,
						'default'           => 'general',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Generate a single post with AI.
	 *
	 * @since 2.3.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function generate_single_post( $request ) {
		// Increase execution time limit for long-running AI requests.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 );
		}
		// Prevent PHP notices/warnings from breaking the JSON response.
		@ini_set( 'display_errors', '0' );

		$files                  = $_FILES['files'] ?? array();
		$attachments            = $this->collect_uploaded_attachments( is_array( $files ) ? $files : array() );
		$inspiration_file       = $_FILES['featured_image_inspiration'] ?? array();
		$inspiration_attachments = $this->collect_uploaded_attachments( is_array( $inspiration_file ) ? $inspiration_file : array() );
		$featured_image_inspiration_attachment = ! empty( $inspiration_attachments ) ? intval( $inspiration_attachments[0] ) : 0;
		$status                 = sanitize_key( (string) ( $request->get_param( 'status' ) ?? 'draft' ) );
		$focus_keyword = $this->resolve_focus_keyword( $request );
		$scheduled_date = sanitize_text_field( (string) ( $request->get_param( 'scheduled_date' ) ?? '' ) );
		$faq_block_type_raw = sanitize_key( (string) ( $request->get_param( 'faq_block_type' ) ?? 'default' ) );
		$faq_block_type     = in_array( $faq_block_type_raw, array( 'default', 'advanced' ), true ) ? $faq_block_type_raw : 'default';

		if ( 'future' === $status ) {
			if ( empty( $scheduled_date ) ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'message' => __( 'Scheduled date is required for future posts.', 'antimanual' ),
					)
				);
			}

			// Parse the ISO 8601 date string which includes the user's timezone offset
			// (e.g. "2026-02-12T09:00:00+06:00"). This ensures the user's intended
			// local time is correctly converted to the WordPress site timezone.
			$scheduled_datetime = date_create( $scheduled_date );

			if ( ! ( $scheduled_datetime instanceof \DateTime ) ) {
				// Fall back to legacy format without timezone (Y-m-d H:i:s).
				$scheduled_datetime = \DateTime::createFromFormat( 'Y-m-d H:i:s', $scheduled_date, wp_timezone() );
			}

			if ( ! ( $scheduled_datetime instanceof \DateTime ) ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'message' => __( 'Invalid scheduled date format.', 'antimanual' ),
					)
				);
			}

			// Convert to the WordPress site timezone for storage.
			$scheduled_datetime->setTimezone( wp_timezone() );
			$scheduled_date = $scheduled_datetime->format( 'Y-m-d H:i:s' );
		}

		$params = array(
			'prompt'                            => sanitize_textarea_field( $request->get_param( 'prompt' ) ?? '' ),
			'tone'                              => sanitize_text_field( $request->get_param( 'tone' ) ?? '' ),
			'language'                          => sanitize_text_field( $request->get_param( 'language' ) ?? 'English' ),
			'min_length'                        => intval( $request->get_param( 'min_length' ) ?? 800 ),
			'max_length'                        => intval( $request->get_param( 'max_length' ) ?? 1200 ),
			'slug_language'                     => sanitize_text_field( $request->get_param( 'slug_language' ) ?? 'English' ),
			'slug_max_length'                   => intval( $request->get_param( 'slug_max_length' ) ?? 50 ),
			'post_type'                         => sanitize_text_field( $request->get_param( 'post_type' ) ?? 'post' ),
			'author'                            => intval( $request->get_param( 'author' ) ?? get_current_user_id() ),
			'status'                            => $status,
			'generate_taxonomies'               => filter_var( $request->get_param( 'generate_taxonomies' ) ?? true, FILTER_VALIDATE_BOOLEAN ),
			'taxonomy_count'                    => intval( $request->get_param( 'taxonomy_count' ) ?? 3 ),
			'taxonomy_settings'                 => sanitize_text_field( (string) ( $request->get_param( 'taxonomy_settings' ) ?? '' ) ),
			'parent'                            => intval( $request->get_param( 'parent' ) ?? 0 ),
			'generate_excerpt'                  => filter_var( $request->get_param( 'generate_excerpt' ) ?? true, FILTER_VALIDATE_BOOLEAN ),
			'excerpt_length'                    => intval( $request->get_param( 'excerpt_length' ) ?? 50 ),
			'attachments'                       => $attachments,
			'focus_keyword'                     => $focus_keyword,
			'scheduled_date'                    => $scheduled_date,
			'use_existing_knowledge'            => filter_var( $request->get_param( 'use_existing_knowledge' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'generate_meta_description'         => filter_var( $request->get_param( 'generate_meta_description' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'optimize_for_seo'                  => filter_var( $request->get_param( 'optimize_for_seo' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'generate_featured_image'           => filter_var( $request->get_param( 'generate_featured_image' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'use_post_title_in_featured_image'  => filter_var( $request->get_param( 'use_post_title_in_featured_image' ) ?? true, FILTER_VALIDATE_BOOLEAN ),
			'featured_image_inspiration_attachment' => $featured_image_inspiration_attachment,
			'include_image_caption'             => filter_var( $request->get_param( 'include_image_caption' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'suggest_internal_links'            => filter_var( $request->get_param( 'suggest_internal_links' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'show_internal_links_pro_tip'       => filter_var( $request->get_param( 'show_internal_links_pro_tip' ) ?? true, FILTER_VALIDATE_BOOLEAN ),
			'internal_links_pro_tip_label'      => sanitize_text_field( $request->get_param( 'internal_links_pro_tip_label' ) ?? $request->get_param( 'internal_links_pro_tip_text' ) ?? '' ),
			'internal_links_pro_tip_bg_color'   => sanitize_hex_color( (string) ( $request->get_param( 'internal_links_pro_tip_bg_color' ) ?? '' ) ) ?: '#f5f1dd',
			'include_toc'                       => filter_var( $request->get_param( 'include_toc' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'include_faq'                       => filter_var( $request->get_param( 'include_faq' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'faq_block_type'                    => $faq_block_type,
			'pov'                               => sanitize_text_field( $request->get_param( 'pov' ) ?? 'third_person' ),
			'target_audience'                   => sanitize_text_field( $request->get_param( 'target_audience' ) ?? 'general' ),
			'include_conclusion'                => filter_var( $request->get_param( 'include_conclusion' ) ?? true, FILTER_VALIDATE_BOOLEAN ),
			'include_content_images'            => filter_var( $request->get_param( 'include_content_images' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'tone_is_custom'                    => filter_var( $request->get_param( 'tone_is_custom' ) ?? false, FILTER_VALIDATE_BOOLEAN ),
			'custom_outline'                    => wp_unslash( (string) ( $request->get_param( 'custom_outline' ) ?? '' ) ),
		);

		$result = PostGenerator::generate( $params );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Resolve focus keyword from request payload.
	 *
	 * Supports both `focus_keyword` (string) and `focus_keywords` (array/JSON string).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return string
	 */
	private function resolve_focus_keyword( $request ): string {
		$focus_keyword = sanitize_text_field( (string) ( $request->get_param( 'focus_keyword' ) ?? '' ) );
		if ( ! empty( $focus_keyword ) ) {
			return $focus_keyword;
		}

		$focus_keywords = $request->get_param( 'focus_keywords' );

		if ( is_string( $focus_keywords ) ) {
			$decoded = json_decode( $focus_keywords, true );
			if ( is_array( $decoded ) ) {
				$focus_keywords = $decoded;
			}
		}

		if ( ! is_array( $focus_keywords ) ) {
			return '';
		}

		foreach ( $focus_keywords as $keyword ) {
			if ( ! is_scalar( $keyword ) ) {
				continue;
			}

			$cleaned = sanitize_text_field( (string) $keyword );
			if ( '' !== $cleaned ) {
				return $cleaned;
			}
		}

		return '';
	}

	/**
	 * Collect uploaded files as media attachments for AI context.
	 *
	 * @param array $files Raw files array from $_FILES['files'].
	 * @return int[] Attachment IDs.
	 */
	private function collect_uploaded_attachments( array $files ): array {
		if ( ! atml_is_public_site() ) {
			return array();
		}

		$names     = $files['name'] ?? array();
		$types     = $files['type'] ?? array();
		$tmp_names = $files['tmp_name'] ?? array();
		$errors    = $files['error'] ?? array();
		$sizes     = $files['size'] ?? array();

		if ( ! is_array( $tmp_names ) ) {
			$names     = array( $names );
			$types     = array( $types );
			$tmp_names = array( $tmp_names );
			$errors    = array( $errors );
			$sizes     = array( $sizes );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$attachments = array();

		foreach ( $tmp_names as $i => $tmp_name ) {
			$tmp_name = is_string( $tmp_name ) ? $tmp_name : '';
			$error    = intval( $errors[ $i ] ?? UPLOAD_ERR_NO_FILE );

			if ( empty( $tmp_name ) || UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $tmp_name ) ) {
				continue;
			}

			$name = sanitize_file_name( (string) ( $names[ $i ] ?? ( 'upload-' . $i . '.pdf' ) ) );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			$allowed_extensions = array( 'pdf', 'txt', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'csv', 'xls', 'xlsx' );

			if ( ! in_array( $ext, $allowed_extensions, true ) ) {
				continue;
			}

			$file_array = array(
				'name'     => $name,
				'type'     => sanitize_text_field( (string) ( $types[ $i ] ?? '' ) ),
				'tmp_name' => $tmp_name,
				'error'    => $error,
				'size'     => intval( $sizes[ $i ] ?? 0 ),
			);

			$filetype     = wp_check_filetype( $file_array['name'] );
			$allowed_exts = array( 'pdf', 'txt', 'csv', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp' );

			if ( empty( $filetype['ext'] ) || ! in_array( strtolower( (string) $filetype['ext'] ), $allowed_exts, true ) ) {
				continue;
			}

			$moved_file = wp_handle_upload( $file_array, array( 'test_form' => false ) );
			if ( ! is_array( $moved_file ) || empty( $moved_file['file'] ) ) {
				continue;
			}

			$attachment = array(
				'post_mime_type' => sanitize_text_field( (string) ( $moved_file['type'] ?? '' ) ),
				'post_title'     => sanitize_file_name( basename( $moved_file['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment, $moved_file['file'] );
			if ( ! is_wp_error( $attach_id ) ) {
				$attachments[] = $attach_id;
			}
		}

		return $attachments;
	}

	/**
	 * List posts generated via the Generate Post feature.
	 *
	 * @since 2.3.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function list_generated_posts( $request ) {
		$page     = intval( $request->get_param( 'page' ) ?? 1 );
		$per_page = intval( $request->get_param( 'per_page' ) ?? 10 );
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$status   = sanitize_text_field( $request->get_param( 'status' ) ?? '' );
		$orderby  = sanitize_text_field( $request->get_param( 'orderby' ) ?? 'date' );
		$order    = sanitize_text_field( $request->get_param( 'order' ) ?? 'DESC' );

		$result = PostGenerator::get_generated_posts( $page, $per_page, $search, $status, $orderby, $order );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Get statistics for generated posts.
	 *
	 * @since 2.6.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function get_generated_posts_stats( $request ) {
		global $wpdb;

		$meta_key = '_atml_generated_post';

		// Total generated posts.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE pm.meta_key = %s 
                AND p.post_status != 'trash'",
				$meta_key
			)
		);

		// Today's posts.
		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$today       = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE pm.meta_key = %s 
                AND p.post_status != 'trash'
                AND p.post_date >= %s",
				$meta_key,
				$today_start
			)
		);

		// This week's posts.
		$week_start = gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
		$this_week  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE pm.meta_key = %s 
                AND p.post_status != 'trash'
                AND p.post_date >= %s",
				$meta_key,
				$week_start
			)
		);

		// This month's posts.
		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$this_month  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE pm.meta_key = %s 
                AND p.post_status != 'trash'
                AND p.post_date >= %s",
				$meta_key,
				$month_start
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'total'      => $total,
					'today'      => $today,
					'this_week'  => $this_week,
					'this_month' => $this_month,
				),
			)
		);
	}

	/**
	 * Generate a detailed prompt suggestion for post generation.
	 *
	 * @since 2.3.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function suggest_post_prompt( $request ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
		@ini_set( 'display_errors', '0' );

		$result = PostGenerator::generate_prompt_suggestion();

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'prompt' => $result,
				),
			)
		);
	}

	/**
	 * Suggest topic ideas based on the existing knowledge base.
	 *
	 * @since 2.8.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function suggest_kb_topics( $request ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
		@ini_set( 'display_errors', '0' );

		$count  = intval( $request->get_param( 'count' ) ?? 6 );
		$topic  = sanitize_text_field( (string) ( $request->get_param( 'topic' ) ?? '' ) );
		$result = PostGenerator::generate_kb_topics( $count, $topic );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'topics' => $result,
				),
			)
		);
	}

	/**
	 * Enhance a rough user prompt into a detailed writing brief.
	 *
	 * @since 2.7.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function enhance_post_prompt( $request ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
		@ini_set( 'display_errors', '0' );

		$rough_prompt = sanitize_textarea_field( (string) ( $request->get_param( 'prompt' ) ?? '' ) );

		if ( empty( $rough_prompt ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Please provide a prompt to enhance.', 'antimanual' ),
				)
			);
		}

		$result = PostGenerator::generate_prompt_enhancement( $rough_prompt );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'prompt' => $result,
				),
			)
		);
	}

	/**
	 * Generate a content outline for preview.
	 *
	 * @since 2.6.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function generate_outline( $request ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}
		@ini_set( 'display_errors', '0' );

		$prompt          = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$language        = sanitize_text_field( $request->get_param( 'language' ) );
		$min_length      = intval( $request->get_param( 'min_length' ) );
		$max_length      = intval( $request->get_param( 'max_length' ) );
		$pov             = sanitize_text_field( $request->get_param( 'pov' ) ?? 'third_person' );
		$target_audience = sanitize_text_field( $request->get_param( 'target_audience' ) ?? 'general' );

		$result = PostGenerator::generate_content_outline( $prompt, $language, $min_length, $max_length, $pov, $target_audience );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * List saved prompt templates.
	 *
	 * @since 2.6.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function list_templates( $request ) {
		$templates = get_option( self::TEMPLATES_OPTION, array() );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array_values( $templates ),
			)
		);
	}

	/**
	 * Save a new prompt template.
	 *
	 * @since 2.6.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function save_template( $request ) {
		$name   = sanitize_text_field( $request->get_param( 'name' ) );
		$prompt = sanitize_textarea_field( $request->get_param( 'prompt' ) );

		if ( empty( $name ) || empty( $prompt ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Name and prompt are required.', 'antimanual' ),
				)
			);
		}

		$templates = get_option( self::TEMPLATES_OPTION, array() );

		$id = 'template_' . wp_generate_uuid4();

		$templates[ $id ] = array(
			'id'         => $id,
			'name'       => $name,
			'prompt'     => $prompt,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		update_option( self::TEMPLATES_OPTION, $templates );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $templates[ $id ],
			)
		);
	}

	/**
	 * Delete a prompt template.
	 *
	 * @since 2.6.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function delete_template( $request ) {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$templates = get_option( self::TEMPLATES_OPTION, array() );

		if ( ! isset( $templates[ $id ] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Template not found.', 'antimanual' ),
				)
			);
		}

		unset( $templates[ $id ] );
		update_option( self::TEMPLATES_OPTION, $templates );

		return rest_ensure_response(
			array(
				'success' => true,
			)
		);
	}

	/**
	 * List recent prompts from generated posts.
	 *
	 * @since 2.6.0
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function list_recent_prompts( $request ) {
		global $wpdb;

		$limit = intval( $request->get_param( 'limit' ) ?? 5 );

		$meta_key        = '_atml_generated_post';
		$prompt_meta_key = '_atml_generation_prompt';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, pm2.meta_value as prompt, p.post_date
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
                WHERE pm.meta_key = %s 
                AND p.post_status != 'trash'
                AND pm2.meta_value IS NOT NULL
                ORDER BY p.post_date DESC
                LIMIT %d",
				$prompt_meta_key,
				$meta_key,
				$limit
			),
			ARRAY_A
		);

		$recent = array();
		foreach ( $results as $row ) {
			$recent[] = array(
				'id'         => (int) $row['ID'],
				'prompt'     => $row['prompt'],
				'title'      => $row['post_title'],
				'created_at' => $row['post_date'],
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $recent,
			)
		);
	}
}
