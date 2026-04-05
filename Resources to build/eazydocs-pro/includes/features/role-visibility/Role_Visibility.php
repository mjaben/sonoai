<?php
/**
 * Role-Based Content Visibility Feature
 *
 * This feature allows restricting doc visibility to specific WordPress user roles.
 * It is exclusive to the EazyDocs Pro Max plan.
 *
 * @package eazyDocsPro
 * @since 2.10.0
 */

namespace eazyDocsPro\Features\RoleVisibility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Role_Visibility
 *
 * Handles role-based content visibility for docs.
 */
class Role_Visibility {

	/**
	 * The meta key used to store role restrictions.
	 *
	 * @var string
	 */
	const META_KEY = 'ezd_role_visibility';

	/**
	 * The meta key for inheritance setting.
	 *
	 * @var string
	 */
	const INHERIT_META_KEY = 'ezd_role_visibility_inherit';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Respect the global toggle for role visibility.
		if ( function_exists( 'ezd_is_role_visibility_enabled' ) && ! ezd_is_role_visibility_enabled() ) {
			return;
		}

		// Register meta box for docs.
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

		// Save meta box data.
		add_action( 'save_post_docs', [ $this, 'save_meta_box' ], 10, 2 );

		// Filter the docs query on frontend.
		add_action( 'pre_get_posts', [ $this, 'filter_docs_query' ] );

		// Redirect if user doesn't have access.
		add_action( 'template_redirect', [ $this, 'restrict_frontend_access' ] );

		// Filter sidebar navigation items via wp_list_pages.
		add_filter( 'wp_list_pages_excludes', [ $this, 'exclude_restricted_from_sidebar' ] );
		add_filter( 'ezd_sidebar_nav_items', [ $this, 'filter_sidebar_items' ] );

		// Add column to docs list table.
		add_filter( 'manage_docs_posts_columns', [ $this, 'add_admin_column' ] );
		add_action( 'manage_docs_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );

		// AJAX handler for bulk role assignment.
		add_action( 'wp_ajax_ezd_bulk_role_visibility', [ $this, 'ajax_bulk_role_visibility' ] );

		// Add role restriction info to doc builder.
		add_action( 'ezd_doc_builder_item_meta', [ $this, 'doc_builder_role_indicator' ] );

		// Filter search results to exclude restricted docs.
		add_filter( 'pre_get_posts', [ $this, 'filter_search_results' ], 20 );
	}

	/**
	 * Check if user has pro plan.
	 *
	 * @return bool
	 */
	private function is_promax() {
		return function_exists( 'ezd_is_premium' ) && ezd_is_premium();
	}

	/**
	 * Get all WordPress roles.
	 *
	 * @return array Associative array of role slug => role name.
	 */
	public static function get_all_roles() {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles();
		}

		$roles = [];
		foreach ( $wp_roles->roles as $key => $role ) {
			$roles[ $key ] = translate_user_role( $role['name'] );
		}

