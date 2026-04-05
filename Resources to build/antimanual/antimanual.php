<?php
/**
 * Plugin Name: Antimanual
 * Description: Experience the complete AI powerhouse for your website. Seamlessly combine intelligent Chatbots, Auto-Posting, Documentation generation, and smart search to revolutionize your site's capabilities.
 * Plugin URI: https://wordpress.org/plugins/antimanual/
 * Author: Spider Themes
 * Author URI: https://spider-themes.net/
 * Version: 3.3.0
 * Tested up to: 6.9
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: antimanual
 * Domain Path: /languages
 * License: GPL2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ANTIMANUAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANTIMANUAL_URL', plugin_dir_url( __FILE__ ) );
define( 'ANTIMANUAL_IMG', plugin_dir_url( __FILE__ ) . 'assets/img/' );
define( 'ANTIMANUAL_ICONS', plugin_dir_url( __FILE__ ) . 'assets/icons/' );
define( 'ANTIMANUAL_JS', plugin_dir_url( __FILE__ ) . 'assets/js/' );
define( 'ANTIMANUAL_VERSION', '3.3.0' );

require_once ANTIMANUAL_DIR . '/vendor/autoload.php';

if ( function_exists( 'atml_fs' ) ) {
	atml_fs()->set_basename( false, __FILE__ );
} else {
	function atml_fs() {
		global $atml_fs;

		if ( ! isset( $atml_fs ) ) {
			$atml_fs = fs_dynamic_init( array(
				'id'                  => '20195',
				'slug'                => 'antimanual',
				'premium_slug'        => 'antimanual-pro',
				'type'                => 'plugin',
				'public_key'          => 'pk_0735a8aad7f2de773c19250ea08ea',
				'is_premium'          => false,
				'premium_suffix'      => 'Pro',
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'parallel_activation' => array(
					'enabled'                  => true,
					'premium_version_basename' => 'antimanual-pro/antimanual.php',
				),
				'trial'               => array(
					'days'               => 14,
					'is_require_payment' => true,
				),
				'menu'                => array(
					'slug'           => 'antimanual',
					'first-path'     => 'admin.php?page=antimanual',
					'contact'        => false,
					'support'        => false,
				),
			) );
		}

		return $atml_fs;
	}

	atml_fs()->add_filter( 'deactivate_on_activation', '__return_false' );
	atml_fs()->add_filter( 'hide_freemius_powered_by', '__return_true' );

	do_action( 'atml_fs_loaded' );
}

/**
 * Class Antimanual
 *
 * Provides the core functionality for the Antimanual plugin.
 */
class Antimanual {
	public function __construct() {

		$this->includes();
		Antimanual\EmailMarketingFeature::instance();

		Antimanual\API::instance();
		Antimanual\AutoPosting::instance();
		
		// Auto Update is only initialized here for free users
		// Pro users will get Auto Update from the Pro plugin
		if ( ! defined( 'ANTIMANUAL_PRO' ) ) {
			Antimanual\AutoUpdate::instance();
		}
		
		Antimanual\Conversation::instance();
		Antimanual\CronJob::instance();

		Antimanual\SearchBlock::instance();

		register_activation_hook( __FILE__, [ $this, 'handle_activation' ] );

		add_action( 'plugins_loaded', [ $this, 'handle_plugins_loaded' ] );
		add_action( 'admin_head', [ $this, 'remove_admin_notices_on_plugin_pages' ] );
		add_filter( 'template_include', [ $this, 'load_iframe_assistant_template' ] );
		add_filter( 'wp_get_nav_menu_items', [ $this, 'hide_antimanual_assistant_nav_menu' ], 10, 3 );
		add_filter( 'wp_list_pages_excludes', [ $this, 'exclude_assistant_from_page_list' ] );
		add_filter( 'get_pages', [ $this, 'hide_antimanual_assistant_from_get_pages' ], 10, 2 );

		add_action( 'save_post', [ $this, 'maybe_resync_kb_post' ], 20, 3 );
		add_action( 'transition_post_status', [ $this, 'maybe_auto_add_on_publish' ], 20, 3 );
		add_action( 'atml_resync_kb_post', [ $this, 'resync_kb_post' ] );
		add_action( 'atml_auto_add_kb_post', [ $this, 'auto_add_kb_post' ] );
		add_action( 'admin_init', [ $this, 'publish_overdue_scheduled_posts' ] );
		add_action( 'admin_init', [ $this, 'maybe_run_db_migrations' ] );
	}

