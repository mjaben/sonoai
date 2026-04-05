<?php
/**
 * Feedback display action hooks
 *
 * @package eazyDocsPro\feedback
 */

/**
 * Original callback for doc feedback loop (kept for backward compatibility)
 */
add_action( 'ezd_feedback_loop', 'ezd_feedback_loop_callback' );

/**
 * Callback for doc feedback loop.
 *
 * @param int $post_id Post ID.
 */
function ezd_feedback_loop_callback( $post_id ) {
	// Get post meta data.
	$doc_id     = get_post_meta( $post_id, 'ezd_feedback_id', true );
	$data_type  = get_post_meta( $post_id, 'ezd_feedback_status', true );
	$data_type  = $data_type === 'false' ? 'archive' : 'open';
	$subject    = get_post_meta( $post_id, 'ezd_feedback_subject', true );
	$name       = get_post_meta( $post_id, 'ezd_feedback_name', true );
	$email      = get_post_meta( $post_id, 'ezd_feedback_email', true );

	// Determine status and icon class.
	$icon_class = ( isset( $_GET['status'] ) && $_GET['status'] === 'archive' ) ? 'hidden' : 'visibility';
	// Ensure proper escaping.
	$permalink = get_the_permalink( $doc_id );
	?>
	<div class="ezd-feedback-item">
		<h2>
			<a href="<?php echo esc_url( $permalink ); ?>" target="_blank"> <?php echo esc_html( $subject ); ?> </a> - <?php echo esc_html( $name ); ?>
		</h2>
		<div class="ezd-feedback-meta">
			<div class="ezd-meta-date-time">
				<span class="dashicons dashicons-clock"></span> <?php echo get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ); ?>
			</div>
			<div class="ezd-meta-mail">
				<span class="dashicons dashicons-email-alt"></span>
				<a href="mailto:<?php echo esc_attr( antispambot( $email ) ); ?>">
					<?php echo esc_attr( antispambot( $email ) ); ?>
				</a>
			</div>
		</div>
		
		<div class="ezd-feedback-content">
			<span class="dashicons dashicons-welcome-write-blog"></span>
			<?php echo wpautop( esc_html( get_the_content( $post_id ) ) ); ?>
		</div>

		<div class="ezd-feedback-btn">
			<?php
			// Delete feedback action.
			$delete_url = add_query_arg(
				array(
					'page'            => 'ezd-user-feedback',
					'tab_type'        => 'doc',
					'feedback_delete' => $post_id,
					'data_type'       => $data_type,
					'_wpnonce'        => wp_create_nonce( $post_id ),
				),
				admin_url( 'admin.php' )
			);
			?>
			<a class="ezd-feedback-delete" href="<?php echo esc_url( $delete_url ); ?>">
				<span class="dashicons dashicons-trash"></span>
			</a>
			<?php
			// Update feedback action.
			$update_url = add_query_arg(
				array(
					'page'        => 'ezd-user-feedback',
					'tab_type'    => 'doc',
					'feedback_id' => $post_id,
					'data_type'   => $data_type,
					'_wpnonce'    => wp_create_nonce( $post_id ),
				),
				admin_url( 'admin.php' )
			);
			?>
			<a class="ezd-feedback-update" href="<?php echo esc_url( $update_url ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon_class ); ?>"></span>
			</a>
		</div>
	</div>
	<?php
}

/**
 * Original callback for text feedback loop (kept for backward compatibility)
 */
add_action( 'ezd_text_feedback_loop', 'ezd_text_feedback_loop_callback' );

/**
 * Callback for text feedback loop.
 *
 * @param int $post_id Post ID.
 */
