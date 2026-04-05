<?php

namespace Antimanual;

use Antimanual\AIProvider;

/**
 * FAQ Generator - Generates FAQs based on Knowledge Base content.
 *
 * @since 2.3.0
 */
class FAQGenerator {

	/**
	 * Generate FAQ items from knowledge base.
	 *
	 * @param array $options {
	 *     Generation options.
	 *
	 *     @type int    $count       Number of FAQs to generate. Default 5.
	 *     @type string $topic       Optional topic focus.
	 *     @type string $tone        Tone of voice. Default 'professional'.
	 *     @type bool   $use_kb      Use entire knowledge base. Default true.
	 *     @type array  $source_ids  Specific knowledge source IDs to use.
	 * }
	 * @return array|\WP_Error Array of FAQ items or WP_Error on failure.
	 */
	public static function generate_faqs( array $options = array() ) {
		$defaults = array(
			'count'      => 5,
			'topic'      => '',
			'tone'       => 'professional',
			'use_kb'     => true,
			'source_ids' => array(),
		);

		$options = wp_parse_args( $options, $defaults );

		// Check for API key.
		if ( ! AIProvider::has_api_key() ) {
			return new \WP_Error( 'no_api_key', __( 'AI API Key is not configured.', 'antimanual' ) );
		}

		// Get knowledge base context.
		$context = self::get_knowledge_context( $options );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( empty( $context ) ) {
			return new \WP_Error( 'no_knowledge', __( 'No knowledge base content found. Please add content to your knowledge base first.', 'antimanual' ) );
		}

		// Generate FAQs using AI.
		$faqs = self::generate_with_ai( $context, $options );

		return $faqs;
	}

