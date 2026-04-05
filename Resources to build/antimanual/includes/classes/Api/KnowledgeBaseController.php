<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\Embedding;

/**
 * Knowledge base API endpoints.
 */
class KnowledgeBaseController {
	/**
	 * Free plan limit for WordPress posts in knowledge base.
	 */
	private const FREE_WP_KB_LIMIT = 100;

	/**
	 * Register REST routes for knowledge base.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace ) {
		register_rest_route(
			$namespace,
			'/knowledge-base',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_get_knowledge_base' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
				'args'                => [
					'type' => [
						'required'          => false,
						'validate_callback' => function ( $param, $request, $key ) {
							return in_array( $param, [ 'wp', 'pdf', 'url', 'txt', 'github' ], true );
						},
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_knowledge_base_stats' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'handle_delete_knowledge_base' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
				'args'                => [
					'knowledge_id' => [
						'required'          => true,
						'validate_callback' => function ( $param, $request, $key ) {
							return is_string( $param );
						},
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/wp',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_to_kb_wp' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
				'args'                => [
					'post_id' => [
						'required'          => true,
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/pdf',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_to_kb_pdf' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/url',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_to_kb_url' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/txt',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_to_kb_txt' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/github',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_to_kb_github' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/migrate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_migrate_knowledge_base' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 300,
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/unmigrated',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_get_unmigrated' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/migrate-single',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_migrate_single' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 300,
				'args'                => [
					'knowledge_id' => [
						'required'          => true,
						'validate_callback' => function ( $param, $request, $key ) {
							return is_string( $param ) && ! empty( $param );
						},
					],
				],
			]
		);
	}

	/**
	 * Add post to knowledge base.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function add_to_kb_wp( $request ) {
		$post_id = intval( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Post not found.', 'antimanual' ),
			] );
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		if ( empty( $content ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( 'Post content is empty.', 'antimanual' ),
			] );
		}

		$already_in_kb = $this->wp_post_exists_in_knowledge_base( $post_id );
		$wp_count      = $this->get_wp_knowledge_base_count();

		if ( ! atml_is_pro() && ! $already_in_kb && $wp_count >= self::FREE_WP_KB_LIMIT ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => sprintf(
					/* translators: %d: number of allowed WordPress posts in free plan. */
					__( 'Free plan limit reached. You can add up to %d WordPress posts to the knowledge base. Upgrade to Pro for more.', 'antimanual' ),
					self::FREE_WP_KB_LIMIT
				),
			] );
		}

		$chunks = Embedding::insert( [
			'content' => $content,
			'type'    => 'wp',
			'post_id' => $post_id,
		] );

		if ( is_wp_error( $chunks ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $chunks->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $chunks,
		] );
	}

	/**
	 * Add PDF to knowledge base.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function add_to_kb_pdf( $request ) {
		if ( atml_is_pro() ) {
			return rest_ensure_response( \Antimanual_Pro\API::add_to_kb_pdf( $request ) );
		}

		return rest_ensure_response( [
			'success' => false,
			'message' => __( 'Upgrade to Pro to add PDF to knowledge base.', 'antimanual' ),
		] );
	}

	/**
	 * Add URL to knowledge base.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function add_to_kb_url( $request ) {
		if ( atml_is_pro() ) {
			return rest_ensure_response( \Antimanual_Pro\API::add_to_kb_url( $request ) );
		}

		return rest_ensure_response( [
			'success' => false,
			'message' => __( 'Upgrade to Pro to add URL to knowledge base.', 'antimanual' ),
		] );
	}

	/**
	 * Add text to knowledge base.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function add_to_kb_txt( $request ) {
		if ( atml_is_pro() ) {
			return rest_ensure_response( \Antimanual_Pro\API::add_to_kb_txt( $request ) );
		}

		return rest_ensure_response( [
			'success' => false,
			'message' => __( 'Upgrade to Pro to add Custom Text to knowledge base.', 'antimanual' ),
		] );
	}

	/**
	 * Add GitHub repository to knowledge base.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function add_to_kb_github( $request ) {
		if ( atml_is_pro() ) {
			return rest_ensure_response( \Antimanual_Pro\GitHubController::add_to_kb_github( $request ) );
		}

		return rest_ensure_response( [
			'success' => false,
			'message' => __( 'Upgrade to Pro to add GitHub repositories to the knowledge base.', 'antimanual' ),
		] );
	}

	/**
	 * Get knowledge base items.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function handle_get_knowledge_base( $request ) {
		$type      = sanitize_key( (string) ( $request->get_param( 'type' ) ?? '' ) );
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?? 'post' ) );
		$offset    = intval( $request->get_param( 'offset' ) ?? 0 );
		$limit     = intval( $request->get_param( 'limit' ) ?? 10 );

		if ( ! in_array( $type, [ 'wp', 'pdf', 'url', 'txt', 'github' ], true ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => __( "Invalid 'type' parameter. Allowed values are wp, pdf, url, txt, github.", 'antimanual' ),
			] );
		}

		$rows = Embedding::list_by_type( $type, $post_type, $offset, $limit );

		if ( is_wp_error( $rows ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $rows->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $rows,
		] );
	}

	/**
	 * Delete knowledge base item.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function handle_delete_knowledge_base( $request ) {
		$knowledge_id = sanitize_text_field( (string) ( $request->get_param( 'knowledge_id' ) ?? '' ) );
		$result       = Embedding::delete_knowledge( $knowledge_id );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [],
		] );
	}

	/**
	 * Get knowledge base stats.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function handle_knowledge_base_stats( $request ) {
		$stats = Embedding::get_stats();

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'stats'                   => $stats,
				'active_provider'         => \Antimanual\AIProvider::get_name(),
				'other_provider_kb_count' => Embedding::get_other_provider_kb_count(),
				'wp_post_type_counts'     => Embedding::get_wp_post_type_counts(),
			],
		] );
	}

	/**
	 * Count WordPress knowledge entries.
	 *
	 * @return int
	 */
	private function get_wp_knowledge_base_count(): int {
		global $wpdb;
		$table_name = Embedding::get_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT knowledge_id) FROM `$table_name` WHERE `type` = %s",
				'wp'
			)
		);
	}

	/**
	 * Check whether a WP post already exists in knowledge base.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function wp_post_exists_in_knowledge_base( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table_name = Embedding::get_table_name();
		$count      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table_name` WHERE `type` = %s AND `post_id` = %d",
				'wp',
				$post_id
			)
		);

		return $count > 0;
	}

	/**
	 * Migrate knowledge base from the other provider to the active one.
	 *
	 * Re-embeds all content using the currently active AI provider.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function handle_migrate_knowledge_base( $request ) {
		$keep_old = (bool) ( $request->get_param( 'keep_old' ) ?? true );
		$result   = Embedding::migrate_from_other_provider( $keep_old );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * Get the list of unmigrated knowledge base items.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function handle_get_unmigrated( $request ) {
		$items = Embedding::get_unmigrated_items();

		return rest_ensure_response( [
			'success' => true,
			'data'    => $items,
		] );
	}

	/**
	 * Migrate a single knowledge base item.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The REST response.
	 */
	public function handle_migrate_single( $request ) {
		$knowledge_id = sanitize_text_field( (string) $request->get_param( 'knowledge_id' ) );
		$result       = Embedding::migrate_single_item( $knowledge_id );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}
}
