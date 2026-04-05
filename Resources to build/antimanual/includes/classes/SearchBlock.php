<?php
/**
 * Antimanual AI Search Block
 *
 * @package Antimanual
 * @since 1.2.0
 */

namespace Antimanual;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Block Handler
 *
 * Registers the Gutenberg search block and enqueues frontend assets.
 *
 * @package Antimanual
 */
class SearchBlock {
    private static $instance = null;

    public function __construct() {
        add_action( 'init', array( $this, 'register_block' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * Get the singleton instance.
     *
     * @return SearchBlock The singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register the Gutenberg block.
     */
    public function register_block() {
        register_block_type(
            ANTIMANUAL_DIR . 'build/blocks/antimanual-search/block.json',
            array(
                'render_callback' => array( $this, 'render_block' ),
            )
        );
    }

    /**
     * Register REST API routes for popular keywords.
     */
    public function register_rest_routes() {
        register_rest_route( 'antimanual/v1', '/popular-keywords', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_popular_keywords' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'count' => array(
                    'type'              => 'integer',
                    'default'           => 5,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );
    }

    /**
     * REST API callback: Return popular keywords from query logs.
     *
     * Groups queries by their text (case-insensitive), counts occurrences,
     * and returns the most frequently searched terms.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return \WP_REST_Response Popular keywords.
     */
    public function get_popular_keywords( $request ) {
        $count    = min( absint( $request->get_param( 'count' ) ), 10 );
        $keywords = $this->query_popular_keywords( $count );

        return rest_ensure_response( array(
            'success'  => true,
            'keywords' => $keywords,
        ) );
    }

    /**
     * Fetch the most frequently searched keywords from the query_votes table.
     *
     * @param int $count Number of keywords to return.
     * @return array List of popular keyword strings.
     */
    private function query_popular_keywords( $count = 5 ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'antimanual_query_votes';
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;

        if ( ! $exists ) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT LOWER( TRIM( query ) ) AS keyword, COUNT(*) AS search_count
                 FROM `$table`
                 WHERE query IS NOT NULL AND query != ''
                 GROUP BY keyword
                 ORDER BY search_count DESC
                 LIMIT %d",
                $count
            ),
            ARRAY_A
        );

        if ( empty( $results ) ) {
            return array();
        }

        // Return only the keyword strings, properly capitalized.
        return array_map( function ( $row ) {
            return ucfirst( $row['keyword'] );
        }, $results );
    }

    /**
     * Render the block on the frontend and enqueue assets.
     *
     * Enqueueing inside render_callback ensures the script loads
     * regardless of where the block is placed (post content, widget
     * area, FSE template, reusable block, etc.). The previous
     * approach used has_block() on wp_enqueue_scripts, which only
     * checks the main queried post content.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Block inner content.
     * @return string Rendered HTML.
     */
    public function render_block( $attributes, $content ) {
        // Enqueue frontend assets when the block is actually rendered.
        $this->enqueue_frontend_assets();

        // Get attributes with defaults.
        $placeholder            = isset( $attributes['placeholder'] ) ? sanitize_text_field( $attributes['placeholder'] ) : 'Ask AI anything...';
        $button_text            = isset( $attributes['buttonText'] ) ? sanitize_text_field( $attributes['buttonText'] ) : 'Ask';
        $show_icon              = isset( $attributes['showIcon'] ) ? (bool) $attributes['showIcon'] : true;
        $show_popular_keywords  = isset( $attributes['showPopularKeywords'] ) ? (bool) $attributes['showPopularKeywords'] : true;
        $popular_keywords_count = isset( $attributes['popularKeywordsCount'] ) ? absint( $attributes['popularKeywordsCount'] ) : 5;
        $popular_keywords_count = max( 1, min( 10, $popular_keywords_count ) );

        // New feature attributes.
        $greeting_text       = isset( $attributes['greetingText'] ) ? sanitize_text_field( $attributes['greetingText'] ) : '';
        $suggested_questions = isset( $attributes['suggestedQuestions'] ) ? sanitize_textarea_field( $attributes['suggestedQuestions'] ) : '';
        $answer_detail       = isset( $attributes['answerDetail'] ) ? sanitize_key( $attributes['answerDetail'] ) : 'balanced';
        if ( ! in_array( $answer_detail, [ 'brief', 'balanced', 'detailed' ], true ) ) {
            $answer_detail = 'balanced';
        }

        // Parse suggested questions from newline-separated text.
        $questions = array();
        if ( ! empty( $suggested_questions ) ) {
            $questions = array_filter( array_map( 'trim', explode( "\n", $suggested_questions ) ) );
        }

        // Style attributes.
        $accent_color = isset( $attributes['accentColor'] ) ? sanitize_hex_color( $attributes['accentColor'] ) : '#0079FF';
        if ( empty( $accent_color ) ) {
            $accent_color = '#0079FF';
        }

        $btn_text_color = isset( $attributes['buttonTextColor'] ) ? sanitize_hex_color( $attributes['buttonTextColor'] ) : '#ffffff';
        if ( empty( $btn_text_color ) ) {
            $btn_text_color = '#ffffff';
        }

        $border_style     = isset( $attributes['borderStyle'] ) ? sanitize_text_field( $attributes['borderStyle'] ) : 'pill';

        // Generate unique ID for this block instance.
        $block_id = 'antimanual-search-' . wp_generate_uuid4();

        // Compute darker accent for hover states.
        $accent_dark = $this->darken_color( $accent_color, 20 );

        // Border radius map.
        $radius_map = array(
            'pill'    => '50px',
            'rounded' => '12px',
            'square'  => '0px',
        );
        $border_radius = isset( $radius_map[ $border_style ] ) ? $radius_map[ $border_style ] : '50px';

        // Build CSS custom property inline style.
        $custom_style = sprintf(
            '--atml-accent:%s;--atml-accent-dark:%s;--atml-btn-text:%s;--atml-border-radius:%s;',
            esc_attr( $accent_color ),
            esc_attr( $accent_dark ),
            esc_attr( $btn_text_color ),
            esc_attr( $border_radius )
        );

        // Fetch popular keywords if enabled.
        $keywords = array();
        if ( $show_popular_keywords ) {
            $keywords = $this->query_popular_keywords( $popular_keywords_count );
        }

        ob_start();
        ?>
        <div class="antimanual-search-block" id="<?php echo esc_attr( $block_id ); ?>"
             style="<?php echo esc_attr( $custom_style ); ?>"
             data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
             data-button-text="<?php echo esc_attr( $button_text ); ?>"
             data-show-icon="<?php echo $show_icon ? '1' : '0'; ?>"
             data-answer-detail="<?php echo esc_attr( $answer_detail ); ?>">

            <?php if ( ! empty( $greeting_text ) ) : ?>
                <h3 class="search-greeting"><?php echo esc_html( $greeting_text ); ?></h3>
            <?php endif; ?>

            <div class="search-form-wrapper">
                <?php if ( $show_icon ) : ?>
                    <div class="ai-search-icon">
                        <svg class="ai-icon" xmlns="http://www.w3.org/2000/svg" version="1.1" width="28" height="28" viewBox="0 0 32 32">
                            <g>
                                <path d="m13.294 7.436.803 2.23a8.835 8.835 0 0 0 5.316 5.316l2.23.803a.229.229 0 0 1 0 .43l-2.23.803a8.835 8.835 0 0 0-5.316 5.316l-.803 2.23a.229.229 0 0 1-.43 0l-.803-2.23a8.835 8.835 0 0 0-5.316-5.316l-2.23-.803a.229.229 0 0 1 0-.43l2.23-.803a8.835 8.835 0 0 0 5.316-5.316l.803-2.23a.228.228 0 0 1 .43 0zM23.332 2.077l.407 1.129a4.477 4.477 0 0 0 2.692 2.692l1.129.407a.116.116 0 0 1 0 .218l-1.129.407a4.477 4.477 0 0 0-2.692 2.692l-.407 1.129a.116.116 0 0 1-.218 0l-.407-1.129a4.477 4.477 0 0 0-2.692-2.692l-1.129-.407a.116.116 0 0 1 0-.218l1.129-.407a4.477 4.477 0 0 0 2.692-2.692l.407-1.129a.116.116 0 0 1 .218 0zM23.332 21.25l.407 1.129a4.477 4.477 0 0 0 2.692 2.692l1.129.407a.116.116 0 0 1 0 .218l-1.129.407a4.477 4.477 0 0 0-2.692 2.692l-.407 1.129a.116.116 0 0 1-.218 0l-.407-1.129a4.477 4.477 0 0 0-2.692-2.692l-1.129-.407a.116.116 0 0 1 0-.218l1.129-.407a4.477 4.477 0 0 0 2.692-2.692l.407-1.129c.037-.102.182-.102.218 0z" fill="currentColor"/>
                            </g>
                        </svg>
                    </div>
                <?php endif; ?>

                <div class="search-input-wrapper">
                    <input type="text" class="search-field" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
                    <button type="button" class="search-button"><?php echo esc_html( $button_text ); ?></button>
                </div>
            </div>

            <?php if ( ! empty( $questions ) ) : ?>
                <div class="suggested-questions">
                    <?php foreach ( $questions as $question ) : ?>
                        <button type="button" class="suggested-question-tag" data-question="<?php echo esc_attr( $question ); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <?php echo esc_html( $question ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $show_popular_keywords && ! empty( $keywords ) ) : ?>
                <div class="popular-keywords">
                    <span class="popular-keywords-label"><?php esc_html_e( 'Popular:', 'antimanual' ); ?></span>
                    <?php foreach ( $keywords as $keyword ) : ?>
                        <button type="button" class="popular-keyword-tag" data-keyword="<?php echo esc_attr( $keyword ); ?>">
                            <?php echo esc_html( $keyword ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ai-search-results" id="ai-search-results-<?php echo esc_attr( $block_id ); ?>"></div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Enqueue frontend assets for the search block.
     *
     * Called from render_block() so assets are only loaded when the
     * block is actually present on the page. Safe to call multiple
     * times — wp_enqueue_script prevents duplicate loading.
     */
    private function enqueue_frontend_assets() {
        wp_enqueue_script(
            'antimanual-search-block-frontend',
            ANTIMANUAL_URL . 'assets/js/search-block.js',
            array(),
            filemtime( ANTIMANUAL_DIR . 'assets/js/search-block.js' ),
            true
        );

        $this->localize_script_data();
    }

    /**
     * Localize script data for the frontend.
     */
    private function localize_script_data() {
        // Prevent duplicate localization.
        if ( wp_script_is( 'antimanual-search-block-frontend', 'done' ) ) {
            return;
        }

        $rest_url = rest_url( 'antimanual/v1' );

        wp_localize_script( 'antimanual-search-block-frontend', 'antimanual_vars', array(
            'rest_url' => $rest_url,
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ) );
    }

    /**
     * Darken a hex color by a given percentage.
     *
     * @param string $hex    Hex color value.
     * @param int    $amount Percentage to darken (0-100).
     * @return string Darkened hex color.
     */
    private function darken_color( $hex, $amount ) {
        $hex = ltrim( $hex, '#' );

        $r = max( 0, hexdec( substr( $hex, 0, 2 ) ) - round( 2.55 * $amount ) );
        $g = max( 0, hexdec( substr( $hex, 2, 2 ) ) - round( 2.55 * $amount ) );
        $b = max( 0, hexdec( substr( $hex, 4, 2 ) ) - round( 2.55 * $amount ) );

        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }
}