function ezd_text_feedback_loop_callback( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'ezd-text-feedback' ) {
		return;
	}

	$content      = $post->post_content;
	$author_name  = get_post_meta( $post_id, 'author_name', true );
	$author_email = get_post_meta( $post_id, 'author_email', true );
	$related_doc  = get_post_meta( $post_id, 'related_doc_id', true );
	$is_archived  = get_post_meta( $post_id, 'ezd_feedback_archived', true );

	$data_type  = $is_archived === 'true' ? 'archive' : 'open';
	$icon_class = $is_archived === 'true' ? 'hidden' : 'visibility';
	$title      = get_the_title( $related_doc );
	$permalink  = get_permalink( $related_doc );
	?>
	<div class="ezd-feedback-item">
		<h2>
			<a href="<?php echo esc_url( $permalink ); ?>" target="_blank">
				<?php echo esc_html( $author_name ? $author_name : __( 'Anonymous', 'eazydocs-pro' ) ); ?>
			</a>
		</h2>

		<div class="ezd-feedback-meta">
			<div class="ezd-meta-date-time">
				<span class="dashicons dashicons-clock"></span>
				<?php echo esc_html( get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post_id ) ); ?>
			</div>
			<?php if ( $title ) : ?>
				<div class="ezd-meta-mail">
					<span class="dashicons dashicons-admin-links"></span>
					<a href="<?php echo esc_url( $permalink ); ?>">
						<?php echo esc_html( $title ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<div class="ezd-feedback-content">
			<span class="dashicons dashicons-welcome-write-blog"></span>
			<?php echo wpautop( esc_html( $content ) ); ?>
		</div>

		<div class="ezd-feedback-btn">
			<?php
			$delete_url = add_query_arg(
				array(
					'page'            => 'ezd-user-feedback',
					'tab_type'        => 'text',
					'feedback_delete' => $post_id,
					'data_type'       => $data_type,
					'_wpnonce'        => wp_create_nonce( $post_id ),
				),
				admin_url( 'admin.php' )
			);

			$update_url = add_query_arg(
				array(
					'page'        => 'ezd-user-feedback',
					'tab_type'    => 'text',
					'feedback_id' => $post_id,
					'data_type'   => $data_type,
					'_wpnonce'    => wp_create_nonce( $post_id ),
				),
				admin_url( 'admin.php' )
			);
			?>

			<a class="ezd-feedback-delete" href="<?php echo esc_url( $delete_url ); ?>">
				<span class="dashicons dashicons-trash"></span>
			</a>
			<a class="ezd-feedback-update" href="<?php echo esc_url( $update_url ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon_class ); ?>"></span>
			</a>
		</div>
	</div>
	<?php
}

/**
 * ================================================================
 * ENHANCED FEEDBACK LOOP CALLBACKS (New Design)
 * ================================================================
 */

add_action( 'ezd_feedback_loop_enhanced', 'ezd_feedback_loop_enhanced_callback', 10, 2 );

/**
 * Enhanced callback for doc feedback loop with new design.
 *
 * @param int    $post_id        Post ID.
 * @param string $current_status Current status filter.
 */
