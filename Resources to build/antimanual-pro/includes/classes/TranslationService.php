<?php

namespace Antimanual_Pro;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Translation Service Class
 *
 * Handles automatic translation of WordPress content using AI providers
 * (OpenAI or Google Gemini) for high-quality translations.
 *
 * @package Antimanual_Pro
 * @since 2.2.0
 */
class TranslationService {
    /**
     * Singleton instance.
     *
     * @var TranslationService|null
     */
    private static $instance = null;

    /**
     * Supported languages with their codes and names.
     *
     * @var array
     */
    private static $supported_languages = [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ru' => 'Russian',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'zh' => 'Chinese (Simplified)',
        'zh-TW' => 'Chinese (Traditional)',
        'ar' => 'Arabic',
        'hi' => 'Hindi',
        'bn' => 'Bengali',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'tr' => 'Turkish',
        'vi' => 'Vietnamese',
        'th' => 'Thai',
        'id' => 'Indonesian',
        'ms' => 'Malay',
        'sv' => 'Swedish',
        'da' => 'Danish',
        'fi' => 'Finnish',
        'no' => 'Norwegian',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'uk' => 'Ukrainian',
        'el' => 'Greek',
        'he' => 'Hebrew',
        'fa' => 'Persian',
    ];

    /**
     * Get singleton instance.
     *
     * @return TranslationService
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'wp_ajax_atml_translate_content', [ $this, 'ajax_translate_content' ] );
        add_action( 'wp_ajax_atml_bulk_translate', [ $this, 'ajax_bulk_translate' ] );
        add_action( 'wp_ajax_atml_get_translation_status', [ $this, 'ajax_get_translation_status' ] );
        add_action( 'transition_post_status', [ $this, 'maybe_auto_translate' ], 10, 3 );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_rest_routes() {
        register_rest_route( 'antimanual/v1', '/translations/languages', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_languages' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'antimanual/v1', '/translations/translate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_translate_content' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'antimanual/v1', '/translations/bulk-translate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_bulk_translate' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'antimanual/v1', '/translations/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_stats' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'antimanual/v1', '/translations/post/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_post_translations' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'antimanual/v1', '/translations/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_settings' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'antimanual/v1', '/translations/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_settings' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Public endpoint for frontend language switcher
        register_rest_route( 'antimanual/v1', '/translations/public/(?P<post_id>\d+)/(?P<lang>[a-z]{2}(-[A-Z]{2})?)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_public_translation' ],
            'permission_callback' => '__return_true',
        ] );

        // Delete a specific translation
        register_rest_route( 'antimanual/v1', '/translations/delete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_delete_translation' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Retry failed translations
        register_rest_route( 'antimanual/v1', '/translations/retry-failed', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_retry_failed' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Get recent translations history
        register_rest_route( 'antimanual/v1', '/translations/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_history' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Get glossary terms
        register_rest_route( 'antimanual/v1', '/translations/glossary', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_glossary' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Save glossary terms
        register_rest_route( 'antimanual/v1', '/translations/glossary', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_glossary' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Preview translation
        register_rest_route( 'antimanual/v1', '/translations/preview', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_preview_translation' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // Get available posts for translation
        register_rest_route( 'antimanual/v1', '/translations/posts', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_available_posts' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    /**
     * Check if user has permission.
     *
     * @return bool
     */
    public function check_permission() {
        return current_user_can( 'edit_posts' );
    }

    /**
     * Get supported languages.
     *
     * @return array
     */
    public static function get_supported_languages() {
        return self::$supported_languages;
    }

    /**
     * Get enabled languages from settings.
     *
     * @return array
     */
    public static function get_enabled_languages() {
        $enabled = atml_option( 'translation_languages', [] );
        
        if ( empty( $enabled ) ) {
            return [];
        }

        $languages = [];
        foreach ( $enabled as $code ) {
            if ( isset( self::$supported_languages[ $code ] ) ) {
                $languages[ $code ] = self::$supported_languages[ $code ];
            }
        }

        return $languages;
    }

