<?php
/**
 * SonoAI — Knowledge Base AJAX handlers.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KnowledgeBaseAjax {

    private static ?KnowledgeBaseAjax $instance = null;

    public static function instance(): KnowledgeBaseAjax {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $actions = [
            'sonoai_kb_get_posts',
            'sonoai_kb_add_post',
            'sonoai_kb_remove_post',
            'sonoai_kb_add_pdf',
            'sonoai_kb_add_url',
            'sonoai_kb_add_jsonl',
            'sonoai_kb_add_txt',
            'sonoai_kb_edit_txt',
            'sonoai_kb_delete_item',
            'sonoai_kb_add_topic',
            'sonoai_kb_edit_topic',
            'sonoai_kb_delete_topic',
            'sonoai_kb_update_meta',
            'sonoai_kb_sync_topics',
            'sonoai_kb_upload_img',
            'sonoai_kb_delete_img_file',
            'sonoai_kb_sync_redis',
            'sonoai_kb_reindex_all',
            'sonoai_kb_reindex_items',
            'sonoai_kb_resync_items',
        ];
        foreach ( $actions as $action ) {
            add_action( "wp_ajax_{$action}", [ $this, str_replace( 'sonoai_kb_', 'handle_', $action ) ] );
        }
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function check( string $nonce_action ): void {
        if ( ! SecurityHelper::check_admin_caps() ) {
            sonoai_log_error( sprintf( '[SonoAI KB] Unauthorized access attempt by user %d for action: %s', get_current_user_id(), $nonce_action ) );
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'sonoai' ) ], 403 );
        }
        if ( ! check_ajax_referer( $nonce_action, 'security', false ) ) {
            sonoai_log_error( sprintf( '[SonoAI KB] Nonce verification failed for action: %s. User: %d', $nonce_action, get_current_user_id() ) );
            wp_send_json_error( [ 'message' => __( 'Forbidden: Security check failed.', 'sonoai' ) ], 403 );
        }
    }

    private function kb_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sonoai_kb_items';
    }

    private function emb_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sonoai_embeddings';
    }

    /**
     * Chunk text into ~800-char word-safe blocks.
     */
    private function chunk( string $text, int $max = 800 ): array {
        $chunks  = [];
        $current = '';
        $words   = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        foreach ( $words as $word ) {
            if ( mb_strlen( $current . $word, 'UTF-8' ) > $max && $current !== '' ) {
                $chunks[]  = $current;
                $current   = '';
            }
            $current .= $word;
        }
        if ( $current !== '' ) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Extract image src URLs and their labels (alt/title) from HTML or manual input.
     * Returns an array of objects: [ ['url' => '...', 'label' => '...'], ... ]
     */
    private function extract_image_urls( string $html, array $manual_images = [] ): array {
        $images = [];

        // 1. Process Manual Images (Priority)
        foreach ( $manual_images as $img ) {
            if ( ! empty( $img['url'] ) ) {
                $images[ $img['url'] ] = [
                    'url'   => esc_url_raw( $img['url'] ),
                    'label' => sanitize_text_field( $img['label'] ?? '' ) ?: __( 'Clinical Image', 'sonoai' ),
                ];
            }
        }

        // 2. Extract from HTML (Fallback/Complement)
        if ( ! empty( $html ) ) {
            if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
                foreach ( $matches[0] as $i => $full_tag ) {
                    $url = $matches[1][$i];
                    
                    // Skip if already in manual list (don't overwrite manual labels)
                    if ( isset( $images[$url] ) ) {
                        continue;
                    }

                    // Try to find alt or title
                    $label = '';
                    if ( preg_match( '/alt=["\']([^"\']+)["\']/', $full_tag, $alt_match ) ) {
                        $label = $alt_match[1];
                    } elseif ( preg_match( '/title=["\']([^"\']+)["\']/', $full_tag, $title_match ) ) {
                        $label = $title_match[1];
                    }
                    
                    // Fallback to filename
                    if ( empty( $label ) ) {
                        $label = ucwords( str_replace( [ '-', '_' ], ' ', pathinfo( parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_FILENAME ) ) );
                    }

                    $images[$url] = [
                        'url'   => $url,
                        'label' => $label ?: __( 'Clinical Image', 'sonoai' ),
                    ];
                }
            }
        }
        
        return array_values( $images );
    }

    /**
     * Parse and sanitize topic IDs from request into a comma-separated string.
     */
    private function get_request_topic_ids(): ?string {
        $topic_ids_input = $_POST['topic_ids'] ?? $_POST['topic_id'] ?? '';
        if ( is_array( $topic_ids_input ) ) {
            $topic_ids = array_map( 'intval', $topic_ids_input );
        } else {
            $topic_ids = array_filter( array_map( 'intval', explode( ',', $topic_ids_input ) ) );
        }
        $topic_ids = array_filter( $topic_ids );
        return ! empty( $topic_ids ) ? implode( ',', $topic_ids ) : null;
    }

    /**
     * Handle updating metadata (mode/topic) for a single KB item
     */
    public function handle_update_meta() {
        $this->check( 'sonoai_kb_update_meta' );

        $post_id  = SecurityHelper::get_param( 'post_id', 0, 'int' );
        $type     = SecurityHelper::get_param( 'type', 'wp' );
        $mode     = SecurityHelper::get_param( 'mode', 'guideline' );
        $topic_id = $this->get_request_topic_ids();

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'sonoai' ) ] );
        }

        global $wpdb;
        $table_name = $this->kb_table();
        $emb_table  = $this->emb_table();

        // Get topic slugs for embedding update
        $topic_slug = null;
        if ( ! empty( $topic_id ) ) {
            $ids = array_filter( array_map( 'intval', explode( ',', $topic_id ) ) );
            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $topics = $wpdb->get_results( $wpdb->prepare( "SELECT slug FROM `{$wpdb->prefix}sonoai_kb_topics` WHERE id IN ($placeholders)", $ids ) );
                $slugs = [];
                foreach ( $topics as $t ) {
                    $slugs[] = $t->slug;
                }
                $topic_slug = implode( ',', $slugs );
            }
        }

        // Update KB items table
        $updated = $wpdb->update(
            $table_name,
            [
                'mode'     => $mode,
                'topic_id' => $topic_id
            ],
            [
                'post_id' => $post_id,
                'type'    => $type
            ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        // Also update embeddings table for correct RAG filtering
        $wpdb->update(
            $emb_table,
            [ 'mode' => $mode, 'topic_slug' => $topic_slug ],
            [ 'post_id' => $post_id, 'type' => $type ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to update metadata.', 'sonoai' ) ] );
        }

        // Sync changes to Redis
        if ( class_exists('SonoAI\RedisManager') ) {
            $redis = \SonoAI\RedisManager::instance();
            $redis->delete_vectors_by_post( $post_id );
            
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$emb_table` WHERE post_id = %d", $post_id ), ARRAY_A );
            foreach ( $rows as $row ) {
                $vector = json_decode( $row['embedding'], true );
                if ( is_array($vector) ) {
                    $meta = [
                        'knowledge_id' => $row['knowledge_id'],
                        'post_id'      => (int) $row['post_id'],
                        'post_type'    => $row['post_type'],
                        'chunk_text'   => $row['chunk_text'],
                        'mode'         => $row['mode'],
                        'topic_slug'   => $row['topic_slug'],
                        'country'      => $row['country'],
                        'source_title' => $row['source_title'],
                        'source_url'   => $row['source_url'],
                        'image_urls'   => ! empty( $row['image_urls'] ) ? json_decode( $row['image_urls'], true ) : [],
                    ];
                    $redis->cache_embedding( $row['knowledge_id'] . ':' . $row['chunk_index'], $vector, $meta );
                }
            }
        }

        wp_send_json_success( [ 'message' => __( 'Metadata updated successfully.', 'sonoai' ) ] );
    }

    /**
     * Embed and store one KB item.
     * Returns array with 'knowledge_id' and 'chunk_count' on success.
     */
    private function embed_and_store( array $args ): array|\WP_Error {
        global $wpdb;

        $type         = $args['type'];
        $plain_text   = sonoai_clean_content( $args['text'] ?? '' );
        $raw_content  = $args['raw_content'] ?? $plain_text;
        $source_url   = $args['source_url']  ?? '';
        $source_title = $args['source_title'] ?? '';
        $post_id      = $args['post_id']      ?? 0;
        $image_urls   = $args['image_urls']   ?? [];
        $mode         = in_array( $args['mode'] ?? 'guideline', [ 'guideline', 'research' ], true ) ? $args['mode'] : 'guideline';
        $topic_id     = ! empty( $args['topic_id'] ) ? $args['topic_id'] : null;
        $country      = $args['country']     ?? '';

        if ( empty( $plain_text ) ) {
            return new \WP_Error( 'empty_content', __( 'Content is empty.', 'sonoai' ) );
        }

        // Get topic slugs if topic_id is provided
        $topic_slug = '';
        if ( ! empty( $topic_id ) ) {
            $ids = array_filter( array_map( 'intval', explode( ',', $topic_id ) ) );
            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $topics = $wpdb->get_results( $wpdb->prepare( "SELECT slug FROM `{$wpdb->prefix}sonoai_kb_topics` WHERE id IN ($placeholders)", $ids ) );
                $slugs = [];
                foreach ( $topics as $t ) {
                    $slugs[] = $t->slug;
                }
                $topic_slug = implode( ',', $slugs );
            }
        }

        // Use the centralized Embedding class for actual vector storage.
        $knowledge_id = Embedding::insert( (int) $post_id, $type, $plain_text, $image_urls, $mode, $topic_slug, $country, $source_title, $source_url );
        
        if ( is_wp_error( $knowledge_id ) ) {
            sonoai_log_error( sprintf( '[SonoAI KB] Embedding::insert failed for type %s, post_id %d: %s', $type, $post_id, $knowledge_id->get_error_message() ) );
            return $knowledge_id;
        }

        $provider        = sonoai_option( 'active_provider', 'openai' );
        $embedding_model = sonoai_option( $provider . '_embedding_model', 'text-embedding-3-small' );
        $chunks          = $this->chunk( $plain_text );
        $image_json      = ! empty( $image_urls ) ? wp_json_encode( $image_urls ) : null;

        $wpdb->insert(
            $this->kb_table(),
            [
                'knowledge_id'    => $knowledge_id,
                'type'            => $type,
                'mode'            => $mode,
                'topic_id'        => $topic_id,
                'country'         => $country,
                'post_id'         => (int) $post_id,
                'source_title'    => $source_title,
                'source_url'      => $source_url,
                'raw_content'     => $raw_content,
                'image_urls'      => $image_json,
                'provider'        => $provider,
                'embedding_model' => $embedding_model,
                'chunk_count'     => count( $chunks ),
            ],
            [ '%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%d' ]
        );

        return [
            'knowledge_id' => $knowledge_id,
            'chunk_count'  => count( $chunks ),
        ];
    }

    // ── Handler: Get WP Posts list ────────────────────────────────────────────

    public function handle_get_posts(): void {
        $this->check( 'sonoai_kb_get_posts' );
        global $wpdb;

        $post_type = SecurityHelper::get_param( 'post_type', 'post' );
        $page      = max( 1, SecurityHelper::get_param( 'page', 1, 'int' ) );
        $filter    = SecurityHelper::get_param( 'kb_status', 'all' ); // all, added, not_added, update
        $per_page  = 20;
        $search    = SecurityHelper::get_param( 'search' );
        $mode      = SecurityHelper::get_param( 'mode' );
        $topic_id  = SecurityHelper::get_param( 'topic_id', 0, 'int' );
        $country   = SecurityHelper::get_param( 'country' );
        $offset    = ( $page - 1 ) * $per_page;

        $posts_tbl  = $wpdb->posts;
        $kb_tbl     = $this->kb_table();
        $topics_tbl = $wpdb->prefix . 'sonoai_kb_topics';

        $where = $wpdb->prepare( "p.post_type = %s AND p.post_status = 'publish'", $post_type );
        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND p.post_title LIKE %s', $like );
        }
        
        // We join to filter by mode, topic, or country if requested
        $join = "LEFT JOIN $kb_tbl kb ON kb.post_id = p.ID AND kb.type = 'wp'";

        if ( $mode ) {
            $where .= $wpdb->prepare( " AND kb.mode = %s", $mode );
        }
        if ( $topic_id ) {
            $where .= $wpdb->prepare( " AND FIND_IN_SET(%d, kb.topic_id) > 0", $topic_id );
        }
        if ( $country ) {
            $where .= $wpdb->prepare( " AND kb.country LIKE %s", '%' . $wpdb->esc_like( $country ) . '%' );
        }

        // Note: Table names are trusted (derived from $wpdb prefix)
        $query = "
            SELECT 
                p.ID as id, 
                p.post_title as title, 
                p.post_modified_gmt as last_modified,
                kb.id as kb_item_id,
                (SELECT COUNT(*) FROM `$kb_tbl` AS sub WHERE sub.id <= kb.id) as sequence_no,
                kb.created_at as kb_added,
                kb.knowledge_id as knowledge_id,
                kb.provider as provider,
                kb.embedding_model as model,
                kb.mode as mode,
                kb.topic_id as topic_id,
                kb.country as country
            FROM $posts_tbl p
            $join
            WHERE $where
            ORDER BY p.post_date DESC
        ";

        $all_posts = $wpdb->get_results( $query );

        $counts = [ 'all' => 0, 'added' => 0, 'not_added' => 0, 'update' => 0 ];
        $filtered  = [];

        // Fetch topics mapping for multi-select resolution
        $topics_raw = $wpdb->get_results( "SELECT id, name FROM `$topics_tbl`", ARRAY_A );
        $topic_map  = [];
        if ( ! empty( $topics_raw ) ) {
            foreach ( $topics_raw as $t_row ) {
                $topic_map[ $t_row['id'] ] = $t_row['name'];
            }
        }

        if ( ! empty( $all_posts ) ) {
            foreach ( $all_posts as $p ) {
                $p_status = 'not_added';
                if ( $p->kb_added ) {
                    $p_status = ( $p->last_modified > $p->kb_added ) ? 'update' : 'added';
                }

                $counts['all']++;
                $counts[ $p_status ]++;

                if ( $filter === 'all' || $filter === $p_status ) {
                    $topic_names = [];
                    if ( ! empty( $p->topic_id ) ) {
                        $t_ids = array_filter( array_map( 'intval', explode( ',', $p->topic_id ) ) );
                        foreach ( $t_ids as $tid ) {
                            if ( isset( $topic_map[ $tid ] ) ) {
                                $topic_names[] = $topic_map[ $tid ];
                            }
                        }
                    }
                    $topic_name = ! empty( $topic_names ) ? implode( ', ', $topic_names ) : '—';

                    $filtered[] = [
                        'id'            => (int) $p->id,
                        'title'         => $p->title,
                        'last_modified' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $p->last_modified . ' UTC' ) ),
                        'kb_added'      => $p->kb_added ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $p->kb_added . ' UTC' ) ) : '—',
                        'kb_status'     => $p_status,
                        'knowledge_id'  => $p->knowledge_id ? $p->knowledge_id : '',
                        'sequence_no'   => $p->sequence_no ? (int) $p->sequence_no : 0,
                        'kb_item_id'    => $p->kb_item_id ? (int) $p->kb_item_id : 0,
                        'mode'          => $p->mode ? ucfirst( $p->mode ) : '—',
                        'topic_name'    => $topic_name,
                        'topic_id'      => $p->topic_id ? $p->topic_id : '',
                        'country'       => $p->country ? $p->country : '—',
                        'raw_mode'      => $p->mode ? $p->mode : '',
                        'ai_model'      => $p->model ? ( ucfirst( $p->provider ) . ' / ' . $p->model ) : '—',
                        'edit_url'      => get_edit_post_link( $p->id, 'raw' ),
                    ];
                }
            }
        }

        $total       = count( $filtered );
        $paged_posts = array_slice( $filtered, $offset, $per_page );

        wp_send_json_success( [
            'posts'       => $paged_posts,
            'counts'      => $counts,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ] );
    }

    // ── Handler: Add WP Post ──────────────────────────────────────────────────

    public function handle_add_post(): void {
        $this->check( 'sonoai_kb_add_post' );
        $post_id = SecurityHelper::get_param( 'post_id', 0, 'int' );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'sonoai' ) ] );
        }

        // Clear old indexing (upsert)
        global $wpdb;
        $table_kb  = $this->kb_table();
        $table_emb = $this->emb_table();

        // Get old knowledge IDs to delete from Redis
        $old_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT knowledge_id FROM `$table_emb` WHERE type = 'wp' AND post_id = %d",
            $post_id
        ) );
        if ( ! empty( $old_ids ) && class_exists('SonoAI\RedisManager') ) {
            foreach ( $old_ids as $id ) {
                \SonoAI\RedisManager::instance()->delete_vectors_by_id( $id );
            }
        }

        $wpdb->delete( $table_kb, [ 'type' => 'wp', 'post_id' => $post_id ], [ '%s', '%d' ] );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `$table_emb` WHERE type = 'wp' AND post_id = %d",
            $post_id
        ) );

        $result = $this->embed_and_store( [
            'type'         => 'wp',
            'text'         => $post->post_content,
            'source_url'   => get_permalink( $post->ID ),
            'source_title' => $post->post_title,
            'post_id'      => $post->ID,
            'mode'         => SecurityHelper::get_param( 'mode', 'guideline' ),
            'topic_id'     => $this->get_request_topic_ids(),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message'      => __( 'Post added to knowledge base.', 'sonoai' ),
            'knowledge_id' => $result['knowledge_id'],
            'chunk_count'  => $result['chunk_count'],
        ] );
    }

    // ── Handler: Remove WP Post ───────────────────────────────────────────────

    public function handle_remove_post(): void {
        $this->check( 'sonoai_kb_remove_post' );
        global $wpdb;
        $post_id = SecurityHelper::get_param( 'post_id', 0, 'int' );

        $kb_item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$this->kb_table()}` WHERE type = 'wp' AND post_id = %d",
            $post_id
        ) );

        if ( $kb_item ) {
            if ( class_exists('SonoAI\RedisManager') ) {
                \SonoAI\RedisManager::instance()->delete_vectors_by_id( $kb_item->knowledge_id );
            }
            $wpdb->delete( $this->kb_table(), [ 'knowledge_id' => $kb_item->knowledge_id ], [ '%s' ] );
        }
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$this->emb_table()}` WHERE type = 'wp' AND post_id = %d",
            $post_id
        ) );

        wp_send_json_success( [ 'message' => __( 'Post removed from knowledge base.', 'sonoai' ) ] );
    }

    // ── Handler: Add PDF ──────────────────────────────────────────────────────

    public function handle_add_pdf(): void {
        $this->check( 'sonoai_kb_add_pdf' );

        $file = $_FILES['pdf_file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => __( 'Please upload a valid PDF file.', 'sonoai' ) ] );
        }
        if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'pdf' ) {
            wp_send_json_error( [ 'message' => __( 'Only PDF files are accepted.', 'sonoai' ) ] );
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $moved = wp_handle_upload( $file, [ 'test_form' => false, 'mimes' => [ 'pdf' => 'application/pdf' ] ] );
        if ( isset( $moved['error'] ) ) {
            wp_send_json_error( [ 'message' => $moved['error'] ] );
        }

        // Extract text via smalot/pdfparser.
        try {
            $parser  = new \Smalot\PdfParser\Parser();
            $pdf     = $parser->parseFile( $moved['file'] );
            $content = $pdf->getText();
        } catch ( \Exception $e ) {
            @unlink( $moved['file'] );
            wp_send_json_error( [ 'message' => __( 'Could not parse PDF: ', 'sonoai' ) . $e->getMessage() ] );
        }

        if ( empty( trim( $content ) ) ) {
            @unlink( $moved['file'] );
            wp_send_json_error( [ 'message' => __( 'PDF appears to be empty or contains only images. Please use a text-based PDF.', 'sonoai' ) ] );
        }

        $result = $this->embed_and_store( [
            'type'         => 'pdf',
            'text'         => $content,
            'source_url'   => SecurityHelper::get_param( 'source_url', $moved['url'], 'url' ),
            'source_title' => SecurityHelper::get_param( 'source_name', $file['name'] ),
            'country'      => SecurityHelper::get_param( 'country' ),
            'mode'         => SecurityHelper::get_param( 'mode', 'guideline' ),
            'topic_id'     => $this->get_request_topic_ids(),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message'      => __( 'PDF added to knowledge base.', 'sonoai' ),
            'knowledge_id' => $result['knowledge_id'],
            'chunk_count'  => $result['chunk_count'],
            'file_name'    => $file['name'],
            'file_url'     => $moved['url'],
        ] );
    }

    // ── Handler: Add URL ──────────────────────────────────────────────────────

    public function handle_add_url(): void {
        $this->check( 'sonoai_kb_add_url' );
        $url = SecurityHelper::get_param( 'url', '', 'url' );
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => __( 'Please provide a valid URL.', 'sonoai' ) ] );
        }

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }
        $html    = wp_remote_retrieve_body( $response );
        $content = \Soundasleep\Html2Text::convert( $html, [ 'ignore_errors' => true ] );

        if ( empty( trim( $content ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Could not extract text from the URL.', 'sonoai' ) ] );
        }

        $result = $this->embed_and_store( [
            'type'         => 'url',
            'text'         => $content,
            'source_url'   => SecurityHelper::get_param( 'source_url', $url, 'url' ),
            'source_title' => SecurityHelper::get_param( 'source_name', $url ),
            'country'      => SecurityHelper::get_param( 'country' ),
            'mode'         => SecurityHelper::get_param( 'mode', 'guideline' ),
            'topic_id'     => $this->get_request_topic_ids(),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message'      => __( 'URL added to knowledge base.', 'sonoai' ),
            'knowledge_id' => $result['knowledge_id'],
            'chunk_count'  => $result['chunk_count'],
            'url'          => $url,
        ] );
    }

    // ── Handler: Add Custom Text ──────────────────────────────────────────────

    public function handle_add_txt(): void {
        $this->check( 'sonoai_kb_add_txt' );
        $raw_html  = SecurityHelper::get_param( 'content', '', 'html' );
        if ( empty( trim( wp_strip_all_tags( $raw_html ) ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Content cannot be empty.', 'sonoai' ) ] );
        }

        $images_param = SecurityHelper::get_param( 'images', '', 'raw' );
        $manual_images = ! empty( $images_param ) ? json_decode( $images_param, true ) : [];
        if ( ! is_array( $manual_images ) ) $manual_images = [];

        $image_urls = $this->extract_image_urls( $raw_html, $manual_images );
        $plain      = sonoai_clean_content( $raw_html );
        $title      = wp_trim_words( $plain, 10, '…' );

        $result = $this->embed_and_store( [
            'type'         => 'txt',
            'text'         => $plain,
            'raw_content'  => $raw_html,
            'source_title' => SecurityHelper::get_param( 'source_name', $title ),
            'source_url'   => SecurityHelper::get_param( 'source_url', '', 'url' ),
            'country'      => SecurityHelper::get_param( 'country' ),
            'image_urls'   => $image_urls,
            'mode'         => SecurityHelper::get_param( 'mode', 'guideline' ),
            'topic_id'     => $this->get_request_topic_ids(),
        ] );

        if ( is_wp_error( $result ) ) {
            sonoai_log_error( sprintf( '[SonoAI KB] Add Txt failed for user %d: %s', get_current_user_id(), $result->get_error_message() ) );
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message'      => __( 'Custom text added to knowledge base.', 'sonoai' ),
            'knowledge_id' => $result['knowledge_id'],
            'chunk_count'  => $result['chunk_count'],
            'preview'      => wp_trim_words( $plain, 20, '…' ),
        ] );
    }

    // ── Handler: Edit Custom Text ─────────────────────────────────────────────

    public function handle_edit_txt(): void {
        $this->check( 'sonoai_kb_edit_txt' );
        global $wpdb;
        $knowledge_id = SecurityHelper::get_param( 'knowledge_id' );
        $raw_html     = SecurityHelper::get_param( 'content', '', 'html' );

        if ( empty( $knowledge_id ) || empty( trim( wp_strip_all_tags( $raw_html ) ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'sonoai' ) ] );
        }

        $table_emb = $this->emb_table();
        $table_kb  = $this->kb_table();

        // Delete old embeddings for this knowledge_id from Redis and MySQL.
        if ( class_exists('SonoAI\RedisManager') ) {
            \SonoAI\RedisManager::instance()->delete_vectors_by_id( $knowledge_id );
        }
        $wpdb->delete( $table_emb, [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );
        $wpdb->delete( $table_kb,  [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );

        $images_param = SecurityHelper::get_param( 'images', '', 'raw' );
        $manual_images = ! empty( $images_param ) ? json_decode( $images_param, true ) : [];
        if ( ! is_array( $manual_images ) ) $manual_images = [];

        $image_urls = $this->extract_image_urls( $raw_html, $manual_images );
        $plain      = sonoai_clean_content( $raw_html );
        $title      = wp_trim_words( $plain, 10, '…' );

        $result = $this->embed_and_store( [
            'type'         => 'txt',
            'text'         => $plain,
            'raw_content'  => $raw_html,
            'source_title' => SecurityHelper::get_param( 'source_name', $title ),
            'source_url'   => SecurityHelper::get_param( 'source_url', '', 'url' ),
            'country'      => SecurityHelper::get_param( 'country' ),
            'image_urls'   => $image_urls,
            'mode'         => SecurityHelper::get_param( 'mode', 'guideline' ),
            'topic_id'     => $this->get_request_topic_ids(),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message'         => __( 'Custom text updated.', 'sonoai' ),
            'new_knowledge_id' => $result['knowledge_id'],
        ] );
    }

    // ── Handler: Delete Item ──────────────────────────────────────────────────

    public function handle_delete_item(): void {
        $this->check( 'sonoai_kb_delete_item' );
        global $wpdb;
        $knowledge_id = SecurityHelper::get_param( 'knowledge_id' );
        if ( empty( $knowledge_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing knowledge ID.', 'sonoai' ) ] );
        }

        $table_kb  = $this->kb_table();
        $table_emb = $this->emb_table();

        // Delete from MySQL
        $wpdb->delete( $table_kb,  [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );
        $wpdb->delete( $table_emb, [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );

        // Delete from Redis
        RedisManager::instance()->delete_vectors_by_id( $knowledge_id );

        wp_send_json_success( [ 'message' => __( 'Item deleted from knowledge base.', 'sonoai' ) ] );
    }

    // ── Topic Handlers ────────────────────────────────────────────────────────

    public function handle_add_topic(): void {
        $this->check( 'sonoai_kb_manage_topics' );
        global $wpdb;

        $name = SecurityHelper::get_param( 'name' );
        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Topic name is required.', 'sonoai' ) ] );
        }

        $slug = sanitize_title( $name );
        $topics_table = $wpdb->prefix . 'sonoai_kb_topics';

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$topics_table` WHERE slug = %s", $slug ) );
        if ( $existing ) {
            wp_send_json_error( [ 'message' => __( 'A topic with this name already exists.', 'sonoai' ) ] );
        }

        $result = $wpdb->insert(
            $topics_table,
            [
                'name' => $name,
                'slug' => $slug,
            ],
            [ '%s', '%s' ]
        );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Database error. Could not create topic.', 'sonoai' ) ] );
        }

        wp_send_json_success( [
            'message' => __( 'Topic created.', 'sonoai' ),
            'topic'   => [
                'id'   => $wpdb->insert_id,
                'name' => $name,
                'slug' => $slug,
            ]
        ] );
    }

    public function handle_edit_topic(): void {
        $this->check( 'sonoai_kb_manage_topics' );
        global $wpdb;

        $id   = SecurityHelper::get_param( 'topic_id', 0, 'int' );
        $name = SecurityHelper::get_param( 'name' );
        if ( empty( $name ) || empty( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Topic ID and name are required.', 'sonoai' ) ] );
        }

        $slug = sanitize_title( $name );
        $topics_table = $wpdb->prefix . 'sonoai_kb_topics';

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$topics_table` WHERE slug = %s AND id != %d", $slug, $id ) );
        if ( $existing ) {
            wp_send_json_error( [ 'message' => __( 'A topic with this name already exists.', 'sonoai' ) ] );
        }

        // We also need to cascade update topic_slug in sonoai_embeddings if we change slug
        $old_topic = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM `$topics_table` WHERE id = %d", $id ) );
        if ( $old_topic && $old_topic->slug !== $slug ) {
            $table_emb = $this->emb_table();
            $wpdb->update( $table_emb, [ 'topic_slug' => $slug ], [ 'topic_slug' => $old_topic->slug ], [ '%s' ], [ '%s' ] );
            $wpdb->update( $wpdb->prefix . 'sonoai_saved_responses', [ 'topic_slug' => $slug ], [ 'topic_slug' => $old_topic->slug ], [ '%s' ], [ '%s' ] );
        }

        $wpdb->update(
            $topics_table,
            [ 'name' => $name, 'slug' => $slug ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [
            'message' => __( 'Topic updated.', 'sonoai' ),
            'topic'   => [ 'id' => $id, 'name' => $name, 'slug' => $slug ]
        ] );
    }

    public function handle_delete_topic(): void {
        $this->check( 'sonoai_kb_manage_topics' );
        global $wpdb;

        $id = SecurityHelper::get_param( 'topic_id', 0, 'int' );
        if ( empty( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Topic ID is required.', 'sonoai' ) ] );
        }

        $topics_table = $wpdb->prefix . 'sonoai_kb_topics';
        $old_topic    = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM `$topics_table` WHERE id = %d", $id ) );

        $wpdb->delete( $topics_table, [ 'id' => $id ], [ '%d' ] );

        // Nullify the topic_id in kb_items
        $table_kb = $this->kb_table();
        $wpdb->update( $table_kb, [ 'topic_id' => null ], [ 'topic_id' => $id ] );
        
        // Nullify topic_slug in embeddings and saved responses
        if ( $old_topic ) {
            $table_emb = $this->emb_table();
            $wpdb->update( $table_emb, [ 'topic_slug' => null ], [ 'topic_slug' => $old_topic->slug ] );
            $wpdb->update( $wpdb->prefix . 'sonoai_saved_responses', [ 'topic_slug' => null ], [ 'topic_slug' => $old_topic->slug ] );
        }

        wp_send_json_success( [ 'message' => __( 'Topic deleted.', 'sonoai' ) ] );
    }

    public function handle_sync_topics(): void {
        $this->check( 'sonoai_kb_manage_topics' );
        
        $taxonomies = [ 'category', 'post_tag' ];
        // Add eazydocs taxonomies if they exist
        if ( taxonomy_exists( 'docs_category' ) ) {
            $taxonomies[] = 'docs_category';
        }

        $count = 0;
        foreach ( $taxonomies as $tax ) {
            $terms = get_terms( [
                'taxonomy'   => $tax,
                'hide_empty' => false,
            ] );

            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( $term->slug === 'uncategorized' ) {
                        continue;
                    }
                    $topic_id = Topics::get_or_create( $term->name, $term->term_id );
                    if ( $topic_id ) {
                        $count++;
                    }
                }
            }
        }

        wp_send_json_success( [
            'message' => sprintf( __( 'Synced %d topics from WordPress categories and tags.', 'sonoai' ), $count ),
        ] );
    }

    /**
     * Handle custom clinical image upload
     */
    public function handle_upload_img(): void {
        $this->check( 'sonoai_kb_upload_img' );

        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'sonoai' ) ] );
        }

        $label = sanitize_title( SecurityHelper::get_param( 'label', 'clinical-image' ) );
        
        // Filter to change the upload directory
        $upload_dir_error = null;
        $upload_dir_filter = function( $dirs ) use ( &$upload_dir_error ) {
            $custom_dir = 'sonoai-Clinical-img-lib/' . date( 'Y' ) . '/' . date( 'm' );
            $target_path = $dirs['basedir'] . '/' . $custom_dir;
            
            if ( ! file_exists( $target_path ) ) {
                if ( ! wp_mkdir_p( $target_path ) ) {
                    $upload_dir_error = sprintf( __( 'Could not create directory: %s. Please check permissions.', 'sonoai' ), $custom_dir );
                    return $dirs;
                }
            }

            if ( ! is_writable( $target_path ) ) {
                $upload_dir_error = sprintf( __( 'Directory not writable: %s', 'sonoai' ), $custom_dir );
            }

            $dirs['path']    = $target_path;
            $dirs['url']     = $dirs['baseurl'] . '/' . $custom_dir;
            $dirs['subdir']  = '/' . $custom_dir;

            return $dirs;
        };

        // Filter to rename the file based on the clinical label
        $rename_filter = function( $file ) use ( $label ) {
            $ext  = pathinfo( $file['name'], PATHINFO_EXTENSION );
            $file['name'] = ( $label ?: 'clinical-image' ) . '.' . $ext;
            return $file;
        };

        add_filter( 'upload_dir', $upload_dir_filter );
        add_filter( 'wp_handle_upload_prefilter', $rename_filter );

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Check for filter-level errors before proceeding
        if ( $upload_dir_error ) {
            remove_filter( 'upload_dir', $upload_dir_filter );
            remove_filter( 'wp_handle_upload_prefilter', $rename_filter );
            sonoai_log_error( 'Image Upload Dir Error: ' . $upload_dir_error );
            wp_send_json_error( [ 'message' => $upload_dir_error ] );
        }

        $upload = wp_handle_upload( $_FILES['file'], [ 'test_form' => false ] );

        remove_filter( 'upload_dir', $upload_dir_filter );
        remove_filter( 'wp_handle_upload_prefilter', $rename_filter );

        if ( isset( $upload['error'] ) ) {
            sonoai_log_error( '[SonoAI KB] Image upload failed: ' . $upload['error'] );
            wp_send_json_error( [ 'message' => $upload['error'] ] );
        }

        sonoai_log_error( sprintf( '[SonoAI KB] Image uploaded successfully. URL: %s, File: %s', $upload['url'], $upload['file'] ) );

        wp_send_json_success( [
            'url'  => $upload['url'],
            'file' => $upload['file']
        ] );
    }

    /**
     * Delete a clinical image file from the server
     */
    public function handle_delete_img_file(): void {
        $this->check( 'sonoai_kb_delete_item' );

        $file_path = SecurityHelper::get_param( 'file_path' );
        if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
            wp_send_json_error( [ 'message' => __( 'File not found.', 'sonoai' ) ] );
        }

        // Security: Ensure the file is within our custom clinical library and inside the uploads directory
        $upload_dir = wp_get_upload_dir();
        $real_path   = realpath( $file_path );
        $base_dir    = realpath( $upload_dir['basedir'] );

        if ( false === $real_path || strpos( $real_path, $base_dir ) !== 0 || strpos( $real_path, 'sonoai-Clinical-img-lib' ) === false ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized file deletion.', 'sonoai' ) ] );
        }

        if ( wp_delete_file( $real_path ) ) {
            wp_send_json_success( [ 'message' => __( 'File deleted.', 'sonoai' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Could not delete file.', 'sonoai' ) ] );
        }
    }

    /**
     * Rebuild the Redis VSS index from MySQL (Manual Sync).
     */
    public function handle_sync_redis(): void {
        $this->check( 'sonoai_kb_sync_redis' );

        if ( ! class_exists( 'SonoAI\RedisMigration' ) ) {
            require_once SONOAI_DIR . 'includes/RedisMigration.php';
        }

        if ( ! isset( $_REQUEST['offset'] ) ) {
            // Fallback for cached JS that doesn't send offset
            $result = RedisMigration::rebuild_index();
            if ( $result['success'] ) {
                wp_send_json_success( [
                    'message' => sprintf( 
                        __( 'Redis index rebuilt successfully! Indexed %d chunks with %d errors.', 'sonoai' ), 
                        $result['indexed'], 
                        $result['errors'] 
                    )
                ] );
            } else {
                $msg = $result['message'] ?? __( 'Unknown error during Redis migration.', 'sonoai' );
                sonoai_log_error( 'Manual Redis Sync Failed: ' . $msg );
                wp_send_json_error( [ 'message' => $msg ] );
            }
            return;
        }

        $offset     = SecurityHelper::get_param( 'offset', 0, 'int' );
        $is_first   = SecurityHelper::get_param( 'is_first', 'false', 'text' ) === 'true';
        $batch_size = 50;

        $result = RedisMigration::sync_batch($offset, $batch_size, $is_first);

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            $msg = $result['message'] ?? __( 'Unknown error during Redis migration.', 'sonoai' );
            sonoai_log_error( 'Manual Redis Sync Failed: ' . $msg );
            wp_send_json_error( [ 'message' => $msg ] );
        }
    }

    /**
     * Re-index all Knowledge Base items with the currently configured embedding model.
     * Supports batch processing with offset and batch_size to prevent timeouts.
     *
     * Safe pattern: embed FIRST → verify success → THEN delete old data.
     *
     * @return void
     */
    public function handle_reindex_all(): void {
        try {
            $this->check( 'sonoai_kb_reindex_all' );

            if ( ! AIProvider::has_api_key() ) {
                wp_send_json_error( [ 'message' => __( 'AI API key is not configured.', 'sonoai' ) ] );
            }

            // Attempt to increase time limit if allowed by the host
            @set_time_limit( 120 );

            $start_time = microtime( true );
            $max_time   = (int) ini_get( 'max_execution_time' );
            if ( $max_time <= 0 ) {
                $max_time = 30; // Default fallback
            }
            // Safety limit is 5 seconds less than max execution time
            $time_limit = $max_time - 5;
            if ( $time_limit < 10 ) {
                $time_limit = 25; // Guarantee at least a reasonable window
            }
            // Enforce a strict ceiling of 40 seconds to prevent Cloudflare 100s HTTP 524 timeouts
            $time_limit = min( $time_limit, 40 );

            global $wpdb;
            $kb_table  = $this->kb_table();
            $emb_table = $this->emb_table();

            $provider        = sonoai_option( 'active_provider', 'openai' );
            $embedding_model = AIProvider::get_embedding_model();

            $offset     = SecurityHelper::get_param( 'offset', 0, 'int' );
            $batch_size = SecurityHelper::get_param( 'batch_size', 5, 'int' );

            // Clean up orphaned embeddings in MySQL from past failed/interrupted runs
            if ( $offset === 0 ) {
                $wpdb->query( "
                    DELETE FROM `{$emb_table}` 
                    WHERE `knowledge_id` NOT IN (
                        SELECT DISTINCT `knowledge_id` FROM `{$kb_table}`
                    )
                " );
                sonoai_log_error( '[SonoAI Reindex] Purged orphaned embeddings from MySQL database.' );
            }

            // Count total items
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$kb_table}`" );

            if ( $total === 0 ) {
                wp_send_json_success( [
                    'message' => __( 'No items to re-index.', 'sonoai' ),
                    'updated' => 0,
                    'errors'  => 0,
                    'done'    => true,
                    'total'   => 0
                ] );
            }

            // Fetch batch
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$kb_table}` ORDER BY created_at ASC LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );

            $updated = 0;
            $errors  = 0;
            $current_item = '';
            $processed = 0;

            foreach ( $items as $item ) {
                // Break early if we've processed at least one item and are running low on execution time budget
                if ( $processed > 0 && ( microtime( true ) - $start_time ) > $time_limit ) {
                    sonoai_log_error( '[SonoAI Reindex] Batch execution time budget exceeded. Stopping early at offset ' . ( $offset + $processed ) . ' to prevent PHP timeout.' );
                    break;
                }

                $old_knowledge_id = $item['knowledge_id'];
                $post_id          = (int) $item['post_id'];
                $type             = $item['type'];
                $mode             = $item['mode']         ?? 'guideline';
                $country          = $item['country']      ?? '';
                $source_title     = $item['source_title'] ?? '';
                $source_url       = $item['source_url']   ?? '';
                $topic_id         = ! empty( $item['topic_id'] ) ? $item['topic_id'] : null;
                $image_urls       = ! empty( $item['image_urls'] ) ? json_decode( $item['image_urls'], true ) : [];
                $raw_content      = $item['raw_content']  ?? '';

                $current_item = $source_title ?: sprintf( '%s #%d', ucfirst( $type ), $post_id );

                // ── Resolve plain text ──────────────────────────────────────────
                $plain_text = ! empty( $raw_content ) ? sonoai_clean_content( $raw_content ) : '';
                if ( empty( trim( $plain_text ) ) && $post_id > 0 ) {
                    $wp_post = get_post( $post_id );
                    if ( $wp_post ) {
                        $plain_text = sonoai_clean_content( $wp_post->post_content );
                    }
                }

                if ( empty( trim( $plain_text ) ) ) {
                    sonoai_log_error( "[SonoAI] Re-index skipped (no content) for knowledge_id: {$old_knowledge_id}" );
                    $errors++;
                    $processed++;
                    continue;
                }

                // ── Get topic slug ──────────────────────────────────────────────
                $topic_slug = '';
                if ( ! empty( $topic_id ) ) {
                    $ids = array_filter( array_map( 'intval', explode( ',', $topic_id ) ) );
                    if ( ! empty( $ids ) ) {
                        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                        $topics = $wpdb->get_results( $wpdb->prepare( "SELECT slug FROM `{$wpdb->prefix}sonoai_kb_topics` WHERE id IN ($placeholders)", $ids ) );
                        $slugs = [];
                        foreach ( $topics as $t ) {
                            $slugs[] = $t->slug;
                        }
                        $topic_slug = implode( ',', $slugs );
                    }
                }

                // ── SAFE PATTERN: Embed FIRST, delete old data only on success ──
                $new_knowledge_id = Embedding::insert(
                    $post_id, $type, $plain_text,
                    is_array( $image_urls ) ? $image_urls : [],
                    $mode, $topic_slug, $country, $source_title, $source_url
                );

                if ( is_wp_error( $new_knowledge_id ) ) {
                    sonoai_log_error( '[SonoAI] Re-index failed for knowledge_id ' . $old_knowledge_id . ': ' . $new_knowledge_id->get_error_message() );
                    $errors++;
                    $processed++;
                    continue;
                }

                // Step 2: New embedding succeeded — NOW safely remove old data
                if ( $old_knowledge_id !== $new_knowledge_id ) {
                    $del_res = $wpdb->delete( $emb_table, [ 'knowledge_id' => $old_knowledge_id ], [ '%s' ] );
                    if ( false === $del_res ) {
                        sonoai_log_error( '[SonoAI] Re-index DB delete failed for old_knowledge_id ' . $old_knowledge_id . ': ' . $wpdb->last_error );
                    }
                    RedisManager::instance()->delete_vectors_by_id( $old_knowledge_id );
                }

                // Step 3: Update the kb_items row with the new model metadata
                $up_res = $wpdb->update(
                    $kb_table,
                    [
                        'knowledge_id'    => $new_knowledge_id,
                        'provider'        => $provider,
                        'embedding_model' => $embedding_model,
                    ],
                    [ 'knowledge_id' => $old_knowledge_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%s' ]
                );
                if ( false === $up_res ) {
                    sonoai_log_error( '[SonoAI] Re-index DB update failed for knowledge_id ' . $old_knowledge_id . ' to ' . $new_knowledge_id . ': ' . $wpdb->last_error );
                }

                $updated++;
                $processed++;
            }

            $next_offset = $offset + $processed;
            $done = $next_offset >= $total;

            wp_send_json_success( [
                'updated'      => $updated,
                'errors'       => $errors,
                'next_offset'  => $next_offset,
                'total'        => $total,
                'done'         => $done,
                'current_item' => $current_item,
                'message'      => $done ? sprintf(
                    __( 'Re-index complete! %d items updated to %s / %s.', 'sonoai' ),
                    $next_offset, $provider, $embedding_model
                ) : ''
            ] );
        } catch ( \Throwable $t ) {
            sonoai_log_error( '[SonoAI Reindex All] Fatal Error during batch re-indexing: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine() );
            wp_send_json_error( [ 'message' => $t->getMessage() ] );
        }
    }

    /**
     * Handle JSONL dataset import.
     */
    public function handle_add_jsonl() {
        $this->check( 'sonoai_kb_add_jsonl' );

        if ( empty( $_FILES['jsonl_file']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'sonoai' ) ] );
        }

        $file     = $_FILES['jsonl_file']['tmp_name'];
        $filename = sanitize_text_field( $_FILES['jsonl_file']['name'] );
        $mode     = SecurityHelper::get_param( 'mode', 'guideline' );
        $topic_id = SecurityHelper::get_param( 'topic_id', 0, 'int' );

        $handle = fopen( $file, 'r' );
        if ( ! $handle ) {
            wp_send_json_error( [ 'message' => __( 'Failed to read file.', 'sonoai' ) ] );
        }

        global $wpdb;
        $kb_table     = $this->kb_table();
        $knowledge_id = wp_generate_uuid4();
        
        $inserted_chunks = 0;
        $line_number     = 0;

        // Process line by line
        while ( ( $line = fgets( $handle ) ) !== false ) {
            $line_number++;
            $data = json_decode( trim( $line ), true );
            if ( ! is_array( $data ) || empty( $data['content'] ) ) {
                continue;
            }

            // Per-line overrides
            $line_content = $data['content'];
            $line_mode    = $data['mode'] ?? $mode;
            $line_source  = $data['source_name'] ?? $filename;
            $line_url     = $data['source_url'] ?? '';
            $line_images  = ! empty( $data['images'] ) ? $this->extract_image_urls( '', $data['images'] ) : [];
            
            // Generate embedding for this atomic fact
            $embedding = AIProvider::generate_embedding( $line_content );
            if ( is_wp_error( $embedding ) ) {
                continue; // Skip failed embeddings
            }

            // Save individual chunk to embeddings table
            $wpdb->insert(
                $this->emb_table(),
                [
                    'knowledge_id'    => $knowledge_id,
                    'type'            => 'jsonl',
                    'source_title'    => $line_source,
                    'source_url'      => $line_url,
                    'mode'            => $line_mode,
                    'image_urls'      => ! empty( $line_images ) ? wp_json_encode( $line_images ) : null,
                    'chunk_index'     => $inserted_chunks,
                    'chunk_text'      => $line_content,
                    'embedding'       => wp_json_encode( $embedding ),
                    'provider'        => AIProvider::get_name(),
                    'embedding_model' => AIProvider::get_embedding_model(),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
            );

            // Also sync to Redis immediately if active
            $redis = RedisManager::instance();
            if ( $redis->is_active() ) {
                $topic_row = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}sonoai_kb_topics WHERE id = %d", $topic_id ) );
                $topic_slug = $topic_row ? $topic_row->slug : null;

                $redis->add_embedding(
                    $knowledge_id,
                    $line_content,
                    $embedding,
                    [
                        'type'         => 'jsonl',
                        'source_title' => $line_source,
                        'source_url'   => $line_url,
                        'image_urls'   => ! empty( $line_images ) ? wp_json_encode( $line_images ) : '',
                        'mode'         => $line_mode,
                        'topic_slug'   => $topic_slug ?: '',
                    ]
                );
            }

            $inserted_chunks++;
        }
        fclose( $handle );

        if ( $inserted_chunks === 0 ) {
            wp_send_json_error( [ 'message' => __( 'No valid JSON lines found in file.', 'sonoai' ) ] );
        }

        // Read full file for raw_content (necessary for re-indexing)
        $full_content = file_get_contents( $file );

        // Create the parent KB item record
        $wpdb->insert(
            $kb_table,
            [
                'knowledge_id'    => $knowledge_id,
                'type'            => 'jsonl',
                'source_title'    => $filename,
                'raw_content'     => $full_content,
                'mode'            => $mode,
                'topic_id'        => $topic_id ?: null,
                'chunk_count'     => $inserted_chunks,
                'provider'        => AIProvider::get_name(),
                'embedding_model' => AIProvider::get_embedding_model(),
            ]
        );

        wp_send_json_success( [
            'message' => sprintf( __( 'Successfully imported %d clinical facts from %s', 'sonoai' ), $inserted_chunks, $filename )
        ] );
    }

    /**
     * Re-index a selected list of KB items (single or bulk).
     */
    public function handle_reindex_items(): void {
        $this->check( 'sonoai_kb_reindex_items' );

        try {
            $start_time = microtime( true );

            // Execution time limit calculations (Layer 1 timeout protection)
            $time_limit = ini_get( 'max_execution_time' );
            $time_limit = $time_limit ? (int) $time_limit - 5 : 25;
            $time_limit = min( $time_limit, 40 ); // Strictly cap at 40s to prevent Cloudflare timeout

            global $wpdb;
            $kb_table  = $this->kb_table();
            $emb_table = $this->emb_table();

            $provider        = sonoai_option( 'active_provider', 'openai' );
            $embedding_model = AIProvider::get_embedding_model();

            $ids_param  = SecurityHelper::get_param( 'ids', [] );
            if ( is_string( $ids_param ) ) {
                $ids = array_filter( array_map( 'trim', explode( ',', $ids_param ) ) );
            } else {
                $ids = array_filter( array_map( 'sanitize_text_field', (array) $ids_param ) );
            }

            if ( empty( $ids ) ) {
                wp_send_json_error( [ 'message' => __( 'No items selected.', 'sonoai' ) ] );
            }

            $offset     = SecurityHelper::get_param( 'offset', 0, 'int' );
            $batch_size = SecurityHelper::get_param( 'batch_size', 5, 'int' );
            $total      = count( $ids );

            // Purge MySQL orphaned embeddings at start
            if ( $offset === 0 ) {
                $wpdb->query( "
                    DELETE FROM `{$emb_table}` 
                    WHERE `knowledge_id` NOT IN (
                        SELECT DISTINCT `knowledge_id` FROM `{$kb_table}`
                    )
                " );
            }

            // Slice selected IDs for current batch
            $batch_ids = array_slice( $ids, $offset, $batch_size );
            if ( empty( $batch_ids ) ) {
                wp_send_json_success( [
                    'updated' => 0,
                    'errors'  => 0,
                    'done'    => true,
                    'total'   => $total
                ] );
            }

            // Fetch the items
            $placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%s' ) );
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$kb_table}` WHERE `knowledge_id` IN ($placeholders)",
                    ...$batch_ids
                ),
                ARRAY_A
            );

            $updated = 0;
            $errors  = 0;
            $current_item = '';
            $processed = 0;

            foreach ( $items as $item ) {
                // Time budget check (Layer 1)
                if ( $processed > 0 && ( microtime( true ) - $start_time ) > $time_limit ) {
                    break;
                }

                $old_knowledge_id = $item['knowledge_id'];
                $post_id          = (int) $item['post_id'];
                $type             = $item['type'];
                $mode             = $item['mode']         ?? 'guideline';
                $country          = $item['country']      ?? '';
                $source_title     = $item['source_title'] ?? '';
                $source_url       = $item['source_url']   ?? '';
                $topic_id         = ! empty( $item['topic_id'] ) ? (int) $item['topic_id'] : null;
                $image_urls       = ! empty( $item['image_urls'] ) ? json_decode( $item['image_urls'], true ) : [];
                $raw_content      = $item['raw_content']  ?? '';

                $current_item = $source_title ?: sprintf( '%s #%d', ucfirst( $type ), $post_id );
                $plain_text = ! empty( $raw_content ) ? sonoai_clean_content( $raw_content ) : '';

                if ( empty( $plain_text ) ) {
                    $errors++;
                    $processed++;
                    continue;
                }

                // Generate new UUID and Embed
                $topic_row = $topic_id ? $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}sonoai_kb_topics WHERE id = %d", $topic_id ) ) : null;
                $topic_slug = $topic_row ? $topic_row->slug : '';

                if ( ! class_exists( 'SonoAI\\Embedding' ) ) {
                    require_once SONOAI_DIR . 'includes/Embedding.php';
                }

                // Call Embedding::insert with Layer 2 protection inside it
                $new_knowledge_id = Embedding::insert(
                    $post_id,
                    $type,
                    $plain_text,
                    is_array( $image_urls ) ? $image_urls : [],
                    $mode,
                    $topic_slug,
                    $country,
                    $source_title,
                    $source_url
                );

                if ( is_wp_error( $new_knowledge_id ) ) {
                    sonoai_log_error( '[SonoAI] Re-index failed for selected item ' . $old_knowledge_id . ': ' . $new_knowledge_id->get_error_message() );
                    $errors++;
                    $processed++;
                    continue;
                }

                // Clean up old embeddings in MySQL & Redis
                if ( $old_knowledge_id !== $new_knowledge_id ) {
                    $wpdb->delete( $emb_table, [ 'knowledge_id' => $old_knowledge_id ], [ '%s' ] );
                    RedisManager::instance()->delete_vectors_by_id( $old_knowledge_id );
                }

                // Update the kb_items row
                $wpdb->update(
                    $kb_table,
                    [
                        'knowledge_id'    => $new_knowledge_id,
                        'provider'        => $provider,
                        'embedding_model' => $embedding_model,
                    ],
                    [ 'knowledge_id' => $old_knowledge_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%s' ]
                );

                $updated++;
                $processed++;
            }

            $next_offset = $offset + $processed;
            $done = $next_offset >= $total;

            wp_send_json_success( [
                'updated'      => $updated,
                'errors'       => $errors,
                'next_offset'  => $next_offset,
                'total'        => $total,
                'done'         => $done,
                'current_item' => $current_item,
                'message'      => $done ? sprintf(
                    __( 'Selected items successfully re-indexed! %d items updated to %s / %s.', 'sonoai' ),
                    $total, $provider, $embedding_model
                ) : ''
            ] );

        } catch ( \Throwable $t ) {
            sonoai_log_error( '[SonoAI Selected Reindex] Fatal Error during batch reindexing: ' . $t->getMessage() );
            wp_send_json_error( [ 'message' => $t->getMessage() ] );
        }
    }

    /**
     * Re-sync selected items from MySQL straight to Redis (extremely fast, zero API cost).
     */
    public function handle_resync_items(): void {
        $this->check( 'sonoai_kb_resync_items' );

        try {
            global $wpdb;
            $emb_table = $this->emb_table();

            $ids_param  = SecurityHelper::get_param( 'ids', [] );
            if ( is_string( $ids_param ) ) {
                $ids = array_filter( array_map( 'trim', explode( ',', $ids_param ) ) );
            } else {
                $ids = array_filter( array_map( 'sanitize_text_field', (array) $ids_param ) );
            }

            if ( empty( $ids ) ) {
                wp_send_json_error( [ 'message' => __( 'No items selected.', 'sonoai' ) ] );
            }

            $redis = RedisManager::instance();
            if ( ! $redis->is_active() ) {
                wp_send_json_error( [ 'message' => __( 'Redis is not active or enabled.', 'sonoai' ) ] );
            }

            $synced = 0;
            $errors = 0;

            foreach ( $ids as $id ) {
                // 1. Delete existing vectors for this ID in Redis
                $redis->delete_vectors_by_id( $id );

                // 2. Fetch all embedding rows from MySQL
                $rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM `{$emb_table}` WHERE `knowledge_id` = %s", $id ),
                    ARRAY_A
                );

                if ( empty( $rows ) ) {
                    $errors++;
                    continue;
                }

                foreach ( $rows as $row ) {
                    $vector = json_decode( $row['embedding'], true );
                    $images = ! empty( $row['image_urls'] ) ? json_decode( $row['image_urls'], true ) : [];

                    if ( ! is_array( $vector ) ) {
                        $errors++;
                        continue;
                    }

                    $redis->cache_embedding(
                        $row['knowledge_id'] . ':' . $row['chunk_index'],
                        $vector,
                        [
                            'knowledge_id' => $row['knowledge_id'],
                            'post_id'      => (int) $row['post_id'],
                            'post_type'    => $row['post_type'],
                            'chunk_text'   => $row['chunk_text'],
                            'mode'         => $row['mode'],
                            'topic_slug'   => $row['topic_slug'],
                            'country'      => $row['country'],
                            'source_title' => $row['source_title'],
                            'source_url'   => $row['source_url'],
                            'image_urls'   => $images,
                        ]
                    );
                }

                $synced++;
            }

            wp_send_json_success( [
                'message' => sprintf(
                    __( 'Successfully re-synced %d items (%d errors) directly to Redis!', 'sonoai' ),
                    $synced, $errors
                )
            ] );

        } catch ( \Throwable $t ) {
            sonoai_log_error( '[SonoAI Selected Resync] Fatal Error: ' . $t->getMessage() );
            wp_send_json_error( [ 'message' => $t->getMessage() ] );
        }
    }
}
