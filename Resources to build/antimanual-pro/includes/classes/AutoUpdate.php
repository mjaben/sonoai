<?php

namespace Antimanual_Pro;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\AIResponseCleaner;

/**
 * Auto Update Manager Class (Pro Feature)
 *
 * Automatically refreshes older posts on a schedule.
 * This is a Pro-only feature.
 *
 * @package Antimanual_Pro
 */
class AutoUpdate {
	public static $instance  = null;
	public static $post_type = 'atml_auto_update';
	public static $meta_data = '_atml_data';

	/**
	 * Transient keys for the lightweight check mechanism.
	 */
	private static $last_check_key   = 'atml_auto_update_last_check';
	private static $running_lock_key = 'atml_auto_update_running';
	private static $check_interval   = 60; // Check every 60 seconds

	/**
	 * Meta keys for tracking auto-update status on posts.
	 */
	public static $auto_update_status_key    = '_atml_auto_update_status';
	public static $auto_update_id_key        = '_atml_auto_update_id';
	public static $auto_update_error_key     = '_atml_auto_update_error';
	public static $auto_update_timestamp_key = '_atml_auto_updated_at';

	/**
	 * Auto-update status values.
	 */
	const AUTO_UPDATE_STATUS_PENDING   = 'pending';
	const AUTO_UPDATE_STATUS_COMPLETED = 'completed';
	const AUTO_UPDATE_STATUS_FAILED    = 'failed';

	/**
	 * Sanitize a list of post IDs.
	 *
	 * @param mixed $ids
	 * @return array
	 */
	private static function sanitize_post_ids( $ids ) {
		if ( is_string( $ids ) ) {
			$ids = preg_split( '/[\s,]+/', $ids );
		}

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$ids = array_map( 'intval', $ids );
		$ids = array_filter( $ids, fn( $id ) => $id > 0 );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Build query args for fetching eligible posts.
	 *
	 * @param array    $auto_update
	 * @param int|null $limit
	 * @return array
	 */
	private static function build_update_query_args( array $auto_update, $limit = null ) {
		$post_types               = $auto_update['post_types'] ?? array( 'post' );
		$post_statuses            = $auto_update['post_statuses'] ?? array( 'publish' );
		$min_age_days             = max( 1, intval( $auto_update['min_age_days'] ?? 90 ) );
		$min_days_between_updates = max( 1, intval( $auto_update['min_days_between_updates'] ?? 30 ) );
		$max_posts_per_run        = max( 1, min( 50, intval( $auto_update['max_posts_per_run'] ?? 5 ) ) );
		$exclude_post_ids         = self::sanitize_post_ids( $auto_update['exclude_post_ids'] ?? array() );

		$cutoff               = gmdate( 'Y-m-d H:i:s', time() - ( $min_age_days * DAY_IN_SECONDS ) );
		$min_update_timestamp = time() - ( $min_days_between_updates * DAY_IN_SECONDS );

		$posts_per_page = $max_posts_per_run;
		if ( null !== $limit ) {
			$posts_per_page = max( 1, min( $posts_per_page, intval( $limit ) ) );
		}

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => $post_statuses,
			'posts_per_page' => $posts_per_page,
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'date_query'     => array(
				array(
					'column'    => 'post_modified_gmt',
					'before'    => $cutoff,
					'inclusive' => true,
				),
			),
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => self::$auto_update_timestamp_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::$auto_update_timestamp_key,
					'value'   => $min_update_timestamp,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			),
		);

		if ( ! empty( $exclude_post_ids ) ) {
			$args['post__not_in'] = $exclude_post_ids;
		}