		return $roles;
	}

	/**
	 * Register the role visibility meta box.
	 */
	public function register_meta_box() {
		add_meta_box(
			'ezd_role_visibility_meta_box',
			__( 'Role-Based Visibility', 'eazydocs-pro' ),
			[ $this, 'render_meta_box' ],
			'docs',
			'side',
			'default'
		);
	}

	/**
	 * Render the role visibility meta box.
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render_meta_box( $post ) {
		// Get saved roles.
		$selected_roles = get_post_meta( $post->ID, self::META_KEY, true );
		$selected_roles = is_array( $selected_roles ) ? $selected_roles : [];

		// Get inheritance setting.
		$inherit = get_post_meta( $post->ID, self::INHERIT_META_KEY, true );
		$inherit = $inherit === '' ? '1' : $inherit; // Default to inherit.

		// Get all available roles.
		$all_roles = self::get_all_roles();

		// Check if this doc has a parent with role restrictions.
		$parent_roles = $this->get_inherited_roles( $post->ID );

		wp_nonce_field( 'ezd_role_visibility_save', 'ezd_role_visibility_nonce' );
		?>
		<div class="ezd-role-visibility-wrap">
			<p class="description" style="margin-bottom: 12px;">
				<?php esc_html_e( 'Restrict this doc to specific user roles. If no roles are selected, all logged-in users can view.', 'eazydocs-pro' ); ?>
			</p>

			<?php if ( ! empty( $parent_roles ) ) : ?>
				<div class="ezd-inherited-roles" style="background: #f9f9f9; padding: 10px; border-radius: 4px; margin-bottom: 12px;">
					<strong><?php esc_html_e( 'Parent Restrictions:', 'eazydocs-pro' ); ?></strong>
					<p style="margin: 5px 0 0; color: #666;">
						<?php echo esc_html( implode( ', ', array_map( function( $role ) use ( $all_roles ) {
							return isset( $all_roles[ $role ] ) ? $all_roles[ $role ] : $role;
						}, $parent_roles ) ) ); ?>
					</p>
				</div>

				<p style="margin-bottom: 10px;">
					<label>
						<input type="checkbox" name="ezd_role_visibility_inherit" value="1" <?php checked( $inherit, '1' ); ?>>
						<?php esc_html_e( 'Inherit from parent', 'eazydocs-pro' ); ?>
					</label>
				</p>
			<?php endif; ?>

			<div class="ezd-role-checkboxes" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
				<?php foreach ( $all_roles as $role_slug => $role_name ) : ?>
					<label style="display: block; margin-bottom: 6px;">
						<input 
							type="checkbox" 
							name="ezd_role_visibility[]" 
							value="<?php echo esc_attr( $role_slug ); ?>"
							<?php checked( in_array( $role_slug, $selected_roles, true ) ); ?>
						>
						<?php echo esc_html( $role_name ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<p style="margin-top: 12px;">
				<label>
					<input type="checkbox" name="ezd_role_visibility_guest" value="1" <?php checked( in_array( 'guest', $selected_roles, true ) ); ?>>
					<?php esc_html_e( 'Allow guests (not logged in)', 'eazydocs-pro' ); ?>
				</label>
			</p>

			<p style="margin-top: 12px;">
				<label>
					<input type="checkbox" id="ezd_apply_to_children" name="ezd_apply_to_children" value="1">
					<?php esc_html_e( 'Apply to all child docs', 'eazydocs-pro' ); ?>
				</label>
			</p>
		</div>

		<style>
			.ezd-role-visibility-wrap .ezd-role-checkboxes::-webkit-scrollbar {
				width: 6px;
			}
			.ezd-role-visibility-wrap .ezd-role-checkboxes::-webkit-scrollbar-thumb {
				background: #ccc;
				border-radius: 3px;
			}
		</style>
		<?php
	}

	/**
	 * Save the role visibility meta box data.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 */
	public function save_meta_box( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['ezd_role_visibility_nonce'] ) ||
		     ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ezd_role_visibility_nonce'] ) ), 'ezd_role_visibility_save' ) ) {
			return;
		}

		// Check user capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Don't save on autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Sanitize and save roles.
		$roles = [];
		if ( isset( $_POST['ezd_role_visibility'] ) && is_array( $_POST['ezd_role_visibility'] ) ) {
			$roles = array_map( 'sanitize_text_field', wp_unslash( $_POST['ezd_role_visibility'] ) );
		}

		// Add guest if checked.
		if ( isset( $_POST['ezd_role_visibility_guest'] ) ) {
			$roles[] = 'guest';
		}

		// Save the roles.
		update_post_meta( $post_id, self::META_KEY, $roles );

		// Save inheritance setting.
		$inherit = isset( $_POST['ezd_role_visibility_inherit'] ) ? '1' : '0';
		update_post_meta( $post_id, self::INHERIT_META_KEY, $inherit );

		// Apply to children if requested.
		if ( isset( $_POST['ezd_apply_to_children'] ) && $_POST['ezd_apply_to_children'] === '1' ) {
			$this->apply_roles_to_children( $post_id, $roles );
		}
	}

	/**
	 * Apply role restrictions to all child docs.
	 *
	 * @param int   $parent_id The parent post ID.
	 * @param array $roles     The roles to apply.
	 */
	private function apply_roles_to_children( $parent_id, $roles ) {
		$children = get_children( [
			'post_parent' => $parent_id,
			'post_type'   => 'docs',
			'post_status' => 'any',
		] );

		foreach ( $children as $child ) {
			update_post_meta( $child->ID, self::META_KEY, $roles );
			update_post_meta( $child->ID, self::INHERIT_META_KEY, '1' );
			
			// Recursively apply to grandchildren.
			$this->apply_roles_to_children( $child->ID, $roles );
		}
	}

	/**
	 * Get inherited roles from parent docs.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array The inherited roles.
	 */
	public function get_inherited_roles( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! $post->post_parent ) {
			return [];
		}

		$parent_id = $post->post_parent;

		while ( $parent_id ) {
			$parent_roles = get_post_meta( $parent_id, self::META_KEY, true );

			if ( ! empty( $parent_roles ) && is_array( $parent_roles ) ) {
				return $parent_roles;
			}

			$parent = get_post( $parent_id );
			$parent_id = $parent ? $parent->post_parent : 0;
		}

		return [];
	}

	/**
	 * Get effective roles for a doc (own or inherited).
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array The effective roles.
	 */
	public static function get_effective_roles( $post_id ) {
		$own_roles = get_post_meta( $post_id, self::META_KEY, true );
		$own_roles = is_array( $own_roles ) ? $own_roles : [];

		// If has own roles, return them.
		if ( ! empty( $own_roles ) ) {
			return $own_roles;
		}

		// Check inheritance.
		$inherit = get_post_meta( $post_id, self::INHERIT_META_KEY, true );
		
		if ( $inherit !== '0' ) {
			$instance = new self();
			$inherited = $instance->get_inherited_roles( $post_id );
			if ( ! empty( $inherited ) ) {
				return $inherited;
			}
		}

		return [];
	}

	/**
	 * Check if a user can access a specific doc.
	 *
	 * @param int      $post_id The post ID.
	 * @param int|null $user_id The user ID (null for current user).
	 *
	 * @return bool Whether the user can access the doc.
	 */
	public static function user_can_access( $post_id, $user_id = null ) {
		$roles = self::get_effective_roles( $post_id );

		// No restrictions - everyone can access.
		if ( empty( $roles ) ) {
			return true;
		}

		// Check for guest access.
		if ( in_array( 'guest', $roles, true ) ) {
			return true;
		}

		// User must be logged in for restricted content.
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		// Administrators always have access.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Get user roles.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$user_roles = $user->roles;

		// Check if any of the user's roles match the allowed roles.
		return ! empty( array_intersect( $roles, $user_roles ) );
	}

	/**
	 * Filter docs query to exclude restricted docs.
	 *
	 * @param \WP_Query $query The query object.
	 */
	public function filter_docs_query( $query ) {
		// Only filter on frontend.
		if ( is_admin() ) {
			return;
		}

		// Avoid excluding single doc requests so redirect logic can run.
		if ( $query->is_singular( 'docs' ) ) {
			return;
		}

		// Only filter docs queries.
		if ( $query->get( 'post_type' ) !== 'docs' ) {
			return;
		}

		// Skip if user is admin.
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// Add meta query to exclude restricted docs.
		add_filter( 'posts_where', [ $this, 'add_role_visibility_where_clause' ], 10, 2 );
	}

	/**
	 * Add WHERE clause for role visibility filtering.
	 *
	 * @param string    $where The WHERE clause.
	 * @param \WP_Query $query The query object.
	 *
	 * @return string Modified WHERE clause.
	 */
	public function add_role_visibility_where_clause( $where, $query ) {
		global $wpdb;

		if ( $query->get( 'post_type' ) !== 'docs' ) {
			return $where;
		}

		$current_user_id = get_current_user_id();
		$user_roles = [];

		if ( $current_user_id ) {
			$user = get_userdata( $current_user_id );
			if ( $user ) {
				$user_roles = $user->roles;
			}
		}

		// Include 'guest' if not logged in.
		if ( ! $current_user_id ) {
			$user_roles[] = 'guest';
		}

		// Build the exclusion subquery.
		$role_conditions = [];
		foreach ( $user_roles as $role ) {
			$role_conditions[] = $wpdb->prepare( "pm.meta_value LIKE %s", '%' . $wpdb->esc_like( $role ) . '%' );
		}

		$role_condition_sql = ! empty( $role_conditions ) ? '(' . implode( ' OR ', $role_conditions ) . ')' : '1=0';

		$subquery = "
			AND (
				{$wpdb->posts}.ID NOT IN (
					SELECT pm.post_id 
					FROM {$wpdb->postmeta} pm 
					WHERE pm.meta_key = %s 
					AND pm.meta_value != '' 
					AND pm.meta_value != 'a:0:{}'
					AND NOT {$role_condition_sql}
				)
			)
		";

		$where .= $wpdb->prepare( $subquery, self::META_KEY );

		// Remove this filter to prevent it from being applied to other queries.
		remove_filter( 'posts_where', [ $this, 'add_role_visibility_where_clause' ], 10 );

		return $where;
	}

	/**
	 * Restrict frontend access to docs.
	 */
	public function restrict_frontend_access() {
		if ( ! is_singular( 'docs' ) ) {
			return;
		}

		global $post;

		if ( ! self::user_can_access( $post->ID ) ) {
			$this->handle_restricted_access();
		}
	}

	/**
	 * Handle restricted access.
	 */
	private function handle_restricted_access() {
		// Get all options directly for debugging.
		$all_opts = get_option( 'eazydocs_settings', array() );

		// Get redirect mode with multiple fallback attempts.
		$redirect_mode = '';

		// First, try to get from direct options array.
		if ( isset( $all_opts['role_visibility_redirect_mode'] ) && ! empty( $all_opts['role_visibility_redirect_mode'] ) ) {
			$redirect_mode = $all_opts['role_visibility_redirect_mode'];
		}

		// If empty, try the ezd_get_opt function.
		if ( empty( $redirect_mode ) ) {
			$redirect_mode = ezd_get_opt( 'role_visibility_redirect_mode', '' );
		}

		// If still empty, use 'login' as default.
		if ( empty( $redirect_mode ) ) {
			$redirect_mode = 'login';
		}

		// Trim and lowercase to ensure consistent comparison.
		$redirect_mode = strtolower( trim( $redirect_mode ) );

		// Debug: Log all related settings.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '=== EazyDocs Role Visibility Debug ===' );
			error_log( 'Redirect Mode (final): ' . $redirect_mode );
			error_log( 'Redirect Mode (raw from opts): ' . ( isset( $all_opts['role_visibility_redirect_mode'] ) ? $all_opts['role_visibility_redirect_mode'] : 'NOT SET' ) );
			error_log( 'Redirect Mode type: ' . gettype( isset( $all_opts['role_visibility_redirect_mode'] ) ? $all_opts['role_visibility_redirect_mode'] : null ) );
			error_log( 'Login Page ID: ' . ( isset( $all_opts['role_visibility_login_page'] ) ? print_r( $all_opts['role_visibility_login_page'], true ) : 'NOT SET' ) );
			error_log( 'Custom Page ID: ' . ( isset( $all_opts['role_visibility_custom_page'] ) ? print_r( $all_opts['role_visibility_custom_page'], true ) : 'NOT SET' ) );
			error_log( 'Feature Enabled: ' . ( isset( $all_opts['role_visibility_enable'] ) ? print_r( $all_opts['role_visibility_enable'], true ) : 'NOT SET' ) );
			error_log( '=======================================' );
		}

		// Handle redirect based on mode.
		if ( 'login' === $redirect_mode ) {
			// Prefer the (legacy) dedicated setting if present, otherwise reuse the Private Doc login page.
			$login_page = isset( $all_opts['role_visibility_login_page'] ) ? $all_opts['role_visibility_login_page'] : '';
			if ( empty( $login_page ) && isset( $all_opts['private_doc_login_page'] ) ) {
				$login_page = $all_opts['private_doc_login_page'];
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EazyDocs Role Visibility - Processing LOGIN mode, Page ID: ' . print_r( $login_page, true ) );
			}

			if ( ! empty( $login_page ) ) {
				$redirect_url = get_permalink( $login_page );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'EazyDocs Role Visibility - Login Page URL: ' . ( $redirect_url ? $redirect_url : 'FAILED TO GET' ) );
				}
				if ( $redirect_url ) {
					wp_safe_redirect( $redirect_url );
					exit;
				}
			}

			// Fallback to WordPress login with redirect back.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EazyDocs Role Visibility - Fallback to WP Login' );
			}
			wp_safe_redirect( wp_login_url( get_permalink() ) );
			exit;

		} elseif ( 'custom' === $redirect_mode ) {
			$custom_page = isset( $all_opts['role_visibility_custom_page'] ) ? $all_opts['role_visibility_custom_page'] : '';

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EazyDocs Role Visibility - Processing CUSTOM mode, Page ID: ' . print_r( $custom_page, true ) );
			}

			if ( ! empty( $custom_page ) ) {
				$redirect_url = get_permalink( $custom_page );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'EazyDocs Role Visibility - Custom Page URL: ' . ( $redirect_url ? $redirect_url : 'FAILED TO GET' ) );
				}
				if ( $redirect_url ) {
					wp_safe_redirect( $redirect_url );
					exit;
				}
			}

			// Fallback to WordPress login if custom page not set.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EazyDocs Role Visibility - Custom page not set, fallback to WP Login' );
			}
			wp_safe_redirect( wp_login_url( get_permalink() ) );
			exit;

		} elseif ( '404' === $redirect_mode ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EazyDocs Role Visibility - Processing 404 mode' );
			}
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;

		} else {
			// Unknown mode - default to login behavior.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EazyDocs Role Visibility - Unknown mode "' . $redirect_mode . '", defaulting to login' );
			}
			wp_safe_redirect( wp_login_url( get_permalink() ) );
			exit;
		}
	}

	/**
	 * Filter sidebar navigation items.
	 *
	 * @param array $items The navigation items.
	 *
	 * @return array Filtered navigation items.
	 */
	public function filter_sidebar_items( $items ) {
		if ( current_user_can( 'manage_options' ) ) {
			return $items;
		}

		return array_filter( $items, function( $item ) {
			$post_id = is_object( $item ) ? $item->ID : ( isset( $item['ID'] ) ? $item['ID'] : 0 );
			return self::user_can_access( $post_id );
		} );
	}

	/**
	 * Add admin column for role visibility.
	 *
	 * @param array $columns The columns array.
	 *
	 * @return array Modified columns array.
	 */
	public function add_admin_column( $columns ) {
		$new_columns = [];
		
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			
			if ( $key === 'title' ) {
				$new_columns['role_visibility'] = __( 'Visibility', 'eazydocs-pro' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render admin column content.
	 *
	 * @param string $column  The column name.
	 * @param int    $post_id The post ID.
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( $column !== 'role_visibility' ) {
			return;
		}

		$roles = self::get_effective_roles( $post_id );
		$all_roles = self::get_all_roles();

		if ( empty( $roles ) ) {
			echo '<span style="color: #28a745;">&#10003; ' . esc_html__( 'Public', 'eazydocs-pro' ) . '</span>';
			return;
		}

		$role_names = array_map( function( $role ) use ( $all_roles ) {
			if ( $role === 'guest' ) {
				return __( 'Guest', 'eazydocs-pro' );
			}
			return isset( $all_roles[ $role ] ) ? $all_roles[ $role ] : $role;
		}, $roles );

		echo '<span style="color: #dc3545;" title="' . esc_attr( implode( ', ', $role_names ) ) . '">&#128274; ';
		echo esc_html( count( $role_names ) . ' ' . _n( 'role', 'roles', count( $role_names ), 'eazydocs-pro' ) );
		echo '</span>';
	}

	/**
	 * Add role indicator to doc builder.
	 *
	 * @param int $post_id The post ID.
	 */
	public function doc_builder_role_indicator( $post_id ) {
		$roles = self::get_effective_roles( $post_id );

		if ( empty( $roles ) ) {
			return;
		}

		$all_roles = self::get_all_roles();
		$role_names = array_map( function( $role ) use ( $all_roles ) {
			if ( $role === 'guest' ) {
				return __( 'Guest', 'eazydocs-pro' );
			}
			return isset( $all_roles[ $role ] ) ? $all_roles[ $role ] : $role;
		}, $roles );

		echo '<span class="ezd-role-badge" title="' . esc_attr( __( 'Restricted to: ', 'eazydocs-pro' ) . implode( ', ', $role_names ) ) . '" style="background: #fff3cd; color: #856404; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">&#128274;</span>';
	}

	/**
	 * AJAX handler for bulk role visibility.
	 */
	public function ajax_bulk_role_visibility() {
		check_ajax_referer( 'ezd_bulk_role_visibility', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'eazydocs-pro' ) ] );
		}

		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : [];
		$roles = isset( $_POST['roles'] ) ? array_map( 'sanitize_text_field', (array) $_POST['roles'] ) : [];
		$apply_to_children = isset( $_POST['apply_to_children'] ) && $_POST['apply_to_children'] === '1';

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, self::META_KEY, $roles );

			if ( $apply_to_children ) {
				$this->apply_roles_to_children( $post_id, $roles );
			}
		}

		wp_send_json_success( [
			'message' => sprintf( 
				/* translators: %d: number of docs updated */
				__( 'Updated role visibility for %d doc(s).', 'eazydocs-pro' ), 
				count( $post_ids ) 
			)
		] );
	}

	/**
	 * Exclude restricted docs from wp_list_pages sidebar navigation.
	 *
	 * @param array $exclude_array Array of post IDs to exclude.
	 *
	 * @return array Modified exclude array.
	 */
	public function exclude_restricted_from_sidebar( $exclude_array ) {
		// Skip for admins.
		if ( current_user_can( 'manage_options' ) ) {
			return $exclude_array;
		}

		// Check if hiding from nav is enabled.
		$hide_from_nav = ezd_get_opt( 'role_visibility_hide_from_nav', true );
		if ( ! $hide_from_nav ) {
			return $exclude_array;
		}

		// Get all restricted docs.
		$restricted_docs = $this->get_restricted_doc_ids();

		// Filter out docs the user can access.
		foreach ( $restricted_docs as $doc_id ) {
			if ( ! self::user_can_access( $doc_id ) ) {
				$exclude_array[] = $doc_id;
			}
		}

		return array_unique( $exclude_array );
	}

	/**
	 * Get all doc IDs that have role restrictions.
	 *
	 * @return array Array of post IDs.
	 */
	private function get_restricted_doc_ids() {
		global $wpdb;

		static $cached_ids = null;

		if ( $cached_ids !== null ) {
			return $cached_ids;
		}

		$results = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id 
			FROM {$wpdb->postmeta} 
			WHERE meta_key = %s 
			AND meta_value != '' 
			AND meta_value != 'a:0:{}'",
			self::META_KEY
		) );

		$cached_ids = array_map( 'absint', $results );

		return $cached_ids;
	}

	/**
	 * Filter search results to exclude restricted docs.
	 *
	 * @param \WP_Query $query The query object.
	 */
	public function filter_search_results( $query ) {
		// Only filter on frontend search.
		if ( is_admin() || ! $query->is_search() ) {
			return;
		}

		// Skip if user is admin.
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if excluding from search is enabled.
		$exclude_from_search = ezd_get_opt( 'role_visibility_exclude_search', true );
		if ( ! $exclude_from_search ) {
			return;
		}

		// Get restricted docs the user cannot access.
		$excluded_ids = [];
		$restricted_docs = $this->get_restricted_doc_ids();

		foreach ( $restricted_docs as $doc_id ) {
			if ( ! self::user_can_access( $doc_id ) ) {
				$excluded_ids[] = $doc_id;
			}
		}

		if ( ! empty( $excluded_ids ) ) {
			$existing_excludes = $query->get( 'post__not_in' );
			$existing_excludes = is_array( $existing_excludes ) ? $existing_excludes : [];
			$query->set( 'post__not_in', array_merge( $existing_excludes, $excluded_ids ) );
		}
	}
}
