<?php

namespace eazyDocsPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Actions
 *
 * @package eazyDocsPro\Admin
 */
class Admin_Actions {
	function __construct() {
		add_action( 'eazydocs_duplicate', [ $this, 'eazydocs_duplicate' ], 99, 1 );
		add_action( 'eazydocs_visibility', [ $this, 'eazydocs_visibility' ], 99, 1 );

		add_action( 'eazydocs_doc_sidebar', [ $this, 'doc_sidebar' ], 99, 5 );
		add_action( 'eazydocs_notification', [ $this, 'notification' ], 99, 1 );
		add_action( 'wp_ajax_eazydocs_sortable_docs', [ $this, 'sortable_docs' ] );
		add_action( 'wp_ajax_ezd_load_more_notifications', [ $this, 'ajax_load_more_notifications' ] );
		add_action( 'wp_ajax_ezd_bulk_feedback_action', [ $this, 'ajax_bulk_feedback_action' ] );
		add_action( 'wp_ajax_ezd_toggle_feedback_star', [ $this, 'ajax_toggle_feedback_star' ] );
		add_action( 'ezd_pro_admin_menu', [ $this, 'ezd_admin_menu' ], 0, 1 );
		add_action( 'eazydocs_parent_doc_drag', [ $this, 'parent_doc_drag' ] );
		add_action( 'eazydocs_social_share', [ $this, 'social_share' ] );
	}

	/**
	 * EazyDocs Parent Duplicator
	 *
	 * @param $id
	 * @param $parent
	 */
	public function eazydocs_duplicate( $id ) {
		$nonce     = wp_create_nonce( $id );
		$duplicate = esc_attr( $id );

		$url = add_query_arg(
			[
				'action'    => 'doc_duplicate',
				'_wpnonce'  => $nonce,
				'duplicate' => $duplicate,
			],
			admin_url( 'admin.php' )
		);
		?>
		<a href="<?php echo esc_url( $url ); ?>" target="_blank"
		class="docs-duplicate" title="<?php esc_attr_e( 'Duplicate this doc with the child docs.', 'eazydocs-pro' ); ?>">
			<span class="dashicons dashicons-admin-page"></span>
			<span class="duplicate-title"><?php esc_html_e( 'Duplicate', 'eazydocs-pro' ); ?></span>
		</a>
		<?php
	}

	/**
	 * EazyDocs Doc visibility
	 *
	 * @param $id
	 * @param $parent
	 */
	public function eazydocs_visibility( $id ) {
		$nonce  = wp_create_nonce( $id );
		$doc_id = esc_attr( $id );

		$post = get_post( $id );
		$current_visibility = 'publish';
		$current_password   = '';
		if ( $post instanceof \WP_Post ) {
			if ( 'private' === $post->post_status ) {
				$current_visibility = 'private';
			} elseif ( ! empty( $post->post_password ) ) {
				// Password-protected docs are stored as publish + post_password.
				$current_visibility = 'protected';
				$current_password   = $post->post_password;
			}
		}

		// Role-based visibility is a Pro feature.
		$role_visibility_roles = [];
		$role_visibility_guest = '0';
		if ( function_exists( 'ezd_is_premium' ) && ezd_is_premium() ) {
			$roles_meta = get_post_meta( $id, 'ezd_role_visibility', true );
			if ( is_array( $roles_meta ) ) {
				$role_visibility_guest = in_array( 'guest', $roles_meta, true ) ? '1' : '0';
				$role_visibility_roles = array_values( array_diff( $roles_meta, [ 'guest' ] ) );
			}
		}

		$url = add_query_arg(
			[
				'doc_visibility' => $doc_id,
				'_wpnonce'       => $nonce,
			],
			admin_url( 'admin.php' )
		);
		?>
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="docs-visibility"
			data-ezd-visibility="<?php echo esc_attr( $current_visibility ); ?>"
			data-ezd-password="<?php echo esc_attr( $current_password ); ?>"
			data-ezd-role-visibility="<?php echo esc_attr( implode( ',', $role_visibility_roles ) ); ?>"
			data-ezd-role-guest="<?php echo esc_attr( $role_visibility_guest ); ?>"
		title="<?php esc_attr_e( 'Docs visibility', 'eazydocs-pro' ); ?>">
			<span class="dashicons dashicons-visibility"></span>
			<span class="visibility-title"><?php esc_html_e( 'Visibility', 'eazydocs-pro' ); ?></span>
		</a>
		<?php
	}