    /**
     * Get the site's default language.
     *
     * @return string
     */
    public static function get_default_language() {
        $locale = get_locale();
        $lang_code = substr( $locale, 0, 2 );
        return $lang_code;
    }

    /**
     * REST: Get supported languages.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_languages( $request ) {
        $languages = [];
        foreach ( self::$supported_languages as $code => $name ) {
            $languages[] = [
                'code' => $code,
                'name' => $name,
            ];
        }

        return rest_ensure_response( [
            'languages'        => $languages,
            'default_language' => self::get_default_language(),
            'enabled_languages' => self::get_enabled_languages(),
        ] );
    }

    /**
     * REST: Translate single content.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_translate_content( $request ) {
        $post_id = $request->get_param( 'post_id' );
        $target_language = $request->get_param( 'target_language' );

        if ( ! $post_id || ! $target_language ) {
            return new \WP_Error( 'missing_params', __( 'Post ID and target language are required.', 'antimanual' ), [ 'status' => 400 ] );
        }

        $result = $this->translate_post( $post_id, $target_language );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    /**
     * REST: Bulk translate content.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_bulk_translate( $request ) {
        $post_types = $request->get_param( 'post_types' ) ?: [ 'post', 'page' ];
        $target_languages = $request->get_param( 'target_languages' ) ?: [];
        $batch_size = $request->get_param( 'batch_size' ) ?: 5;
        $post_ids = $request->get_param( 'post_ids' ) ?: [];

        if ( empty( $target_languages ) ) {
            return new \WP_Error( 'missing_languages', __( 'At least one target language is required.', 'antimanual' ), [ 'status' => 400 ] );
        }

        $results = $this->bulk_translate( $post_types, $target_languages, $batch_size, $post_ids );

        return rest_ensure_response( $results );
    }

    /**
     * REST: Get translation statistics.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_stats( $request ) {
        $stats = atml_get_translation_stats();
        return rest_ensure_response( $stats );
    }

    /**
     * REST: Get translations for a specific post.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_post_translations( $request ) {
        $post_id = $request->get_param( 'post_id' );
        $translations = atml_get_post_translations( $post_id );

        $formatted = [];
        foreach ( $translations as $translation ) {
            $formatted[ $translation->language_code ] = [
                'title'   => $translation->translated_title,
                'content' => $translation->translated_content,
                'excerpt' => $translation->translated_excerpt,
                'status'  => $translation->translation_status,
                'date'    => $translation->translated_at,
            ];
        }

        return rest_ensure_response( $formatted );
    }

    /**
     * REST: Get translation settings.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_settings( $request ) {
        return rest_ensure_response( [
            'enabled_languages'       => atml_option( 'translation_languages', [] ),
            'auto_translate_new'      => atml_option( 'translation_auto_new', false ),
            'show_language_switcher'  => atml_option( 'translation_show_switcher', true ),
            'show_switcher_on_translated' => atml_option( 'translation_show_switcher_on_translated', false ),
            'switcher_position'       => atml_option( 'translation_switcher_position', 'after_title' ),
            'translation_provider'    => atml_option( 'translation_provider', 'openai' ),
            'enabled_post_types'      => atml_option( 'translation_post_types', [ 'post', 'page' ] ),
        ] );
    }

    /**
     * REST: Save translation settings.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_save_settings( $request ) {
        $settings = [
            'translation_languages'         => $request->get_param( 'enabled_languages' ),
            'translation_auto_new'          => $request->get_param( 'auto_translate_new' ),
            'translation_show_switcher'     => $request->get_param( 'show_language_switcher' ),
            'translation_show_switcher_on_translated' => $request->get_param( 'show_switcher_on_translated' ),
            'translation_switcher_position' => $request->get_param( 'switcher_position' ),
            'translation_provider'          => $request->get_param( 'translation_provider' ),
            'translation_post_types'        => $request->get_param( 'enabled_post_types' ),
        ];

        foreach ( $settings as $key => $value ) {
            if ( null !== $value ) {
                atml_update_option( $key, $value );
            }
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * REST: Get public translation for frontend.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_public_translation( $request ) {
        $post_id = $request->get_param( 'post_id' );
        $lang = $request->get_param( 'lang' );

        if ( ! atml_option( 'translation_show_switcher', true ) ) {
            return new \WP_Error( 'disabled', __( 'Language switcher is disabled.', 'antimanual' ), [ 'status' => 403 ] );
        }

        $translation = atml_get_translation( $post_id, $lang );

        if ( ! $translation || 'completed' !== $translation->translation_status ) {
            return new \WP_Error( 'not_found', __( 'Translation not found.', 'antimanual' ), [ 'status' => 404 ] );
        }

        return rest_ensure_response( [
            'title'   => $translation->translated_title,
            'content' => $translation->translated_content,
            'excerpt' => $translation->translated_excerpt,
        ] );
    }

    /**
     * REST: Delete a specific translation.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_delete_translation( $request ) {
        $post_id = $request->get_param( 'post_id' );
        $language = $request->get_param( 'language' );

        if ( ! $post_id ) {
            return new \WP_Error( 'missing_params', __( 'Post ID is required.', 'antimanual' ), [ 'status' => 400 ] );
        }

        if ( $language ) {
            // Delete specific language translation
            $result = atml_delete_translation( $post_id, $language );
        } else {
            // Delete all translations for the post
            $result = atml_delete_post_translations( $post_id );
        }

        if ( false === $result ) {
            return new \WP_Error( 'delete_failed', __( 'Failed to delete translation.', 'antimanual' ), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'success' => true,
            'deleted' => $result,
        ] );
    }

    /**
     * REST: Retry failed translations.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_retry_failed( $request ) {
        $limit = $request->get_param( 'limit' ) ?: 10;

        global $wpdb;
        $table_name = $wpdb->prefix . 'atml_translations';

        // Get failed translations
        $failed = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, language_code FROM $table_name WHERE translation_status = 'failed' LIMIT %d",
                $limit
            )
        );

        $results = [
            'success' => [],
            'failed'  => [],
            'total'   => count( $failed ),
        ];

        foreach ( $failed as $item ) {
            $result = $this->translate_post( $item->post_id, $item->language_code );

            if ( is_wp_error( $result ) ) {
                $results['failed'][] = [
                    'post_id'  => $item->post_id,
                    'language' => $item->language_code,
                    'error'    => $result->get_error_message(),
                ];
            } else {
                $results['success'][] = $result;
            }
        }

        return rest_ensure_response( $results );
    }

    /**
     * REST: Get translation history.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_history( $request ) {
        $limit     = $request->get_param( 'limit' ) ?: 50;
        $status    = $request->get_param( 'status' );
        $post_type = $request->get_param( 'post_type' );
        $language  = $request->get_param( 'language' );

        global $wpdb;
        $table_name = $wpdb->prefix . 'atml_translations';

        $where_clauses = array();

        if ( $status && in_array( $status, array( 'completed', 'pending', 'failed' ), true ) ) {
            $where_clauses[] = $wpdb->prepare( 't.translation_status = %s', $status );
        }

        if ( $post_type && '' !== $post_type ) {
            $where_clauses[] = $wpdb->prepare( 'p.post_type = %s', $post_type );
        }

        if ( $language && '' !== $language ) {
            $where_clauses[] = $wpdb->prepare( 't.language_code = %s', $language );
        }

        $where = '';
        if ( ! empty( $where_clauses ) ) {
            $where = ' WHERE ' . implode( ' AND ', $where_clauses );
        }

        $translations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, p.post_title as original_title, p.post_type 
                FROM $table_name t
                LEFT JOIN {$wpdb->posts} p ON t.post_id = p.ID
                $where
                ORDER BY t.updated_at DESC
                LIMIT %d",
                $limit
            )
        );

        $history = [];
        foreach ( $translations as $t ) {
            $lang_name = self::$supported_languages[ $t->language_code ] ?? $t->language_code;
            $post_url = '';
            if ( ! empty( $t->post_id ) ) {
                $permalink = get_permalink( $t->post_id );
                if ( $permalink ) {
                    $post_url = add_query_arg( 'atml_lang', $t->language_code, $permalink );
                }
            }
            $history[] = [
                'id'              => $t->id,
                'post_id'         => $t->post_id,
                'post_title'      => $t->original_title,
                'post_type'       => $t->post_type,
                'post_url'        => $post_url,
                'language_code'   => $t->language_code,
                'language_name'   => $lang_name,
                'translated_title' => $t->translated_title,
                'status'          => $t->translation_status,
                'translated_at'   => $t->translated_at,
                'updated_at'      => $t->updated_at,
            ];
        }

        return rest_ensure_response( $history );
    }

    /**
     * REST: Get glossary terms.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_glossary( $request ) {
        $glossary = atml_option( 'translation_glossary', [] );
        $tone = atml_option( 'translation_tone', 'neutral' );
        $formality = atml_option( 'translation_formality', 'default' );

        return rest_ensure_response( [
            'glossary'   => $glossary,
            'tone'       => $tone,
            'formality'  => $formality,
        ] );
    }

    /**
     * REST: Save glossary terms.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_save_glossary( $request ) {
        $glossary = $request->get_param( 'glossary' );
        $tone = $request->get_param( 'tone' );
        $formality = $request->get_param( 'formality' );

        if ( null !== $glossary && is_array( $glossary ) ) {
            // Sanitize glossary entries
            $sanitized = [];
            foreach ( $glossary as $entry ) {
                if ( ! empty( $entry['term'] ) ) {
                    $sanitized[] = [
                        'term'        => sanitize_text_field( $entry['term'] ),
                        'translation' => sanitize_text_field( $entry['translation'] ?? '' ),
                        'do_not_translate' => ! empty( $entry['do_not_translate'] ),
                    ];
                }
            }
            atml_update_option( 'translation_glossary', $sanitized );
        }

        if ( null !== $tone ) {
            atml_update_option( 'translation_tone', sanitize_text_field( $tone ) );
        }

        if ( null !== $formality ) {
            atml_update_option( 'translation_formality', sanitize_text_field( $formality ) );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * REST: Preview translation without saving.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_preview_translation( $request ) {
        $text = $request->get_param( 'text' );
        $target_language = $request->get_param( 'target_language' );

        if ( empty( $text ) || empty( $target_language ) ) {
            return new \WP_Error( 'missing_params', __( 'Text and target language are required.', 'antimanual' ), [ 'status' => 400 ] );
        }

        $source_language = self::get_default_language();
        $result = $this->translate_text( $text, $source_language, $target_language );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( [
            'original'   => $text,
            'translated' => $result,
            'source'     => $source_language,
            'target'     => $target_language,
        ] );
    }

    /**
     * REST: Get available posts for translation.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function rest_get_available_posts( $request ) {
        $post_types_raw = $request->get_param( 'post_types' ) ?: [ 'post', 'page' ];
        $post_types = is_array( $post_types_raw ) ? $post_types_raw : array_filter( explode( ',', $post_types_raw ) );
        $search = $request->get_param( 'search' ) ?: '';
        $per_page = min( absint( $request->get_param( 'per_page' ) ?: 50 ), 100 );
        $page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

        global $wpdb;
        $table_name = $wpdb->prefix . 'atml_translations';
        atml_maybe_create_translations_table();

        $post_types_placeholder = implode( "','", array_map( 'esc_sql', $post_types ) );

        $where_search = '';
        if ( ! empty( $search ) ) {
            $where_search = $wpdb->prepare( " AND p.post_title LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
        }

        // Get total count
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('$post_types_placeholder')
            $where_search"
        );

        $offset = ( $page - 1 ) * $per_page;

        // Get posts with their translation status
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type, p.post_date
                FROM {$wpdb->posts} p
                WHERE p.post_status = 'publish'
                AND p.post_type IN ('$post_types_placeholder')
                $where_search
                ORDER BY p.post_date DESC
                LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        $enabled_languages = array_keys( self::get_enabled_languages() );

        $result = [];
        foreach ( $posts as $post ) {
            $translations = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT language_code, translation_status FROM $table_name WHERE post_id = %d",
                    $post->ID
                )
            );

            $translation_map = [];
            foreach ( $translations as $t ) {
                $translation_map[ $t->language_code ] = $t->translation_status;
            }

            // Determine if the post needs translation for any enabled language
            $needs_translation = false;
            foreach ( $enabled_languages as $lang ) {
                if ( ! isset( $translation_map[ $lang ] ) || $translation_map[ $lang ] !== 'completed' ) {
                    $needs_translation = true;
                    break;
                }
            }

            $post_type_obj = get_post_type_object( $post->post_type );

            $result[] = [
                'id'                => (int) $post->ID,
                'title'             => $post->post_title,
                'post_type'         => $post->post_type,
                'post_type_label'   => $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type,
                'date'              => $post->post_date,
                'translations'      => $translation_map,
                'needs_translation'  => $needs_translation,
            ];
        }

        return rest_ensure_response( [
            'posts'      => $result,
            'total'      => $total,
            'total_pages' => ceil( $total / $per_page ),
            'page'       => $page,
        ] );
    }

    /**
     * Auto-translate newly published posts when enabled.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post       Post object.
     * @return void
     */
    public function maybe_auto_translate( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( ! $post || empty( $post->ID ) ) {
            return;
        }

        if ( wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) ) {
            return;
        }

