<?php
/**
 * Internal Linking API Controller.
 *
 * Scans site posts for internal link data, generates AI-powered
 * link suggestions using semantic analysis, and inserts links
 * into post content.
 *
 * @package Antimanual
 * @since   1.7.0
 */

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;

/**
 * Internal Linking REST API controller.
 */
class InternalLinkingController {

	/**
	 * Transient key prefix for link report cache.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'atml_il_report_';

	/**
	 * Cache lifetime for link reports (seconds).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Option key used to bust report cache after content mutations.
	 *
	 * @var string
	 */
	private const CACHE_VERSION_OPTION = 'atml_il_report_cache_version';

	/**
	 * Register REST routes for Internal Linking.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace ) {
		// Scan the site and return internal link report data.
		register_rest_route(
			$namespace,
			'/internal-linking/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		// Get AI-powered link suggestions for a specific post.
		register_rest_route(
			$namespace,
			'/internal-linking/suggestions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 120,
			)
		);

		// Insert a link into a post's content.
		register_rest_route(
			$namespace,
			'/internal-linking/insert-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'insert_link' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 60,
			)
		);

		// Bulk generate and insert links automatically.
		register_rest_route(
			$namespace,
			'/internal-linking/bulk-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_link' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'timeout'             => 300,
			)
		);
	}

	/**
	 * Generate links report: per-post inbound/outbound link counts,
	 * orphan pages, and anchor text data.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function get_report( $request ) {
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Internal Linking requires the SEO Plus plan.', 'antimanual' ),
				),
				403
			);
		}

		$payload   = json_decode( $request->get_body(), true );
		$payload   = is_array( $payload ) ? $payload : array();
		$post_type = sanitize_key( $payload['post_type'] ?? 'post' );
		$page      = max( 1, intval( $payload['page'] ?? 1 ) );
		$per_page  = min( 500, max( 5, intval( $payload['per_page'] ?? 20 ) ) );
		$search    = sanitize_text_field( $payload['search'] ?? '' );
		$filter    = sanitize_key( $payload['filter'] ?? '' ); // orphan, low-links, over-linked.
		$use_cache = filter_var( $payload['use_cache'] ?? false, FILTER_VALIDATE_BOOLEAN );

		// Support "all" or empty post_type to query all public post types.
		if ( '' === $post_type || 'all' === $post_type ) {
			$post_type = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		} elseif ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$cache_key = $this->get_report_cache_key( $post_type, $search );
		$rows      = $use_cache ? get_transient( $cache_key ) : false;

		if ( ! is_array( $rows ) ) {
			$rows     = array();
			$site_url = trailingslashit( home_url() );
			$args     = array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			$query = new \WP_Query( $args );
			$posts = $query->posts;

			// Build permalink maps once to avoid repeated DB lookups.
			$permalink_map = array(); // post_id => permalink.
			$url_patterns  = array(); // post_id => [ patterns to search for ].
			foreach ( $posts as $p ) {
				$permalink = get_permalink( $p->ID );
				if ( ! $permalink ) {
					continue;
				}
				$permalink_map[ $p->ID ] = $permalink;
				$plain                   = untrailingslashit( $permalink );
				$relative                = untrailingslashit( wp_make_link_relative( $permalink ) );
				$url_patterns[ $p->ID ]  = array_unique( array_filter( [
					'href="' . $plain . '"',
					'href="' . $plain . '/"',
					"href='" . $plain . "'",
					"href='" . $plain . "/'",
					'href="' . $relative . '"',
					'href="' . $relative . '/"',
					"href='" . $relative . "'",
					"href='" . $relative . "/'",
				] ) );
			}

			// Batch-compute inbound links: scan every post's content for
			// links pointing to each target post. This replaces the N+1
			// SQL approach that caused 503 timeouts on large sites.
			$inbound_counts = array_fill_keys( array_keys( $permalink_map ), 0 );
			foreach ( $posts as $p ) {
				$content = $p->post_content;
				if ( '' === $content ) {
					continue;
				}
				foreach ( $url_patterns as $target_id => $patterns ) {
					if ( $target_id === $p->ID ) {
						continue; // Don't count self-links.
					}
					foreach ( $patterns as $pattern ) {
						if ( false !== strpos( $content, $pattern ) ) {
							++$inbound_counts[ $target_id ];
							break; // One match per source→target pair is enough.
						}
					}
				}
			}

			foreach ( $posts as $post ) {
				$content    = $post->post_content;
				$outbound   = $this->extract_internal_links( $content, $site_url );
				$inbound    = $inbound_counts[ $post->ID ] ?? 0;
				$word_count = str_word_count( wp_strip_all_tags( $content ) );
				$out_count  = count( $outbound );

				$rows[] = array(
					'post_id'        => $post->ID,
					'title'          => $post->post_title,
					'url'            => $permalink_map[ $post->ID ] ?? '',
					'edit_link'      => get_edit_post_link( $post->ID, 'raw' ) ?: '',
					'post_type'      => $post->post_type,
					'date'           => $post->post_date,
					'word_count'     => $word_count,
					'outbound_links' => $out_count,
					'inbound_links'  => $inbound,
					'outbound_data'  => array_slice( $outbound, 0, 10 ),
					'is_orphan'      => ( 0 === $inbound ),
					'status'         => $this->get_link_health( $out_count, $inbound, $word_count ),
				);
			}

			set_transient( $cache_key, $rows, self::CACHE_TTL );
		}

		$filtered_rows = $this->apply_report_filter( $rows, $filter );
		$total_rows    = count( $filtered_rows );
		$total_pages   = max( 1, (int) ceil( $total_rows / $per_page ) );
		$page          = min( $page, $total_pages );
		$offset        = ( $page - 1 ) * $per_page;
		$posts         = array_slice( $filtered_rows, $offset, $per_page );

		if ( 0 === $total_rows ) {
			$page = 1;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'posts'   => $posts,
					'total'   => $total_rows,
					'page'    => $page,
					'pages'   => $total_pages,
					'summary' => $this->summarize_report_rows( $filtered_rows ),
				),
			)
		);
	}

	/**
	 * Get AI-powered link suggestions for a specific post.
	 *
	 * Uses AI to analyze the source post content and find the best
	 * matching posts to link to, with suggested anchor text.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function get_suggestions( $request ) {
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Internal Linking requires the SEO Plus plan.', 'antimanual' ),
				),
				403
			);
		}

		$payload = json_decode( $request->get_body(), true );
		$payload = is_array( $payload ) ? $payload : array();
		$post_id = intval( $payload['post_id'] ?? 0 );
		$max     = min( 15, max( 3, intval( $payload['max_suggestions'] ?? 8 ) ) );

		if ( $post_id <= 0 ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'A valid post ID is required.', 'antimanual' ),
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Post not found.', 'antimanual' ),
				)
			);
		}

		// Get other published posts to consider for linking.
		$candidates = $this->get_candidate_posts( $post_id, $post->post_type, 40 );
		if ( empty( $candidates ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'suggestions' => array(),
						'message'     => __( 'No candidate posts found for linking.', 'antimanual' ),
					),
				)
			);
		}

		// Build AI prompt for link suggestions.
		$source_content      = wp_strip_all_tags( $post->post_content );
		$source_content_trim = mb_substr( $source_content, 0, 3000 );
		$candidate_map       = array();

		$candidates_text = '';
		foreach ( $candidates as $idx => $candidate ) {
			$candidate_url = get_permalink( $candidate->ID );
			if ( ! $candidate_url ) {
				continue;
			}

			$candidate_map[ (int) $candidate->ID ] = esc_url_raw( $candidate_url );
			$excerpt = wp_strip_all_tags( $candidate->post_content );
			$excerpt = mb_substr( $excerpt, 0, 200 );
			$candidates_text .= sprintf(
				"\n%d. ID: %d | Title: %s | URL: %s | Excerpt: %s",
				$idx + 1,
				$candidate->ID,
				$candidate->post_title,
				$candidate_url,
				$excerpt
			);
		}

		$system_prompt = "You are an expert SEO internal linking analyst. Your job is to analyze a source post and recommend which candidate posts should be linked from within this source post's content.\n\n"
			. "For each recommendation, provide:\n"
			. "1. The candidate post ID to link to\n"
			. "2. A suggested anchor text (natural sentence fragment from the source content that would work as anchor)\n"
			. "3. A short context quote showing around where in the source content the link should be inserted\n"
			. "4. A relevancy score from 1-100\n\n"
			. "Return valid JSON only, no markdown fences:\n"
			. "{\n"
			. "  \"suggestions\": [\n"
			. "    {\n"
			. "      \"target_post_id\": 123,\n"
			. "      \"target_title\": \"Title of the target post\",\n"
			. "      \"target_url\": \"https://example.com/post\",\n"
			. "      \"anchor_text\": \"suggested anchor text\",\n"
			. "      \"context\": \"...surrounding text where anchor appears...\",\n"
			. "      \"relevancy_score\": 85,\n"
			. "      \"reason\": \"Brief reason why this link is relevant\"\n"
			. "    }\n"
			. "  ]\n"
			. "}\n\n"
			. "Rules:\n"
			. "- Only suggest genuinely relevant links (score >= 50).\n"
			. "- Use natural anchor text that flows within the existing sentence.\n"
			. "- Avoid generic anchors like \"click here\" or \"read more\".\n"
			. "- Maximum {$max} suggestions.\n"
			. "- Sort by relevancy score descending.";

		$user_prompt = sprintf(
			"Source Post Title: %s\n\nSource Post Content:\n%s\n\nCandidate Posts:%s",
			$post->post_title,
			$source_content_trim,
			$candidates_text
		);

		$messages = array(
			array( 'role' => 'system', 'content' => $system_prompt ),
			array( 'role' => 'user', 'content' => $user_prompt ),
		);

		$reply = AIProvider::get_reply( $messages );

		if ( ! is_string( $reply ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $reply['error'] ?? __( 'AI analysis failed. Please try again.', 'antimanual' ),
				)
			);
		}

		$parsed = $this->parse_json_response( $reply );

		if ( ! is_array( $parsed ) || ! isset( $parsed['suggestions'] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Could not parse AI suggestions. Please try again.', 'antimanual' ),
				)
			);
		}

		// Sanitize and validate suggestions.
		$suggestions = array();
		$seen        = array();
		foreach ( $parsed['suggestions'] as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$target_id = intval( $suggestion['target_post_id'] ?? 0 );
			if ( $target_id <= 0 || $target_id === $post_id || ! isset( $candidate_map[ $target_id ] ) ) {
				continue;
			}

			$target_post = get_post( $target_id );
			if ( ! $target_post instanceof \WP_Post || 'publish' !== $target_post->post_status ) {
				continue;
			}

			$anchor_text = sanitize_text_field( $suggestion['anchor_text'] ?? '' );
			$relevancy   = min( 100, max( 0, intval( $suggestion['relevancy_score'] ?? 0 ) ) );
			if ( '' === $anchor_text || $relevancy < 50 ) {
				continue;
			}

			if ( ! preg_match( '/' . preg_quote( $anchor_text, '/' ) . '/iu', $source_content ) ) {
				continue;
			}

			$target_url = $candidate_map[ $target_id ];
			if ( ! $this->is_internal_url( $target_url, trailingslashit( home_url() ) ) ) {
				continue;
			}

			$unique_key = strtolower( $anchor_text ) . '|' . $this->normalize_url_for_compare( $target_url );
			if ( isset( $seen[ $unique_key ] ) ) {
				continue;
			}
			$seen[ $unique_key ] = true;

			$suggestions[] = array(
				'target_post_id' => $target_id,
				'target_title'   => sanitize_text_field( $suggestion['target_title'] ?? $target_post->post_title ),
				'target_url'     => esc_url( $target_url ),
				'anchor_text'    => $anchor_text,
				'context'        => sanitize_text_field( $suggestion['context'] ?? '' ),
				'relevancy'      => $relevancy,
				'reason'         => sanitize_text_field( $suggestion['reason'] ?? '' ),
			);
		}

		// Sort by relevancy descending.
		usort( $suggestions, fn( $a, $b ) => $b['relevancy'] - $a['relevancy'] );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'post_id'     => $post_id,
					'post_title'  => $post->post_title,
					'suggestions' => array_slice( $suggestions, 0, $max ),
				),
			)
		);
	}

	/**
	 * Insert an internal link into a post's content.
	 *
	 * Searches for the anchor text in the source post content
	 * and wraps it with a link to the target URL.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function insert_link( $request ) {
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Internal Linking requires the SEO Plus plan.', 'antimanual' ),
				),
				403
			);
		}

		$payload     = json_decode( $request->get_body(), true );
		$payload     = is_array( $payload ) ? $payload : array();
		$post_id     = intval( $payload['post_id'] ?? 0 );
		$anchor_text = sanitize_text_field( $payload['anchor_text'] ?? '' );
		$target_url  = esc_url_raw( $payload['target_url'] ?? '' );
		$site_url    = trailingslashit( home_url() );

		if ( $post_id <= 0 || empty( $anchor_text ) || empty( $target_url ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Post ID, anchor text, and target URL are required.', 'antimanual' ),
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Post not found.', 'antimanual' ),
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'You do not have permission to edit this post.', 'antimanual' ),
				)
			);
		}

		$content = $post->post_content;
		if ( ! $this->is_internal_url( $target_url, $site_url ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Target URL must be an internal URL on this site.', 'antimanual' ),
				)
			);
		}

		$post_url = get_permalink( $post_id );
		if ( $post_url && $this->normalize_url_for_compare( $post_url ) === $this->normalize_url_for_compare( $target_url ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Cannot insert a self-link to the same post.', 'antimanual' ),
				)
			);
		}

		// Check if this URL is already linked in content.
		if ( $this->has_link_in_content( $content, $target_url, $site_url ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'This link already exists in the post content.', 'antimanual' ),
				)
			);
		}

		$replacement = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $target_url ),
			esc_html( $anchor_text )
		);

		$updated_content = $this->insert_link_in_content( $content, $anchor_text, $replacement );

		if ( $updated_content === $content ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Could not find the anchor text in the post content. Try editing it slightly.', 'antimanual' ),
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		$this->bump_report_cache_version();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'post_id'     => $post_id,
					'anchor_text' => $anchor_text,
					'target_url'  => esc_url( $target_url ),
					'message'     => __( 'Link inserted successfully.', 'antimanual' ),
				),
			)
		);
	}

	/**
	 * Bulk auto-interlink: generate suggestions and insert links
	 * for multiple posts in one operation.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function bulk_link( $request ) {
		if ( ! atml_is_seo_plus() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Internal Linking requires the SEO Plus plan.', 'antimanual' ),
				),
				403
			);
		}

		$payload          = json_decode( $request->get_body(), true );
		$payload          = is_array( $payload ) ? $payload : array();
		$post_ids         = isset( $payload['post_ids'] ) && is_array( $payload['post_ids'] )
			? array_map( 'intval', $payload['post_ids'] )
			: array();
		$max_per_post     = min( 5, max( 1, intval( $payload['max_links_per_post'] ?? 3 ) ) );
		$cluster_post_ids = isset( $payload['cluster_post_ids'] ) && is_array( $payload['cluster_post_ids'] )
			? array_values( array_unique( array_filter( array_map( 'intval', $payload['cluster_post_ids'] ), fn( $id ) => $id > 0 ) ) )
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

		if ( count( $post_ids ) > 20 ) {
			$post_ids = array_slice( $post_ids, 0, 20 );
		}

		$results      = array();
		$total_linked = 0;
		$site_url     = trailingslashit( home_url() );

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

			// Get candidates — restrict to cluster posts when provided.
			$candidates = ! empty( $cluster_post_ids )
				? $this->get_cluster_candidate_posts( $post_id, $cluster_post_ids )
				: $this->get_candidate_posts( $post_id, $post->post_type, 20 );
			if ( empty( $candidates ) ) {
				$results[] = array(
					'post_id'     => $post_id,
					'title'       => $post->post_title,
					'status'      => 'skipped',
					'message'     => __( 'No candidate posts available.', 'antimanual' ),
					'links_added' => 0,
				);
				continue;
			}

			// Build a quick AI prompt for this post.
			$source_text = wp_strip_all_tags( $post->post_content );
			$source_text = mb_substr( $source_text, 0, 2000 );

			$candidate_lines = '';
			$candidate_map   = array();
			$candidate_limit = ! empty( $cluster_post_ids ) ? 50 : 15;
			foreach ( array_slice( $candidates, 0, $candidate_limit ) as $idx => $c ) {
				$candidate_url = get_permalink( $c->ID );
				if ( ! $candidate_url ) {
					continue;
				}
				$candidate_map[ (int) $c->ID ] = esc_url_raw( $candidate_url );
				$candidate_lines .= sprintf(
					"\n%d. ID:%d | %s | %s",
					$idx + 1,
					$c->ID,
					$c->post_title,
					$candidate_url
				);
			}

			$system = "You are an SEO expert. Given a source post and candidate posts, return JSON with the top {$max_per_post} internal link suggestions. Each must have: target_post_id, target_url, anchor_text (an existing phrase from the source content). Return valid JSON only: {\"links\":[{\"target_post_id\":123,\"target_url\":\"...\",\"anchor_text\":\"...\"}]}";

			$user_msg = "Source: \"{$post->post_title}\"\n{$source_text}\n\nCandidates:{$candidate_lines}";

			$reply = AIProvider::get_reply( array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user_msg ),
			) );

			if ( ! is_string( $reply ) ) {
				$results[] = array(
					'post_id'     => $post_id,
					'title'       => $post->post_title,
					'status'      => 'error',
					'message'     => __( 'AI suggestion failed.', 'antimanual' ),
					'links_added' => 0,
				);
				continue;
			}

			$parsed = $this->parse_json_response( $reply );
			$links  = $parsed['links'] ?? array();
			$added  = 0;

			$content = $post->post_content;
			foreach ( $links as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}

				$target_id = intval( $link['target_post_id'] ?? 0 );
				$anchor    = sanitize_text_field( $link['anchor_text'] ?? '' );
				$url       = $target_id > 0 && isset( $candidate_map[ $target_id ] )
					? $candidate_map[ $target_id ]
					: esc_url_raw( $link['target_url'] ?? '' );

				if ( empty( $anchor ) || empty( $url ) ) {
					continue;
				}

				if ( ! $this->is_internal_url( $url, $site_url ) ) {
					continue;
				}

				$post_url = get_permalink( $post_id );
				if ( $post_url && $this->normalize_url_for_compare( $post_url ) === $this->normalize_url_for_compare( $url ) ) {
					continue;
				}

				if ( ! preg_match( '/' . preg_quote( $anchor, '/' ) . '/iu', wp_strip_all_tags( $content ) ) ) {
					continue;
				}

				if ( $this->has_link_in_content( $content, $url, $site_url ) ) {
					continue;
				}

				$replacement = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $anchor ) );
				$new_content = $this->insert_link_in_content( $content, $anchor, $replacement );

				if ( $new_content !== $content ) {
					$content = $new_content;
					++$added;
				}
			}

			if ( $added > 0 ) {
				wp_update_post( array(
					'ID'           => $post_id,
					'post_content' => $content,
				) );
				$total_linked += $added;
			}

			$results[] = array(
				'post_id'     => $post_id,
				'title'       => $post->post_title,
				'status'      => 'success',
				'links_added' => $added,
			);
		}

		if ( $total_linked > 0 ) {
			$this->bump_report_cache_version();
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'results'      => $results,
					'total_linked' => $total_linked,
				),
			)
		);
	}

	/* ======================================================================
	   Helper Methods
	   ====================================================================== */