	/**
	 * Get knowledge base context for FAQ generation.
	 *
	 * @param array $options Generation options.
	 * @return string|\WP_Error Context string or error.
	 */
	private static function get_knowledge_context( array $options ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'antimanual_embeddings';

		// Check if table exists.
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;
		if ( ! $table_exists ) {
			return new \WP_Error( 'no_table', __( 'Knowledge base table does not exist.', 'antimanual' ) );
		}

		$context_parts = array();

		// If specific source IDs provided, use them.
		if ( ! empty( $options['source_ids'] ) ) {
			$chunks     = array();
			$source_ids = array_values( array_filter( array_map( 'sanitize_text_field', (array) $options['source_ids'] ) ) );

			foreach ( $source_ids as $source_id ) {
				$source_chunks = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT DISTINCT knowledge_id, chunk_text, post_id, type, url FROM %i WHERE knowledge_id = %s ORDER BY chunk_index',
						$table_name,
						$source_id
					)
				);

				if ( ! empty( $source_chunks ) ) {
					$chunks = array_merge( $chunks, $source_chunks );
				}
			}
		} else {
			// Use semantic search if topic is provided.
			if ( ! empty( $options['topic'] ) ) {
				$related_chunks = antimanual_get_related_chunks( $options['topic'], 15 );

				if ( is_array( $related_chunks ) && ! isset( $related_chunks['error'] ) ) {
					foreach ( $related_chunks as $chunk ) {
						if ( ( $chunk['similarity'] ?? 0 ) >= 0.2 ) {
							$row             = $chunk['row'];
							$reference       = Embedding::get_chunk_reference( $row );
							$context_parts[] = sprintf(
								"[Source: %s]\n%s",
								$reference['title'],
								$row->chunk_text
							);
						}
					}
				}
			}

			// If no topic or not enough context, get general knowledge.
			if ( count( $context_parts ) < 5 ) {
				$limit  = 20 - count( $context_parts );
				$chunks = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT knowledge_id, chunk_text, post_id, type, url 
						FROM `$table_name` 
						ORDER BY RAND() 
						LIMIT %d",
						$limit
					)
				);

				foreach ( $chunks as $row ) {
					$reference       = Embedding::get_chunk_reference( $row );
					$context_parts[] = sprintf(
						"[Source: %s]\n%s",
						$reference['title'],
						$row->chunk_text
					);
				}
			}
		}

		// If we queried by source IDs, process those chunks.
		if ( isset( $chunks ) && ! empty( $chunks ) ) {
			foreach ( $chunks as $row ) {
				$reference       = Embedding::get_chunk_reference( $row );
				$context_parts[] = sprintf(
					"[Source: %s]\n%s",
					$reference['title'],
					$row->chunk_text
				);
			}
		}

		return implode( "\n\n---\n\n", $context_parts );
	}

	/**
	 * Generate FAQs using AI.
	 *
	 * @param string $context  Knowledge base context.
	 * @param array  $options  Generation options.
	 * @return array|\WP_Error Array of FAQ items or error.
	 */
	private static function generate_with_ai( string $context, array $options ) {
		$count = min( max( (int) $options['count'], 1 ), 20 );
		$tone  = sanitize_text_field( $options['tone'] );
		$topic = ! empty( $options['topic'] ) ? sanitize_text_field( $options['topic'] ) : 'the provided content';

		$topic_instruction = ! empty( $options['topic'] )
			? "Focus the FAQs specifically on: {$topic}"
			: 'Generate FAQs that cover the most important aspects of the knowledge base.';

		$system_prompt = "You are an expert FAQ writer. Your task is to generate frequently asked questions based on the provided knowledge base content.\n\n"
			. "RULES:\n"
			. "- Generate exactly {$count} FAQs.\n"
			. "- Each FAQ must have a clear, concise question and a comprehensive answer.\n"
			. "- Questions should be phrased as a real user would ask them.\n"
			. "- Answers should be informative, accurate, and based ONLY on the provided content.\n"
			. "- Use a {$tone} tone throughout.\n"
			. "- Do NOT make up information not present in the knowledge base.\n"
			. "- Answers should be 2-4 sentences, providing enough detail to be helpful.\n\n"
			. "OUTPUT FORMAT:\n"
			. "Return ONLY a valid JSON array. No explanations, no markdown, no code blocks.\n"
			. "Each item must have \"question\" and \"answer\" keys.\n\n"
			. "Example:\n"
			. "[{\"question\": \"How do I get started?\", \"answer\": \"To get started, simply...\"}]";

		$user_prompt = "# KNOWLEDGE BASE CONTENT:\n"
			. $context
			. "\n\n# INSTRUCTIONS:\n"
			. $topic_instruction
			. "\n\nGenerate {$count} FAQs based on the above content. Return only valid JSON.";

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

		try {
			$response = AIProvider::get_reply( $messages );

			// Check for error response.
			if ( is_array( $response ) && isset( $response['error'] ) ) {
				return new \WP_Error( 'ai_error', $response['error'] );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Clean the response - remove markdown code blocks and trim.
			$cleaned = AIResponseCleaner::clean_plain_text( $response );

			// Try to extract JSON array from response.
			// First, try direct decode.
			$faqs = json_decode( $cleaned, true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $faqs ) ) {
				// Try to extract JSON array using regex.
				preg_match( '/\[[\s\S]*\]/', $cleaned, $matches );
				if ( ! empty( $matches[0] ) ) {
					$faqs = json_decode( $matches[0], true );
				}
			}

			if ( ! is_array( $faqs ) ) {
				return new \WP_Error( 'parse_error', __( 'Failed to parse FAQ response from AI.', 'antimanual' ) );
			}

			// Validate and sanitize FAQs.
			$valid_faqs = array();
			foreach ( $faqs as $index => $faq ) {
				if ( isset( $faq['question'] ) && isset( $faq['answer'] ) ) {
					$valid_faqs[] = array(
						'id'       => 'faq-' . uniqid(),
						'question' => sanitize_text_field( $faq['question'] ),
						'answer'   => wp_kses_post( $faq['answer'] ),
					);
				}
			}

			return $valid_faqs;

		} catch ( \Exception $e ) {
			return new \WP_Error( 'generation_failed', $e->getMessage() );
		}
	}

	/**
	 * Convert FAQ items to Gutenberg accordion blocks.
	 * Returns ONLY the FAQ accordion blocks, without schema.
	 *
	 * @param array $items Array of FAQ items with 'question' and 'answer'.
	 * @return string Gutenberg block HTML.
	 */
	public static function to_gutenberg_blocks( array $items ) {
		$inner_blocks = array();

		foreach ( $items as $item ) {
			$question = esc_html( $item['question'] ?? '' );
			$answer   = wp_kses_post( $item['answer'] ?? '' );

			// Build accordion-item block.
			$inner_blocks[] = array(
				'blockName'    => 'aab/accordion-item',
				'attrs'        => array(),
				'innerContent' => array(
					'<div class="wp-block-aab-accordion-item aagb__accordion_container panel">',
					'<div class="aagb__accordion_head">',
					'<div class="aagb__accordion_heading">',
					'<div class="head_content_wrapper">',
					'<div class="title_wrapper">',
					'<h5 class="aagb__accordion_title">' . $question . '</h5>',
					'</div>',
					'</div>',
					'</div>',
					'<div class="aagb__accordion_icon">',
					'<div class="aagb__icon_dashicons_box"><span class="aagb__icon dashicons dashicons-plus-alt2"></span></div>',
					'</div>',
					'</div>',
					'<div class="aagb__accordion_body" role="region">',
					'<div class="aagb__accordion_component">',
					null,
					'</div>',
					'</div>',
					'</div>',
				),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/paragraph',
						'attrs'        => array(),
						'innerContent' => array(
							'<p>' . $answer . '</p>',
						),
						'innerBlocks'  => array(),
					),
				),
			);
		}

		// Build innerContent array with one null per accordion item.
		$group_inner_content   = array( '<div class="wp-block-aab-group-accordion">' );
		$group_inner_content   = array_merge( $group_inner_content, array_fill( 0, count( $inner_blocks ), null ) );
		$group_inner_content[] = '</div>';

		// Wrap all accordion items in a group-accordion block.
		$blocks = array(
			array(
				'blockName'    => 'aab/group-accordion',
				'attrs'        => array(),
				'innerContent' => $group_inner_content,
				'innerBlocks'  => $inner_blocks,
			),
		);

		return serialize_blocks( $blocks );
	}

	/**
	 * Convert FAQ items to default WordPress accordion blocks (core/details).
	 * Uses the native WordPress Details block for broader compatibility.
	 *
	 * @since 2.3.0
	 * @param array $items Array of FAQ items with 'question' and 'answer'.
	 * @return string Gutenberg block HTML.
	 */
	public static function to_default_accordion_blocks( array $items ) {
		$blocks = array();

		foreach ( $items as $item ) {
			$question = esc_html( $item['question'] ?? '' );
			$answer   = wp_kses_post( $item['answer'] ?? '' );

			$blocks[] = array(
				'blockName'    => 'core/accordion',
				'innerContent' => array(
					'<div role="group" class="wp-block-accordion">',
					null,
					'</div>',
				),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/accordion-item',
						'innerContent' => array(
							'<div class="wp-block-accordion-item">',
							null,
							null,
							'</div>',
						),
						'innerBlocks'  => array(
							array(
								'blockName'    => 'core/accordion-heading',
								'innerContent' => array(
									'<h3 class="wp-block-accordion-heading">',
									'<button class="wp-block-accordion-heading__toggle">',
									'<span class="wp-block-accordion-heading__toggle-title">',
									$question,
									'</span>',
									'<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">',
									'+',
									'</span>',
									'</button>',
									'</h3>',
								),
							),
							array(
								'blockName'    => 'core/accordion-panel',
								'innerContent' => array(
									'<div role="region" class="wp-block-accordion-panel">',
									null,
									'</div>',
								),
								'innerBlocks'  => array(
									array(
										'blockName'    => 'core/paragraph',
										'innerContent' => array(
											'<p>',
											$answer,
											'</p>',
										),
									),
								),
							),
						),
					),
				),
			);
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Check if the Advanced Accordion Block plugin is installed and activated.
	 * Supports both free (advanced-accordion-block) and pro (advanced-accordion-block-pro) versions.
	 *
	 * Uses hybrid detection:
	 * - Class/constant existence for activation check (reliable, no file path dependency)
	 * - File path check only for generating install/activate URLs
	 *
	 * @since 2.3.0
	 * @return array Plugin status with install/activate info.
	 */
	public static function check_aab_plugin_status() {
		// Primary detection: Check if the main class or constant exists (plugin is ACTIVE).
		// This works regardless of folder name (free or pro).
		$is_activated = class_exists( 'AAGB_BLOCKS_CLASS' ) || defined( 'AAGB_VERSION' );

		// If activated, check if it's pro version via Freemius.
		$is_pro = false;
		if ( $is_activated && function_exists( 'aab_fs' ) ) {
			$is_pro = aab_fs()->is_premium();
		}

		// For install/activate URLs, we need to check file paths.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();

		// Plugin file paths - Pro and Free versions.
		// Only the folder name differs, main file is the same.
		$plugin_files = array(
			'pro'  => 'advanced-accordion-block-pro/advanced-accordion-block.php',
			'free' => 'advanced-accordion-block/advanced-accordion-block.php',
		);

		$is_installed = false;
		$found_file   = '';

		// Check if pro version is installed (priority).
		if ( isset( $installed_plugins[ $plugin_files['pro'] ] ) ) {
			$is_installed = true;
			$found_file   = $plugin_files['pro'];
			$is_pro       = true;
		} elseif ( isset( $installed_plugins[ $plugin_files['free'] ] ) ) {
			// Check if free version is installed.
			$is_installed = true;
			$found_file   = $plugin_files['free'];
		}

		// Generate URLs.
		$installation_url = wp_nonce_url(
			admin_url( 'update.php?action=install-plugin&plugin=advanced-accordion-block' ),
			'install-plugin_advanced-accordion-block'
		);

		$activation_url = '';
		if ( $is_installed && ! empty( $found_file ) ) {
			$activation_url = wp_nonce_url(
				admin_url( 'plugins.php?action=activate&plugin=' . $found_file ),
				'activate-plugin_' . $found_file
			);
		}

		return array(
			'is_installed'     => $is_installed,
			'is_activated'     => $is_activated,
			'is_pro'           => $is_pro,
			'plugin_file'      => $found_file,
			'installation_url' => $installation_url,
			'activation_url'   => $activation_url,
			'plugins_url'      => admin_url( 'plugins.php' ),
			'plugin_url'       => 'https://wordpress.org/plugins/advanced-accordion-block/',
		);
	}

	/**
	 * Install the Advanced Accordion Block plugin from WordPress.org.
	 *
	 * @since 2.3.0
	 * @return array|WP_Error Result with status or error.
	 */
	public static function install_aab_plugin() {
		// Check if user can install plugins.
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new \WP_Error( 'permission_denied', __( 'You do not have permission to install plugins.', 'antimanual' ) );
		}

		// Check if already installed.
		$status = self::check_aab_plugin_status();
		if ( $status['is_installed'] ) {
			return [
				'success'     => true,
				'message'     => __( 'Plugin is already installed.', 'antimanual' ),
				'plugin_file' => $status['plugin_file'],
			];
		}

		// Include all required WordPress admin files for plugin installation via REST API.
		// template.php must be loaded explicitly as it defines request_filesystem_credentials(),
		// which is not available outside the normal wp-admin request context.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

		// Get plugin info from WordPress.org.
		$api = plugins_api(
			'plugin_information',
			[
				'slug'   => 'advanced-accordion-block',
				'fields' => [ 'sections' => false ],
			]
		);

		if ( is_wp_error( $api ) ) {
			return new \WP_Error( 'api_error', $api->get_error_message() );
		}

		// Initialize the filesystem with 'direct' method so the upgrader never
		// renders a credentials HTML form into the output buffer.
		// This is safe on most shared hosts where the web server owns the files.
		$access_type = get_filesystem_method();
		if ( 'direct' !== $access_type ) {
			return new \WP_Error(
				'filesystem_error',
				__( 'Could not install the plugin automatically. Please install "Advanced Accordion Block" manually from the WordPress plugin directory.', 'antimanual' )
			);
		}

		// Suppress any stray HTML the upgrader classes may echo (e.g., progress
		// output from WP_Upgrader::run or fs_connect) so the REST response
		// remains valid JSON.
		ob_start();

		WP_Filesystem();

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		// Discard any HTML output produced during installation.
		ob_end_clean();

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'install_failed', $result->get_error_message() );
		}

		if ( true !== $result ) {
			$error_message = __( 'Plugin installation failed.', 'antimanual' );
			if ( ! empty( $skin->get_errors() ) ) {
				$errors        = $skin->get_errors()->get_error_messages();
				$error_message = implode( ' ', $errors );
			}
			return new \WP_Error( 'install_failed', $error_message );
		}

		// Refresh status to get the newly installed plugin file path.
		$new_status = self::check_aab_plugin_status();

		return [
			'success'     => true,
			'message'     => __( 'Plugin installed successfully.', 'antimanual' ),
			'plugin_file' => $new_status['plugin_file'],
		];
	}


	/**
	 * Activate the Advanced Accordion Block plugin.
	 *
	 * @since 2.3.0
	 * @return array|WP_Error Result with status or error.
	 */
	public static function activate_aab_plugin() {
		// Check if user can activate plugins.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error( 'permission_denied', __( 'You do not have permission to activate plugins.', 'antimanual' ) );
		}

		// Check current status using the correct key names returned by check_aab_plugin_status().
		$status = self::check_aab_plugin_status();

		// If already activated, nothing to do.
		if ( $status['is_activated'] ) {
			return [
				'success' => true,
				'message' => __( 'Plugin is already activated.', 'antimanual' ),
			];
		}

		// If not installed yet, install it first automatically.
		if ( ! $status['is_installed'] ) {
			$install_result = self::install_aab_plugin();
			if ( is_wp_error( $install_result ) ) {
				return $install_result;
			}
			// Refresh status after install to get the plugin file path.
			$status = self::check_aab_plugin_status();
		}

		if ( empty( $status['plugin_file'] ) ) {
			return new \WP_Error( 'not_installed', __( 'Plugin could not be located after installation.', 'antimanual' ) );
		}

		// Activate the plugin.
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$result = activate_plugin( $status['plugin_file'] );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'activation_failed', $result->get_error_message() );
		}

		return [
			'success' => true,
			'message' => __( 'Plugin activated successfully.', 'antimanual' ),
		];
	}


	/**
	 * Create a new post/page with FAQ blocks as content.
	 *
	 * @since 2.3.0
	 * @param string $blocks      The serialized block content.
	 * @param string $schema      Optional schema block content.
	 * @param string $post_type   Post type (post or page). Default 'post'.
	 * @param string $post_status Post status. Default 'draft'.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_faq_post( $blocks, $schema = '', $post_type = 'post', $post_status = 'draft' ) {
		$content = $blocks;

		// Append schema block if provided.
		if ( ! empty( $schema ) ) {
			$content .= "\n\n" . $schema;
		}

		$post_data = array(
			'post_title'   => __( 'FAQ', 'antimanual' ) . ' - ' . gmdate( 'Y-m-d H:i:s' ),
			'post_content' => $content,
			'post_status'  => $post_status,
			'post_type'    => $post_type,
		);

		$post_id = wp_insert_post( $post_data, true );

		return $post_id;
	}

	/**
	 * Generate FAQ Schema as a Gutenberg HTML block.
	 *
	 * @param array $items Array of FAQ items.
	 * @return string Schema block HTML.
	 */
	public static function generate_schema_block( array $items ) {
		$schema      = self::generate_faq_schema( $items );
		$schema_json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		$schema_block = array(
			array(
				'blockName'    => 'core/html',
				'attrs'        => array(),
				'innerContent' => array(
					'<script type="application/ld+json">' . $schema_json . '</script>',
				),
			),
		);

		return serialize_blocks( $schema_block );
	}

	/**
	 * Generate FAQ Schema markup for SEO.
	 *
	 * @param array $items Array of FAQ items.
	 * @return array FAQ Schema array.
	 */
	public static function generate_faq_schema( array $items ) {
		$main_entity = array();

		foreach ( $items as $item ) {
			$main_entity[] = array(
				'@type'          => 'Question',
				'name'           => $item['question'] ?? '',
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( $item['answer'] ?? '' ),
				),
			);
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);
	}

	/**
	 * Get available knowledge base sources for selection.
	 *
	 * @return array Array of knowledge sources.
	 */
	public static function get_knowledge_sources() {
		$sources = array();
		$types   = array( 'wp', 'pdf', 'url', 'txt' );

		foreach ( $types as $type ) {
			$items = Embedding::list_by_type( $type, 'post', 0, 100 );

			if ( ! is_wp_error( $items ) && is_array( $items ) ) {
				foreach ( $items as $item ) {
					$sources[] = array(
						'id'    => $item['id'],
						'title' => $item['title'],
						'type'  => $type,
						'link'  => $item['link'],
					);
				}
			}
		}

		return $sources;
	}
}