        if ( ! atml_option( 'translation_auto_new', false ) ) {
            return;
        }

        $enabled_post_types = atml_option( 'translation_post_types', [ 'post', 'page' ] );

        if ( ! in_array( $post->post_type, $enabled_post_types, true ) ) {
            return;
        }

        $enabled_languages = array_keys( self::get_enabled_languages() );

        if ( empty( $enabled_languages ) ) {
            return;
        }

        foreach ( $enabled_languages as $language ) {
            $this->translate_post( $post->ID, $language );
        }
    }

    /**
     * Translate a single post.
     *
     * @param int    $post_id         The post ID.
     * @param string $target_language The target language code.
     * @return array|\WP_Error Result array or WP_Error on failure.
     */
    public function translate_post( $post_id, $target_language ) {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return new \WP_Error( 'post_not_found', __( 'Post not found.', 'antimanual' ) );
        }

        $source_language = self::get_default_language();
        $target_name = self::$supported_languages[ $target_language ] ?? $target_language;

        // Translate title
        $translated_title = $this->translate_text( $post->post_title, $source_language, $target_language );
        if ( is_wp_error( $translated_title ) ) {
            atml_save_translation( $post_id, $target_language, '', '', '', 'failed' );
            return $translated_title;
        }

        // Translate content
        $translated_content = $this->translate_text( $post->post_content, $source_language, $target_language, true );
        if ( is_wp_error( $translated_content ) ) {
            atml_save_translation( $post_id, $target_language, $translated_title, '', '', 'failed' );
            return $translated_content;
        }

        // Translate excerpt if exists
        $translated_excerpt = '';
        if ( ! empty( $post->post_excerpt ) ) {
            $translated_excerpt = $this->translate_text( $post->post_excerpt, $source_language, $target_language );
            if ( is_wp_error( $translated_excerpt ) ) {
                $translated_excerpt = '';
            }
        }

        // Save translation
        $result = atml_save_translation(
            $post_id,
            $target_language,
            $translated_title,
            $translated_content,
            $translated_excerpt,
            'completed'
        );

        if ( false === $result ) {
            return new \WP_Error( 'save_failed', __( 'Failed to save translation.', 'antimanual' ) );
        }

        return [
            'post_id'  => $post_id,
            'language' => $target_language,
            'title'    => $translated_title,
            'content'  => $translated_content,
            'excerpt'  => $translated_excerpt,
            'status'   => 'completed',
        ];
    }

    /**
     * Translate text using AI provider.
     *
     * @param string $text            The text to translate.
     * @param string $source_language The source language code.
     * @param string $target_language The target language code.
     * @param bool   $preserve_html   Whether to preserve HTML structure.
     * @return string|\WP_Error Translated text or WP_Error on failure.
     */
    public function translate_text( $text, $source_language, $target_language, $preserve_html = false ) {
        if ( empty( trim( $text ) ) ) {
            return $text;
        }

        // Use the translation-specific provider, falling back to the global active provider.
        $provider = atml_option( 'translation_provider' );
        if ( empty( $provider ) || 'openai' === $provider ) {
            // If translation provider was never explicitly changed, respect the global provider.
            $global_provider = atml_option( 'last_active_provider' );
            if ( ! empty( $global_provider ) ) {
                $provider = $global_provider;
            }
        }

        $source_name = self::$supported_languages[ $source_language ] ?? $source_language;
        $target_name = self::$supported_languages[ $target_language ] ?? $target_language;

        $system_prompt = $this->get_translation_prompt( $source_name, $target_name, $preserve_html );

        $messages = [
            [
                'role'    => 'user',
                'content' => $text,
            ],
        ];

        if ( 'gemini' === $provider && atml_is_pro() ) {
            $gemini = new \Antimanual\Gemini();
            $response = $gemini->get_reply( $messages, '', $system_prompt );
        } else {
            $openai = new \Antimanual\OpenAI();
            $response = $openai->get_reply( $messages, '', $system_prompt );
        }

        if ( is_array( $response ) && isset( $response['error'] ) ) {
            return new \WP_Error( 'translation_failed', $response['error'] );
        }

        // OpenAI get_reply returns ['reply' => '...'], Gemini returns a plain string.
        if ( is_array( $response ) && isset( $response['reply'] ) ) {
            return $response['reply'];
        }

        return $response;
    }

    /**
     * Get the translation prompt.
     *
     * @param string $source_name   The source language name.
     * @param string $target_name   The target language name.
     * @param bool   $preserve_html Whether to preserve HTML.
     * @return string The system prompt.
     */
    private function get_translation_prompt( $source_name, $target_name, $preserve_html = false ) {
        $html_instruction = $preserve_html
            ? "IMPORTANT: Preserve all HTML tags, WordPress Gutenberg blocks (<!-- wp:* -->), shortcodes, and special formatting. Only translate the visible text content, not the markup."
            : "";

        // Get tone setting
        $tone = atml_option( 'translation_tone', 'neutral' );
        $tone_instruction = '';
        switch ( $tone ) {
            case 'formal':
                $tone_instruction = 'Use formal, professional language suitable for business communications.';
                break;
            case 'casual':
                $tone_instruction = 'Use casual, friendly language suitable for blogs and social media.';
                break;
            case 'technical':
                $tone_instruction = 'Use precise, technical language suitable for documentation and technical content.';
                break;
            case 'creative':
                $tone_instruction = 'Use creative, engaging language that captures the original style and flair.';
                break;
            default:
                $tone_instruction = 'Use a neutral, balanced tone.';
        }

        // Get formality setting
        $formality = atml_option( 'translation_formality', 'default' );
        $formality_instruction = '';
        if ( 'formal' === $formality ) {
            $formality_instruction = 'Use formal forms of address (e.g., "vous" in French, "Sie" in German, "usted" in Spanish).';
        } elseif ( 'informal' === $formality ) {
            $formality_instruction = 'Use informal forms of address (e.g., "tu" in French, "du" in German, "tú" in Spanish).';
        }

        // Get glossary terms
        $glossary = atml_option( 'translation_glossary', [] );
        $glossary_instruction = '';
        if ( ! empty( $glossary ) ) {
            $terms = [];
            foreach ( $glossary as $entry ) {
                if ( ! empty( $entry['do_not_translate'] ) ) {
                    $terms[] = "- \"{$entry['term']}\" → Keep as is (do not translate)";
                } elseif ( ! empty( $entry['translation'] ) ) {
                    $terms[] = "- \"{$entry['term']}\" → \"{$entry['translation']}\"";
                }
            }
            if ( ! empty( $terms ) ) {
                $glossary_instruction = "GLOSSARY - Use these specific translations:\n" . implode( "\n", $terms );
            }
        }

        $prompt = "You are a professional translator. Translate the following text from {$source_name} to {$target_name}.

Tone: {$tone_instruction}
{$formality_instruction}

Rules:
1. Maintain the original meaning and intent
2. Use natural, fluent {$target_name}
3. Keep proper nouns, brand names, and technical terms as appropriate
4. {$html_instruction}
5. Do not add any explanations or notes - only output the translated text
6. Preserve line breaks and paragraph structure

{$glossary_instruction}

Translate the following:";

        return $prompt;
    }

    /**
     * Bulk translate posts.
     *
     * @param array $post_types       Array of post types to translate.
     * @param array $target_languages Array of target language codes.
     * @param int   $batch_size       Number of posts to process per batch.
     * @param array $post_ids         Optional. Specific post IDs to translate.
     * @return array Results array.
     */
    public function bulk_translate( $post_types, $target_languages, $batch_size = 5, $post_ids = [] ) {
        $results = [
            'success'   => [],
            'failed'    => [],
            'remaining' => 0,
        ];

        foreach ( $target_languages as $language ) {
            if ( ! empty( $post_ids ) ) {
                // Translate only the specified posts
                $posts_to_translate = array_slice( array_map( 'intval', $post_ids ), 0, $batch_size );
                // Count remaining from the full list that haven't been processed
                $results['remaining'] += max( 0, count( $post_ids ) - $batch_size );
            } else {
                $posts_to_translate = atml_get_posts_needing_translation( $language, $post_types, $batch_size );
                $results['remaining'] += count( $posts_to_translate );
            }

            foreach ( $posts_to_translate as $post_id ) {
                $post = get_post( $post_id );
                $result = $this->translate_post( $post_id, $language );

                if ( is_wp_error( $result ) ) {
                    $results['failed'][] = [
                        'post_id'    => $post_id,
                        'post_title' => $post ? $post->post_title : '',
                        'language'   => $language,
                        'error'      => $result->get_error_message(),
                    ];
                } else {
                    $result['post_title'] = $post ? $post->post_title : '';
                    $results['success'][] = $result;
                    $results['remaining']--;
                }
            }
        }

        return $results;
    }

    /**
     * AJAX: Translate content.
     *
     * @return void
     */
    public function ajax_translate_content() {
        check_ajax_referer( 'atml_translation', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'antimanual' ) ] );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

        if ( ! $post_id || ! $language ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'antimanual' ) ] );
        }

        $result = $this->translate_post( $post_id, $language );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Bulk translate.
     *
     * @return void
     */
    public function ajax_bulk_translate() {
        check_ajax_referer( 'atml_translation', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'antimanual' ) ] );
        }

        $post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : [ 'post', 'page' ];
        $languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['languages'] ) ) : [];
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 5;

        if ( empty( $languages ) ) {
            wp_send_json_error( [ 'message' => __( 'No languages selected.', 'antimanual' ) ] );
        }

        $results = $this->bulk_translate( $post_types, $languages, $batch_size );

        wp_send_json_success( $results );
    }

    /**
     * AJAX: Get translation status.
     *
     * @return void
     */
    public function ajax_get_translation_status() {
        check_ajax_referer( 'atml_translation', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'antimanual' ) ] );
        }

        $stats = atml_get_translation_stats();
        wp_send_json_success( $stats );
    }
}

// Initialize the translation service
add_action( 'init', function() {
    TranslationService::instance();
} );