	/**
	 * EazyDocs Doc Sidebar
	 *
	 * @param $id
	 * @param $parent
	 */
	public function doc_sidebar( $id, $left_type, $left_cont, $right_type, $right_cont ) {
		// Safely sanitize all components before using them
		$doc_id     = sanitize_key( $id );
		$left_type  = sanitize_key( $left_type );
		$left_cont  = sanitize_key( $left_cont );
		$right_type = sanitize_key( $right_type );
		$right_cont = sanitize_key( $right_cont );

		// Create a unique nonce action using all relevant parts
		$nonce_action = $doc_id . $left_type . $left_cont . $right_type . $right_cont;
		$nonce        = wp_create_nonce( $nonce_action );

		// Build the safe URL
		$url = add_query_arg(
			[
				'doc_sidebar' => $nonce_action,
				'_wpnonce'    => $nonce,
			],
			admin_url( 'admin.php' )
		);
		?>
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="docs-sidebar"
		title="<?php esc_attr_e( 'Docs Sidebar', 'eazydocs-pro' ); ?>">
			<span class="dashicons dashicons-welcome-widgets-menus"></span>
			<span class="sidebar-title"><?php esc_html_e( 'Sidebar', 'eazydocs-pro' ); ?></span>
		</a>
		<?php
	}

	public function parent_doc_drag() {
		if ( current_user_can( 'manage_options' ) ) :
			?>
			<div class="dd-handle dd3-handle" style="z-index: 1;">
				<svg class="dd-handle-icon" width="15px" height="15px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
					title="<?php esc_attr_e( 'Hold the mouse and drag to move this doc.', 'eazydocs-pro' ); ?>">
					<path fill="none" stroke="#bbc0c4" stroke-width="2"
						d="M15,5 L17,5 L17,3 L15,3 L15,5 Z M7,5 L9,5 L9,3 L7,3 L7,5 Z M15,13 L17,13 L17,11 L15,11 L15,13 Z M7,13 L9,13 L9,11 L7,11 L7,13 Z M15,21 L17,21 L17,19 L15,19 L15,21 Z M7,21 L9,21 L9,19 L7,19 L7,21 Z"/>
				</svg>
			</div>
			<?php
		endif;
	}

