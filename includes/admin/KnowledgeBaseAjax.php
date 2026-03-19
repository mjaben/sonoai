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
     * Extract image src URLs from HTML.
     */
    private function extract_image_urls( string $html ): array {
        if ( empty( $html ) ) {
            return [];
        }
        $urls = [];
        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $html, $m ) ) {
            $urls = array_values( array_unique( $m[1] ) );
        }
        return $urls;
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

        if ( empty( $plain_text ) ) {
            return new \WP_Error( 'empty_content', __( 'Content is empty.', 'sonoai' ) );
        }

        // Use the centralized Embedding class for actual vector storage.
        $knowledge_id = Embedding::insert( (int) $post_id, $type, $plain_text, $image_urls );
        
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
                'post_id'         => (int) $post_id,
                'source_title'    => $source_title,
                'source_url'      => $source_url,
                'raw_content'     => $raw_content,
                'image_urls'      => $image_json,
                'provider'        => $provider,
                'embedding_model' => $embedding_model,
                'chunk_count'     => count( $chunks ),
            ],
            [ '%s','%s','%d','%s','%s','%s','%s','%s','%s','%d' ]
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
        $offset    = ( $page - 1 ) * $per_page;

        $posts_tbl = $wpdb->posts;
        $kb_tbl    = $this->kb_table();

        $where    = $wpdb->prepare( "p.post_type = %s AND p.post_status = 'publish'", $post_type );
        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND p.post_title LIKE %s', $like );
        }

        $query = "
            SELECT 
                p.ID as id, 
                p.post_title as title, 
                p.post_modified_gmt as last_modified,
                kb.created_at as kb_added,
                kb.provider as provider,
                kb.embedding_model as model
            FROM $posts_tbl p
            LEFT JOIN $kb_tbl kb ON kb.post_id = p.ID AND kb.type = 'wp'
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
            'source_url'   => $moved['url'],
            'source_title' => sanitize_file_name( $file['name'] ),
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
            'source_url'   => $url,
            'source_title' => $url,
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

        $image_urls = $this->extract_image_urls( $raw_html );
        $plain      = sonoai_clean_content( $raw_html );
        $title      = wp_trim_words( $plain, 10, '…' );

        $result = $this->embed_and_store( [
            'type'         => 'txt',
            'text'         => $plain,
            'raw_content'  => $raw_html,
            'source_title' => $title,
            'image_urls'   => $image_urls,
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

        // Delete old embeddings for this knowledge_id.
        $wpdb->delete( $this->emb_table(), [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );
        $wpdb->delete( $this->kb_table(),  [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );

        $image_urls = $this->extract_image_urls( $raw_html );
        $plain      = sonoai_clean_content( $raw_html );
        $title      = wp_trim_words( $plain, 10, '…' );

        $result = $this->embed_and_store( [
            'type'         => 'txt',
            'text'         => $plain,
            'raw_content'  => $raw_html,
            'source_title' => $title,
            'image_urls'   => $image_urls,
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

        $wpdb->delete( $this->kb_table(),  [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );
        $wpdb->delete( $this->emb_table(), [ 'knowledge_id' => $knowledge_id ], [ '%s' ] );

        wp_send_json_success( [ 'message' => __( 'Item deleted from knowledge base.', 'sonoai' ) ] );
    }
}
