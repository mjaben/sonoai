<?php
/**
 * Translations Database Table Handler
 *
 * Creates and manages the translations database table for storing
 * translated content across multiple languages.
 *
 * @package Antimanual_Pro
 * @since 2.2.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create the translations table on plugin activation.
 *
 * @return void
 */
function atml_create_translations_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'atml_translations';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        language_code varchar(10) NOT NULL,
        translated_title text,
        translated_content longtext,
        translated_excerpt text,
        translation_status varchar(20) DEFAULT 'pending',
        translated_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY post_language (post_id, language_code),
        KEY language_code (language_code),
        KEY translation_status (translation_status),
        KEY post_id (post_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Store the database version
    update_option( 'atml_translations_db_version', '1.0.0' );
}

/**
 * Check if the translations table exists.
 *
 * @return bool
 */
function atml_translations_table_exists() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'atml_translations';

    $found = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
    );

    return $found === $table_name;
}

/**
 * Create the translations table if it does not exist.
 *
 * @return void
 */
function atml_maybe_create_translations_table() {
    if ( ! atml_translations_table_exists() ) {
        atml_create_translations_table();
    }
}

/**
 * Get a translation for a specific post and language.
 *
 * @param int    $post_id       The post ID.
 * @param string $language_code The language code (e.g., 'es', 'fr', 'de').
 * @return object|null The translation object or null if not found.
 */
function atml_get_translation( $post_id, $language_code ) {
    global $wpdb;

    atml_maybe_create_translations_table();

    $table_name = $wpdb->prefix . 'atml_translations';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE post_id = %d AND language_code = %s",
            $post_id,
            $language_code
        )
    );
}

/**
 * Get all translations for a specific post.
 *
 * @param int $post_id The post ID.
 * @return array Array of translation objects.
 */
function atml_get_post_translations( $post_id ) {
    global $wpdb;

    atml_maybe_create_translations_table();

    $table_name = $wpdb->prefix . 'atml_translations';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE post_id = %d",
            $post_id
        )
    );
}

/**
 * Save or update a translation.
 *
 * @param int    $post_id            The post ID.
 * @param string $language_code      The language code.
 * @param string $translated_title   The translated title.
 * @param string $translated_content The translated content.
 * @param string $translated_excerpt The translated excerpt.
 * @param string $status             The translation status.
 * @return int|false The number of rows affected or false on error.
 */
function atml_save_translation( $post_id, $language_code, $translated_title, $translated_content, $translated_excerpt = '', $status = 'completed' ) {
    global $wpdb;

    atml_maybe_create_translations_table();

    $table_name = $wpdb->prefix . 'atml_translations';

    $existing = atml_get_translation( $post_id, $language_code );

    if ( $existing ) {
        return $wpdb->update(
            $table_name,
            [
                'translated_title'   => $translated_title,
                'translated_content' => $translated_content,
                'translated_excerpt' => $translated_excerpt,
                'translation_status' => $status,
                'translated_at'      => current_time( 'mysql' ),
            ],
            [
                'post_id'       => $post_id,
                'language_code' => $language_code,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );
    }

    return $wpdb->insert(
        $table_name,
        [
            'post_id'            => $post_id,
            'language_code'      => $language_code,
            'translated_title'   => $translated_title,
            'translated_content' => $translated_content,
            'translated_excerpt' => $translated_excerpt,
            'translation_status' => $status,
            'translated_at'      => current_time( 'mysql' ),
        ],
        [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
    );
}

/**
 * Delete a translation.
 *
 * @param int    $post_id       The post ID.
 * @param string $language_code The language code.
 * @return int|false The number of rows deleted or false on error.
 */
function atml_delete_translation( $post_id, $language_code ) {
    global $wpdb;

    atml_maybe_create_translations_table();

    $table_name = $wpdb->prefix . 'atml_translations';

    return $wpdb->delete(
        $table_name,
        [
            'post_id'       => $post_id,
            'language_code' => $language_code,
        ],
        [ '%d', '%s' ]
    );
}

/**
 * Delete all translations for a post.
 *
 * @param int $post_id The post ID.
 * @return int|false The number of rows deleted or false on error.
 */
function atml_delete_post_translations( $post_id ) {
    global $wpdb;

    atml_maybe_create_translations_table();

    $table_name = $wpdb->prefix . 'atml_translations';

    return $wpdb->delete(
        $table_name,
        [ 'post_id' => $post_id ],
        [ '%d' ]
    );
}

/**
 * Get translation statistics.
 *
 * @return array Array of statistics.
 */
function atml_get_translation_stats() {
    global $wpdb;

    atml_maybe_create_translations_table();

    $table_name = $wpdb->prefix . 'atml_translations';

    $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
    $completed = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE translation_status = 'completed'" );
    $pending = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE translation_status = 'pending'" );
    $failed = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE translation_status = 'failed'" );

    $by_language = $wpdb->get_results(
        "SELECT language_code, COUNT(*) as count FROM $table_name GROUP BY language_code"
    );

    return [
        'total'       => (int) $total,
        'completed'   => (int) $completed,
        'pending'     => (int) $pending,
        'failed'      => (int) $failed,
        'by_language' => $by_language,
    ];
}

/**
 * Get posts that need translation for a specific language.
 *
 * @param string $language_code The language code.
 * @param array  $post_types    Array of post types to check.
 * @param int    $limit         Maximum number of posts to return.
 * @return array Array of post IDs that need translation.
 */
function atml_get_posts_needing_translation( $language_code, $post_types = [ 'post', 'page' ], $limit = 100 ) {
    global $wpdb;

    atml_maybe_create_translations_table();

    $table_name = $wpdb->prefix . 'atml_translations';
    $post_types_placeholder = implode( "','", array_map( 'esc_sql', $post_types ) );

    $query = $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
        LEFT JOIN $table_name t ON p.ID = t.post_id AND t.language_code = %s
        WHERE p.post_status = 'publish'
        AND p.post_type IN ('$post_types_placeholder')
        AND (t.id IS NULL OR t.translation_status = 'pending' OR t.translation_status = 'failed')
        LIMIT %d",
        $language_code,
        $limit
    );

    return $wpdb->get_col( $query );
}

// Hook to delete translations when a post is deleted
add_action( 'before_delete_post', 'atml_delete_post_translations' );