	/**
	 * EazyDocs Notification - Redesigned with improved UI/UX
	 */
	public function notification() {
		$counter = eazydocs_voted() + ezd_comment_count();
		$nonce   = wp_create_nonce( 'ezd_notification_nonce' );
		?>
		<li class="easydocs-notification ezd-notification-redesigned" title="<?php esc_attr_e( 'Notifications', 'eazydocs-pro' ); ?>">
			<div class="header-notify-icon">
				<svg class="notify-icon-svg" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M12 22C13.1 22 14 21.1 14 20H10C10 21.1 10.9 22 12 22ZM18 16V11C18 7.93 16.36 5.36 13.5 4.68V4C13.5 3.17 12.83 2.5 12 2.5C11.17 2.5 10.5 3.17 10.5 4V4.68C7.63 5.36 6 7.92 6 11V16L4 18V19H20V18L18 16Z" fill="currentColor"/>
				</svg>
			</div>

			<?php if ( $counter > 0 ) : ?>
				<span class="easydocs-badge ezd-notification-count">
					<?php echo $counter > 99 ? '99+' : esc_html( $counter ); ?>
				</span>
			<?php endif; ?>

			<div class="easydocs-dropdown notification-dropdown ezd-notification-panel">
				<!-- Improved Header -->
				<div class="ezd-notification-header">
					<div class="ezd-notification-header-left">
						<h4 class="ezd-notification-title">
							<?php esc_html_e( 'Notifications', 'eazydocs-pro' ); ?>
						</h4>
						<?php if ( $counter > 0 ) : ?>
							<span class="ezd-notification-header-badge">
								<?php
								/* translators: %d: notification count */
								printf( esc_html__( '%d new', 'eazydocs-pro' ), $counter );
								?>
							</span>
						<?php endif; ?>
					</div>
					<button type="button" class="ezd-notification-mark-read" title="<?php esc_attr_e( 'Mark all as read', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-yes-alt"></span>
					</button>
				</div>

				<?php if ( $counter > 0 ) : ?>
					<!-- Filter Tabs -->
					<div class="ezd-notification-filters">
						<button type="button" class="ezd-filter-tab is-active" data-filter="all">
							<span class="ezd-filter-icon">
								<span class="dashicons dashicons-list-view"></span>
							</span>
							<span class="ezd-filter-text"><?php esc_html_e( 'All', 'eazydocs-pro' ); ?></span>
						</button>
						<button type="button" class="ezd-filter-tab" data-filter="comment">
							<span class="ezd-filter-icon">
								<span class="dashicons dashicons-format-chat"></span>
							</span>
							<span class="ezd-filter-text"><?php esc_html_e( 'Comments', 'eazydocs-pro' ); ?></span>
						</button>
						<button type="button" class="ezd-filter-tab" data-filter="vote">
							<span class="ezd-filter-icon">
								<span class="dashicons dashicons-thumbs-up"></span>
							</span>
							<span class="ezd-filter-text"><?php esc_html_e( 'Votes', 'eazydocs-pro' ); ?></span>
						</button>
					</div>

					<!-- Notification List Container -->
					<div class="ezd-notification-list-container">
						<?php
						// Pre-render to check if there are items.
						ob_start();
						$this->render_notification_items( 1, 10, 'all' );
						$initial_items = ob_get_clean();

						if ( ! empty( trim( $initial_items ) ) ) :
							?>
							<div class="ezd-notification-list" data-page="1" data-per-page="10" data-filter="all" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php echo $initial_items; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-rendered HTML. ?>
							</div>

							<!-- Loading indicator for infinite scroll -->
							<div class="ezd-notification-loader" style="display: none;">
								<div class="ezd-loader-spinner"></div>
								<span><?php esc_html_e( 'Loading more...', 'eazydocs-pro' ); ?></span>
							</div>

							<!-- End of list message -->
							<div class="ezd-notification-end" style="display: none;">
								<span class="dashicons dashicons-yes-alt"></span>
								<span><?php esc_html_e( "You're all caught up!", 'eazydocs-pro' ); ?></span>
							</div>
						<?php endif; ?>
					</div>

				<?php else : ?>
					<!-- Empty State -->
					<div class="ezd-notification-empty">
						<div class="ezd-empty-icon">
							<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 22C13.1 22 14 21.1 14 20H10C10 21.1 10.9 22 12 22ZM18 16V11C18 7.93 16.36 5.36 13.5 4.68V4C13.5 3.17 12.83 2.5 12 2.5C11.17 2.5 10.5 3.17 10.5 4V4.68C7.63 5.36 6 7.92 6 11V16L4 18V19H20V18L18 16Z" fill="#ddd"/>
							</svg>
						</div>
						<h5 class="ezd-empty-title"><?php esc_html_e( 'No notifications yet', 'eazydocs-pro' ); ?></h5>
						<p class="ezd-empty-text"><?php esc_html_e( "When you get notifications, they'll show up here.", 'eazydocs-pro' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/**
	 * Render notification items for pagination
	 *
	 * @param int    $page    Current page number.
	 * @param int    $per_page Items per page.
	 * @param string $filter  Filter type (all, comment, vote).
	 */
	public function render_notification_items( $page = 1, $per_page = 10, $filter = 'all' ) {
		$offset         = ( $page - 1 ) * $per_page;
		$items_rendered = 0;
		$total_items    = [];

		// Get votes if filter allows
		if ( 'all' === $filter || 'vote' === $filter ) {
			$args = [
				'post_type'      => 'docs',
				'posts_per_page' => -1,
				'post_status'    => [ 'publish' ],
			];

			foreach ( get_posts( $args ) as $post ) {
				// Positive votes
				$positive_time = get_post_meta( $post->ID, 'positive_time', true );
				if ( ! empty( $positive_time ) ) {
					$total_items[] = [
						'type'      => 'vote',
						'vote_type' => 'positive',
						'post_id'   => $post->ID,
						'timestamp' => strtotime( $positive_time ),
						'time_str'  => $positive_time,
					];
				}

				// Negative votes
				$negative_time = get_post_meta( $post->ID, 'negative_time', true );
				if ( ! empty( $negative_time ) ) {
					$total_items[] = [
						'type'      => 'vote',
						'vote_type' => 'negative',
						'post_id'   => $post->ID,
						'timestamp' => strtotime( $negative_time ),
						'time_str'  => $negative_time,
					];
				}
			}
		}

		// Get comments if filter allows
		if ( 'all' === $filter || 'comment' === $filter ) {
			$comments_args = [
				'post_status' => 'publish',
				'post_type'   => [ 'docs' ],
				'parent'      => 0,
				'order'       => 'desc',
				'number'      => 100, // Get a reasonable number to sort.
			];
			$comments      = get_comments( $comments_args );

			foreach ( $comments as $comment ) {
				$total_items[] = [
					'type'      => 'comment',
					'comment'   => $comment,
					'timestamp' => strtotime( $comment->comment_date ),
				];
			}
		}

		// Sort all items by timestamp (newest first)
		usort(
			$total_items,
			function ( $a, $b ) {
				return $b['timestamp'] - $a['timestamp'];
			}
		);

		// Get the paginated slice
		$paginated_items = array_slice( $total_items, $offset, $per_page );

		if ( empty( $paginated_items ) && $page > 1 ) {
			// No more items - signal end
			echo '<div class="ezd-no-more-items" data-end="true"></div>';
			return;
		}

		foreach ( $paginated_items as $item ) :
			++$items_rendered;

			if ( 'vote' === $item['type'] ) :
				$post        = get_post( $item['post_id'] );
				$is_positive = 'positive' === $item['vote_type'];
				$vote_class  = $is_positive ? 'ezd-vote-positive' : 'ezd-vote-negative';
				$vote_icon   = $is_positive ? 'dashicons-thumbs-up' : 'dashicons-thumbs-down';
				$vote_label  = $is_positive ? __( 'Positive vote', 'eazydocs-pro' ) : __( 'Negative vote', 'eazydocs-pro' );
				?>
				<a href="<?php echo esc_url( get_the_permalink( $post ) ); ?>"
					class="ezd-notification-item ezd-item-vote <?php echo esc_attr( $vote_class ); ?>"
					target="_blank">
					<div class="ezd-notification-avatar">
						<?php
						if ( has_post_thumbnail( $post->ID ) ) {
							echo get_the_post_thumbnail( $post->ID, 'thumbnail' );
						} else {
							?>
							<div class="ezd-avatar-placeholder">
								<span class="dashicons dashicons-media-document"></span>
							</div>
							<?php
						}
						?>
						<span class="ezd-notification-type-badge <?php echo esc_attr( $vote_class ); ?>">
							<span class="dashicons <?php echo esc_attr( $vote_icon ); ?>"></span>
						</span>
					</div>
					<div class="ezd-notification-content">
						<p class="ezd-notification-text">
							<strong><?php echo esc_html( $vote_label ); ?></strong>
							<?php esc_html_e( 'on', 'eazydocs-pro' ); ?>
							<span class="ezd-doc-title"><?php echo esc_html( get_the_title( $post->ID ) ); ?></span>
						</p>
						<time class="ezd-notification-time">
							<?php
							echo esc_html(
								human_time_diff( $item['timestamp'], time() ) .
								__( ' ago', 'eazydocs-pro' )
							);
							?>
						</time>
					</div>
				</a>
				<?php
			elseif ( 'comment' === $item['type'] ) :
				$comment = $item['comment'];
				?>
				<a href="<?php echo esc_url( get_comment_link( $comment ) ); ?>"
					class="ezd-notification-item ezd-item-comment"
					target="_blank">
					<div class="ezd-notification-avatar">
						<?php echo get_avatar( $comment, 40 ); ?>
						<span class="ezd-notification-type-badge ezd-badge-comment">
							<span class="dashicons dashicons-format-chat"></span>
						</span>
					</div>
					<div class="ezd-notification-content">
						<p class="ezd-notification-text">
							<strong><?php echo esc_html( $comment->comment_author ); ?></strong>
							<?php esc_html_e( 'commented on', 'eazydocs-pro' ); ?>
							<span class="ezd-doc-title"><?php echo esc_html( get_the_title( $comment->comment_post_ID ) ); ?></span>
						</p>
						<time class="ezd-notification-time">
							<?php
							echo esc_html(
								human_time_diff( strtotime( $comment->comment_date ), time() ) .
								__( ' ago', 'eazydocs-pro' )
							);
							?>
						</time>
					</div>
				</a>
				<?php
			endif;
		endforeach;

		wp_reset_postdata();
	}
	// Notification ended

	/**
	 * Ajax handler for loading more notifications
	 *
	 * @return void
	 */
	public function ajax_load_more_notifications() {
		check_ajax_referer( 'ezd_notification_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 10;
		$filter   = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';

		// Validate filter
		$allowed_filters = [ 'all', 'comment', 'vote' ];
		if ( ! in_array( $filter, $allowed_filters, true ) ) {
			$filter = 'all';
		}

		ob_start();
		$this->render_notification_items( $page, $per_page, $filter );
		$html = ob_get_clean();

		// Check if we received the "end" marker
		$has_more = false === strpos( $html, 'data-end="true"' ) && ! empty( trim( $html ) );

		wp_send_json_success(
			[
				'html'     => $html,
				'has_more' => $has_more,
				'page'     => $page,
			]
		);
	}

	/**
	 * Sort docs.
	 *
	 * @return void
	 */
	public function sortable_docs() {
		check_ajax_referer( 'eazydocs-admin-nonce', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$doc_ids = $_POST['page_id_array'];

		if ( $doc_ids ) {
			foreach ( $doc_ids as $order => $id ) {
				wp_update_post(
					[
						'ID'         => $id,
						'menu_order' => $order,
					]
				);
			}
		}
		exit;
	}

	public function ezd_admin_menu() {
		$capabilites = 'manage_options';

		// === Content Management Section (continues from free version) ===
		add_submenu_page( 'eazydocs', __( 'Badges', 'eazydocs-pro' ), __( 'Badges', 'eazydocs-pro' ), $capabilites, 'edit-tags.php?taxonomy=doc_badge&post_type=docs' );

		// === User Engagement Section ===
		// Note: Separator is already added in the free version before this action hook
		add_submenu_page( 'eazydocs', __( 'Users Feedback', 'eazydocs-pro' ), __( 'Users Feedback', 'eazydocs-pro' ), $capabilites, 'ezd-user-feedback', [ $this, 'user_feedback' ] );

		add_submenu_page( '', __( 'Feedbacks Archived', 'eazydocs-pro' ), __( 'Feedbacks Archived', 'eazydocs-pro' ), $capabilites, 'ezd-user-feedback-archived', [ $this, 'user_feedback_archived' ] );

		if ( class_exists( 'EazyDocs' ) && eaz_fs()->is_plan( 'promax' ) ) {

			$analytics_cap = 'manage_options';

			if ( ! empty( ezd_get_opt( 'analytics-access' ) ) ) {
				$current_user_roles     = ezd_get_current_user_role_by_id( get_current_user_id() );
				$analytics_access_roles = ezd_get_opt( 'analytics-access' );

				if ( is_array( $current_user_roles ) && is_array( $analytics_access_roles ) ) {
					if ( array_intersect( $current_user_roles, $analytics_access_roles ) ) {
						foreach ( $current_user_roles as $role ) {
							if ( in_array( $role, $analytics_access_roles ) ) {
								switch ( $role ) {
									case 'administrator':
										$analytics_cap = 'manage_options';
										break;
									case 'editor':
										$analytics_cap = 'publish_posts';
										break;
									case 'author':
										$analytics_cap = 'edit_posts';
										break;
								}
							}
						}
					}
				} else {
					error_log( 'Expected arrays for user roles and analytics access roles' );
				}
			}

			add_submenu_page( 'eazydocs', __( 'Analytics', 'eazydocs-pro' ), __( 'Analytics', 'eazydocs-pro' ), $analytics_cap, 'ezd-analytics', [ $this, 'analytics_presents_pro' ] );
		} else {
			// Show Analytics presentation page for Pro users who don't have Promax plan
			add_submenu_page( 'eazydocs', __( 'Analytics', 'eazydocs-pro' ), __( 'Analytics', 'eazydocs-pro' ), $capabilites, 'ezd-analytics', [ $this, 'analytics_presentation' ] );
		}
	}

	public function user_feedback() {
		include EAZYDOCSPRO_PATH . '/includes/feedback/feedback.php';
	}

	public function user_feedback_archived() {
		include EAZYDOCSPRO_PATH . '/includes/feedback/feedback-archived.php';
	}

	public function analytics_presents_pro() {
		include EAZYDOCSPRO_PATH . '/includes/Admin/analytics/Analytics.php';
	}

	/**
	 * Analytics Presentation Page
	 * Shows the upsell page for Pro users who don't have the Promax plan.
	 */
	public function analytics_presentation() {
		// Enqueue the analytics presentation CSS.
		wp_enqueue_style(
			'ezd-analytics-presentation',
			EAZYDOCS_ASSETS . '/css/analytics-presentation.css',
			[],
			EAZYDOCS_VERSION
		);

		// Include the template file from the free plugin.
		require_once EAZYDOCS_PATH . '/includes/Admin/template/analytics-presentation.php';
	}

		public function social_share() {
		echo 'sharer';
	}

	/**
	 * Handle bulk feedback actions (archive, delete)
	 */
	public function ajax_bulk_feedback_action() {
		check_ajax_referer( 'ezd_bulk_feedback_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'eazydocs-pro' ) ] );
		}

		$action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];

		if ( empty( $action ) || empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'eazydocs-pro' ) ] );
		}

		$success_count = 0;

		foreach ( $ids as $post_id ) {
			$post_type = get_post_type( $post_id );
			
			if ( ! in_array( $post_type, [ 'ezd_feedback', 'ezd-text-feedback' ], true ) ) {
				continue;
			}

			if ( 'delete' === $action ) {
				if ( wp_delete_post( $post_id, true ) ) {
					$success_count++;
				}
			} elseif ( 'archive' === $action ) {
				if ( 'ezd_feedback' === $post_type ) {
					update_post_meta( $post_id, 'ezd_feedback_status', 'false' );
				} else {
					update_post_meta( $post_id, 'ezd_feedback_archived', 'true' );
				}
				$success_count++;
			} elseif ( 'unarchive' === $action ) {
				if ( 'ezd_feedback' === $post_type ) {
					update_post_meta( $post_id, 'ezd_feedback_status', 'open' );
				} else {
					update_post_meta( $post_id, 'ezd_feedback_archived', 'false' );
				}
				$success_count++;
			}
		}

		if ( $success_count > 0 ) {
			wp_send_json_success( [ 
				'message' => sprintf( __( 'Successfully processed %d items.', 'eazydocs-pro' ), $success_count ) 
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'No items were processed.', 'eazydocs-pro' ) ] );
		}
	}

	/**
	 * Handle toggling feedback star status
	 */
	public function ajax_toggle_feedback_star() {
		check_ajax_referer( 'ezd_toggle_star_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'eazydocs-pro' ) ] );
		}

		$post_id    = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$is_starred = isset( $_POST['is_starred'] ) && $_POST['is_starred'] === 'true' ? 'true' : 'false';

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'eazydocs-pro' ) ] );
		}

		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, [ 'ezd_feedback', 'ezd-text-feedback' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post type.', 'eazydocs-pro' ) ] );
		}

		update_post_meta( $post_id, 'ezd_feedback_starred', $is_starred );

		wp_send_json_success( [ 'message' => __( 'Status updated.', 'eazydocs-pro' ) ] );
	}
}

