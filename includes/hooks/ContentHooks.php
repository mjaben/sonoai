<?php
/**
 * SonoAI — Content hooks.
 *
 * Automatically generates and stores embeddings when eligible WordPress posts
 * are published or updated.
 *
 * Uses WP-Cron to run embedding in the background so saves aren't blocked.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ContentHooks {

    private static ?ContentHooks $instance = null;

    public static function instance(): ContentHooks {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook into post status transitions so we catch both create and update.
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 20, 3 );

        // Hook into post deletion to remove stale embeddings.
        add_action( 'before_delete_post', [ $this, 'on_post_delete' ] );

        // WP-Cron event handler.
        add_action( 'sonoai_embed_post_async', [ $this, 'do_embed_post' ], 10, 2 );
    }

    // ── Status transition ─────────────────────────────────────────────────────

    /**
     * Check if a post type is publicly queryable and supports the editor.
     *
     * @param string $post_type The post type to check.
     * @return bool
     */
    private function is_eligible_post_type( string $post_type ): bool {
        $pt_obj = get_post_type_object( $post_type );
        return $pt_obj && $pt_obj->public && post_type_supports( $post_type, 'editor' );
    }

    /**
     * Fired whenever a post's status changes.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post       The post object.
     */
    public function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        // Only embed published, watched CPTs.
        if ( 'publish' !== $new_status ) {
            return;
        }

        if ( ! $this->is_eligible_post_type( $post->post_type ) ) {
            return;
        }

        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        // Skip if API key isn't configured — no point queuing a cron that will fail.
        if ( ! AIProvider::has_api_key() ) {
            return;
        }

        // Schedule background embedding (avoid duplicate events).
        if ( ! wp_next_scheduled( 'sonoai_embed_post_async', [ $post->ID, $post->post_type ] ) ) {
            wp_schedule_single_event( time() + 5, 'sonoai_embed_post_async', [ $post->ID, $post->post_type ] );
            spawn_cron();
        }
    }

    // ── Background embedding ──────────────────────────────────────────────────

    /**
     * WP-Cron callback: generate and store embeddings for a post.
     *
     * @param int    $post_id   WP post ID.
     * @param string $post_type CPT slug.
     */
    public function do_embed_post( int $post_id, string $post_type ): void {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }

        $content = sonoai_clean_content( $post->post_content );

        // Include the post title in the embedding for better retrieval.
        $text = $post->post_title . "\n\n" . $content;

        if ( empty( trim( $text ) ) ) {
            return;
        }

        $result = Embedding::insert( $post_id, $post_type, $text );

        if ( is_wp_error( $result ) ) {
            error_log( '[SonoAI] Embedding failed for post ' . $post_id . ': ' . $result->get_error_message() );
        }
    }

    // ── Deletion ──────────────────────────────────────────────────────────────

    /**
     * Remove embeddings when a post is permanently deleted.
     *
     * @param int $post_id
     */
    public function on_post_delete( int $post_id ): void {
        $post = get_post( $post_id );
        if ( $post && $this->is_eligible_post_type( $post->post_type ) ) {
            Embedding::delete_by_post( $post_id );
        }
    }
}