	/**
	 * Extract internal links from post content.
	 *
	 * @param string $content  Post HTML content.
	 * @param string $site_url Site home URL with trailing slash.
	 * @return array Array of link data arrays.
	 */
	private function extract_internal_links( string $content, string $site_url ): array {
		$links = array();
		if ( empty( $content ) ) {
			return $links;
		}

		preg_match_all(
			'/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$href   = html_entity_decode( trim( $match[1] ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
			$anchor = wp_strip_all_tags( $match[2] );

			if ( $this->is_internal_url( $href, $site_url ) ) {
				$links[] = array(
					'url'    => $href,
					'anchor' => $anchor,
				);
			}
		}

		return $links;
	}

	/**
	 * Count inbound internal links pointing to a post.
	 *
	 * @param int $post_id Target post ID.
	 * @return int Number of inbound links found.
	 */
	private function count_inbound_links( int $post_id ): int {
		global $wpdb;

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return 0;
		}

		$permalink = untrailingslashit( $permalink );
		$relative  = untrailingslashit( wp_make_link_relative( $permalink ) );
		$patterns  = array_filter(
			array_unique(
				array(
					$permalink,
					$permalink . '/',
					$relative,
					$relative . '/',
				)
			)
		);

		$like_clauses = array();
		foreach ( $patterns as $pattern ) {
			$like_clauses[] = $wpdb->prepare(
				'post_content LIKE %s',
				'%' . $wpdb->esc_like( 'href="' . $pattern ) . '%'
			);
			$like_clauses[] = $wpdb->prepare(
				'post_content LIKE %s',
				'%' . $wpdb->esc_like( "href='" . $pattern ) . '%'
			);
		}