if ( strstr( $_SERVER['REQUEST_URI'], 'wp-admin/post-new.php' ) || strstr( $_SERVER['REQUEST_URI'], 'wp-admin/post.php' ) ) {
	$is_post          = $_GET['post'] ?? '';
	$get_post_type    = get_post_type( $is_post );
	$add_new_doc_type = $_GET['post_type'] ?? '';
	if ( 'docs' === $get_post_type || 'docs' === $add_new_doc_type ) {
		global $current_user;
		wp_get_current_user();

		$guest_email         = strtolower( get_bloginfo( 'name' ) );
		$guest_email         = str_replace( ' ', '_', $guest_email );
		$frontend_submission = ezd_get_opt( 'ezd_fronted_submission' );

		$frontend_edit = $frontend_submission['frontend-edit-switcher'] ?? '';
		$edit_user     = $frontend_submission['docs-frontend-edit-user-permission'] ?? '';
		$user_id       = $frontend_submission['docs-frontend-edit-user'] ?? '';

		$frontend_add = $frontend_submission['frontend-add-switcher'] ?? '';
		$add_user     = $frontend_submission['docs-frontend-add-user-permission'] ?? '';
		$add_user_id  = $frontend_submission['docs-frontend-add-user'] ?? '';

		if ( 1 == $frontend_edit || 1 == $frontend_add ) {
			if ( 'guest' === $edit_user || 'guest' === $add_user ) {
				$user_info     = get_userdata( $user_id );
				$username      = $user_info->user_login ?? $guest_email . '_guest';
				$add_user_info = get_userdata( $add_user_id );
				$add_username  = $add_user_info->user_login ?? $guest_email . '_guest';

				if ( $current_user->user_login === $username || $current_user->user_login === $add_username ) :
					?>
					<style>
						/* Doc editor screen */
						#adminmenuback {
							width: 0 !important;
						}

						.editor-post-locked-modal__buttons .components-flex-item:last-child,
						button.components-button.editor-post-switch-to-draft.is-tertiary,
						#adminmenuwrap,
						#wpadminbar {
							display: none;
						}

						.auto-fold .interface-interface-skeleton {
							left: 0 !important;
							top: 0 !important;
						}

						#wpcontent {
							margin-left: 0;
						}

						.edit-post-header > div:first-child {
							width: 0;
						}

						.edit-post-header > div:first-child .edit-post-fullscreen-mode-close {
							display: none;
						}

						.editor-styles-wrapper .wp-block {
							width: 100% !important;
							padding: 0 15px;
						}

						a.components-button.components-menu-item__button {
							display: none;
						}
					</style>
					<?php
				endif;
			}
		}
	}
}