	/**
	 * Run database migrations when the plugin version changes.
	 *
	 * Ensures schema updates (e.g. new columns, backfills) always run on
	 * plugin updates, not just on activation.
	 */
	public function maybe_run_db_migrations() {
		$stored_version = get_option( 'antimanual_db_version', '0' );

		if ( version_compare( $stored_version, ANTIMANUAL_VERSION, '<' ) ) {
			Antimanual\Embedding::create_table();
			Antimanual\UsageTracker::create_table();
			Antimanual\AutoPostingQueue::create_table();
			Antimanual\EmailMarketingFeature::create_tables();

			update_option( 'antimanual_db_version', ANTIMANUAL_VERSION );
		}
	}

	public function handle_plugins_loaded() {
		load_plugin_textdomain( 'antimanual', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( atml_fs()->can_use_premium_code() && class_exists( 'Antimanual_Pro' ) ) {
			define( 'ANTIMANUAL_PRO', true );
		}
	}

	public function remove_admin_notices_on_plugin_pages() {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id ?? '', 'antimanual' ) !== false ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}
	}

	public function load_iframe_assistant_template( $template ) {
		if ( is_page() ) {
			global $post;
			if ( $post && get_post_meta( $post->ID, '_antimanual_assistant_page', true ) === '1' ) {
				$custom_template = __DIR__ . '/templates/embed_assistant.php';
				if ( file_exists( $custom_template ) ) {
					return $custom_template;
				}
			}
		}

		return $template;
	}

	public function hide_antimanual_assistant_nav_menu( $items, $menu, $args ) {
		foreach ( $items as $key => $item ) {
			if ( $item->object == 'page' && $item->object_id ) {
				$is_iframe = get_post_meta( $item->object_id, '_antimanual_assistant_page', true );
				if ( $is_iframe === '1' ) {
					unset( $items[$key] );
				}
			}
		}
		return $items;
	}

	/**
	 * Exclude the antimanual-assistant page from wp_list_pages() output.
	 *
	 * Many themes (e.g. Twenty Twenty-Five, Astra) auto-generate navigation
	 * menus by calling wp_list_pages() when no custom menu is assigned.
	 *
	 * @param int[] $excluded_pages Array of page IDs to exclude.
	 * @return int[]
	 */
	public function exclude_assistant_from_page_list( $excluded_pages ) {
		$page = get_page_by_path( 'antimanual-assistant' );
		if ( $page ) {
			$excluded_pages[] = $page->ID;
		}
		return $excluded_pages;
	}

	/**
	 * Hide the antimanual-assistant page from get_pages() results.
	 *
	 * Some themes and widgets use get_pages() directly to build page
	 * lists or dropdown selectors on the frontend.
	 *
	 * @param WP_Post[] $pages Array of page objects.
	 * @param array     $parsed_args  The array of parsed arguments.
	 * @return WP_Post[]
	 */
	public function hide_antimanual_assistant_from_get_pages( $pages, $parsed_args ) {
		if ( is_admin() ) {
			return $pages; // Admin list is already handled by pre_get_posts filter.
		}
		return array_filter( $pages, function ( $page ) {
			return get_post_meta( $page->ID, '_antimanual_assistant_page', true ) !== '1';
		} );
	}

	private function includes() {
		// Utility files
		require_once ANTIMANUAL_DIR . '/includes/utils/migrate-wpeazy-ai.php';
		require_once ANTIMANUAL_DIR . '/includes/utils/options.php';
		require_once ANTIMANUAL_DIR . '/includes/utils/helper-functions.php';
		require_once ANTIMANUAL_DIR . '/includes/utils/css.php';
		require_once ANTIMANUAL_DIR . '/includes/utils/post-content.php';

		// Antimanual Data Store
		require_once ANTIMANUAL_DIR . '/includes/utils/store.php';

		// Core files
		require_once ANTIMANUAL_DIR . '/includes/admin/admin-page.php';
		require_once ANTIMANUAL_DIR . '/includes/admin/settings.php';

		// API handlers
		require_once ANTIMANUAL_DIR . '/includes/api/openai-handler.php';
		require_once ANTIMANUAL_DIR . '/includes/api/ajax-handler.php';

		// Database handlers
		require_once ANTIMANUAL_DIR . '/includes/database/db-handler.php';
		require_once ANTIMANUAL_DIR . '/includes/database/auto-posting-queue.php';
		// Note: Translation database table is handled by the Pro plugin

		// Public files
		require_once ANTIMANUAL_DIR . '/includes/public/frontend.php';
		require_once ANTIMANUAL_DIR . '/includes/public/shortcodes.php';
		require_once ANTIMANUAL_DIR . '/includes/public/chat-interface.php';
		// Note: Frontend translation is a Pro-only feature - loaded by Pro plugin

		// Hooks
		require_once ANTIMANUAL_DIR . '/includes/hooks/forumax-hooks.php';

		// Remote Notice
		require_once ANTIMANUAL_DIR . '/includes/classes/class-remote-notice-client.php';

		// Disable notices when Pro is active
		add_action( 'plugins_loaded', function() {
			if ( atml_fs()->can_use_premium_code() && class_exists( 'Antimanual_Pro' ) ) {
				Remote_Notice_Client::disable( 'Antimanual' );
				return;
			}else{
				Remote_Notice_Client::enable( 'Antimanual' );
			}
			
			Remote_Notice_Client::init( 'Antimanual', [
				'api_url' => 'https://manage.spider-themes.net/wp-json/html-notice-widget/v1/content/antimanual',
			]);
		});
	}

	public function handle_activation() {
		atml_save_chatbot_configs();
		Antimanual\Embedding::create_table();
		Antimanual\UsageTracker::create_table();
		Antimanual\AutoPostingQueue::create_table();
		Antimanual\EmailMarketingFeature::create_tables();
		if ( function_exists( 'atml_create_translations_table' ) ) {
			atml_create_translations_table();
		}

		if ( ! get_page_by_path( 'antimanual-assistant' ) ) {
			$iframe_page_id = wp_insert_post( array(
				'post_title'     => 'antimanual-assistant',
				'post_name'      => 'antimanual-assistant',
				'post_content'   => '',
				'post_status'    => 'publish',
				'post_author'    => 1,
				'post_type'      => 'page',
			));
			if ( $iframe_page_id ) {
				update_post_meta( $iframe_page_id, '_antimanual_assistant_page', '1' );
			}
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'antimanual_query_votes';
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			query MEDIUMTEXT NOT NULL,
			answer MEDIUMTEXT NOT NULL,
			is_helpful TINYINT(1) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		dbDelta( $sql );
		
		// Update existing tables: change column types and allow NULL for is_helpful
		$wpdb->query( "ALTER TABLE $table_name MODIFY query MEDIUMTEXT NOT NULL" );
		$wpdb->query( "ALTER TABLE $table_name MODIFY answer MEDIUMTEXT NOT NULL" );
		$wpdb->query( "ALTER TABLE $table_name MODIFY is_helpful TINYINT(1) DEFAULT NULL" );
	}

	/**
	 * Automatically re-sync post to KB when updated.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function maybe_resync_kb_post( $post_id, $post, $update ) {
		// Only on updates to published posts, not revisions or autosaves.
		if ( ! $update || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		global $wpdb;
		$table_name = Antimanual\Embedding::get_table_name();

		// Check if this post exists in the KB.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_modified_gmt FROM `$table_name` WHERE post_id = %d AND type = 'wp' LIMIT 1",
				$post_id
			)
		);

		if ( ! $row ) {
			// Post not yet in KB — check if auto-add is enabled.
			$this->maybe_auto_add_kb_post( $post_id, $post );
			return;
		}

		// Check if stale.
		$kb_time   = strtotime( $row->post_modified_gmt );
		$post_time = strtotime( $post->post_modified_gmt );

		if ( $post_time > $kb_time ) {
			if ( ! wp_next_scheduled( 'atml_resync_kb_post', [ $post_id ] ) ) {
				wp_schedule_single_event( time(), 'atml_resync_kb_post', [ $post_id ] );
				spawn_cron();
			}
		}
	}

	/**
	 * Handler for background KB re-sync.
	 *
	 * @param int $post_id Post ID.
	 */
	public function resync_kb_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Re-check existence to prevent race conditions or manual deletion.
		global $wpdb;
		$table_name = Antimanual\Embedding::get_table_name();
		$exists     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table_name` WHERE post_id = %d AND type = 'wp'",
				$post_id
			)
		);

		if ( ! $exists ) {
			return;
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = preg_replace( '/\s+/', ' ', $content );

		if ( empty( $content ) ) {
			return;
		}

		$result = Antimanual\Embedding::insert( [
			'content' => $content,
			'type'    => 'wp',
			'post_id' => $post_id,
		] );

		if ( is_wp_error( $result ) ) {
			error_log( '[Antimanual] KB re-sync failed for post ' . $post_id . ': ' . $result->get_error_message() );
		}
	}

	/**
	 * Handle post status transition to catch new posts being published.
	 *
	 * This fires when a post transitions from any status to 'publish',
	 * catching cases that save_post might miss (e.g. Classic Editor
	 * where $update is false for newly created posts).
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function maybe_auto_add_on_publish( $new_status, $old_status, $post ) {
		// Only when transitioning to 'publish' from a non-published state.
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		// Check if this post is already in the KB.
		global $wpdb;
		$table_name = Antimanual\Embedding::get_table_name();
		$exists     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table_name` WHERE post_id = %d AND type = 'wp'",
				$post->ID
			)
		);

		if ( $exists ) {
			return;
		}

		$this->maybe_auto_add_kb_post( $post->ID, $post );
	}

	/**
	 * Check if post should be auto-added to KB based on preferences.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	private function maybe_auto_add_kb_post( $post_id, $post ) {
		$prefs = get_option( 'antimanual_kb_preferences', [] );

		if ( empty( $prefs['auto_add_enabled'] ) ) {
			return;
		}

		$allowed_types = isset( $prefs['auto_add_post_types'] ) ? (array) $prefs['auto_add_post_types'] : [ 'post' ];

		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'atml_auto_add_kb_post', [ $post_id ] ) ) {
			wp_schedule_single_event( time(), 'atml_auto_add_kb_post', [ $post_id ] );
			spawn_cron();
		}
	}

	/**
	 * Handler for background auto-add to KB.
	 *
	 * @param int $post_id Post ID.
	 */
	public function auto_add_kb_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Double-check auto-add is still enabled.
		$prefs = get_option( 'antimanual_kb_preferences', [] );
		if ( empty( $prefs['auto_add_enabled'] ) ) {
			return;
		}

		// Prevent duplicate insertions.
		global $wpdb;
		$table_name = Antimanual\Embedding::get_table_name();
		$exists     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table_name` WHERE post_id = %d AND type = 'wp'",
				$post_id
			)
		);

		if ( $exists ) {
			return;
		}

		// Respect free plan limit.
		if ( ! atml_is_pro() ) {
			$wp_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT knowledge_id) FROM `$table_name` WHERE `type` = %s",
					'wp'
				)
			);

			if ( $wp_count >= 10 ) {
				return;
			}
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = preg_replace( '/\s+/', ' ', $content );

		if ( empty( $content ) ) {
			return;
		}

		$result = Antimanual\Embedding::insert( [
			'content' => $content,
			'type'    => 'wp',
			'post_id' => $post_id,
		] );

		if ( is_wp_error( $result ) ) {
			error_log( '[Antimanual] KB auto-add failed for post ' . $post_id . ': ' . $result->get_error_message() );
		}
	}

	/**
	 * Publish overdue scheduled posts created by the plugin.
	 *
	 * WordPress relies on WP-Cron to transition 'future' posts to 'publish',
	 * but WP-Cron may not fire reliably on some environments (local dev, shared
	 * hosting, or when DISABLE_WP_CRON is set). This fallback runs on admin
	 * page loads to catch any overdue scheduled posts.
	 *
	 * @since 2.3.0
	 */
	public function publish_overdue_scheduled_posts() {
		// Only run on plugin pages to minimize overhead.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( empty( $page ) || false === strpos( $page, 'atml' ) ) {
			return;
		}

		$overdue_posts = get_posts(
			array(
				'post_status'    => 'future',
				'meta_key'       => Antimanual\PostGenerator::$meta_key,
				'meta_value'     => '1',
				'date_query'     => array(
					array(
						'before' => current_time( 'mysql' ),
					),
				),
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);

		foreach ( $overdue_posts as $post_id ) {
			wp_publish_post( $post_id );
		}
	}
}

new Antimanual();