function ezd_feedback_loop_enhanced_callback( $post_id, $current_status = 'open' ) {
	// Get post meta data.
	$doc_id         = get_post_meta( $post_id, 'ezd_feedback_id', true );
	$feedback_status = get_post_meta( $post_id, 'ezd_feedback_status', true );
	$data_type      = $feedback_status === 'false' ? 'archive' : 'open';
	$subject        = get_post_meta( $post_id, 'ezd_feedback_subject', true );
	$name           = get_post_meta( $post_id, 'ezd_feedback_name', true );
	$email          = get_post_meta( $post_id, 'ezd_feedback_email', true );

	$icon_class   = $data_type === 'archive' ? 'visibility' : 'archive';
	$action_title = $data_type === 'archive' ? __( 'Unarchive', 'eazydocs-pro' ) : __( 'Archive', 'eazydocs-pro' );
	$permalink    = get_the_permalink( $doc_id );
	$doc_title    = get_the_title( $doc_id );
	$content      = get_post_field( 'post_content', $post_id );
	$post_date    = get_the_date( 'M j, Y \a\t g:i a', $post_id );

	// Time ago.
	$post_time   = get_post_time( 'U', false, $post_id );
	$time_diff   = human_time_diff( $post_time, current_time( 'timestamp' ) );

	// Starred status
	$is_starred = get_post_meta( $post_id, 'ezd_feedback_starred', true );
	$star_class = $is_starred === 'true' ? 'is-starred' : '';
	$star_icon  = $is_starred === 'true' ? 'dashicons-star-filled' : 'dashicons-star-empty';

	// Get user avatar.
	$avatar = get_avatar_url( $email, array( 'size' => 48 ) );

	// Action URLs.
	$delete_url = add_query_arg(
		array(
			'page'            => 'ezd-user-feedback',
			'tab_type'        => 'doc',
			'feedback_delete' => $post_id,
			'data_type'       => $data_type,
			'_wpnonce'        => wp_create_nonce( $post_id ),
		),
		admin_url( 'admin.php' )
	);

	$update_url = add_query_arg(
		array(
			'page'        => 'ezd-user-feedback',
			'tab_type'    => 'doc',
			'feedback_id' => $post_id,
			'data_type'   => $data_type,
			'_wpnonce'    => wp_create_nonce( $post_id ),
		),
		admin_url( 'admin.php' )
	);
	?>
	<div class="ezd-feedback-item" data-id="<?php echo esc_attr( $post_id ); ?>">
		<div class="ezd-fi-checkbox">
			<input type="checkbox" class="ezd-feedback-item-checkbox" value="<?php echo esc_attr( $post_id ); ?>">
		</div>

		<div class="ezd-fi-star">
			<button type="button" class="ezd-star-btn <?php echo esc_attr( $star_class ); ?>" title="<?php esc_attr_e( 'Mark as Important', 'eazydocs-pro' ); ?>">
				<span class="dashicons <?php echo esc_attr( $star_icon ); ?>"></span>
			</button>
		</div>

		<div class="ezd-fi-avatar">
			<?php if ( $avatar ) : ?>
				<img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $name ); ?>">
			<?php else : ?>
				<div class="ezd-fi-avatar-placeholder">
					<span class="dashicons dashicons-admin-users"></span>
				</div>
			<?php endif; ?>
		</div>

		<div class="ezd-fi-main">
			<div class="ezd-fi-header">
				<div class="ezd-fi-header-left">
					<h3 class="ezd-fi-title">
						<a href="<?php echo esc_url( $permalink ); ?>" target="_blank"><?php echo esc_html( $subject ); ?></a>
					</h3>
					<span class="ezd-fi-author"><?php echo esc_html( $name ); ?></span>
				</div>
				<div class="ezd-fi-header-right">
					<span class="ezd-fi-date" title="<?php echo esc_attr( $post_date ); ?>">
						<span class="dashicons dashicons-clock"></span>
						<?php echo esc_html( $time_diff ); ?> <?php esc_html_e( 'ago', 'eazydocs-pro' ); ?>
					</span>
				</div>
			</div>

			<div class="ezd-fi-meta">
				<a href="mailto:<?php echo esc_attr( antispambot( $email ) ); ?>" class="ezd-fi-email">
					<span class="dashicons dashicons-email"></span>
					<span class="ezd-fi-email-link"><?php echo esc_html( antispambot( $email ) ); ?></span>
				</a>
				<button type="button" class="ezd-copy-email" data-email="<?php echo esc_attr( antispambot( $email ) ); ?>" title="<?php esc_attr_e( 'Copy Email', 'eazydocs-pro' ); ?>">
					<span class="dashicons dashicons-admin-page"></span>
				</button>
				<?php if ( $doc_title ) : ?>
					<a href="<?php echo esc_url( $permalink ); ?>" class="ezd-fi-doc" target="_blank">
						<span class="dashicons dashicons-media-document"></span>
						<span class="ezd-fi-doc-link"><?php echo esc_html( $doc_title ); ?></span>
					</a>
				<?php endif; ?>
			</div>

			<div class="ezd-fi-content">
				<span class="dashicons dashicons-format-quote"></span>
				<div class="ezd-fi-content-wrap">
					<p class="ezd-fi-content-text"><?php echo esc_html( $content ); ?></p>
				</div>
				<?php if ( strlen( $content ) > 150 ) : ?>
					<button type="button" class="ezd-read-more-btn">
						<?php esc_html_e( 'Read More', 'eazydocs-pro' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>

		<div class="ezd-fi-actions">
			<button type="button" class="ezd-action-btn ezd-reply-btn" data-email="<?php echo esc_attr( antispambot( $email ) ); ?>" title="<?php esc_attr_e( 'Reply', 'eazydocs-pro' ); ?>">
				<span class="dashicons dashicons-admin-comments"></span>
			</button>
			<a href="<?php echo esc_url( $update_url ); ?>" class="ezd-action-btn ezd-archive-btn" title="<?php echo esc_attr( $action_title ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon_class ); ?>"></span>
			</a>
			<a href="<?php echo esc_url( $delete_url ); ?>" class="ezd-action-btn ezd-delete-btn" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this feedback?', 'eazydocs-pro' ); ?>');" title="<?php esc_attr_e( 'Delete', 'eazydocs-pro' ); ?>">
				<span class="dashicons dashicons-trash"></span>
			</a>
		</div>
	</div>
	<?php
}

