<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\AIResponseCleaner;
use Antimanual\PostGenerator;
use Antimanual\PostPromptBuilder;

/**
 * Auto Posting Manager Class
 *
 * Manages scheduled auto-posting tasks, including creation, updates,
 * and execution of AI-generated content.
 *
 * @package Antimanual
 */
class AutoPosting {
    public static $instance  = null;
    public static $post_type = 'atml_auto_posting';
    public static $meta_data = '_atml_data';

    /**
     * Transient keys for the lightweight check mechanism.
     */
    private static $last_check_key    = 'atml_auto_posting_last_check';
    private static $running_lock_key  = 'atml_auto_posting_running';
    private static $check_interval    = 60; // Check every 60 seconds

    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
        // Use lightweight init-based trigger instead of WP-Cron
        add_action( 'init', [ $this, 'maybe_run_auto_posting' ], 20 );
    }

    /**
     * Lightweight check that runs on every WordPress request.
     * Only executes auto-posting logic when sufficient time has passed.
     *
     * This replaces WP-Cron dependency - runs on page views, API calls,
     * admin requests, AJAX, Heartbeat, and even WP-Cron spawns.
     */
    public function maybe_run_auto_posting() {
        // Quick bail: No active auto-postings? Skip entirely.
        $postings_count = self::get_postings_count();
        if ( $postings_count <= 0 ) {
            return;
        }

        // LIGHTWEIGHT CHECK #1: Has enough time passed since last check?
        $last_check = get_transient( self::$last_check_key );
        $now        = time();

        if ( false !== $last_check && ( $now - intval( $last_check ) ) < self::$check_interval ) {
            return; // Too soon, skip this request
        }

        // LIGHTWEIGHT CHECK #2: Is another process already running?
        if ( get_transient( self::$running_lock_key ) ) {
            return; // Another process is running, skip
        }

        // Acquire lock (5-minute max to prevent deadlocks)
        set_transient( self::$running_lock_key, true, 300 );

        // Update last check timestamp
        set_transient( self::$last_check_key, $now, DAY_IN_SECONDS );

        try {
            // Run the actual auto-posting check
            $this->run_scheduled_auto_posting();
        } finally {
            // Always release lock, even if an error occurs
            delete_transient( self::$running_lock_key );
        }
    }

    /**
     * Get the timestamp of the last auto-posting check.
     *
     * @return int|null Unix timestamp or null if never checked.
     */
    public static function get_last_check_time() {
        $last_check = get_transient( self::$last_check_key );
        return false !== $last_check ? intval( $last_check ) : null;
    }

    /**
     * Check if auto-posting is currently running.
     *
     * @return bool True if running, false otherwise.
     */
    public static function is_running() {
        return (bool) get_transient( self::$running_lock_key );
    }

    /**
     * Get the singleton instance.
     *
     * @return AutoPosting The singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register the Auto Posting custom post type.
     */
    public static function register() {
		$labels = [
			'name'          => __( 'Auto Postings', 'antimanual' ),
			'singular_name' => __( 'Auto Posting', 'antimanual' ),
		];

		$args = [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => false,
			'show_in_menu' => false,
			'supports'     => [ 'custom-fields' ],
		];

		register_post_type( self::$post_type, $args );
	}

    /**
     * Get total number of active auto-postings.
     *
     * @return int Count of published auto-posting configurations.
     */
    public static function get_postings_count() {
        $query = wp_count_posts( self::$post_type );

        return $query->publish;
    }

    /**
     * List auto-posting configurations.
     *
     * @param array $populate Fields to populate (e.g., 'author', 'posts').
     * @param int   $offset   Query offset.
     * @param int   $limit    Query limit.
     * @return array|\WP_Error Array of auto-posting data or WP_Error.
     */
    public static function list( $populate = [], $offset = 0, $limit = -1 ) {
        $args = [
            'post_type'      => self::$post_type,
            'posts_per_page' => $limit,
            'offset'         => $offset,
        ];

        $query = new \WP_Query( $args );

        if ( is_wp_error( $query ) ) {
            return new \WP_Error( 'query_error', $query->get_error_message() );
        }

        $postings = [];

        update_meta_cache( 'post', wp_list_pluck( $query->posts, 'ID' ) );

        foreach ( $query->posts as $post ) {
            $postings[] = self::get( $post->ID, $populate );
        }

        return $postings;
    }

    public static function get( $auto_posting_id, $populate = [] ) {
        $data = get_post_meta( $auto_posting_id, self::$meta_data, true );

        if ( ! $data || empty( $data ) ) {
            return new \WP_Error( 'not_found', __( 'Auto Posting not found.', 'antimanual' ) );
        }

        if ( in_array( 'attachments', $populate, true ) ) {
            foreach ( $data['attachments'] as $i => $attachment_id ) {
                $attachment = [
                    'id' => $attachment_id,
                    'title' => get_the_title( $attachment_id ),
                    'url'   => wp_get_attachment_url( $attachment_id ),
                ];

                $data['attachments'][ $i ] = $attachment;
            }
        }

        if ( in_array( 'author', $populate, true ) ) {
            $data['author'] = [ 'id' => intval( $data['author'] ?? 1 ) ];

            $author = get_user_by( 'ID', $data['author']['id'] );

            if ( $author ) {
                $data['author']['username']     = $author->user_login;
                $data['author']['email']        = $author->user_email;
                $data['author']['display_name'] = $author->display_name;
                $data['author']['url']          = get_author_posts_url( $author->ID );
            }
        }

        if ( in_array( 'posts', $populate, true ) ) {
            $generated_posts = $data['posts'] ?? [];
            $posts           = [];

            foreach ( $generated_posts as $generated_post ) {
                $post_id  = $generated_post['post_id'] ?? 0;
                $run_date = $generated_post['run_date'] ?? '';
                $post     = get_post( $post_id );

                if ( $post && ! is_wp_error( $post ) ) {
                    // Get auto-post meta for status tracking
                    $auto_post_status = get_post_meta( $post_id, self::$auto_post_status_key, true );
                    $auto_post_topic  = get_post_meta( $post_id, self::$auto_post_topic_key, true );
                    $auto_post_error  = get_post_meta( $post_id, self::$auto_post_error_key, true );

                    $posts[] = [
                        'id'               => $post->ID,
                        'run_date'         => $run_date,
                        'title'            => $post->post_title,
                        'content'          => $post->post_content,
                        'status'           => $post->post_status,
                        'type'             => $post->post_type,
                        'created_at'       => $post->post_date_gmt,
                        'view_link'        => get_permalink( $post->ID ),
                        'edit_link'        => get_edit_post_link( $post->ID, '&' ),
                        // Auto-post specific fields
                        'auto_post_status' => $auto_post_status ?: self::AUTO_POST_STATUS_COMPLETED, // Default for old posts
                        'auto_post_topic'  => $auto_post_topic ?: '',
                        'auto_post_error'  => $auto_post_error ?: '',
                    ];
                }
            }

            $data['posts'] = $posts;
        }

        return $data;
    }

    public static function create( $payload ) {
        if ( ! atml_is_pro() && atml_is_auto_posting_limit_exceeded() ) {
            return new \WP_Error(
                'limit_exceeded',
                __( 'You have reached the maximum limit of 2 auto postings. Upgrade to Pro for unlimited auto postings.', 'antimanual' )
            );
        }

        $topics = $payload['topics'] ?? [];
        $topics = is_array( $topics ) ? array_map( 'sanitize_text_field', $topics ) : [];
        $topics = array_filter( $topics, fn( $topic ) => ! empty( $topic ) );

        $tone            = $payload['tone'] ?? $GLOBALS['ATML_STORE']['tones']['blog-style'];
        $language        = $payload['language'] ?? 'English';
        $length_range    = PostPromptBuilder::normalize_word_length_range(
            intval( $payload['min_length'] ?? 500 ),
            intval( $payload['max_length'] ?? 1000 ),
            500,
            1000
        );
        $min_length      = $length_range['min'];
        $max_length      = $length_range['max'];
        $slug_language   = $payload['slug_language'] ?? 'English';
        $slug_max_length = $payload['slug_max_length'] ?? 50;
        $author          = $payload['author'] ?? 1;
        $type            = $payload['type'] ?? 'post';
        $status          = $payload['status'] ?? 'draft';
        $weekdays        = $payload['weekdays'] ?? [];
        $times           = $payload['times'] ?? [];
        $exp_date        = $payload['exp_date'] ?? null;
        $attachments     = $payload['attachments'] ?? [];
        $include_content_images = filter_var( $payload['include_content_images'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $include_image_caption  = filter_var( $payload['include_image_caption'] ?? false, FILTER_VALIDATE_BOOLEAN );

        // Content & SEO extras.
        $pov                      = sanitize_text_field( $payload['pov'] ?? 'third_person' );
        $target_audience          = sanitize_text_field( $payload['target_audience'] ?? 'general' );
        $generate_excerpt         = filter_var( $payload['generate_excerpt'] ?? true, FILTER_VALIDATE_BOOLEAN );
        $excerpt_length           = intval( $payload['excerpt_length'] ?? 50 );
        $taxonomy_settings        = is_array( $payload['taxonomy_settings'] ?? false ) ? $payload['taxonomy_settings'] : [];
        $focus_keywords           = is_array( $payload['focus_keywords'] ?? false ) ? array_map( 'sanitize_text_field', $payload['focus_keywords'] ) : [];
        $generate_meta_description = filter_var( $payload['generate_meta_description'] ?? true, FILTER_VALIDATE_BOOLEAN );
        $optimize_for_seo          = filter_var( $payload['optimize_for_seo'] ?? true, FILTER_VALIDATE_BOOLEAN );

        // Advanced feature flags.
        $generate_featured_image  = filter_var( $payload['generate_featured_image']  ?? false, FILTER_VALIDATE_BOOLEAN );
        $suggest_internal_links   = filter_var( $payload['suggest_internal_links']   ?? true, FILTER_VALIDATE_BOOLEAN );
        $include_toc              = filter_var( $payload['include_toc']              ?? false, FILTER_VALIDATE_BOOLEAN );
        $include_faq              = filter_var( $payload['include_faq']              ?? false, FILTER_VALIDATE_BOOLEAN );
        $faq_block_type           = in_array( $payload['faq_block_type'] ?? 'default', [ 'default', 'advanced' ], true ) ? $payload['faq_block_type'] : 'default';
        $include_conclusion       = filter_var( $payload['include_conclusion']       ?? true, FILTER_VALIDATE_BOOLEAN );

        if ( empty( $topics ) ) {
            return new \WP_Error( 'empty_topics', __( 'Topics cannot be empty.', 'antimanual' ) );
        }

        $invalid_days = array_filter( $weekdays, fn( $day ) => $day < 0 || $day > 6 );
        if ( ! empty( $invalid_days ) ) {
            return new \WP_Error( 'invalid_weekdays', __( 'Weekdays are invalid', 'antimanual' ) );
        }

        foreach ( $times as $i => $time ) {
            $time        = (array) $time;
            $times[ $i ] = $time;

            $hour = intval( $time['hour'] ?? -1 );
            $min  = intval( $time['minute'] ?? -1 );

            if ( $hour < 0 || $hour > 23 || $min < 0 || $min > 59 ) {
                return new \WP_Error( 'invalid_times', __( 'Times are invalid', 'antimanual' ) );
            }
        }

        $auto_posting_id = wp_insert_post( [
            'post_type'   => self::$post_type,
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $auto_posting_id ) ) {
            return new \WP_Error( 'insert_post_error', $auto_posting_id->get_error_message() );
        }

        $auto_posting_data = [
            'is_active'                => true,
            'post_id'                  => (int)    $auto_posting_id,
            'tone'                     => (string) $tone,
            'language'                 => (string) $language,
            'min_length'               => (int)    $min_length,
            'max_length'               => (int)    $max_length,
            'slug_language'            => (string) $slug_language,
            'slug_max_length'          => (int)    $slug_max_length,
            'topics'                   => (array)  $topics,
            'status'                   => (string) $status,
            'type'                     => (string) $type,
            'weekdays'                 => (array)  $weekdays,
            'times'                    => (array)  $times,
            'author'                   => (int)    $author,
            'exp_date'                 => (int)    $exp_date,
            'attachments'              => (array)  $attachments,
            'include_content_images'   => (bool)   $include_content_images,
            'include_image_caption'    => (bool)   $include_image_caption,
            // Extra fields
            'pov'                      => (string) $pov,
            'target_audience'          => (string) $target_audience,
            'generate_excerpt'         => (bool)   $generate_excerpt,
            'excerpt_length'           => (int)    $excerpt_length,
            'taxonomy_settings'        => (array)  $taxonomy_settings,
            'focus_keywords'           => (array)  $focus_keywords,
            'generate_meta_description' => (bool)  $generate_meta_description,
            'optimize_for_seo'         => (bool)   $optimize_for_seo,
            'generate_featured_image'  => (bool)   $generate_featured_image,
            'suggest_internal_links'   => (bool)   $suggest_internal_links,
            'include_toc'              => (bool)   $include_toc,
            'include_faq'              => (bool)   $include_faq,
            'faq_block_type'           => (string) $faq_block_type,
            'include_conclusion'       => (bool)   $include_conclusion,
        ];

        update_post_meta( $auto_posting_id, self::$meta_data, $auto_posting_data );

        return self::get( $auto_posting_id );
    }

    public static function update( int $auto_posting_id, $payload ) {
        if ( empty( $auto_posting_id ) ) {
            return new \WP_Error( 'auto_posting_id_required', __( 'Auto Posting ID is required.', 'antimanual' ) );
        }

        $auto_posting = self::get( $auto_posting_id );

        if ( is_wp_error( $auto_posting ) ) {
            return $auto_posting;
        }

        // Toggle active state.
        if ( isset( $payload['is_active'] ) ) {
            $auto_posting['is_active'] = boolval( $payload['is_active'] );
        }

        // Content settings.
        if ( isset( $payload['topics'] ) ) {
            $topics = is_array( $payload['topics'] ) ? $payload['topics'] : [];
            $auto_posting['topics'] = array_values( array_filter( array_map( 'sanitize_text_field', $topics ) ) );
        }

        if ( isset( $payload['tone'] ) ) {
            $auto_posting['tone'] = sanitize_text_field( $payload['tone'] );
        }

        if ( isset( $payload['language'] ) ) {
            $auto_posting['language'] = sanitize_text_field( $payload['language'] );
        }

        if ( isset( $payload['min_length'] ) ) {
            $auto_posting['min_length'] = intval( $payload['min_length'] );
        }

        if ( isset( $payload['max_length'] ) ) {
            $auto_posting['max_length'] = intval( $payload['max_length'] );
        }

        $length_range = PostPromptBuilder::normalize_word_length_range(
            intval( $auto_posting['min_length'] ?? 800 ),
            intval( $auto_posting['max_length'] ?? 1200 )
        );
        $auto_posting['min_length'] = $length_range['min'];
        $auto_posting['max_length'] = $length_range['max'];

        if ( isset( $payload['slug_language'] ) ) {
            $auto_posting['slug_language'] = sanitize_text_field( $payload['slug_language'] );
        }

        if ( isset( $payload['slug_max_length'] ) ) {
            $auto_posting['slug_max_length'] = intval( $payload['slug_max_length'] );
        }

        // Post settings.
        if ( isset( $payload['type'] ) ) {
            $auto_posting['type'] = sanitize_key( $payload['type'] );
        }

        if ( isset( $payload['status'] ) ) {
            $auto_posting['status'] = sanitize_key( $payload['status'] );
        }

        if ( isset( $payload['author'] ) ) {
            $auto_posting['author'] = intval( $payload['author'] );
        }

        // Schedule settings.
        if ( isset( $payload['weekdays'] ) ) {
            $weekdays = is_array( $payload['weekdays'] ) ? $payload['weekdays'] : [];
            $auto_posting['weekdays'] = array_map( 'intval', $weekdays );
        }

        if ( isset( $payload['times'] ) ) {
            $times = is_array( $payload['times'] ) ? $payload['times'] : [];
            $auto_posting['times'] = array_map( function( $t ) {
                $t = (array) $t;
                return [
                    'hour'   => intval( $t['hour'] ?? 0 ),
                    'minute' => intval( $t['minute'] ?? 0 ),
                ];
            }, $times );
        }

        if ( isset( $payload['exp_date'] ) ) {
            $auto_posting['exp_date'] = intval( $payload['exp_date'] );
        }

        // Audience & viewpoint.
        if ( isset( $payload['pov'] ) ) {
            $auto_posting['pov'] = sanitize_text_field( $payload['pov'] );
        }

        if ( isset( $payload['target_audience'] ) ) {
            $auto_posting['target_audience'] = sanitize_text_field( $payload['target_audience'] );
        }

        // Post generation extras.
        if ( isset( $payload['generate_excerpt'] ) ) {
            $auto_posting['generate_excerpt'] = filter_var( $payload['generate_excerpt'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['excerpt_length'] ) ) {
            $auto_posting['excerpt_length'] = intval( $payload['excerpt_length'] );
        }

        if ( isset( $payload['taxonomy_settings'] ) ) {
            $auto_posting['taxonomy_settings'] = is_array( $payload['taxonomy_settings'] ) ? $payload['taxonomy_settings'] : [];
        }

        // SEO settings.
        if ( isset( $payload['focus_keywords'] ) ) {
            $keywords = is_array( $payload['focus_keywords'] ) ? $payload['focus_keywords'] : [];
            $auto_posting['focus_keywords'] = array_map( 'sanitize_text_field', $keywords );
        }

        if ( isset( $payload['generate_meta_description'] ) ) {
            $auto_posting['generate_meta_description'] = filter_var( $payload['generate_meta_description'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['optimize_for_seo'] ) ) {
            $auto_posting['optimize_for_seo'] = filter_var( $payload['optimize_for_seo'], FILTER_VALIDATE_BOOLEAN );
        }

        // Advanced feature flags.
        if ( isset( $payload['generate_featured_image'] ) ) {
            $auto_posting['generate_featured_image'] = filter_var( $payload['generate_featured_image'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['suggest_internal_links'] ) ) {
            $auto_posting['suggest_internal_links'] = filter_var( $payload['suggest_internal_links'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['include_toc'] ) ) {
            $auto_posting['include_toc'] = filter_var( $payload['include_toc'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['include_faq'] ) ) {
            $auto_posting['include_faq'] = filter_var( $payload['include_faq'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['faq_block_type'] ) ) {
            $auto_posting['faq_block_type'] = in_array( $payload['faq_block_type'], [ 'default', 'advanced' ], true ) ? $payload['faq_block_type'] : 'default';
        }

        if ( isset( $payload['include_conclusion'] ) ) {
            $auto_posting['include_conclusion'] = filter_var( $payload['include_conclusion'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['include_content_images'] ) ) {
            $auto_posting['include_content_images'] = filter_var( $payload['include_content_images'], FILTER_VALIDATE_BOOLEAN );
        }

        if ( isset( $payload['include_image_caption'] ) ) {
            $auto_posting['include_image_caption'] = filter_var( $payload['include_image_caption'], FILTER_VALIDATE_BOOLEAN );
        }

        update_post_meta( $auto_posting_id, self::$meta_data, $auto_posting );

        return $auto_posting;
    }

    public static function delete( $auto_posting_id ) {
        $deleted = wp_delete_post( $auto_posting_id, true );

        if ( ! $deleted ) {
            return new \WP_Error( 'delete_failed', __( 'Failed to delete Auto Posting.', 'antimanual' ) );
        }

        return true;
    }

    /**
     * Meta keys for tracking auto-generated post status.
     */
    public static $auto_post_status_key     = '_atml_auto_post_status';
    public static $auto_posting_id_key      = '_atml_auto_posting_id';
    public static $auto_post_topic_key      = '_atml_auto_post_topic';
    public static $auto_post_error_key      = '_atml_auto_post_error';
    public static $auto_post_scheduled_key  = '_atml_auto_post_scheduled';

    /**
     * Auto-post status values.
     */
    const AUTO_POST_STATUS_PENDING   = 'pending';
    const AUTO_POST_STATUS_COMPLETED = 'completed';
    const AUTO_POST_STATUS_FAILED    = 'failed';

    /**
     * Create a post for an auto-posting schedule.
     * 
     * New approach: Creates a placeholder post immediately, then tries to generate
     * AI content. This ensures users can see every execution attempt and its result.
     *
     * @param int    $auto_posting_id The auto-posting configuration ID.
     * @param string $run_date        The scheduled run date (for duplicate prevention).
     * @param int    $existing_post_id Optional. If provided, retry generating for this existing post.
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public static function create_post( $auto_posting_id, string $run_date = "", int $existing_post_id = 0 ) {
        $auto_posting = self::get( $auto_posting_id );

        if ( is_wp_error( $auto_posting ) || empty( $auto_posting ) ) {
            return new \WP_Error( 'not_found', __( 'Auto Posting not found.', 'antimanual' ) );
        }

        // Check for duplicate only if not retrying an existing post
        if ( empty( $existing_post_id ) && ! empty( $run_date ) ) {
            if ( isset( $auto_posting['posts'] ) && is_array( $auto_posting['posts'] ) ) {
                foreach ( $auto_posting['posts'] as $post ) {
                    if ( ! isset( $post['run_date'] ) ) {
                        continue;
                    }

                    if ( $post['run_date'] === $run_date ) {
                        return new \WP_Error( 'already_posted', __( 'Post already created for this scheduled run.', 'antimanual' ) );
                    }
                }
            }
        }

        $topics               = $auto_posting['topics'] ?? [];
        $tone                 = $auto_posting['tone'] ?? $GLOBALS['ATML_STORE']['tones']['blog-style'];
        $language             = $auto_posting['language'] ?? 'English';
        $length_range         = PostPromptBuilder::normalize_word_length_range(
            intval( $auto_posting['min_length'] ?? 800 ),
            intval( $auto_posting['max_length'] ?? 1200 )
        );
        $min_length           = $length_range['min'];
        $max_length           = $length_range['max'];
        $slug_language        = $auto_posting['slug_language'] ?? 'English';
        $slug_max_length      = $auto_posting['slug_max_length'] ?? 50;
        $author               = $auto_posting['author']['id'] ?? 1;
        $type                 = $auto_posting['type'] ?? 'post';
        $target_status        = $auto_posting['status'] ?? 'draft';
        $attachments          = $auto_posting['attachments'] ?? [];
        $include_content_images = (bool) ( $auto_posting['include_content_images'] ?? false );
        $include_image_caption  = (bool) ( $auto_posting['include_image_caption'] ?? false );

        // Advanced feature flags.
        $pov                      = $auto_posting['pov']                      ?? 'third_person';
        $target_audience          = $auto_posting['target_audience']          ?? 'general';
        $focus_keywords           = $auto_posting['focus_keywords']           ?? [];
        $optimize_for_seo         = (bool) ( $auto_posting['optimize_for_seo']         ?? true );
        $generate_meta_description = (bool) ( $auto_posting['generate_meta_description'] ?? true );
        $include_toc              = (bool) ( $auto_posting['include_toc']              ?? false );
        $include_faq              = (bool) ( $auto_posting['include_faq']              ?? false );
        $faq_block_type           = $auto_posting['faq_block_type']           ?? 'default';
        $include_conclusion       = (bool) ( $auto_posting['include_conclusion']       ?? true );
        $suggest_internal_links   = (bool) ( $auto_posting['suggest_internal_links']   ?? true );
        $generate_featured_image  = (bool) ( $auto_posting['generate_featured_image']  ?? false );

        $advanced_options = [
            'pov'                       => $pov,
            'target_audience'           => $target_audience,
            'focus_keywords'            => is_array( $focus_keywords ) ? $focus_keywords : [],
            'optimize_for_seo'          => $optimize_for_seo,
            'generate_meta_description' => $generate_meta_description,
            'include_toc'               => $include_toc,
            'include_faq'               => $include_faq,
            'faq_block_type'            => $faq_block_type,
            'include_conclusion'        => $include_conclusion,
            'suggest_internal_links'    => $suggest_internal_links,
            'generate_featured_image'   => $generate_featured_image,
        ];

        $topic = $topics[ array_rand( $topics ) ] ?? '';

        // If retrying, get the post and its original topic
        $post_id = $existing_post_id;

        if ( $existing_post_id > 0 ) {
            // Retry mode: use the existing post's topic
            $saved_topic = get_post_meta( $existing_post_id, self::$auto_post_topic_key, true );
            if ( ! empty( $saved_topic ) ) {
                $topic = $saved_topic;
            }

            // Update post to pending status
            wp_update_post([
                'ID'           => $existing_post_id,
                'post_status'  => 'private',
                'post_title'   => $topic,
                'post_content' => '',
            ]);

            update_post_meta( $existing_post_id, self::$auto_post_status_key, self::AUTO_POST_STATUS_PENDING );
            delete_post_meta( $existing_post_id, self::$auto_post_error_key );
        } else {
            $placeholder_data = [
                'post_title'    => $topic,
                'post_content'  => '',
                'post_status'   => 'private', // Private until AI succeeds
                'post_type'     => $type,
                'post_author'   => $author,
                'post_date'     => current_time( 'mysql' ),
                'post_date_gmt' => current_time( 'mysql', 1 ),
                'meta_input'    => [
                    self::$auto_post_status_key    => self::AUTO_POST_STATUS_PENDING,
                    self::$auto_posting_id_key     => $auto_posting_id,
                    self::$auto_post_topic_key     => $topic,
                    self::$auto_post_scheduled_key => $run_date,
                ],
            ];

            $post_id = wp_insert_post( $placeholder_data );

            if ( is_wp_error( $post_id ) ) {
                return new \WP_Error( 'insert_failed', __( 'Failed to create placeholder post.', 'antimanual' ) );
            }

            // Record this post in the auto-posting's posts array
            $posts   = $auto_posting['posts'] ?? [];
            $posts[] = [
                'post_id'  => $post_id,
                'run_date' => $run_date,
            ];
            $auto_posting['posts'] = $posts;
            update_post_meta( $auto_posting_id, self::$meta_data, $auto_posting );
        }

        // Now try to generate content with AI
        $ai_result = self::generate_ai_content( $topic, $tone, $language, $min_length, $max_length, $slug_language, $slug_max_length, $attachments, $advanced_options );

        if ( is_wp_error( $ai_result ) ) {
            // AI failed - update post with error message
            $error_message = $ai_result->get_error_message();

            wp_update_post([
                'ID'           => $post_id,
                'post_title'   => $topic,
                'post_content' => '',
                'post_status'  => 'private',
            ]);

            update_post_meta( $post_id, self::$auto_post_status_key, self::AUTO_POST_STATUS_FAILED );
            update_post_meta( $post_id, self::$auto_post_error_key, $error_message );

            return new \WP_Error( 'ai_failed', $error_message );
        }

        // AI succeeded - update post with real content
        $title   = $ai_result['title'] ?? '';
        $slug    = $ai_result['slug'] ?? '';
        $content = $ai_result['content'] ?? '';

        // Resolve content image placeholders if enabled.
        if ( $include_content_images ) {
            $content = PostGenerator::resolve_content_images( $content, $post_id, $include_image_caption, $language );
        }

        // Never persist unresolved image prompt placeholders in post content.
        $content = preg_replace(
            '/<!-- wp:paragraph(?:\s+\{[^}]*\})?\s*-->\s*<p>\s*\[CONTENT_IMAGE:\s*[^\]]+\]\s*<\/p>\s*<!-- \/wp:paragraph -->/si',
            '',
            (string) $content
        );
        $content = preg_replace( '/\s*\[CONTENT_IMAGE:\s*[^\]]+\]\s*/si', ' ', (string) $content );
        $content = preg_replace(
            '/<!-- wp:paragraph(?:\s+\{[^}]*\})?\s*-->\s*<p>\s*<\/p>\s*<!-- \/wp:paragraph -->/si',
            '',
            (string) $content
        );

        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_name'    => $slug,
            'post_status'  => $target_status,
        ]);

        update_post_meta( $post_id, self::$auto_post_status_key, self::AUTO_POST_STATUS_COMPLETED );
        delete_post_meta( $post_id, self::$auto_post_error_key );

        return $post_id;
    }

    /**
     * Generate AI content for a post.
     *
     * @param string $topic          The topic to write about.
     * @param string $tone           Writing tone.
     * @param string $language       Article language.
     * @param int    $min_length     Minimum word count.
     * @param int    $max_length     Maximum word count.
     * @param string $slug_language  Language for the slug.
     * @param int    $slug_max_length Maximum slug length.
     * @param array  $attachments    Attachment IDs for reference images.
     * @param array  $advanced       Advanced feature flags (pov, target_audience, SEO, structure flags, etc.).
     *
     * @return array|WP_Error Array with title, content, slug on success, WP_Error on failure.
     */
    private static function generate_ai_content( $topic, $tone, $language, $min_length, $max_length, $slug_language, $slug_max_length, $attachments = [], $advanced = [] ) {
        // ── Unpack advanced options ──────────────────────────────────────
        $pov                    = $advanced['pov']                    ?? 'third_person';
        $target_audience        = $advanced['target_audience']        ?? 'general';
        $focus_keywords         = (array) ( $advanced['focus_keywords']         ?? [] );
        $optimize_seo           = (bool)  ( $advanced['optimize_for_seo']       ?? true );
        $generate_meta_desc     = (bool)  ( $advanced['generate_meta_description'] ?? false );
        $generate_featured_img  = (bool)  ( $advanced['generate_featured_image']  ?? false );
        $include_toc            = (bool)  ( $advanced['include_toc']            ?? false );
        $include_faq            = (bool)  ( $advanced['include_faq']            ?? false );
        $faq_block_type         = $advanced['faq_block_type']                    ?? 'default';
        $include_conclusion     = (bool)  ( $advanced['include_conclusion']     ?? true );
        $suggest_internal_links = (bool)  ( $advanced['suggest_internal_links'] ?? true );

        // ── Build Advanced + SEO prompt blocks via shared PostPromptBuilder ──
        $seo_result = PostPromptBuilder::build_seo_instructions( [
            'focus_keywords'            => $focus_keywords,
            'optimize_for_seo'          => $optimize_seo,
            'generate_meta_description' => $generate_meta_desc,
            'generate_featured_image'   => $generate_featured_img,
        ] );

        $adv_result = PostPromptBuilder::build_advanced_instructions( [
            'include_toc'            => $include_toc,
            'include_faq'            => $include_faq,
            'faq_block_type'         => $faq_block_type,
            'include_conclusion'     => $include_conclusion,
            'suggest_internal_links' => $suggest_internal_links,
            'include_content_images' => false,
        ] );

        $advanced_instructions = $seo_result['instructions'] . $adv_result['instructions'];
        $advanced_json         = $seo_result['json_fields']  . $adv_result['json_fields'];

        $audience_pov_block = PostPromptBuilder::build_audience_and_pov_prompt( $pov, $target_audience );
        $conclusion_line    = PostPromptBuilder::build_conclusion_instruction( $include_conclusion, $min_length, '4.' );

        // ── Language note ─────────────────────────────────────────────────
        if ( ! empty( $language ) && strtolower( $language ) !== 'english' ) {
            $language_note = sprintf(
                '⚠️ CRITICAL for %s: You MUST reach the minimum word count of %d words in %s. Do not truncate the content. Expand on every point to ensure the article is long and comprehensive.',
                $language,
                $min_length,
                $language
            );
        } else {
            $language_note = sprintf(
                '⚠️ CRITICAL: You MUST write at least %d words. DO NOT stop early. Expand every point with detailed explanations, examples, and context.',
                $min_length
            );
        }

        $instructions = '
            You are a professional WordPress content writer, SEO specialist, and taxonomy expert.

            Your job is to generate a **complete JSON response** for creating a new WordPress post.
            The response must contain ALL required fields with high-quality, SEO-optimized content.

            **Your entire response must be a single valid JSON object.**
            - No text, comments, explanations, or formatting outside the JSON object.
            - Do NOT wrap the JSON in code fences (no ```json or similar).
            - Every value inside JSON must be valid and properly escaped.

            ---

            ### JSON Response Format (STRICT - NO DEVIATION)
            {
                "title": "Your SEO-optimized article title here",
                "content": "<!-- wp:paragraph --><p>Your Gutenberg-formatted article content...</p><!-- /wp:paragraph --> ...",
                "slug": "your-seo-friendly-slug-here"
                ' . ( ! empty( $advanced_json ) ? ', ' . rtrim( $advanced_json, ",
" ) : '' ) . '
            }

            ---

            ### Content Generation Rules (NON-NEGOTIABLE)
            - Write in **pure Gutenberg block format** - NO EXCEPTIONS.
            - Only use `<!-- wp:... -->` blocks (with proper JSON attributes where needed).
            - Do **NOT** use Markdown, raw HTML tags outside blocks, or inline styling.
            - Output only Gutenberg blocks inside the `content` field.
            - The content must be rich, engaging, detailed, and SEO-friendly.
            - **Variety of Blocks**: Use a healthy mix of paragraphs, headings (H2-H4), lists, and where appropriate, tables or pull-quotes.

            ---

            ### Advanced Features
            ' . $advanced_instructions . '

            ---

            Only output the JSON object, nothing else.
        ';

        $user_prompt = '
            ⚠️⚠️⚠️ CRITICAL: ALL REQUIREMENTS BELOW ARE MANDATORY ⚠️⚠️⚠️

            ═══════════════════════════════════════════════════════════════
            📋 MANDATORY ARTICLE PARAMETERS
            ═══════════════════════════════════════════════════════════════

            1. ✍️ TONE: "' . $tone . '"
                ➜ The ENTIRE article must be written in this EXACT tone.
                ➜ Maintain this tone consistently from introduction to conclusion.

            ' . $audience_pov_block . '

            2. 🌐 ARTICLE LANGUAGE: "' . $language . '"
                ➜ Write the ENTIRE article (title, content, headings, paragraphs) in "' . $language . '" ONLY.
                ➜ Do NOT mix languages.
                ➜ ' . $language_note . '

            3. 📏 WORD COUNT: ' . $min_length . ' - ' . $max_length . ' words (STRICTLY ENFORCED)
                ➜ MINIMUM: ' . $min_length . ' words - DO NOT WRITE LESS.
                ➜ MAXIMUM: ' . $max_length . ' words - DO NOT EXCEED.

            4. 🔗 SLUG LANGUAGE: "' . $slug_language . '"
                ➜ Generate the slug in "' . $slug_language . '" ONLY.

            5. ⚖️ SLUG LENGTH: Maximum ' . $slug_max_length . ' characters
                ➜ The slug must be SEO-friendly and derived from the title.
                ➜ Keep it under ' . $slug_max_length . ' characters.

            ═══════════════════════════════════════════════════════════════
            📝 CONTENT STRUCTURE REQUIREMENTS
            ═══════════════════════════════════════════════════════════════

            1. **Detailed Introduction** (100-200 words minimum)
            2. **3-6 Main Sections** with clear headings
            3. **Rich, Detailed Content** — examples, context, actionable insights in every section
            ' . $conclusion_line . '
            5. **Total content MUST be at least ' . $min_length . ' words. DO NOT submit shorter content.**

            ═══════════════════════════════════════════════════════════════
            🎯 TOPIC TO WRITE ABOUT
            ═══════════════════════════════════════════════════════════════

            ' . $topic . '

            ═══════════════════════════════════════════════════════════════
            ✅ FINAL CHECKLIST
            ═══════════════════════════════════════════════════════════════

            ✓ Article is in "' . $language . '"
            ✓ Tone matches "' . $tone . '"
            ✓ Word count is ' . $min_length . '-' . $max_length . '
            ✓ Slug is in "' . $slug_language . '" and ≤' . $slug_max_length . ' chars
            ✓ Content is in Gutenberg block format
            ✓ Output is valid JSON

            Remember: Output ONLY the JSON object, nothing else.
        ';

        $input = [
            [
                'role'    => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $instructions,
                    ],
                ],
            ],
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $user_prompt,
                    ],
                ]
            ],
        ];

        if ( atml_is_public_site() ) {
            foreach ( $attachments as $attachment_id ) {
                $attachment_url = wp_get_attachment_url( $attachment_id );
    
                if ( $attachment_url ) {
                    $input[1]['content'][] = [
                        'type'     => 'input_file',
                        'file_url' => esc_url_raw( $attachment_url ),
                    ];
                }
            }
        }

        // Calculate max output tokens based on article length.
        // Reasoning models (like GPT-5) use a significant portion of tokens for internal "thinking"
        // before generating actual content. Based on testing, we need to account for:
        // - Reasoning tokens: ~8000-12000 tokens (observed in logs)
        // - Content tokens: ~1.5 tokens per word
        // - JSON structure overhead: ~500 tokens
        $reasoning_overhead = 12000;
        $content_tokens     = intval( $max_length * 1.5 );
        $max_output_tokens  = $reasoning_overhead + $content_tokens + 500;

        $response = AIProvider::get_reply( $input, '', '', $max_output_tokens );

        if ( ! is_string( $response ) ) {
            return new \WP_Error( 'ai_failed', __( 'Failed to get response from AI provider. Please check your API key and try again.', 'antimanual' ) );
        }

        // Clean the AI response to remove unwanted characters and formatting.
        $response = AIResponseCleaner::clean_json_response( $response );

        $parsed = json_decode( $response, true );

        if ( empty( $parsed ) ) {
            return new \WP_Error( 'ai_parse_failed', __( 'AI returned an invalid response. The content could not be parsed.', 'antimanual' ) );
        }

        if ( empty( $parsed['title'] ) || empty( $parsed['content'] ) ) {
            return new \WP_Error( 'ai_incomplete', __( 'AI returned incomplete content. Missing title or content.', 'antimanual' ) );
        }

        // Clean the individual fields as well.
        $parsed['title']   = AIResponseCleaner::clean_content( $parsed['title'] );
        $parsed['content'] = AIResponseCleaner::clean_gutenberg_content( $parsed['content'] );
        $parsed['slug']    = AIResponseCleaner::clean_slug( $parsed['slug'] ?? '' );

        $adjusted_content = PostPromptBuilder::fit_content_to_word_range(
            $parsed['content'],
            $min_length,
            $max_length,
            [
                'tone'          => $tone,
                'language'      => $language,
                'focus_keyword' => $focus_keywords[0] ?? '',
            ]
        );

        if ( is_wp_error( $adjusted_content ) ) {
            return $adjusted_content;
        }

        $parsed['content'] = $adjusted_content;
        $word_count        = \Antimanual\Utils\count_post_words( $parsed['content'] );

        if ( $word_count < $min_length || $word_count > $max_length ) {
            return new \WP_Error(
                'content_length_out_of_range',
                sprintf(
                    /* translators: 1: actual word count, 2: minimum words, 3: maximum words */
                    __( 'Generated content is %1$d words, which is outside the requested %2$d-%3$d word range. Please try again or adjust the article length.', 'antimanual' ),
                    $word_count,
                    $min_length,
                    $max_length
                )
            );
        }

        return $parsed;
    }

    /**
     * Retry generating content for a failed post.
     *
     * @param int $post_id The post ID to retry.
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public static function retry_failed_post( $post_id ) {
        $auto_posting_id = get_post_meta( $post_id, self::$auto_posting_id_key, true );
        $status = get_post_meta( $post_id, self::$auto_post_status_key, true );

        if ( empty( $auto_posting_id ) ) {
            return new \WP_Error( 'not_auto_post', __( 'This post was not created by auto-posting.', 'antimanual' ) );
        }

        if ( $status !== self::AUTO_POST_STATUS_FAILED ) {
            return new \WP_Error( 'not_failed', __( 'This post has not failed. Only failed posts can be retried.', 'antimanual' ) );
        }

        $run_date = get_post_meta( $post_id, self::$auto_post_scheduled_key, true );

        return self::create_post( intval( $auto_posting_id ), $run_date, $post_id );
    }

    public function run_scheduled_auto_posting() {
        // Get current UTC time for consistent timezone handling
        $current_time_utc = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $current_weekday  = intval( $current_time_utc->format( 'w' ) );
        $current_hour     = intval( $current_time_utc->format( 'G' ) );
        $current_minute   = intval( $current_time_utc->format( 'i' ) );
        $current_timestamp = $current_time_utc->getTimestamp();

        // Time tolerance window (5 minutes) to catch missed schedules
        $tolerance_minutes = 5;

        $auto_postings = self::list();
        $postings_in_schedule = [];

        foreach ( $auto_postings as $auto_posting ) {
            $posting_id = $auto_posting['post_id'];
            $weekdays   = $auto_posting['weekdays'] ?? [];
            $times      = $auto_posting['times'] ?? [];
            $exp_date   = $auto_posting['exp_date'] ?? null;
            $is_active  = $auto_posting['is_active'] ?? false;
            $last_run   = $auto_posting['last_run_at'] ?? null;

            // FIXED: Correct expiration logic - only expired if exp_date exists AND is in the past
            $is_expired = $exp_date && intval( $exp_date ) > 0 && intval( $exp_date ) < $current_timestamp;

            // Skip if not active or expired
            if ( ! $is_active ) {
                continue;
            }

            if ( $is_expired ) {
                continue;
            }

            // Check if today is a scheduled day
            // Convert weekdays to integers to handle both string and int storage
            $weekdays = array_map( 'intval', $weekdays );
            if ( ! in_array( $current_weekday, $weekdays, false ) ) {
                continue;
            }

            // Check if current time matches any scheduled time (with tolerance window)
            $should_run = false;
            $matched_time = null;

            foreach ( $times as $time ) {
                $scheduled_hour   = intval( $time['hour'] ?? -1 );
                $scheduled_minute = intval( $time['minute'] ?? -1 );

                if ( $scheduled_hour < 0 || $scheduled_hour > 23 || $scheduled_minute < 0 || $scheduled_minute > 59 ) {
                    continue;
                }

                // Calculate the scheduled time for today
                $scheduled_time = clone $current_time_utc;
                $scheduled_time->setTime( $scheduled_hour, $scheduled_minute, 0 );
                $scheduled_timestamp = $scheduled_time->getTimestamp();

                // Check if we're within the tolerance window (5 minutes after scheduled time)
                $time_diff_minutes = ( $current_timestamp - $scheduled_timestamp ) / 60;

                if ( $time_diff_minutes >= 0 && $time_diff_minutes <= $tolerance_minutes ) {
                    // Check if we already ran within this window
                    $run_date_key = $scheduled_time->format( 'Y-m-d H:i:00' );
                    
                    // If last_run exists, check if we already ran for this exact scheduled time
                    if ( $last_run ) {
                        $last_run_time = new \DateTime( $last_run, new \DateTimeZone( 'UTC' ) );
                        $last_run_key = $last_run_time->format( 'Y-m-d H:i:00' );

                        if ( $last_run_key === $run_date_key ) {
                            continue; // Already ran for this schedule
                        }
                    }

                    $should_run = true;
                    $matched_time = $run_date_key;
                    break;
                }
            }

            if ( $should_run ) {
                $postings_in_schedule[] = [
                    'posting' => $auto_posting,
                    'run_date' => $matched_time,
                ];
            }
        }

        // Add items to queue
        foreach ( $postings_in_schedule as $item ) {
            $posting  = $item['posting'];
            $run_date = $item['run_date'];
            $posting_id = $posting['post_id'];

            // Add to queue (will be skipped if already exists)
            \Antimanual\AutoPostingQueue::add( $posting_id, $run_date );

            // Update last_run_at to prevent re-queuing
            $posting['last_run_at'] = $current_time_utc->format( 'Y-m-d H:i:s' );
            update_post_meta( $posting_id, self::$meta_data, $posting );
        }

        // Process queue items (with retry support)
        $this->process_queue();

        // Cleanup old completed queue items
        \Antimanual\AutoPostingQueue::cleanup();
    }

    /**
     * Process items from the queue
     */
    private function process_queue() {
        $queue_items = \Antimanual\AutoPostingQueue::get_pending( 10 );

        if ( empty( $queue_items ) ) {
            return;
        }

        foreach ( $queue_items as $item ) {
            $queue_id       = $item->id;
            $posting_id     = $item->posting_id;
            $scheduled_time = $item->scheduled_time;
            $retry_count    = $item->retry_count;

            // Mark as processing
            \Antimanual\AutoPostingQueue::mark_processing( $queue_id );

            // Create the post
            $result = self::create_post( $posting_id, $scheduled_time );

            if ( is_wp_error( $result ) ) {
                $error_msg = $result->get_error_message();

                // Mark as failed (will retry if retry_count < 3)
                \Antimanual\AutoPostingQueue::mark_failed( $queue_id, $error_msg );

                // Send email notification on final failure
                if ( $retry_count >= 2 ) { // This will be the 3rd attempt
                    $this->send_failure_notification( $posting_id, $error_msg );
                }
            } else {
                // Mark as completed
                \Antimanual\AutoPostingQueue::mark_completed( $queue_id );
            }
        }
    }

    /**
     * Send email notification on posting failure
     */
    private function send_failure_notification( $posting_id, $error_msg ) {
        $admin_email = get_option( 'admin_email' );
        $posting = self::get( $posting_id );

        if ( is_wp_error( $posting ) ) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: auto posting ID */
            __( '[%1$s] Auto Posting Failed - ID #%2$d', 'antimanual' ),
            get_bloginfo( 'name' ),
            $posting_id
        );

        $message = sprintf(
            /* translators: 1: auto posting ID, 2: comma-separated topics, 3: failure error message, 4: admin URL */
            __( 'Auto posting failed after 3 retry attempts.\n\nPosting ID: %1$d\nTopics: %2$s\nError: %3$s\n\nPlease check your auto posting configuration and cron status at:\n%4$s', 'antimanual' ),
            $posting_id,
            implode( ', ', array_slice( $posting['topics'] ?? [], 0, 3 ) ),
            $error_msg,
            admin_url( 'admin.php?page=antimanual&tab=auto-posting' )
        );

        wp_mail( $admin_email, $subject, $message );
    }
}