		$sql = 'SELECT COUNT(DISTINCT ID) FROM %i '
			. "WHERE post_status = 'publish' "
			. 'AND ID != %d '
			. 'AND (' . implode( ' OR ', $like_clauses ) . ')';

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $wpdb->posts, $post_id ) );
	}

	/**
	 * Determine link health status for a post.
	 *
	 * @param int $outbound   Number of outbound internal links.
	 * @param int $inbound    Number of inbound internal links.
	 * @param int $word_count Post word count.
	 * @return string Health status: good, warning, critical.
	 */
	private function get_link_health( int $outbound, int $inbound, int $word_count ): string {
		if ( $inbound === 0 ) {
			return 'critical'; // Orphan page.
		}

		$total = $outbound + $inbound;

		if ( $total < 2 ) {
			return 'critical';
		}

		if ( $total < 5 ) {
			return 'warning';
		}

		if ( $outbound > 30 ) {
			return 'warning'; // Over-linked.
		}

		return 'good';
	}

	/**
	 * Get candidate posts for linking (exclude the source post).
	 *
	 * @param int    $exclude_id Post ID to exclude.
	 * @param string $post_type  Post type to search.
	 * @param int    $limit      Max candidates to return.
	 * @return \WP_Post[] Array of candidate posts.
	 */
	private function get_candidate_posts( int $exclude_id, string $post_type, int $limit = 40 ): array {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'post__not_in'   => array( $exclude_id ),
			'orderby'        => 'rand',
			'fields'         => '',
		);

		return get_posts( $args );
	}

	/**
	 * Get candidate posts from a specific cluster (exclude the source post).
	 *
	 * Used by silo/cluster auto-linking to restrict candidates to only
	 * the posts that belong to the same cluster, ensuring all cluster
	 * members can be interlinked.
	 *
	 * @param int   $exclude_id       Post ID to exclude (the source post).
	 * @param int[] $cluster_post_ids  All post IDs in the cluster.
	 * @return \WP_Post[] Array of candidate posts.
	 */
	private function get_cluster_candidate_posts( int $exclude_id, array $cluster_post_ids ): array {
		$candidate_ids = array_values( array_filter(
			$cluster_post_ids,
			fn( $id ) => $id !== $exclude_id
		) );

		if ( empty( $candidate_ids ) ) {
			return array();
		}

		return get_posts( array(
			'post__in'    => $candidate_ids,
			'post_type'   => 'any',
			'post_status' => 'publish',
			'numberposts' => count( $candidate_ids ),
			'orderby'     => 'post__in',
		) );
	}

	/**
	 * Insert a link into HTML content at the first unlinked occurrence
	 * of the anchor text.
	 *
	 * @param string $content     Original HTML content.
	 * @param string $anchor_text Text to search for.
	 * @param string $replacement Full <a> tag replacement.
	 * @return string Updated content (or original if not found).
	 */
	private function insert_link_in_content( string $content, string $anchor_text, string $replacement ): string {
		if ( empty( $anchor_text ) || empty( $content ) ) {
			return $content;
		}

		$anchor_pattern = '/' . preg_quote( $anchor_text, '/' ) . '/iu';
		$segments       = preg_split(
			'/(<a\b[^>]*>.*?<\/a>)/isu',
			$content,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $segments ) ) {
			return $content;
		}

		$inserted = false;
		foreach ( $segments as $segment_index => $segment ) {
			if ( preg_match( '/^<a\b/i', $segment ) ) {
				continue;
			}

			$tokens = preg_split( '/(<[^>]+>)/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( ! is_array( $tokens ) ) {
				continue;
			}

			foreach ( $tokens as $token_index => $token ) {
				if ( '' === $token || 0 === strpos( $token, '<' ) ) {
					continue;
				}

				$replaced = preg_replace( $anchor_pattern, $replacement, $token, 1, $count );
				if ( is_string( $replaced ) && $count > 0 ) {
					$tokens[ $token_index ]     = $replaced;
					$segments[ $segment_index ] = implode( '', $tokens );
					$inserted                   = true;
					break 2;
				}
			}
		}

		return $inserted ? implode( '', $segments ) : $content;
	}

	/**
	 * Apply report filter to rows.
	 *
	 * @param array  $rows   Full report rows.
	 * @param string $filter Active filter.
	 * @return array
	 */
	private function apply_report_filter( array $rows, string $filter ): array {
		if ( 'orphan' === $filter ) {
			return array_values( array_filter( $rows, fn( $r ) => ! empty( $r['is_orphan'] ) ) );
		}

		if ( 'low-links' === $filter ) {
			return array_values( array_filter( $rows, fn( $r ) => ( (int) $r['outbound_links'] + (int) $r['inbound_links'] ) < 3 ) );
		}

		if ( 'over-linked' === $filter ) {
			return array_values( array_filter( $rows, fn( $r ) => (int) $r['outbound_links'] > 20 ) );
		}

		return $rows;
	}

	/**
	 * Build report summary from rows.
	 *
	 * @param array $rows Report rows.
	 * @return array
	 */
	private function summarize_report_rows( array $rows ): array {
		$total_orphans  = 0;
		$total_outbound = 0;
		$total_inbound  = 0;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_orphan'] ) ) {
				++$total_orphans;
			}
			$total_outbound += (int) ( $row['outbound_links'] ?? 0 );
			$total_inbound  += (int) ( $row['inbound_links'] ?? 0 );
		}

		$total_posts = count( $rows );

		return array(
			'total_posts'    => $total_posts,
			'orphan_pages'   => $total_orphans,
			'total_outbound' => $total_outbound,
			'total_inbound'  => $total_inbound,
			'avg_links'      => $total_posts > 0
				? round( ( $total_outbound + $total_inbound ) / $total_posts, 1 )
				: 0,
		);
	}

	/**
	 * Build report cache key for a post type + search context.
	 *
	 * @param string $post_type Post type.
	 * @param string $search    Search term.
	 * @return string
	 */
	private function get_report_cache_key( $post_type, string $search ): string {
		$version = (string) get_option( self::CACHE_VERSION_OPTION, '1' );
		$payload = wp_json_encode(
			array(
				'version'   => $version,
				'post_type' => $post_type,
				'search'    => $search,
			)
		);

		return self::CACHE_PREFIX . md5( (string) $payload );
	}

	/**
	 * Bump report cache version so future scans re-compute fresh data.
	 *
	 * @return void
	 */
	private function bump_report_cache_version(): void {
		update_option( self::CACHE_VERSION_OPTION, (string) time(), false );
	}

	/**
	 * Validate whether URL points to the current site.
	 *
	 * @param string $url      URL to check.
	 * @param string $site_url Site URL.
	 * @return bool
	 */
	private function is_internal_url( string $url, string $site_url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}

		$lower = strtolower( $url );
		if ( 0 === strpos( $lower, 'mailto:' ) || 0 === strpos( $lower, 'tel:' ) || 0 === strpos( $lower, 'javascript:' ) || 0 === strpos( $lower, '#' ) ) {
			return false;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$site_scheme = wp_parse_url( $site_url, PHP_URL_SCHEME ) ?: 'https';
			$url         = $site_scheme . ':' . $url;
		}

		$parsed = wp_parse_url( $url );
		if ( false === $parsed ) {
			return false;
		}

		$site_host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$link_host = strtolower( (string) ( $parsed['host'] ?? '' ) );

		if ( '' !== $link_host ) {
			return '' !== $site_host && $link_host === $site_host;
		}

		// Relative URLs (path, query) are internal.
		return true;
	}

	/**
	 * Check if content already contains a link to target URL.
	 *
	 * @param string $content  Post content.
	 * @param string $target   Target URL.
	 * @param string $site_url Site URL.
	 * @return bool
	 */
	private function has_link_in_content( string $content, string $target, string $site_url ): bool {
		$target_normalized = $this->normalize_url_for_compare( $target );
		if ( '' === $target_normalized ) {
			return false;
		}

		$links = $this->extract_internal_links( $content, $site_url );
		foreach ( $links as $link ) {
			$link_url = $link['url'] ?? '';
			if ( $target_normalized === $this->normalize_url_for_compare( (string) $link_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize URLs to compare internal targets safely.
	 *
	 * @param string $url Input URL.
	 * @return string
	 */
	private function normalize_url_for_compare( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$site_scheme = wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https';
			$url         = $site_scheme . ':' . $url;
		}

		$parsed = wp_parse_url( $url );
		if ( false === $parsed ) {
			return '';
		}

		if ( empty( $parsed['host'] ) ) {
			$path  = (string) ( $parsed['path'] ?? '/' );
			$query = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
			$url   = home_url( '/' . ltrim( $path, '/' ) ) . $query;
			$parsed = wp_parse_url( $url );
			if ( false === $parsed ) {
				return '';
			}
		}

		$host = strtolower( (string) ( $parsed['host'] ?? '' ) );
		$path = '/' . ltrim( (string) ( $parsed['path'] ?? '/' ), '/' );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		$query = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

		return $host . $path . $query;
	}

	/**
	 * Parse a JSON response from AI, handling markdown fences.
	 *
	 * @param string $raw Raw AI response string.
	 * @return array|null Parsed array or null on failure.
	 */
	private function parse_json_response( string $raw ): ?array {
		// Remove markdown code fences if present.
		$cleaned = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
		$cleaned = preg_replace( '/\s*```$/', '', $cleaned );

		$result = json_decode( $cleaned, true );

		return is_array( $result ) ? $result : null;
	}
}