add_action( 'ezd_text_feedback_loop_enhanced', 'ezd_text_feedback_loop_enhanced_callback', 10, 2 );

/**
 * Enhanced callback for text feedback loop with new design.
 *
 * @param int    $post_id        Post ID.
 * @param string $current_status Current status filter.
 */
function ezd_text_feedback_loop_enhanced_callback( $post_id, $current_status = 'open' ) {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'ezd-text-feedback' ) {
		return;
	}

	$content      = $post->post_content;
	$author_name  = get_post_meta( $post_id, 'author_name', true );
	$author_email = get_post_meta( $post_id, 'author_email', true );
	$related_doc  = get_post_meta( $post_id, 'related_doc_id', true );
	$is_archived  = get_post_meta( $post_id, 'ezd_feedback_archived', true );

	$data_type    = $is_archived === 'true' ? 'archive' : 'open';
	$icon_class   = $data_type === 'archive' ? 'visibility' : 'archive';
	$action_title = $data_type === 'archive' ? __( 'Unarchive', 'eazydocs-pro' ) : __( 'Archive', 'eazydocs-pro' );
	$title        = get_the_title( $related_doc );
	$permalink    = get_permalink( $related_doc );
	$post_date    = get_the_date( 'M j, Y \a\t g:i a', $post_id );

	// Time ago.
	$post_time = get_post_time( 'U', false, $post_id );
	$time_diff = human_time_diff( $post_time, current_time( 'timestamp' ) );

	// Starred status
	$is_starred = get_post_meta( $post_id, 'ezd_feedback_starred', true );
	$star_class = $is_starred === 'true' ? 'is-starred' : '';
	$star_icon  = $is_starred === 'true' ? 'dashicons-star-filled' : 'dashicons-star-empty';

	// Get user avatar.
	$avatar = $author_email ? get_avatar_url( $author_email, array( 'size' => 48 ) ) : '';

	// Action URLs.
	$delete_url = add_query_arg(
		array(
			'page'            => 'ezd-user-feedback',
			'tab_type'        => 'text',
			'feedback_delete' => $post_id,
			'data_type'       => $data_type,
			'_wpnonce'        => wp_create_nonce( $post_id ),
		),
		admin_url( 'admin.php' )
	);

	$update_url = add_query_arg(
		array(
			'page'        => 'ezd-user-feedback',
			'tab_type'    => 'text',
			'feedback_id' => $post_id,
			'data_type'   => $data_type,
			'_wpnonce'    => wp_create_nonce( $post_id ),
		),
		admin_url( 'admin.php' )
	);

	$display_name = $author_name ? $author_name : __( 'Anonymous', 'eazydocs-pro' );
	?>
	<div class="ezd-feedback-item" data-id="<?php echo esc_attr( $post_id ); ?>">
		<div class="ezd-fi-checkbox">
			<input type="checkbox" class="ezd-feedback-item-checkbox" value="<?php echo esc_attr( $post_id ); ?>">
		</div>

		<div class="ezd-fi-star">
			<button type="button" class="ezd-star-btn <?php echo esc_attr( $star_class ); ?>" title="<?php esc_attr_e( 'Mark as Important', 'eazydocs-pro' ); ?>">
				<span class="dashicons <?php echo esc_attr( $star_icon ); ?>"></span>
			</button>
		</div>

		<div class="ezd-fi-avatar">
			<?php if ( $avatar ) : ?>
				<img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $display_name ); ?>">
			<?php else : ?>
				<div class="ezd-fi-avatar-placeholder">
					<span class="dashicons dashicons-admin-users"></span>
				</div>
			<?php endif; ?>
		</div>

		<div class="ezd-fi-main">
			<div class="ezd-fi-header">
				<div class="ezd-fi-header-left">
					<h3 class="ezd-fi-title">
						<span class="ezd-fi-author"><?php echo esc_html( $display_name ); ?></span>
					</h3>
					<span class="ezd-fi-type-badge"><?php esc_html_e( 'Text Selection', 'eazydocs-pro' ); ?></span>
				</div>
				<div class="ezd-fi-header-right">
					<span class="ezd-fi-date" title="<?php echo esc_attr( $post_date ); ?>">
						<span class="dashicons dashicons-clock"></span>
						<?php echo esc_html( $time_diff ); ?> <?php esc_html_e( 'ago', 'eazydocs-pro' ); ?>
					</span>
				</div>
			</div>

			<div class="ezd-fi-meta">
				<?php if ( $author_email ) : ?>
					<a href="mailto:<?php echo esc_attr( antispambot( $author_email ) ); ?>" class="ezd-fi-email">
						<span class="dashicons dashicons-email"></span>
						<span class="ezd-fi-email-link"><?php echo esc_html( antispambot( $author_email ) ); ?></span>
					</a>
					<button type="button" class="ezd-copy-email" data-email="<?php echo esc_attr( antispambot( $author_email ) ); ?>" title="<?php esc_attr_e( 'Copy Email', 'eazydocs-pro' ); ?>">
						<span class="dashicons dashicons-admin-page"></span>
					</button>
				<?php endif; ?>
				<?php if ( $title ) : ?>
					<a href="<?php echo esc_url( $permalink ); ?>" class="ezd-fi-doc" target="_blank">
						<span class="dashicons dashicons-media-document"></span>
						<span class="ezd-fi-doc-link"><?php echo esc_html( $title ); ?></span>
					</a>
				<?php endif; ?>
			</div>

			<div class="ezd-fi-content">
				<span class="dashicons dashicons-format-quote"></span>
				<div class="ezd-fi-content-wrap">
					<p class="ezd-fi-content-text"><?php echo esc_html( $content ); ?></p>
				</div>
				<?php if ( strlen( $content ) > 150 ) : ?>
					<button type="button" class="ezd-read-more-btn">
						<?php esc_html_e( 'Read More', 'eazydocs-pro' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>

		<div class="ezd-fi-actions">
			<?php if ( $author_email ) : ?>
				<button type="button" class="ezd-action-btn ezd-reply-btn" data-email="<?php echo esc_attr( antispambot( $author_email ) ); ?>" title="<?php esc_attr_e( 'Reply', 'eazydocs-pro' ); ?>">
					<span class="dashicons dashicons-admin-comments"></span>
				</button>
			<?php endif; ?>
			<a href="<?php echo esc_url( $update_url ); ?>" class="ezd-action-btn ezd-archive-btn" title="<?php echo esc_attr( $action_title ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon_class ); ?>"></span>
			</a>
			<a href="<?php echo esc_url( $delete_url ); ?>" class="ezd-action-btn ezd-delete-btn" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this feedback?', 'eazydocs-pro' ); ?>');" title="<?php esc_attr_e( 'Delete', 'eazydocs-pro' ); ?>">
				<span class="dashicons dashicons-trash"></span>
			</a>
		</div>
	</div>
	<?php
}