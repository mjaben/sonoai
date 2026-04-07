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
            'sonoai_kb_add_txt',
            'sonoai_kb_edit_txt',
            'sonoai_kb_delete_item',
            'sonoai_kb_add_topic',
            'sonoai_kb_edit_topic',
            'sonoai_kb_delete_topic',
            'sonoai_kb_update_meta',
            'sonoai_kb_get_items',
            'sonoai_kb_upload_img',
        ];
        foreach ( $actions as $action ) {
            add_action( "wp_ajax_{$action}", [ $this, str_replace( 'sonoai_kb_', 'handle_', $action ) ] );
        }
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function check( string $nonce_action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'sonoai' ) ], 403 );
        }
        check_ajax_referer( $nonce_action, 'nonce' );
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
     * Handle updating metadata (mode/topic) for a single KB item
     */
    public function handle_update_meta() {
        $this->check( 'sonoai_kb_update_meta' );

        $post_id  = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'wp';
        $mode     = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'guideline';
        $topic_id = isset( $_POST['topic_id'] ) ? intval( $_POST['topic_id'] ) : 0;

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'sonoai' ) ] );
        }

        global $wpdb;
        $table_name = $this->kb_table();
        $emb_table  = $this->emb_table();

        // Get topic slug for embedding update
        $topic_slug = null;
        if ( $topic_id ) {
            $topic = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM `{$wpdb->prefix}sonoai_kb_topics` WHERE id = %d", $topic_id ) );
            if ( $topic ) {
                $topic_slug = $topic->slug;
            }
        }

        // Update KB items table
        $updated = $wpdb->update(
            $table_name,
            [
                'mode'     => $mode,
                'topic_id' => $topic_id ?: null
            ],
            [
                'post_id' => $post_id,
                'type'    => $type
            ],
            [ '%s', '%d' ],
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
        $topic_id     = ! empty( $args['topic_id'] ) ? (int) $args['topic_id'] : null;
        $country      = $args['country']     ?? '';

        if ( empty( $plain_text ) ) {
            return new \WP_Error( 'empty_content', __( 'Content is empty.', 'sonoai' ) );
        }

        // Get topic slug if topic_id is provided
        $topic_slug = '';
        if ( $topic_id ) {
            $topic = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM `{$wpdb->prefix}sonoai_kb_topics` WHERE id = %d", $topic_id ) );
            if ( $topic ) {
                $topic_slug = $topic->slug;
            } else {
                $topic_id = null; // Reset if invalid
            }
        }

        // Use the centralized Embedding class for actual vector storage.
        $knowledge_id = Embedding::insert( (int) $post_id, $type, $plain_text, $image_urls, $mode, $topic_slug, $country, $source_title, $source_url );
        
        if ( is_wp_error( $knowledge_id ) ) {
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
            [ '%s','%s','%s','%d','%s','%d','%s','%s','%s','%s','%s','%s','%d' ]
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

        $post_type = sanitize_key( $_POST['post_type'] ?? 'post' );
        $page      = max( 1, intval( $_POST['page'] ?? 1 ) );
        $filter    = sanitize_key( $_POST['kb_status'] ?? 'all' ); // all, added, not_added, update
        $per_page  = 20;
        $search    = sanitize_text_field( $_POST['search'] ?? '' );
        $mode      = sanitize_key( $_POST['mode'] ?? '' );
        $topic_id  = intval( $_POST['topic_id'] ?? 0 );
        $country   = sanitize_text_field( $_POST['country'] ?? '' );
        $offset    = ( $page - 1 ) * $per_page;

        $posts_tbl = $wpdb->posts;
        $kb_tbl    = $this->kb_table();

        $where    = $wpdb->prepare( "p.post_type = %s AND p.post_status = 'publish'", $post_type );
        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND p.post_title LIKE %s', $like );
        }
        
        $topics_tbl = $wpdb->prefix . 'sonoai_kb_topics';
        
        if ( $mode ) {
            $where .= $wpdb->prepare( " AND kb.mode = %s", $mode );
            if ( $mode === 'research' && $topic_id ) {
                $where .= $wpdb->prepare( " AND kb.topic_id = %d", $topic_id );
            } elseif ( $mode === 'guideline' && $country ) {
                $where .= $wpdb->prepare( " AND kb.country LIKE %s", '%' . $wpdb->esc_like( $country ) . '%' );
            }
        }

        $query = "
            SELECT 
                p.ID as id, 
                p.post_title as title, 
                p.post_modified_gmt as last_modified,
                kb.created_at as kb_added,
                kb.provider as provider,
                kb.embedding_model as model,
                kb.mode as mode,
                kb.topic_id as topic_id,
                t.name as topic_name
            FROM $posts_tbl p
            LEFT JOIN $kb_tbl kb ON kb.post_id = p.ID AND kb.type = 'wp'
            LEFT JOIN $topics_tbl t ON t.id = kb.topic_id
            WHERE $where
            ORDER BY p.post_date DESC
        ";

        $query = "
            SELECT 
                p.ID as id, 
                p.post_title as title, 
                p.post_modified_gmt as last_modified,
                kb.created_at as kb_added,
                kb.provider as provider,
                kb.embedding_model as model,
                kb.mode as mode,
                kb.topic_id as topic_id,
                t.name as topic_name
            FROM $posts_tbl p
            LEFT JOIN $kb_tbl kb ON kb.post_id = p.ID AND kb.type = 'wp'
            LEFT JOIN $topics_tbl t ON t.id = kb.topic_id
            WHERE $where
            ORDER BY p.post_date DESC
        ";

        $all_posts = $wpdb->get_results( $query );

        $counts = [ 'all' => 0, 'added' => 0, 'not_added' => 0, 'update' => 0 ];
        $filtered  = [];

        foreach ( $all_posts as $p ) {
            $p_status = 'not_added';
            if ( $p->kb_added ) {
                $p_status = ( $p->last_modified > $p->kb_added ) ? 'update' : 'added';
            }

            $counts['all']++;
            $counts[ $p_status ]++;

            if ( $filter === 'all' || $filter === $p_status ) {
                $filtered[] = [
                    'id'            => $p->id,
                    'title'         => $p->title,
                    'last_modified' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $p->last_modified . ' UTC' ) ),
                    'kb_added'      => $p->kb_added ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $p->kb_added . ' UTC' ) ) : '—',
                    'kb_status'     => $p_status,
                    'mode'          => $p->mode ? ucfirst($p->mode) : '—',
                    'topic_name'    => $p->topic_name ? $p->topic_name : '—',
                    'topic_id'      => $p->topic_id ? $p->topic_id : 0,
                    'raw_mode'      => $p->mode ? $p->mode : '',
                    'ai_model'      => $p->model ? ( ucfirst( $p->provider ) . ' / ' . $p->model ) : '—',
                    'edit_url'      => get_edit_post_link( $p->id, 'raw' ),
                ];
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

    // ── Handler: Get Custom Items ─────────────────────────────────────────────

    public function handle_get_items(): void {
        $this->check( 'sonoai_kb_get_posts' ); // Re-use the same nonce/capability
        global $wpdb;

        $type      = sanitize_key( $_POST['type'] ?? 'pdf' ); // pdf, url, txt
        $page      = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page  = 10;
        $search    = sanitize_text_field( $_POST['search'] ?? '' );
        $mode      = sanitize_key( $_POST['mode'] ?? '' );
        $topic_id  = intval( $_POST['topic_id'] ?? 0 );
        $country   = sanitize_text_field( $_POST['country'] ?? '' );
        $offset    = ( $page - 1 ) * $per_page;

        $kb_tbl     = $this->kb_table();
        $topics_tbl = $wpdb->prefix . 'sonoai_kb_topics';

        $where = $wpdb->prepare( "kb.type = %s", $type );

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            if ( $type === 'txt' ) {
                $where .= $wpdb->prepare( " AND kb.raw_content LIKE %s", $like );
            } else {
                $where .= $wpdb->prepare( " AND (kb.source_title LIKE %s OR kb.source_url LIKE %s)", $like, $like );
            }
        }
        if ( $mode ) {
            $where .= $wpdb->prepare( " AND kb.mode = %s", $mode );
            if ( $mode === 'research' && $topic_id ) {
                $where .= $wpdb->prepare( " AND kb.topic_id = %d", $topic_id );
            } elseif ( $mode === 'guideline' && $country ) {
                $like_country = '%' . $wpdb->esc_like( $country ) . '%';
                $where .= $wpdb->prepare( " AND kb.country LIKE %s", $like_country );
            }
        }

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $kb_tbl kb WHERE $where" );

        $query = "
            SELECT kb.*, t.name as topic_name 
            FROM $kb_tbl kb
            LEFT JOIN $topics_tbl t ON t.id = kb.topic_id
            WHERE $where
            ORDER BY kb.created_at DESC
            LIMIT " . (int) $per_page . " OFFSET " . (int) $offset;

        $items = $wpdb->get_results( $query );

        $formatted = [];
        foreach ( $items as $item ) {
            $formatted[] = [
                'knowledge_id' => $item->knowledge_id,
                'source_title' => $item->source_title ?: ( $item->type === 'txt' ? wp_trim_words( strip_tags( $item->raw_content ), 10 ) : '—' ),
                'source_url'   => $item->source_url,
                'mode'         => ucfirst( $item->mode ),
                'raw_mode'     => $item->mode,
                'topic_name'   => $item->topic_name ?: '—',
                'topic_id'     => $item->topic_id,
                'country'      => $item->country ?: '—',
                'ai_model'     => ucfirst( $item->provider ) . ' / ' . ( $item->embedding_model ?: '—' ),
                'created_at'   => date_i18n( get_option( 'date_format' ), strtotime( $item->created_at ) ),
                'raw_content'  => $item->raw_content,
            ];
        }

        wp_send_json_success( [
            'items'       => $formatted,
            'total'       => (int) $total,
            'page'        => $page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ] );
    }

    // ── Handler: Add WP Post ──────────────────────────────────────────────────

    public function handle_add_post(): void {
        $this->check( 'sonoai_kb_add_post' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'sonoai' ) ] );
        }

        // Clear old indexing (upsert)
        global $wpdb;
        $wpdb->delete( $this->kb_table(), [ 'type' => 'wp', 'post_id' => $post_id ], [ '%s', '%d' ] );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$this->emb_table()}` WHERE type = 'wp' AND post_id = %d",
            $post_id
        ) );

        $result = $this->embed_and_store( [
            'type'         => 'wp',
            'text'         => $post->post_content,
            'source_url'   => get_permalink( $post->ID ),
            'source_title' => $post->post_title,
            'post_id'      => $post->ID,
            'mode'         => sanitize_text_field( $_POST['mode'] ?? 'guideline' ),
            'topic_id'     => intval( $_POST['topic_id'] ?? 0 ),
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
        $post_id = intval( $_POST['post_id'] ?? 0 );

        $kb_item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$this->kb_table()}` WHERE type = 'wp' AND post_id = %d",
            $post_id
        ) );

        if ( $kb_item ) {
            // Evict from Redis before MySQL deletion to keep the index consistent.
            $mode = $kb_item->mode ?? 'guideline';
            RedisManager::instance()->evict_vector( $kb_item->knowledge_id, $mode );
            RedisManager::instance()->evict_vector( $kb_item->knowledge_id, $mode === 'guideline' ? 'research' : 'guideline' );

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
            wp_send_json_error( [ 'message' => __( 'Could not parse PDF: ', 'sonoai' ) . $e->getMessage() ] );
        }

        if ( empty( trim( $content ) ) ) {
            wp_send_json_error( [ 'message' => __( 'PDF appears to be empty or contains only images. Please use a text-based PDF.', 'sonoai' ) ] );
        }

        $result = $this->embed_and_store( [
            'type'         => 'pdf',
            'text'         => $content,
            'source_url'   => sanitize_text_field( $_POST['source_url'] ?? $moved['url'] ),
            'source_title' => sanitize_text_field( $_POST['source_name'] ?? $file['name'] ),
            'country'      => sanitize_text_field( $_POST['country'] ?? '' ),
            'mode'         => sanitize_text_field( $_POST['mode'] ?? 'guideline' ),
            'topic_id'     => intval( $_POST['topic_id'] ?? 0 ),
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
        $url = esc_url_raw( $_POST['url'] ?? '' );
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
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
            'source_url'   => sanitize_text_field( $_POST['source_url'] ?? $url ),
            'source_title' => sanitize_text_field( $_POST['source_name'] ?? $url ),
            'country'      => sanitize_text_field( $_POST['country'] ?? '' ),
            'mode'         => sanitize_text_field( $_POST['mode'] ?? 'guideline' ),
            'topic_id'     => intval( $_POST['topic_id'] ?? 0 ),
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
        $raw_html  = wp_kses_post( $_POST['content'] ?? '' );
        if ( empty( trim( strip_tags( $raw_html ) ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Content cannot be empty.', 'sonoai' ) ] );
        }

        $manual_images = isset( $_POST['images'] ) ? json_decode( wp_unslash( $_POST['images'] ), true ) : [];
        if ( ! is_array( $manual_images ) ) $manual_images = [];

        $image_urls = $this->extract_image_urls( $raw_html, $manual_images );
        $plain      = sonoai_clean_content( $raw_html );
        $title      = wp_trim_words( $plain, 10, '…' );

        $result = $this->embed_and_store( [
            'type'         => 'txt',
            'text'         => $plain,
            'raw_content'  => $raw_html,
            'source_title' => sanitize_text_field( $_POST['source_name'] ?? $title ),
            'source_url'   => sanitize_text_field( $_POST['source_url'] ?? '' ),
            'country'      => sanitize_text_field( $_POST['country'] ?? '' ),
            'image_urls'   => $image_urls,
            'mode'         => sanitize_text_field( $_POST['mode'] ?? 'guideline' ),
            'topic_id'     => intval( $_POST['topic_id'] ?? 0 ),
        ] );

        if ( is_wp_error( $result ) ) {
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
        $knowledge_id = sanitize_text_field( $_POST['knowledge_id'] ?? '' );
        $raw_html     = wp_kses_post( $_POST['content'] ?? '' );

        if ( empty( $knowledge_id ) || empty( trim( strip_tags( $raw_html ) ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'sonoai' ) ] );
        }

        // Retrieve mode before deleting.
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT mode FROM `{$this->kb_table()}` WHERE knowledge_id = %s",
            $knowledge_id
        ) );
        $old_mode = $item->mode ?? 'guideline';

        // Evict stale Redis vectors for the old knowledge_id before re-embedding.
        RedisManager::instance()->evict_vector( $knowledge_id, $old_mode );
        RedisManager::instance()->evict_vector( $knowledge_id, $old_mode === 'guideline' ? 'research' : 'guideline' );

        // Delete old embeddings for this knowledge_id.
        $wpdb->delete( $this->emb_table(), [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );
        $wpdb->delete( $this->kb_table(),  [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );

        $manual_images = isset( $_POST['images'] ) ? json_decode( wp_unslash( $_POST['images'] ), true ) : [];
        if ( ! is_array( $manual_images ) ) $manual_images = [];

        $image_urls = $this->extract_image_urls( $raw_html, $manual_images );
        $plain      = sonoai_clean_content( $raw_html );
        $title      = wp_trim_words( $plain, 10, '…' );

        $result = $this->embed_and_store( [
            'type'         => 'txt',
            'text'         => $plain,
            'raw_content'  => $raw_html,
            'source_title' => sanitize_text_field( $_POST['source_name'] ?? $title ),
            'source_url'   => sanitize_text_field( $_POST['source_url'] ?? '' ),
            'country'      => sanitize_text_field( $_POST['country'] ?? '' ),
            'image_urls'   => $image_urls,
            'mode'         => sanitize_text_field( $_POST['mode'] ?? 'guideline' ),
            'topic_id'     => intval( $_POST['topic_id'] ?? 0 ),
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
        $knowledge_id = sanitize_text_field( $_POST['knowledge_id'] ?? '' );
        if ( empty( $knowledge_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing knowledge ID.', 'sonoai' ) ] );
        }

        // Retrieve mode before deleting so we can target the correct Redis index.
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT mode FROM `{$this->kb_table()}` WHERE knowledge_id = %s",
            $knowledge_id
        ) );
        $mode = $item->mode ?? 'guideline';

        // Evict from Redis Set index before MySQL deletion.
        RedisManager::instance()->evict_vector( $knowledge_id, $mode );
        // Also evict the opposite mode in case it was re-indexed differently.
        RedisManager::instance()->evict_vector( $knowledge_id, $mode === 'guideline' ? 'research' : 'guideline' );

        $wpdb->delete( $this->kb_table(),  [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );
        $wpdb->delete( $this->emb_table(), [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );

        wp_send_json_success( [ 'message' => __( 'Item deleted from knowledge base.', 'sonoai' ) ] );
    }

    // ── Topic Handlers ────────────────────────────────────────────────────────

    public function handle_add_topic(): void {
        $this->check( 'sonoai_kb_manage_topics' );
        global $wpdb;

        $name = sanitize_text_field( $_POST['name'] ?? '' );
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

        $id   = intval( $_POST['topic_id'] ?? 0 );
        $name = sanitize_text_field( $_POST['name'] ?? '' );
        if ( empty( $name ) || empty( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Topic ID and name are required.', 'sonoai' ) ] );
        }

        $slug = sanitize_title( $name );
        $topics_table = $wpdb->prefix . 'sonoai_kb_topics';

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$topics_table` WHERE slug = %s AND id != %d", $slug, $id ) );
        if ( $existing ) {
            wp_send_json_error( [ 'message' => __( 'A topic with this name already exists.', 'sonoai' ) ] );
        }

        // We also need to cascade update topic_slug in sonoai_embeddings if we change slug, but for simplicity, let's just update the topic table. Actually, we should update both to prevent orphan topic_slugs in embeddings or saved responses.
        $old_topic = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM `$topics_table` WHERE id = %d", $id ) );
        if ( $old_topic && $old_topic->slug !== $slug ) {
            $wpdb->update( $this->emb_table(), [ 'topic_slug' => $slug ], [ 'topic_slug' => $old_topic->slug ], [ '%s' ], [ '%s' ] );
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

        $id = intval( $_POST['topic_id'] ?? 0 );
        if ( empty( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Topic ID is required.', 'sonoai' ) ] );
        }

        $topics_table = $wpdb->prefix . 'sonoai_kb_topics';
        $old_topic    = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM `$topics_table` WHERE id = %d", $id ) );

        $wpdb->delete( $topics_table, [ 'id' => $id ], [ '%d' ] );

        // Nullify the topic_id in kb_items
        $wpdb->update( $this->kb_table(), [ 'topic_id' => null ], [ 'topic_id' => $id ] );
        
        // Nullify topic_slug in embeddings and saved responses
        if ( $old_topic ) {
            $wpdb->update( $this->emb_table(), [ 'topic_slug' => null ], [ 'topic_slug' => $old_topic->slug ] );
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

        $label = sanitize_title( $_POST['label'] ?? 'clinical-image' );
        
        // Filter to change the upload directory
        $upload_dir_filter = function( $dirs ) {
            $custom_dir = 'sonoai-Clinical-img-lib/' . date( 'Y' ) . '/' . date( 'm' );
            $dirs['path']    = $dirs['basedir'] . '/' . $custom_dir;
            $dirs['url']     = $dirs['baseurl'] . '/' . $custom_dir;
            $dirs['subdir']  = '/' . $custom_dir;

            if ( ! file_exists( $dirs['path'] ) ) {
                wp_mkdir_p( $dirs['path'] );
            }

            return $dirs;
        };

        // Filter to rename the file based on the clinical label
        $rename_filter = function( $file ) use ( $label ) {
            $ext  = pathinfo( $file['name'], PATHINFO_EXTENSION );
            $file['name'] = $label . '.' . $ext;
            return $file;
        };

        add_filter( 'upload_dir', $upload_dir_filter );
        add_filter( 'wp_handle_upload_prefilter', $rename_filter );

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_handle_upload( $_FILES['file'], [ 'test_form' => false ] );

        remove_filter( 'upload_dir', $upload_dir_filter );
        remove_filter( 'wp_handle_upload_prefilter', $rename_filter );

        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => $upload['error'] ] );
        }

        wp_send_json_success( [
            'url'  => $upload['url'],
            'file' => $upload['file']
        ] );
    }
}