		return $args;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'maybe_run_auto_update' ), 20 );
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return AutoUpdate The singleton instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the Auto Update custom post type.
	 */
	public static function register() {
		$labels = array(
			'name'          => __( 'Auto Updates', 'antimanual' ),
			'singular_name' => __( 'Auto Update', 'antimanual' ),
		);

		$args = array(
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => false,
			'show_in_menu' => false,
			'supports'     => array( 'custom-fields' ),
		);

		register_post_type( self::$post_type, $args );
	}

	/**
	 * Lightweight check that runs on every WordPress request.
	 */
	public function maybe_run_auto_update() {
		$updates_count = self::get_updates_count();
		if ( $updates_count <= 0 ) {
			return;
		}

		$last_check = get_transient( self::$last_check_key );
		$now        = time();

		if ( false !== $last_check && ( $now - intval( $last_check ) ) < self::$check_interval ) {
			return;
		}

		if ( get_transient( self::$running_lock_key ) ) {
			return;
		}

		set_transient( self::$running_lock_key, true, 300 );
		set_transient( self::$last_check_key, $now, DAY_IN_SECONDS );

		try {
			$this->run_scheduled_auto_updates();
		} finally {
			delete_transient( self::$running_lock_key );
		}
	}

	/**
	 * Get total number of active auto-update schedules.
	 *
	 * @return int
	 */
	public static function get_updates_count() {
		$query = wp_count_posts( self::$post_type );
		return $query->publish;
	}

	/**
	 * Check if auto-update is currently running.
	 *
	 * @return bool
	 */
	public static function is_running() {
		return (bool) get_transient( self::$running_lock_key );
	}

	/**
	 * List auto-update schedules.
	 *
	 * @param array $populate Fields to populate.
	 * @return array|\WP_Error
	 */
	public static function list( $populate = array() ) {
		$args = array(
			'post_type'      => self::$post_type,
			'posts_per_page' => -1,
		);

		$query = new \WP_Query( $args );

		if ( is_wp_error( $query ) ) {
			return new \WP_Error( 'query_error', $query->get_error_message() );
		}

		$updates = array();

		foreach ( $query->posts as $post ) {
			$updates[] = self::get( $post->ID, $populate );
		}

		return $updates;
	}

	/**
	 * Get a single auto-update schedule.
	 *
	 * @param int   $auto_update_id Schedule ID.
	 * @param array $populate Fields to populate.
	 * @return array|\WP_Error
	 */
	public static function get( $auto_update_id, $populate = array() ) {
		$data = get_post_meta( $auto_update_id, self::$meta_data, true );

		if ( ! $data || empty( $data ) ) {
			return new \WP_Error( 'not_found', __( 'Auto Update not found.', 'antimanual' ) );
		}

		$data['is_running'] = self::is_running();

		return $data;
	}

	/**
	 * Create a new auto-update schedule.
	 *
	 * @param array $payload
	 * @return array|\WP_Error
	 */
	public static function create( $payload ) {
		$post_types = $payload['post_types'] ?? array( 'post' );
		$post_types = is_array( $post_types ) ? array_map( 'sanitize_key', $post_types ) : array();
		$post_types = array_values( array_filter( $post_types, 'post_type_exists' ) );

		if ( empty( $post_types ) ) {
			return new \WP_Error( 'invalid_post_types', __( 'Please select at least one valid post type.', 'antimanual' ) );
		}

		$post_statuses    = $payload['post_statuses'] ?? array( 'publish' );
		$post_statuses    = is_array( $post_statuses ) ? array_map( 'sanitize_key', $post_statuses ) : array();
		$allowed_statuses = array_keys( get_post_stati() );
		$post_statuses    = array_values( array_intersect( $post_statuses, $allowed_statuses ) );
		if ( empty( $post_statuses ) ) {
			$post_statuses = array( 'publish' );
		}

		$min_age_days             = max( 1, intval( $payload['min_age_days'] ?? 90 ) );
		$min_days_between_updates = max( 1, intval( $payload['min_days_between_updates'] ?? 30 ) );
		$max_posts_per_run        = max( 1, min( 50, intval( $payload['max_posts_per_run'] ?? 5 ) ) );
		$tone                     = sanitize_text_field( $payload['tone'] ?? $GLOBALS['ATML_STORE']['tones']['blog-style'] );
		$language                 = sanitize_text_field( $payload['language'] ?? 'English' );
		$update_prompt            = sanitize_textarea_field( $payload['update_prompt'] ?? '' );
		$update_title             = isset( $payload['update_title'] ) ? boolval( $payload['update_title'] ) : false;
		$update_excerpt           = isset( $payload['update_excerpt'] ) ? boolval( $payload['update_excerpt'] ) : false;
		$name                     = sanitize_text_field( $payload['name'] ?? '' );
		$description              = sanitize_textarea_field( $payload['description'] ?? '' );
		$exclude_post_ids         = self::sanitize_post_ids( $payload['exclude_post_ids'] ?? array() );

		$weekdays = $payload['weekdays'] ?? array();
		$times    = $payload['times'] ?? array();
		$exp_date = $payload['exp_date'] ?? null;

		$invalid_days = array_filter( $weekdays, fn( $day ) => $day < 0 || $day > 6 );
		if ( ! empty( $invalid_days ) ) {
			return new \WP_Error( 'invalid_weekdays', __( 'Weekdays are invalid.', 'antimanual' ) );
		}

		foreach ( $times as $i => $time ) {
			$time        = (array) $time;
			$times[ $i ] = $time;

			$hour = intval( $time['hour'] ?? -1 );
			$min  = intval( $time['minute'] ?? -1 );

			if ( $hour < 0 || $hour > 23 || $min < 0 || $min > 59 ) {
				return new \WP_Error( 'invalid_times', __( 'Times are invalid.', 'antimanual' ) );
			}
		}

		$auto_update_id = wp_insert_post(
			array(
				'post_type'   => self::$post_type,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $auto_update_id ) ) {
			return new \WP_Error( 'insert_post_error', $auto_update_id->get_error_message() );
		}

		$auto_update_data = array(
			'is_active'                => true,
			'post_id'                  => (int) $auto_update_id,
			'post_types'               => $post_types,
			'post_statuses'            => $post_statuses,
			'min_age_days'             => $min_age_days,
			'min_days_between_updates' => $min_days_between_updates,
			'max_posts_per_run'        => $max_posts_per_run,
			'tone'                     => $tone,
			'language'                 => $language,
			'update_prompt'            => $update_prompt,
			'update_title'             => $update_title,
			'update_excerpt'           => $update_excerpt,
			'name'                     => $name,
			'description'              => $description,
			'exclude_post_ids'         => $exclude_post_ids,
			'weekdays'                 => (array) $weekdays,
			'times'                    => (array) $times,
			'exp_date'                 => (int) $exp_date,
			'last_run_at'              => null,
			'last_run_stats'           => null,
		);

		update_post_meta( $auto_update_id, self::$meta_data, $auto_update_data );

		return self::get( $auto_update_id );
	}

	/**
	 * Update an auto-update schedule.
	 *
	 * @param int   $auto_update_id
	 * @param array $payload
	 * @return array|\WP_Error
	 */
	public static function update( int $auto_update_id, $payload ) {
		if ( empty( $auto_update_id ) ) {
			return new \WP_Error( 'auto_update_id_required', __( 'Auto Update ID is required.', 'antimanual' ) );
		}

		$auto_update = self::get( $auto_update_id );

		if ( is_wp_error( $auto_update ) ) {
			return $auto_update;
		}

		// Update is_active flag
		$is_active = $payload['is_active'] ?? null;
		if ( null !== $is_active ) {
			$auto_update['is_active'] = boolval( $is_active );
		}

		// Update post types if provided
		if ( isset( $payload['post_types'] ) ) {
			$post_types = is_array( $payload['post_types'] ) ? array_map( 'sanitize_key', $payload['post_types'] ) : array();
			$post_types = array_values( array_filter( $post_types, 'post_type_exists' ) );
			if ( ! empty( $post_types ) ) {
				$auto_update['post_types'] = $post_types;
			}
		}

		// Update post statuses if provided
		if ( isset( $payload['post_statuses'] ) ) {
			$post_statuses    = is_array( $payload['post_statuses'] ) ? array_map( 'sanitize_key', $payload['post_statuses'] ) : array();
			$allowed_statuses = array_keys( get_post_stati() );
			$post_statuses    = array_values( array_intersect( $post_statuses, $allowed_statuses ) );
			if ( ! empty( $post_statuses ) ) {
				$auto_update['post_statuses'] = $post_statuses;
			}
		}

		// Update numeric fields
		if ( isset( $payload['min_age_days'] ) ) {
			$auto_update['min_age_days'] = max( 1, intval( $payload['min_age_days'] ) );
		}

		if ( isset( $payload['min_days_between_updates'] ) ) {
			$auto_update['min_days_between_updates'] = max( 1, intval( $payload['min_days_between_updates'] ) );
		}

		if ( isset( $payload['max_posts_per_run'] ) ) {
			$auto_update['max_posts_per_run'] = max( 1, min( 50, intval( $payload['max_posts_per_run'] ) ) );
		}

		// Update text fields
		if ( isset( $payload['tone'] ) ) {
			$auto_update['tone'] = sanitize_text_field( $payload['tone'] );
		}

		if ( isset( $payload['language'] ) ) {
			$auto_update['language'] = sanitize_text_field( $payload['language'] );
		}

		if ( isset( $payload['update_prompt'] ) ) {
			$auto_update['update_prompt'] = sanitize_textarea_field( $payload['update_prompt'] );
		}

		// Update boolean fields
		if ( isset( $payload['update_title'] ) ) {
			$auto_update['update_title'] = boolval( $payload['update_title'] );
		}

		if ( isset( $payload['update_excerpt'] ) ) {
			$auto_update['update_excerpt'] = boolval( $payload['update_excerpt'] );
		}

		if ( isset( $payload['name'] ) ) {
			$auto_update['name'] = sanitize_text_field( $payload['name'] );
		}

		if ( isset( $payload['description'] ) ) {
			$auto_update['description'] = sanitize_textarea_field( $payload['description'] );
		}

		if ( array_key_exists( 'exclude_post_ids', $payload ) ) {
			$auto_update['exclude_post_ids'] = self::sanitize_post_ids( $payload['exclude_post_ids'] );
		}

		// Update schedule fields
		if ( isset( $payload['weekdays'] ) ) {
			$weekdays = $payload['weekdays'] ?? array();
			$invalid_update_days = array_filter( $weekdays, fn( $day ) => $day < 0 || $day > 6 );
			if ( empty( $invalid_update_days ) ) {
				$auto_update['weekdays'] = (array) $weekdays;
			}
		}

		if ( isset( $payload['times'] ) ) {
			$times       = $payload['times'] ?? array();
			$valid_times = true;

			foreach ( $times as $time ) {
				$time = (array) $time;
				$hour = intval( $time['hour'] ?? -1 );
				$min  = intval( $time['minute'] ?? -1 );

				if ( $hour < 0 || $hour > 23 || $min < 0 || $min > 59 ) {
					$valid_times = false;
					break;
				}
			}

			if ( $valid_times && ! empty( $times ) ) {
				$auto_update['times'] = (array) $times;
			}
		}

		if ( isset( $payload['exp_date'] ) ) {
			$auto_update['exp_date'] = (int) $payload['exp_date'];
		}

		update_post_meta( $auto_update_id, self::$meta_data, $auto_update );

		return $auto_update;
	}

	/**
	 * Delete an auto-update schedule.
	 *
	 * @param int $auto_update_id
	 * @return bool|\WP_Error
	 */
	public static function delete( $auto_update_id ) {
		$deleted = wp_delete_post( $auto_update_id, true );

		if ( ! $deleted ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete Auto Update.', 'antimanual' ) );
		}

		return true;
	}

	/**
	 * Run all schedules that match the current time window.
	 */
	public function run_scheduled_auto_updates() {
		$current_time_utc  = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$current_weekday   = intval( $current_time_utc->format( 'w' ) );
		$current_timestamp = $current_time_utc->getTimestamp();

		$tolerance_minutes = 5;

		$auto_updates        = self::list();
		$updates_in_schedule = array();

		foreach ( $auto_updates as $auto_update ) {
			if ( is_wp_error( $auto_update ) ) {
				continue;
			}

			$update_id = $auto_update['post_id'];
			$weekdays  = $auto_update['weekdays'] ?? array();
			$times     = $auto_update['times'] ?? array();
			$exp_date  = $auto_update['exp_date'] ?? null;
			$is_active = $auto_update['is_active'] ?? false;
			$last_run  = $auto_update['last_run_at'] ?? null;

			$is_expired = $exp_date && intval( $exp_date ) > 0 && intval( $exp_date ) < $current_timestamp;

			if ( ! $is_active || $is_expired ) {
				continue;
			}

			$weekdays = array_map( 'intval', $weekdays );
			if ( ! in_array( $current_weekday, $weekdays, false ) ) {
				continue;
			}

			$should_run   = false;
			$matched_time = null;

			foreach ( $times as $time ) {
				$scheduled_hour   = intval( $time['hour'] ?? -1 );
				$scheduled_minute = intval( $time['minute'] ?? -1 );

				if ( $scheduled_hour < 0 || $scheduled_hour > 23 || $scheduled_minute < 0 || $scheduled_minute > 59 ) {
					continue;
				}

				$scheduled_time = clone $current_time_utc;
				$scheduled_time->setTime( $scheduled_hour, $scheduled_minute, 0 );
				$scheduled_timestamp = $scheduled_time->getTimestamp();

				$time_diff_minutes = ( $current_timestamp - $scheduled_timestamp ) / 60;

				if ( $time_diff_minutes >= 0 && $time_diff_minutes <= $tolerance_minutes ) {
					$run_date_key = $scheduled_time->format( 'Y-m-d H:i:00' );

					if ( $last_run ) {
						$last_run_time = new \DateTime( $last_run, new \DateTimeZone( 'UTC' ) );
						$last_run_key  = $last_run_time->format( 'Y-m-d H:i:00' );

						if ( $last_run_key === $run_date_key ) {
							continue;
						}
					}

					$should_run   = true;
					$matched_time = $run_date_key;
					break;
				}
			}

			if ( $should_run ) {
				$updates_in_schedule[] = array(
					'update'   => $auto_update,
					'run_date' => $matched_time,
				);
			}
		}

		foreach ( $updates_in_schedule as $item ) {
			$update    = $item['update'];
			$run_date  = $item['run_date'];
			$update_id = $update['post_id'];

			$stats = self::run_update( $update_id );

			$update['last_run_at'] = $current_time_utc->format( 'Y-m-d H:i:s' );

			if ( is_wp_error( $stats ) ) {
				$update['last_run_stats'] = array(
					'run_date' => $run_date,
					'error'    => $stats->get_error_message(),
				);
			} else {
				$update['last_run_stats'] = array_merge( array( 'run_date' => $run_date ), $stats );
			}
			update_post_meta( $update_id, self::$meta_data, $update );
		}
	}

	/**
	 * Manually run an auto-update schedule.
	 *
	 * @param int $auto_update_id
	 * @return array|\WP_Error
	 */
	public static function run_update( int $auto_update_id ) {
		$auto_update = self::get( $auto_update_id );

		if ( is_wp_error( $auto_update ) ) {
			return $auto_update;
		}

		$posts = self::get_posts_to_update( $auto_update );

		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		$updated = 0;
		$failed  = 0;

		foreach ( $posts as $post ) {
			$result = self::update_post_content( $post, $auto_update );

			if ( is_wp_error( $result ) ) {
				++$failed;
				continue;
			}

			++$updated;
		}

		return array(
			'updated' => $updated,
			'failed'  => $failed,
		);
	}

	/**
	 * Get posts eligible for auto-update.
	 *
	 * @param array $auto_update
	 * @return array|\WP_Error
	 */
	private static function get_posts_to_update( array $auto_update ) {
		$args = self::build_update_query_args( $auto_update );

		$query = new \WP_Query( $args );

		if ( is_wp_error( $query ) ) {
			return new \WP_Error( 'query_error', $query->get_error_message() );
		}

		return $query->posts;
	}

	/**
	 * Preview posts eligible for auto-update based on payload.
	 *
	 * @param array $payload
	 * @param int   $limit
	 * @return array|\WP_Error
	 */
	public static function preview_posts( array $payload, $limit = 10 ) {
		$post_types = $payload['post_types'] ?? array( 'post' );
		$post_types = is_array( $post_types ) ? array_map( 'sanitize_key', $post_types ) : array();
		$post_types = array_values( array_filter( $post_types, 'post_type_exists' ) );

		if ( empty( $post_types ) ) {
			return new \WP_Error( 'invalid_post_types', __( 'Please select at least one valid post type.', 'antimanual' ) );
		}

		$post_statuses    = $payload['post_statuses'] ?? array( 'publish' );
		$post_statuses    = is_array( $post_statuses ) ? array_map( 'sanitize_key', $post_statuses ) : array();
		$allowed_statuses = array_keys( get_post_stati() );
		$post_statuses    = array_values( array_intersect( $post_statuses, $allowed_statuses ) );
		if ( empty( $post_statuses ) ) {
			$post_statuses = array( 'publish' );
		}

		$auto_update = array(
			'post_types'               => $post_types,
			'post_statuses'            => $post_statuses,
			'min_age_days'             => max( 1, intval( $payload['min_age_days'] ?? 90 ) ),
			'min_days_between_updates' => max( 1, intval( $payload['min_days_between_updates'] ?? 30 ) ),
			'max_posts_per_run'        => max( 1, min( 50, intval( $payload['max_posts_per_run'] ?? 5 ) ) ),
			'exclude_post_ids'         => self::sanitize_post_ids( $payload['exclude_post_ids'] ?? array() ),
		);

		$args                  = self::build_update_query_args( $auto_update, $limit );
		$args['no_found_rows'] = false;

		$query = new \WP_Query( $args );

		if ( is_wp_error( $query ) ) {
			return new \WP_Error( 'query_error', $query->get_error_message() );
		}

		$posts = array();

		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'type'        => $post->post_type,
				'status'      => $post->post_status,
				'modified_at' => intval( get_post_modified_time( 'U', true, $post ) ),
				'edit_link'   => get_edit_post_link( $post->ID, '&' ),
				'view_link'   => get_permalink( $post->ID ),
			);
		}

		return array(
			'total' => intval( $query->found_posts ),
			'posts' => $posts,
		);
	}

	/**
	 * Update a post's content using AI.
	 *
	 * @param \WP_Post $post
	 * @param array    $auto_update
	 * @return int|\WP_Error
	 */
	private static function update_post_content( \WP_Post $post, array $auto_update ) {
		if ( ! AIProvider::has_api_key() ) {
			return new \WP_Error( 'no_api_key', __( 'AI API Key is not configured.', 'antimanual' ) );
		}

		$tone           = $auto_update['tone'] ?? $GLOBALS['ATML_STORE']['tones']['blog-style'];
		$language       = $auto_update['language'] ?? 'English';
		$update_prompt  = $auto_update['update_prompt'] ?? '';
		$update_title   = isset( $auto_update['update_title'] ) ? boolval( $auto_update['update_title'] ) : false;
		$update_excerpt = isset( $auto_update['update_excerpt'] ) ? boolval( $auto_update['update_excerpt'] ) : false;

		$current_year = gmdate( 'Y' );
		$is_gutenberg = strpos( $post->post_content, '<!-- wp:' ) !== false;

		update_post_meta( $post->ID, self::$auto_update_status_key, self::AUTO_UPDATE_STATUS_PENDING );
		update_post_meta( $post->ID, self::$auto_update_id_key, $auto_update['post_id'] ?? 0 );
		delete_post_meta( $post->ID, self::$auto_update_error_key );

		$system_message = "
You are a WordPress content editor. Refresh and improve existing content without changing its meaning.

Rules:
- Preserve existing structure, internal links, and shortcodes.
- Do not invent new facts, stats, or claims.
- If content feels time-sensitive, make it more evergreen instead of adding new dates.
- Keep the original language: {$language}.
- Use a {$tone} tone.
- Return only valid JSON.
";

		$format_hint = $is_gutenberg
			? 'Return content in Gutenberg block format (<!-- wp:... -->).'
			: 'Return content in the same HTML format as provided.';

		$user_message = "
Update the following WordPress post for freshness and clarity. {$format_hint}

Title: {$post->post_title}

Content:
{$post->post_content}

Excerpt:
{$post->post_excerpt}

Additional editor instructions (optional):
{$update_prompt}

Return JSON with keys: title, content, excerpt. Use the current year {$current_year} only if explicitly needed.
";

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_message,
			),
			array(
				'role'    => 'user',
				'content' => $user_message,
			),
		);

		$response = antimanual_openai_chat_completions( $messages, true );

		if ( is_array( $response ) && isset( $response['error'] ) ) {
			update_post_meta( $post->ID, self::$auto_update_status_key, self::AUTO_UPDATE_STATUS_FAILED );
			update_post_meta( $post->ID, self::$auto_update_error_key, $response['error'] );
			return new \WP_Error( 'ai_failed', $response['error'] );
		}

		$parsed = json_decode( $response, true );

		if ( empty( $parsed ) || ! is_array( $parsed ) ) {
			update_post_meta( $post->ID, self::$auto_update_status_key, self::AUTO_UPDATE_STATUS_FAILED );
			update_post_meta( $post->ID, self::$auto_update_error_key, __( 'AI response could not be parsed.', 'antimanual' ) );
			return new \WP_Error( 'ai_invalid', __( 'AI response could not be parsed.', 'antimanual' ) );
		}

		$new_title   = $parsed['title'] ?? $post->post_title;
		$new_content = $parsed['content'] ?? $post->post_content;
		$new_excerpt = $parsed['excerpt'] ?? $post->post_excerpt;

		$new_title   = AIResponseCleaner::clean_content( $new_title );
		$new_excerpt = AIResponseCleaner::clean_content( $new_excerpt );

		if ( $is_gutenberg ) {
			$new_content = AIResponseCleaner::clean_gutenberg_content( $new_content );
		} else {
			$new_content = AIResponseCleaner::clean_content( $new_content );
		}

		$update_data = array(
			'ID'           => $post->ID,
			'post_content' => $new_content,
		);

		if ( $update_title ) {
			$update_data['post_title'] = $new_title;
		}

		if ( $update_excerpt ) {
			$update_data['post_excerpt'] = $new_excerpt;
		}

		$updated_id = wp_update_post( $update_data, true );

		if ( is_wp_error( $updated_id ) ) {
			update_post_meta( $post->ID, self::$auto_update_status_key, self::AUTO_UPDATE_STATUS_FAILED );
			update_post_meta( $post->ID, self::$auto_update_error_key, $updated_id->get_error_message() );
			return new \WP_Error( 'update_failed', $updated_id->get_error_message() );
		}

		update_post_meta( $post->ID, self::$auto_update_status_key, self::AUTO_UPDATE_STATUS_COMPLETED );
		update_post_meta( $post->ID, self::$auto_update_timestamp_key, time() );
		delete_post_meta( $post->ID, self::$auto_update_error_key );

		return $updated_id;
	}
}
