<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Get the value of a settings field.
 *
 * @param string $option  settings field name
 * @param string $section the section name this field belongs to
 * @param string $default default text if it's not found
 *
 * @return mixed
 */
function eazydocspro_get_option( $option, $default = '' ) {
	$options = get_option( 'eazydocs_settings' );
	return ( isset( $options[ $option ] ) && ! empty( $options[ $option ] ) ) ? $options[ $option ] : $default;
}

/**
 * Get the docs search keywords query
 *
 * @param bool $include_resolved Whether to include resolved keywords in the results. Default false.
 * @return array|object|stdClass[]|null
 */
function ezd_get_search_keywords( $include_resolved = false ) {
	global $wpdb;

	$keyword_table = $wpdb->prefix . 'eazydocs_search_keyword';
	$log_table     = $wpdb->prefix . 'eazydocs_search_log';

	// Get resolved keyword IDs.
	$resolved_ids = get_option( 'ezd_resolved_search_keywords', [] );

	// Build WHERE clause to exclude resolved keywords if needed.
	$exclude_resolved = '';
	if ( ! $include_resolved && ! empty( $resolved_ids ) ) {
		$ids_placeholder  = implode( ',', array_map( 'absint', $resolved_ids ) );
		$exclude_resolved = " AND {$keyword_table}.id NOT IN ({$ids_placeholder})";
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_results(
		"SELECT {$keyword_table}.id AS keyword_id, {$keyword_table}.keyword, COUNT(*) AS not_found_count
		FROM {$log_table}
		JOIN {$keyword_table} ON {$keyword_table}.id = {$log_table}.keyword_id
		WHERE {$log_table}.not_found_count = 1 {$exclude_resolved}
		GROUP BY {$keyword_table}.id, {$keyword_table}.keyword
		ORDER BY not_found_count DESC
		LIMIT 20"
	);
}


/**
 * Displays a badge associated with the 'doc_badge' taxonomy for premium documentation pages.
 * The badge is styled based on metadata assigned to the taxonomy term.
 *
 * @return string
 */
function ezdpro_badge( $post ) {
    if ( ! ezd_is_premium() ) {
        return '';
    }

    $output = '';

    // Get doc_badge taxonomy badges.
    $badges = get_the_terms( $post, 'doc_badge' );
    if ( is_array( $badges ) && ! empty( $badges ) ) {
        $rgba_to_hex = function ( $rgba ) {
            if ( ! preg_match( '/rgba?\(([^)]+)\)/i', $rgba, $m ) ) {
                return $rgba;
            }

            $parts = array_map( 'trim', explode( ',', $m[1] ) );
            $r = max( 0, min( 255, intval( $parts[0] ?? 0 ) ) );
            $g = max( 0, min( 255, intval( $parts[1] ?? 0 ) ) );
            $b = max( 0, min( 255, intval( $parts[2] ?? 0 ) ) );
            $a = isset( $parts[3] ) ? floatval( $parts[3] ) : 1;

            $hex = sprintf( '#%02x%02x%02x', $r, $g, $b );
            if ( $a < 1 ) {
                $hex .= sprintf( '%02x', round( $a * 255 ) );
            }

            return $hex;
        };

        foreach ( $badges as $badge ) {
            $settings = get_term_meta( $badge->term_id, 'ezd_badge_settings', true ) ?: [];

            $color_val = $settings['ezd-badge-color'] ?? '';
            $bg_val    = $settings['ezd-badge-bg'] ?? '';

            if ( $color_val && preg_match( '/^rgba?\(/i', $color_val ) ) {
                $color_val = $rgba_to_hex( $color_val );
            }
            if ( $bg_val && preg_match( '/^rgba?\(/i', $bg_val ) ) {
                $bg_val = $rgba_to_hex( $bg_val );
            }

            $style_parts = [];
            if ( $color_val ) {
                $style_parts[] = "color: {$color_val};";
            }
            if ( $bg_val ) {
                $style_parts[] = "background: {$bg_val};";
            }

            $style_attr = $style_parts ? ' style="' . esc_attr( implode( ' ', $style_parts ) ) . '"' : '';

            $output .= '<span class="ezd-doc-badge"' . $style_attr . '>' . esc_html( $badge->name ) . '</span>';
        }
    }

    return $output;
}


if ( ! function_exists( 'eazydocs_breadcrumbs' ) ) {

	/**
	 * Docs breadcrumb.
	 *
	 * @return void
	 */
	function eazydocs_breadcrumbs() {
		global $post;

		$home_text       = ezd_get_opt( 'breadcrumb-home-text', 'eazydocs_settings' );
		$front_page      = ! empty( $home_text ) ? esc_html( $home_text ) : esc_html__( 'Home', 'eazydocs-pro' );
		$docs_home       = ezd_get_opt( 'docs-slug', 'eazydocs_settings' );
		$docs_page_title = ezd_get_opt( 'docs-page-title', 'eazydocs_settings' );
		$docs_page_title = ! empty( $docs_page_title ) ? esc_html( $docs_page_title ) : esc_html__( 'Docs', 'eazydocs-pro' );

		$html = '';
		$args = apply_filters( 'eazydocs_breadcrumbs', [
			'delimiter' => '',
			'home'      => $front_page,
			'before'    => '<li class="breadcrumb-item active">',
			'after'     => '</li>',
		] );

		$breadcrumb_position = 1;

		$html .= '<ol class="breadcrumb" itemscope itemtype="http://schema.org/BreadcrumbList">';
		$html .= eazydocs_get_breadcrumb_item( $args['home'], home_url( '/' ), $breadcrumb_position );
		$html .= $args['delimiter'];

		if ( $docs_home ) {
			++ $breadcrumb_position;

			$html .= eazydocs_get_breadcrumb_item( $docs_page_title, get_permalink( $docs_home ), $breadcrumb_position );
			$html .= $args['delimiter'];
		}

		if ( 'docs' === $post->post_type && $post->post_parent ) {
			$parent_id   = $post->post_parent;
			$breadcrumbs = [];

			while ( $parent_id ) {
				++ $breadcrumb_position;

				$page          = get_post( $parent_id );
				$breadcrumbs[] = eazydocs_get_breadcrumb_item( get_the_title( $page->ID ), get_permalink( $page->ID ), $breadcrumb_position );
				$parent_id     = $page->post_parent;
			}

			$breadcrumbs = array_reverse( $breadcrumbs );

			for ( $i = 0; $i < count( $breadcrumbs ); ++ $i ) {
				$html .= $breadcrumbs[ $i ];
				$html .= ' ' . $args['delimiter'] . ' ';
			}
		}

		$html .= ' ' . $args['before'] . get_the_title() . $args['after'];

		$html .= '</ol>';

		echo wp_kses_post( apply_filters( 'eazydocs_breadcrumbs_html', $html, $args ) );
	}
}

// Recently Viewed Docs
add_action( 'template_redirect', 'ezd_posts_visited' );
function ezd_posts_visited() {
	if ( is_single() && 'docs' === get_post_type() ) {
		$cooki    = 'eazydocs_recent_posts';
		$ft_posts = isset( $_COOKIE[ $cooki ] ) ? json_decode( htmlspecialchars( $_COOKIE[ $cooki ], true ) ) : null;
		if ( isset( $ft_posts ) ) {
			// Remove current post in the cookie
			$ft_posts = array_diff( $ft_posts, [ get_the_ID() ] );
			// update cookie with current post
			array_unshift( $ft_posts, get_the_ID() );
		} else {
			$ft_posts = [ get_the_ID() ];
		}
		setcookie( $cooki, json_encode( $ft_posts ), time() + ( DAY_IN_SECONDS * 31 ), COOKIEPATH, COOKIE_DOMAIN );
	}
}

/**
 * EazyDocsPro img callback
 *
 * @param string $src Image Source URL.
 * @param string $alt Image Alt Text.
 */
function eazydocs_pro_img( $src, $alt ) {
	echo wp_kses_post( "<img src='" . $src . "' alt='" . $alt . "' />" );
}

/**
 * @param string $a First date.
 * @param string $b Second date.
 *
 * @return bool
 */
function ezdpro_date_sort( $a, $b ) {
	$date1 = DateTime::createFromFormat( 'd/m/Y', $a );
	$date2 = DateTime::createFromFormat( 'd/m/Y', $b );

	return $b > $a ? 1 : - 1;
}

/**
 * Votes counter
 *
 * @return int
 */
function eazydocs_voted() {
	global $wpdb;

	// Direct SQL count of postmeta entries for 'positive_time' and 'negative_time'
	// where the associated post is a published 'docs' post.
	$count = $wpdb->get_var( "
        SELECT COUNT(pm.meta_id)
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'docs'
        AND p.post_status = 'publish'
        AND pm.meta_key IN ('positive_time', 'negative_time')
        AND pm.meta_value != ''
    " );

	return (int) $count;
}

/**
 * EazyDocsPro comments counter
 *
 * @return int
 */
function ezd_comment_count() {
	$args     = [
		'number'      => 5,
		'post_status' => 'publish',
		'post_type'   => [ 'docs' ],
		'parent'      => 0,
	];
	$comments = get_comments( $args );

	return count( $comments );
}

/**
 * Get all WP Roles
 *
 * @return string[]
 */
function eazydocs_user_role_names() {
	global $wp_roles;
	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}

	return $wp_roles->get_names();
}

/**
 * Retrieve User Role by User ID
 *
 * This function fetches the roles assigned to a specific user by their user ID,
 * ensuring that only top-level (default) WordPress roles are included.
 *
 * @param int $user_id The ID of the user whose roles are to be retrieved.
 * @return array An array of the user's roles. Returns an empty array if the user has no roles or does not exist.
 */
function ezd_get_current_user_role_by_id( $user_id ) {

	// Define the default WordPress roles
	$default_roles = [ 'administrator', 'editor', 'author' ];

	// Get the user data object
	$user = get_userdata( $user_id );

	// Check if the user exists and has roles
	if ( $user && ! empty( $user->roles ) ) {
		// Filter user roles to include only default WordPress roles
		$user_roles = array_intersect( $user->roles, $default_roles );
		return $user_roles;
	}

	// Return an empty array if no roles are found or user doesn't exist
	return [];
}

/**
 * Get the allowed user roles for documentation contributors.
 *
 * @return array Array of allowed user roles.
 */
function ezd_contributor_allowed_roles() {
	$allowed_roles = ezd_get_opt( 'ezd_add_editable_roles', [ 'administrator', 'editor', 'author' ] );
	if ( ! is_array( $allowed_roles ) ) {
		$allowed_roles = [ $allowed_roles ];
	}
	return $allowed_roles;
}

/**
 * Matched User Role by User ID
 *
 * This function fetches the roles assigned to a specific user by their user ID,
 * ensuring that only top-level (default) WordPress roles are included.
 *
 * @param int $user_id The ID of the user whose roles are to be retrieved.
 * @return array An array of the user's roles. Returns an empty array if the user has no roles or does not exist.
 */
function ezd_match_contribute_create_doc_role( $user_id ) {
	// Get the user object
	$user = get_userdata( $user_id );

	if ( ! $user || empty( $user->roles ) ) {
		return [];
	}

	global $wp_roles;
	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}

	$matching_roles = [];
	$allowed_roles  = ezd_contributor_allowed_roles();

	// Check each role the user has
	foreach ( $user->roles as $role_key ) {
		$role = $wp_roles->get_role( $role_key );

		if ( $role && isset( $role->capabilities['publish_docs'] ) && $role->capabilities['publish_docs'] && in_array( $role_key, $allowed_roles ) ) {
			$matching_roles[] = $role_key;
		}
	}

	return $matching_roles;
}

function ezd_match_contribute_edit_role( $user_id ) {
	// Get the user object
	$user = get_userdata( $user_id );

	if ( ! $user || empty( $user->roles ) ) {
		return [];
	}

	global $wp_roles;
	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}

	$matching_roles = [];
	$allowed_roles  = ezd_contributor_allowed_roles();

	// Check each role the user has
	foreach ( $user->roles as $role_key ) {
		$role = $wp_roles->get_role( $role_key );

		if ( $role && ! empty( $role->capabilities['edit_docs'] ) && in_array( $role_key, $allowed_roles ) ) {
			$matching_roles[] = $role_key;
		}
	}

	return $matching_roles;
}


/**
 * Limit latter
 *
 * @param string $string       The string to limit.
 * @param int    $limit_length The length to limit to.
 * @param string $suffix
 */
function ezd_limit_letter( $string, $limit_length, $suffix = '...' ) {
	if ( strlen( $string ) > $limit_length ) {
		echo esc_html( strip_shortcodes( substr( $string, 0, $limit_length ) . $suffix ) );
	} else {
		echo esc_html( strip_shortcodes( $string ) );
	}
}

/**
 * Get total views
 *
 * @return int
 */
function ezdpro_get_total_views( $user_id = null ) {
    global $wpdb;

    $table = $wpdb->prefix . 'eazydocs_view_log';

    // Prepare WHERE clause if $user_id is provided
    $where = '';
    if ( $user_id ) {
        $where = $wpdb->prepare(
            "WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_author = %d)",
            $user_id
        );
    }

    $results = $wpdb->get_results(
        "SELECT `count`, `created_at` FROM $table $where",
        ARRAY_A
    );

    $totalValues = [];

    foreach ( $results as $row ) {
        $date = explode( ' ', $row['created_at'] )[0]; // get date only
        if ( isset( $totalValues[$date] ) ) {
            $totalValues[$date] += $row['count'];
        } else {
            $totalValues[$date] = $row['count'];
        }
    }

    // Sum all counts
    return array_sum( $totalValues );
}

//CUSTOM META BOX
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'EZD Feedback Options', 'EZD Feedback Options', 'ezd_feedback_docs', 'ezd_feedback' );
	add_meta_box( 'doc_extra_information', 'Additional Information', 'ezd_docs_extra_info', 'docs' );
	// side meta box
	add_meta_box( 'doc_secondary_title_meta', 'Secondary Title', 'doc_secondary_title_meta_callback', 'docs', 'side', 'high' );
} );

function ezd_feedback_docs() {
	wp_nonce_field( 'ezd_feedback_save', 'ezd_feedback_nonce' );
	?>
    <p><b>ID</b><br/>
        <input type="text" name="ezd_feedback_id" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_feedback_id', true ) ); ?>" class="widefat"/>
    </p>
    <p><b>Name</b><br/>
        <input type="text" name="ezd_feedback_name" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_feedback_name', true ) ); ?>" class="widefat"/>
    </p>
    <p><b>Email</b><br/>
        <input name="ezd_feedback_email" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_feedback_email', true ) ); ?>" class="widefat"/>
    </p>
    <p><b>Subject</b><br/>
        <input name="ezd_feedback_subject" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_feedback_subject', true ) ); ?>" class="widefat"/>
    </p>
    <p><b>Status</b><br/>
        <input name="ezd_feedback_status" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_feedback_status', true ) ); ?>" class="widefat"/>
    </p>
<?php }

function ezd_docs_extra_info() {
	wp_nonce_field( 'ezd_docs_extra_info_save', 'ezd_docs_extra_info_nonce' );
	?>
    <p><b><?php echo esc_html( 'Doc Contributors' ); ?></b><br/>
        <input type="text" name="ezd_doc_contributors" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_contributors', true ) ); ?>" class="widefat"/>
    </p>
    <p><b><?php echo esc_html( 'Read / Unread' ); ?></b><br/>
        <input type="text" name="ezd_doc_read_unread" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_read_unread', true ) ); ?>" class="widefat"/>
    </p>
    <p><b><?php echo esc_html( 'Read / Unread Time' ); ?></b><br/>
        <input type="text" name="ezd_doc_read_unread_time" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_read_unread_time', true ) ); ?>"
               class="widefat"/>
    </p>
    <p><b><?php echo esc_html( 'Left Sidebar' ); ?></b><br/>
        <input type="text" name="ezd_doc_left_sidebar_type" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_left_sidebar_type', true ) ); ?>"
               class="widefat"/><br/>
        <textarea name="ezd_doc_left_sidebar" id="" rows="5" class="widefat"> <?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_left_sidebar',
				true ) ); ?> </textarea>
    </p>
    <p><b><?php echo esc_html( 'Right Sidebar' ); ?></b><br/>
        <input type="text" name="ezd_doc_right_sidebar_type" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_right_sidebar_type', true ) ); ?>"
               class="widefat"/><br/>
        <textarea name="ezd_doc_right_sidebar" id="" rows="5" class="widefat"> <?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_right_sidebar',
				true ) ); ?> </textarea>
    </p>
<?php }

/**
 * Render Secondary Title Meta Box.
 *
 * @return void
 */
function doc_secondary_title_meta_callback() {
	wp_nonce_field( 'ezd_doc_secondary_title_save', 'ezd_doc_secondary_title_nonce' );
	?>
	<p><b><?php echo esc_html( 'Title' ); ?></b><br/>
        <input type="text" name="ezd_doc_secondary_title" value="<?php echo esc_attr( get_post_meta( get_the_ID(), 'ezd_doc_secondary_title', true ) ); ?>" class="widefat"/>
		<i>The secondary title should be displayed in the left sidebar instead of the main title.</i>
    </p>
	<?php
}


add_action( 'save_post', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Feedback Options
	if ( isset( $_POST['ezd_feedback_nonce'] ) && wp_verify_nonce( $_POST['ezd_feedback_nonce'], 'ezd_feedback_save' ) ) {
		$ezd_feedback_id      = isset( $_POST['ezd_feedback_id'] ) ? sanitize_text_field( $_POST['ezd_feedback_id'] ) : '';
		$ezd_feedback_name    = isset( $_POST['ezd_feedback_name'] ) ? sanitize_text_field( $_POST['ezd_feedback_name'] ) : '';
		$ezd_feedback_email   = isset( $_POST['ezd_feedback_email'] ) ? sanitize_email( $_POST['ezd_feedback_email'] ) : '';
		$ezd_feedback_subject = isset( $_POST['ezd_feedback_subject'] ) ? sanitize_text_field( $_POST['ezd_feedback_subject'] ) : '';
		$ezd_feedback_status  = isset( $_POST['ezd_feedback_status'] ) ? sanitize_text_field( $_POST['ezd_feedback_status'] ) : '';

		if ( ! empty( $ezd_feedback_id ) ) {
			update_post_meta( $post_id, 'ezd_doc_layout', $ezd_feedback_id );
		}
		if ( ! empty( $ezd_feedback_name ) ) {
			update_post_meta( $post_id, 'ezd_feedback_name', $ezd_feedback_name );
		}
		if ( ! empty( $ezd_feedback_email ) ) {
			update_post_meta( $post_id, 'ezd_feedback_email', $ezd_feedback_email );
		}
		if ( ! empty( $ezd_feedback_subject ) ) {
			update_post_meta( $post_id, 'ezd_feedback_subject', $ezd_feedback_subject );
		}
		if ( ! empty( $ezd_feedback_status ) ) {
			update_post_meta( $post_id, 'ezd_feedback_status', $ezd_feedback_status );
		}
	}

	// Docs Extra Info
	if ( isset( $_POST['ezd_docs_extra_info_nonce'] ) && wp_verify_nonce( $_POST['ezd_docs_extra_info_nonce'], 'ezd_docs_extra_info_save' ) ) {
		$ezd_doc_read_unread      = isset( $_POST['ezd_doc_read_unread'] ) ? sanitize_text_field( $_POST['ezd_doc_read_unread'] ) : '';
		$ezd_doc_read_unread_time = isset( $_POST['ezd_doc_read_unread_time'] ) ? sanitize_text_field( $_POST['ezd_doc_read_unread_time'] ) : '';
		$ezd_doc_left_sidebar     = isset( $_POST['ezd_doc_left_sidebar'] ) ? wp_kses_post( $_POST['ezd_doc_left_sidebar'] ) : '';
		$ezd_doc_right_sidebar    = isset( $_POST['ezd_doc_right_sidebar'] ) ? wp_kses_post( $_POST['ezd_doc_right_sidebar'] ) : '';

		if ( ! empty( $ezd_doc_read_unread ) ) {
			update_post_meta( $post_id, 'ezd_doc_read_unread', $ezd_doc_read_unread );
		}
		if ( ! empty( $ezd_doc_read_unread_time ) ) {
			update_post_meta( $post_id, 'ezd_doc_read_unread_time', $ezd_doc_read_unread_time );
		}
		if ( ! empty( $ezd_doc_left_sidebar ) ) {
			update_post_meta( $post_id, 'ezd_doc_left_sidebar', $ezd_doc_left_sidebar );
		}
		if ( ! empty( $ezd_doc_right_sidebar ) ) {
			update_post_meta( $post_id, 'ezd_doc_right_sidebar', $ezd_doc_right_sidebar );
		}

		if ( isset( $_POST['ezd_doc_contributors'] ) ) {
			update_post_meta( $post_id, 'ezd_doc_contributors', sanitize_text_field( $_POST['ezd_doc_contributors'] ) );
		}

		// Revisions logic
		$ezd_doc_revisions     = wp_get_post_revisions( $post_id );
		$ezd_doc_revision_list = [];
		foreach ( $ezd_doc_revisions as $ezd_doc_revision ) {
			$ezd_doc_revision_list[] = $ezd_doc_revision->post_author;
		}

		if ( ! empty( $ezd_doc_revision_list ) ) {
			$existing_contributors = get_post_meta( $post_id, 'ezd_doc_contributors', true );
			$existing_array        = array_filter( explode( ',', $existing_contributors ) );
			$merged_contributors   = array_unique( array_merge( $existing_array, $ezd_doc_revision_list ) );
			update_post_meta( $post_id, 'ezd_doc_contributors', implode( ',', $merged_contributors ) );
		}
	}

	// Secondary Title
	if ( isset( $_POST['ezd_doc_secondary_title_nonce'] ) && wp_verify_nonce( $_POST['ezd_doc_secondary_title_nonce'], 'ezd_doc_secondary_title_save' ) ) {
		$ezd_doc_secondary_title = isset( $_POST['ezd_doc_secondary_title'] ) ? sanitize_text_field( $_POST['ezd_doc_secondary_title'] ) : '';
		if ( ! empty( $ezd_doc_secondary_title ) ) {
			update_post_meta( $post_id, 'ezd_doc_secondary_title', $ezd_doc_secondary_title );
		}
	}
} );

// User Feedback archive menu active
add_action( 'admin_footer', function () { ?>
    <script>
      // User feedback archived
      feedback_archived = 'admin.php?page=ezd-user-feedback-archived';
      doc_badge = 'edit-tags.php?taxonomy=doc_badge&post_type=docs';

      // EazyDocs menu active when it's EazyDocs screen
      if (window.location.href.indexOf(feedback_archived) > -1) {
        jQuery('.toplevel_page_eazydocs').
            removeClass('wp-not-current-submenu').
            addClass('wp-has-current-submenu wp-menu-open').
            find('li').
            has('a[href*="admin.php?page=ezd-user-feedback"]').
            addClass('current');
      }
      if (window.location.href.indexOf(doc_badge) > -1) {
        jQuery('.toplevel_page_eazydocs').
            removeClass('wp-not-current-submenu').
            addClass('wp-has-current-submenu wp-menu-open').
            find('li').
            has('a[href*="edit-tags.php?taxonomy=doc_badge&post_type=docs"]').
            addClass('current');
      }
    </script>
<?php } );

/**
 * Private Doc visibility
 * Guest Login / Login Required
 * Login with custom form
 **/
function ezd_private_login() {
	if ( is_404() ) {
		global $wp_query;
		global $wpdb;
		$query          = $wp_query->request;
		$statuses       = $wpdb->get_results( $query );
		$is_post_status = '';

		if ( $statuses ) {
			foreach ( $statuses as $status ) {
				$is_post_status = $status->post_status ?? '';
				$is_post_id     = $status->ID ?? '';
			}
		}

		if ( 'private' === $is_post_status && 'docs' === get_post_type( $is_post_id ) ) {
			$doc_user_mode    = ezd_get_opt( 'private_doc_mode' );
			$add_user_page_id = ezd_get_opt( 'private_doc_login_page' );
			$add_user_page_id = get_permalink( $add_user_page_id );

			if ( 'login' === $doc_user_mode && ! empty( $add_user_page_id ) ) {
				if ( ! is_user_logged_in() ) {
					$permalink_structure = get_option('permalink_structure');
					if ( empty( $permalink_structure ) ) {
						$post_id = '&post_id=' . $is_post_id;
					} else {
						$post_id = '?post_id=' . $is_post_id;
					}

					wp_safe_redirect( $add_user_page_id . $post_id . '&private_doc=yes' );
				}
			}
		}
	}
}

// Run before the headers and cookies are sent.
add_action( 'wp', 'ezd_private_login' );


/**
 * EazyDocs Login Form
 *
 * @param array $atts
 * @param array $content
 *
 * @return void
 */
add_shortcode( 'ezd_login_form', function ( $atts, $content = [] ) {
	$ezd_login_atts = shortcode_atts( [
		'login_title'      => __( 'You must log in to continue.', 'eazydocs-pro' ),
		// translators: %s is the site name.
		'login_subtitle'   => sprintf( __( 'Login to %s', 'eazydocs-pro' ), get_bloginfo() ),
		'login_btn'        => __( 'Log In', 'eazydocs-pro' ),
		'login_forgot_btn' => __( 'Forgotten account?', 'eazydocs-pro' ),
	], $atts );

	ob_start();
	if ( is_user_logged_in() ) {
		/* Silence is golden */
	} else {
		if ( function_exists( 'eazydocs_get_template' ) ) {
			eazydocs_get_template( 'ezd-login.php', [
				'login_title'      => $ezd_login_atts['login_title'] ?? '',
				'login_subtitle'   => $ezd_login_atts['login_subtitle'] ?? '',
				'login_btn'        => $ezd_login_atts['login_btn'] ?? '',
				'login_forgot_btn' => $ezd_login_atts['login_forgot_btn'] ?? '',
			] );
		}
	}

	return ob_get_clean();
} );


/**
 *  EazyDcos login by ajax
 * Add AJAX actions for both logged in and non-logged in users
 */
add_action('wp_ajax_ezd_login_check', 'ezd_login_check');
add_action('wp_ajax_nopriv_ezd_login_check', 'ezd_login_check');

/**
 * Handle EazyDocs Login via AJAX.
 *
 * Verifies the nonce, checks credentials, and logs the user in.
 *
 * @return void Sends JSON response.
 */
function ezd_login_check() {
    // Security check
    check_ajax_referer('eazydocs_local_nonce', 'nonce');

    // Get username and password from AJAX request
    $username 	= sanitize_user($_POST[ 'log' ]);
    $password 	= sanitize_text_field($_POST[ 'pwd' ]);
	$login_type = sanitize_text_field($_POST[ 'login_type' ]);

	$user_id 	= get_user_by('login', $username)->ID;
		if ( 'add' === $login_type ) {
			$permitted_role 	= ezd_match_contribute_create_doc_role( $user_id );
		} elseif ( 'edit' === $login_type ) {
			$permitted_role 	= ezd_match_contribute_edit_role( $user_id );
		} else {
			$permitted_role 	= 1;
		}

    // Perform your custom username and password check
    $user = wp_authenticate($username, $password);

    if ( is_wp_error( $user ) ) {
        // Failed authentication
        $response = ['success' => false, 'message' => esc_html__('Invalid username or password', 'eazydocs-pro')];
    } else {
        // Successful authentication
        $creds = [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        ];

		$success_message 	= $permitted_role ? esc_html__( 'Login successful, Redirecting...', 'eazydocs-pro' ) : esc_html__( 'You are not permitted to access this page', 'eazydocs-pro' );
		$user_signon 		= '';
		$redirect_to 		= '';

		if ( $permitted_role ) {
		$user_signon = wp_signon( $creds, false );
            $redirect_to = isset( $_POST[ 'redirect_to' ] ) ? $_POST[ 'redirect_to' ] : home_url();

			$response = [
				'success' 		=> $success_message,
				'redirect_to' 	=> $redirect_to
			];

		} else {
			$response = ['success' => false, 'message' => esc_html__('You are not permitted to access this page', 'eazydocs-pro')];
		}

        if ( is_wp_error( $user_signon ) ) {
            // Failed signon
            $response = ['success' => false, 'message' => esc_html__('Login failed', 'eazydocs-pro')];
        }
    }

    // Send JSON response
    wp_send_json($response);
}

add_action( 'wp_head', function () {
	$doc_login    = function_exists('ezd_get_page_by_title') ? ezd_get_page_by_title( 'Documentation Login' ) : [];
	$doc_login_id = $doc_login[0]->ID ?? '';
	?>
    <style>
        <?php echo '.page-id-'.esc_attr( $doc_login_id ); ?>
        .ezd_doc_login_form {
            margin: auto;
            width: 515px;
        }

        <?php echo '.page-id-'.esc_attr( $doc_login_id ); ?>
        .ezd_doc_login_wrap {
            background-color: #e9ebee;
            width: 100%;
            height: 100vh;
            display: flex;
        }

        <?php echo '.page-id-'.esc_attr( $doc_login_id ); ?>
        .ezd_doc_login_form input {
            width: 300px
        }

        <?php echo '.page-id-'.esc_attr( $doc_login_id ); ?>
        .ezd-login-form-wrap {
            padding: 22px 108px 26px;
        }
    </style>
<?php } );

add_action('admin_head', function(){
	?>
	<style>
	#doc_extra_information {
		display: none;
	}
	</style>
	<?php
});

add_action( 'wp_ajax_ezd_doc_contributor', 'ezd_doc_contributor' );

/**
 * Handle Doc Contributor AJAX actions (Add/Delete).
 *
 * @return void
 */
function ezd_doc_contributor() {

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'eazydocs_local_nonce' ) ) {
		wp_die( esc_html__( 'Security check failed', 'eazydocs-pro' ) );
	}

	$doc_id = isset( $_POST['data_doc_id'] ) ? intval( $_POST['data_doc_id'] ) : 0;

	if ( ! current_user_can( 'edit_post', $doc_id ) ) {
		wp_die( esc_html__( 'Unauthorized access', 'eazydocs-pro' ) );
	}

	$is_doc_delete  = sanitize_text_field( $_POST['contributor_delete'] ?? '' );
	$is_doc_add     = sanitize_text_field( $_POST['contributor_add'] ?? '' );

	$allowed_roles = ezd_contributor_allowed_roles();

	// 🔴 Handle DELETE contributor
	if ( $is_doc_delete ) {
		$ezd_doc_contributor_list = get_post_meta( $doc_id, 'ezd_doc_contributors', true );
		$ezd_doc_contributor_array = array_filter( explode( ',', $ezd_doc_contributor_list ) );

		$updated_contributor_array = array_diff( $ezd_doc_contributor_array, [ $is_doc_delete ] );
		update_post_meta( $doc_id, 'ezd_doc_contributors', implode( ',', $updated_contributor_array ) );

		$user = get_userdata( $is_doc_delete );
		if ( $user && array_intersect( $allowed_roles, (array) $user->roles ) ) :
			?>
			<ul class="users_wrap_item <?php echo esc_attr( 'to-add-user-' . $is_doc_delete ); ?>" id="<?php echo esc_attr( 'to-add-user-' . $is_doc_delete ); ?>">
				<li>
					<a href="<?php echo esc_url( get_author_posts_url( $is_doc_delete ) ); ?>">
						<?php echo get_avatar( $is_doc_delete, 35 ); ?>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( get_author_posts_url( $is_doc_delete ) ); ?>">
						<?php echo esc_html( get_the_author_meta( 'display_name', $is_doc_delete ) ); ?>
					</a>
					<span><?php echo esc_html( get_the_author_meta( 'user_email', $is_doc_delete ) ); ?></span>
				</li>
				<li>
					<a data_name="<?php echo esc_attr( get_the_author_meta( 'display_name', $is_doc_delete ) ); ?>"
					   class="ezd_contribute_add circle-btn"
					   data-contributor-add="<?php echo esc_attr( $is_doc_delete ); ?>"
					   data-doc-id="<?php echo esc_attr( $doc_id ); ?>"> &plus; </a>
				</li>
			</ul>
			<?php
		endif;
	}

	// 🟢 Handle ADD contributor
	if ( $is_doc_add ) {
		$user = get_userdata( $is_doc_add );
		if ( ! $user || ! array_intersect( $allowed_roles, (array) $user->roles ) ) {
			wp_die(); // Don't allow unauthorized role to be added
		}

		$ezd_doc_contributor_list = get_post_meta( $doc_id, 'ezd_doc_contributors', true );
		$ezd_doc_contributor_array = array_filter( explode( ',', $ezd_doc_contributor_list ) );

		$ezd_doc_contributor_array[] = $is_doc_add;
		$ezd_doc_contributor_array = array_unique( $ezd_doc_contributor_array );

		update_post_meta( $doc_id, 'ezd_doc_contributors', implode( ',', $ezd_doc_contributor_array ) );

		?>
		<ul class="users_wrap_item <?php echo esc_attr( 'user-' . $is_doc_add ); ?>" id="<?php echo esc_attr( 'user-' . $is_doc_add ); ?>">
			<li>
				<a href="<?php echo esc_url( get_author_posts_url( $is_doc_add ) ); ?>">
					<?php echo get_avatar( $is_doc_add, 35 ); ?>
				</a>
			</li>
			<li>
				<a href="<?php echo esc_url( get_author_posts_url( $is_doc_add ) ); ?>">
					<?php echo esc_html( get_the_author_meta( 'display_name', $is_doc_add ) ); ?>
				</a>
				<span><?php echo esc_html( get_the_author_meta( 'user_email', $is_doc_add ) ); ?></span>
			</li>
			<li>
				<a data_name="<?php echo esc_attr( get_the_author_meta( 'display_name', $is_doc_add ) ); ?>"
				   class="ezd_contribute_delete circle-btn"
				   data-contributor-delete="<?php echo esc_attr( $is_doc_add ); ?>"
				   data-doc-id="<?php echo esc_attr( $doc_id ); ?>">
					&times;
				</a>
			</li>
		</ul>

		<a id="contributor-avatar-<?php echo esc_attr( $is_doc_add ); ?>"
		   title="<?php echo esc_attr( get_the_author_meta( 'display_name', $is_doc_add ) ); ?>"
		   href="<?php echo esc_url( get_author_posts_url( $is_doc_add ) ); ?>"
		   data-bs-toggle="tooltip"
		   data-bs-placement="bottom">
			<img width="24px" src="<?php echo esc_url( get_avatar_url( $is_doc_add, [ 'size' => 24 ] ) ); ?>">
		</a>
		<?php
	}

	wp_die();
}


/**
 * Admin assets
 *
 * @return bool|void
 */
function ezydocspro_admin_assets() {
	$admin_page = $_GET[ 'page' ] ?? '';
	$post_type  = $_GET[ 'post_type' ] ?? '';

	if ( strstr( $_SERVER['REQUEST_URI'], 'wp-admin/users.php' ) || 'eazydocs-builder' === $admin_page || 'eazydocs-settings' === $admin_page
	     || 'ezd-analytics' === $admin_page
	     || 'ezd-user-feedback' === $admin_page
	     || 'ezd-user-feedback-archived' === $admin_page
	     || 'onepage-docs' === $post_type
	) {
		return true;
	}
}

/**
 * Number format function
 *
 * @param $number
 *
 * @return mixed|string
 */
function eazydocspro_number_format( $number ) {
	if ( $number >= 1000 && $number < 1000000 ) {
		$number = round( $number / 1000, 1 ) . 'k';
	} elseif ( $number >= 1000000 && $number < 1000000000 ) {
		$number = round( $number / 1000000, 1 ) . 'm';
	} elseif ( $number >= 1000000000 && $number < 1000000000000 ) {
		$number = round( $number / 1000000000, 1 ) . 'b';
	} elseif ( $number >= 1000000000000 ) {
		$number = round( $number / 1000000000000, 1 ) . 't';
	}

	return $number;
}

/**
 * Frontend assets
 *
 * @return bool|void
 */
function ezydocspro_frontend_assets() {
	global $post;
	$post_content_check = $post->post_content ?? '';

	if ( in_array( 'eazydocs_shortcode', get_body_class() ) || has_shortcode( $post_content_check, 'ezd_login_form' ) || is_singular( 'docs' )
	     || is_singular( 'onepage-docs' )
	) {
		return true;
	}
}

/**
 * Assistant assets
 *
 * @return bool|void
 */
function eazydocspro_assistant_assets() {
	$opt       = get_option( 'eazydocs_settings' );
	$assistant = $opt['assistant_visibility'] ?? '1';

	if ( 1 === (int) $assistant ) {
		return true;
	}
}

// Get top level parent doc id
function get_root_parent_id( $page_id ) {
	global $wpdb;
	$parent = $wpdb->get_var( "SELECT post_parent FROM $wpdb->posts WHERE post_type='docs' AND post_status='publish' AND ID = '$page_id'" );
	if ( 0 === (int) $parent ) {
		return $page_id;
	} else {
		return get_root_parent_id( $parent );
	}
}

/**
 * Assign parent to new doc
 *
 * @param string  $post_content
 * @param WP_Post $post
 *
 * @return string
 */
function ezd_assign_parent_to_new_doc( $post_content, $post ) {
	if ( 'docs' !== $post->post_type ) {
		return $post_content;
	}

	if ( isset( $_GET['add_new_doc'] ) && isset( $_GET['ezd_doc_order'] ) ) {
		$post->post_parent 	= $_GET[ 'ezd_doc_parent' ] ?? ''; // Parent post_id goes here
		$post->menu_order  .= $_GET[ 'ezd_doc_order' ] ?? ''; // Total child posts counter as order goes here
		wp_update_post( $post );
	}

	return $post_content;
}

add_filter( 'default_content', 'ezd_assign_parent_to_new_doc', 10, 2 );

// Register image size for embed post
add_image_size( 'ezd_embed_thumb', 100, 100, true );

/**
 * Get contributors
 */
function load_more_contributors() {

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'eazydocs_local_nonce' ) ) {
		wp_die( esc_html__( 'Security check failed', 'eazydocs-pro' ) );
	}

	$doc_id = isset( $_POST['doc_id'] ) ? intval( $_POST['doc_id'] ) : 0;

	if ( ! current_user_can( 'edit_post', $doc_id ) ) {
		wp_die( esc_html__( 'Unauthorized access', 'eazydocs-pro' ) );
	}

	$loaditems = isset( $_POST['loaditems'] ) ? absint( $_POST['loaditems'] ) : 3;
	$paged     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
	$exclude   = isset( $_POST['exclude'] ) ? $_POST['exclude'] : [];

	if ( is_array( $exclude ) ) {
		$exclude = array_map( 'intval', $exclude );
	}

	$users = get_users( [
		'number'   => $loaditems,
		'paged'    => $paged,
		'exclude'  => $exclude,
		'role__in' => ezd_contributor_allowed_roles(),
	] );

	if ( ! empty( $users ) ) :
		foreach ($users as $user ) :
			?>
			<ul class="users_wrap_item to-add-<?php echo esc_attr('user-'.$user->ID); ?>"
				id="to-add-<?php echo esc_attr('user-'.$user->ID); ?>">
				<li>
					<a href='<?php echo esc_url( get_author_posts_url($user->ID) ); ?>'>
						<?php echo get_avatar($user->ID, '35'); ?>
					</a>
				</li>
				<li>
					<a href='<?php echo esc_url( get_author_posts_url($user->ID) ); ?>'>
						<?php echo esc_html( $user->display_name ?? '' ); ?>
					</a>
					<span> <?php echo esc_html( $user->user_email ?? '' ); ?> </span>
				</li>
				<li>
					<a data_name="<?php echo esc_attr( get_the_author_meta( 'display_name', $user->ID ) ); ?>" class="circle-btn ezd_contribute_add" data-contributor-add="<?php echo esc_attr($user->ID); ?>" data-doc-id="<?php echo esc_attr( $doc_id ); ?>">
						&plus;
					</a>
				</li>
			</ul>
			<?php
		endforeach;
	endif;
	die;
}
add_action( 'wp_ajax_load_more_contributors', 'load_more_contributors' );

/**
 * Adds the author's avatar and name to the REST API response for the 'docs' post type.
 */
function ezd_author_avatar_to_docs_rest( $response, $post, $request ) {
    if ('docs' === $post->post_type) {
        $author_id	= $post->post_author;
        $avatar_url	= get_avatar_url($author_id, ['size' => 96]);

        // Add avatar URL and author name to the API response
        $response->data['author_avatar'] = $avatar_url;
        $response->data['author_name']	 = get_the_author_meta('display_name', $author_id);
     }
    return $response;
}
add_filter('rest_prepare_docs', 'ezd_author_avatar_to_docs_rest', 10, 3);

/**
 * Manage private post access for specific user roles.
 * 
 * Uses new settings: private_doc_access_type, private_doc_allowed_roles
 * Falls back to legacy settings: private_doc_user_restriction for backward compatibility
 * 
 * @return void
 */
function ezd_manage_private_post_access() {
    // Define all available user roles
    $all_users_role = array_keys( function_exists( 'eazydocs_user_role_names' ) ? eazydocs_user_role_names() : [ 'administrator', 'editor', 'author', 'subscriber', 'contributor' ] );
    
    // Try new settings first
    $access_type = function_exists( 'ezd_get_opt' ) ? ezd_get_opt( 'private_doc_access_type', '' ) : '';
    
    if ( ! empty( $access_type ) ) {
        // Using new settings
        if ( 'all_users' === $access_type ) {
            // All logged-in users can access
            $allowed_roles = $all_users_role;
        } else {
            // Specific roles only
            $allowed_roles = function_exists( 'ezd_get_opt' ) ? ezd_get_opt( 'private_doc_allowed_roles', [ 'administrator', 'editor' ] ) : [ 'administrator', 'editor' ];
            if ( ! is_array( $allowed_roles ) ) {
                $allowed_roles = [ $allowed_roles ];
            }
        }
    } else {
        // Fallback to legacy settings for backward compatibility
        $opt           = function_exists( 'ezd_get_opt' ) ? ezd_get_opt( 'private_doc_user_restriction' ) : [];
        $all_roles_opt = $opt['private_doc_all_user'] ?? '';
        $allowed_roles = isset( $opt['private_doc_roles'] ) && is_array( $opt['private_doc_roles'] ) ? $opt['private_doc_roles'] : [];

        // If 'private_doc_all_user' is enabled, allow all roles; otherwise, use the selected roles.
        $allowed_roles = ( $all_roles_opt === '1' || $all_roles_opt === 1 || $all_roles_opt === true ) ? $all_users_role : $allowed_roles;
    }

    // Ensure $allowed_roles is always an array
    if ( ! is_array( $allowed_roles ) ) {
        $allowed_roles = [];
    }

	// Get all registered roles in WordPress.
	$all_roles = wp_roles()->roles;

    foreach ( $all_roles as $role_key => $role_data ) {
        $role = get_role( $role_key );
        if ( $role ) {
            if ( in_array( $role_key, $allowed_roles, true ) ) {
				// Grant permission to read private docs (EazyDocs custom capability).
				$role->add_cap( 'read_private_docs' );
            } else {
				// Remove permission from other roles.
				$role->remove_cap( 'read_private_docs' );
            }
        }
    }
}
add_action('init', 'ezd_manage_private_post_access');

/**
 * Get the public profile URL for a given username in EazyDocs Pro.
 *
 * @param string $username The user's login/username (not display name).
 * @return string The absolute, escaped URL to the user's profile page.
 */
function ezdpro_author_url( $username ) {
	$url       = trailingslashit( home_url() );

	$doc_slug  = ezd_docs_slug();
	$url      .= $doc_slug ? $doc_slug . '/' : '';

	$user_slug = rawurlencode( $username );
	$url      .= 'profile/' . $user_slug;

    return esc_url( $url );
}

/**
 * Register a custom rewrite rule for author profile pages under /docs/profile/{username}
 */
function ezd_add_profile_endpoint() {
    add_rewrite_rule( '^docs/profile/([^/]+)/?', 'index.php?ezd_profile_user=$matches[1]', 'top' );
}
add_action( 'init', 'ezd_add_profile_endpoint' );

/**
 * Register the custom query var so WordPress recognizes it
 */
function ezd_add_query_vars( $vars ) {
    $vars[] = 'ezd_profile_user';
    return $vars;
}
add_filter( 'query_vars', 'ezd_add_query_vars' );

/**
 * Determines if the current page is a user profile page within the documentation section.
 *
 * This function checks the current request URI to see if it matches the pattern for a profile page
 * (e.g., /{doc_slug}/profile/{username}/). If a valid user is found for the extracted username,
 * it sets the 'ezd_user_slug' query variable and returns true.
 *
 * @return bool True if the current page is a valid user profile page, false otherwise.
 */
function ezdpro_is_profile_page() {
	$doc_slug = function_exists('ezd_docs_slug') ? ezd_docs_slug() : '';
	$doc_slug = $doc_slug ? '/' . $doc_slug : '';

	$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
	$path = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

	if ( $home_path && 0 === strpos( $path, $home_path ) ) {
		$path = substr( $path, strlen( $home_path ) );
	}

	$pattern  = "#^$doc_slug/profile/([a-zA-Z0-9._-]+)(?:/)?$#";
	preg_match( $pattern, $path, $matches );

	$username = sanitize_user( $matches[1] ?? '' );
	$user = get_user_by( 'login', $username );

	if ( $user ) {
		set_query_var( 'ezd_user_slug', $username );
		return true;
	}

	return false;
}

/**
 * Get docs contributions by user
 *
 * @param int $user_id
 * @param int $limit
 * @param int $offset
 * @return array
 */
function ezdpro_get_contributions_by_user( int $user_id, int $limit = 5, int $offset = 0 ): array {
    $contributions = [];
    if ( ! $user_id ) {
        return $contributions;
    }
    $args = [
        'post_type'      => 'docs',
		'status'         => 'publish',
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'author'         => $user_id,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $query = new WP_Query( $args );
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $tags = [];
            $post_tags = get_the_terms( get_the_ID(), 'doc_tag' );
            if ( ! is_wp_error( $post_tags ) && ! empty( $post_tags ) ) {
                foreach ( $post_tags as $tag ) {
                    $tags[] = [
                        'name' => $tag->name,
                        'link' => get_term_link( $tag ),
                    ];
                }
            }
            $contributions[] = [
                'id'    => get_the_ID(),
                'title' => get_the_title(),
                'link'  => get_permalink(),
                'date'  => get_the_date(),
                'tags'  => $tags,
            ];
        }
        wp_reset_postdata();
    }
    return $contributions;
}

/**
 * Get docs activities by user
 *
 * @param int $user_id
 * @param int $limit
 * @param int $offset
 * @return array
 */
function ezdpro_get_activities_by_user( int $user_id, int $limit = 5, int $offset = 0 ): array {
	$activities = [];

	// Recent articles (docs)
	$activity_args = [
		'post_type'      => 'docs',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post_status'    => 'publish',
	];
	$recent_docs = new WP_Query( $activity_args );
	if ( $recent_docs->have_posts() ) {
		while ( $recent_docs->have_posts() ) {
			$recent_docs->the_post();
			$modified       = get_the_modified_time( 'U' );
			$published      = get_the_time( 'U' );

			$post_author_id = intval( get_post_field( 'post_author', get_the_ID() ) ?: -1 );
			$last_editor_id = intval( get_post_meta( get_the_ID(), '_edit_last', true ) ?: -1 );

			$date           = $modified;
			$activity_type  = '';

			if ( $user_id === $last_editor_id ) {
				if ( $published === $modified ) {
					$activity_type = __( 'Published', 'eazydocs-pro' );
				} else {
					$activity_type = __( 'Updated', 'eazydocs-pro' );
				}
			} elseif ( $user_id === $post_author_id ) {
				$activity_type = __( 'Published', 'eazydocs-pro' );
				$date = $published;
			} else {
				continue;
			}

			$activities[] = [
				'id'        => 'doc_' . get_the_ID(),
				'type'      => 'article',
				'date'      => $date,
				'label'     => $activity_type,
				'title'     => get_the_title(),
				'permalink' => get_permalink(),
			];
		}
		wp_reset_postdata();
	}

	// Recent comments
	$comments_query = [
		'user_id' => $user_id,
		'number'  => $limit,
		'status'  => 'approve',
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	];
	$recent_comments = get_comments( $comments_query );

	if ( $recent_comments ) {
		foreach ( $recent_comments as $comment ) {
			$activities[] = [
				'id'        => 'comment_' . $comment->comment_ID,
				'type'      => 'comment',
				'date'      => strtotime( $comment->comment_date_gmt ),
				'label'     => __( 'Commented on', 'eazydocs-pro' ),
				'title'     => get_the_title( $comment->comment_post_ID ),
				'permalink' => get_comment_link( $comment ),
			];
		}
	}

	// Fetch latest votes by this user (positive/negative feedback)
	$votes = [];
	$docs_query = new WP_Query([
		'post_type'      => 'docs',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [
			'relation' => 'OR',
			[
				'key'     => 'positive_voter',
				'value'   => $user_id,
				'compare' => '='
			],
			[
				'key'     => 'positive_voter',
				'value'   => 'i:' . $user_id . ';',
				'compare' => 'LIKE'
			],
			[
				'key'     => 'positive_voter',
				'value'   => '"' . $user_id . '"',
				'compare' => 'LIKE'
			],
			[
				'key'     => 'negative_voter',
				'value'   => $user_id,
				'compare' => '='
			],
			[
				'key'     => 'negative_voter',
				'value'   => 'i:' . $user_id . ';',
				'compare' => 'LIKE'
			],
			[
				'key'     => 'negative_voter',
				'value'   => '"' . $user_id . '"',
				'compare' => 'LIKE'
			]
		]
	]);

	if ( $docs_query->have_posts() ) {
		foreach ( $docs_query->posts as $doc_id ) {
			// Check if this user voted positively
			$positive_time = get_post_meta( $doc_id, 'positive_time', true );
			$positive_voter = get_post_meta( $doc_id, 'positive_voter', true );
			$positive_voter = is_array( $positive_voter ) ? $positive_voter : ( '' !== $positive_voter ? [ $positive_voter ] : [] );
			$positive_voter = array_map( 'intval', $positive_voter );
			if ( $positive_time && in_array( $user_id, $positive_voter, true ) ) {
				$votes[] = [
					'id'        => 'positive_vote_on_' . $doc_id,
					'type'      => 'vote',
					'date'      => strtotime( $positive_time ),
					'label'     => __( 'Voted on', 'eazydocs-pro' ),
					'title'     => get_the_title( $doc_id ),
					'permalink' => get_permalink( $doc_id ),
				];
			}
			// Check if this user voted negatively
			$negative_time = get_post_meta( $doc_id, 'negative_time', true );
			$negative_voter = get_post_meta( $doc_id, 'negative_voter', true );
			$negative_voter = is_array( $negative_voter ) ? $negative_voter : ( '' !== $negative_voter ? [ $negative_voter ] : [] );
			$negative_voter = array_map( 'intval', $negative_voter );
			if ( $negative_time && in_array( $user_id, $negative_voter, true ) ) {
				$votes[] = [
					'id'        => 'negative_vote_on_' . $doc_id,
					'type'      => 'vote',
					'date'      => strtotime( $negative_time ),
					'label'     => __( 'Voted on', 'eazydocs-pro' ),
					'title'     => get_the_title( $doc_id ),
					'permalink' => get_permalink( $doc_id ),
				];
			}
		}
	}

	// Merge votes into activities
	$activities = array_merge( $activities, $votes );

	// Sort all activities by date, descending
	usort( $activities, function( $a, $b ) {
		return $b['date'] - $a['date'];
	});

	$totalActivities = count ( $activities );

	$activities = array_slice( $activities, $offset, $limit );

	return [
		'activities' => $activities,
		'total'      => $totalActivities,
	];
}


/**
 * Calculate the percentage difference between two time periods of analytics data.
 *
 * @param int   $days   Number of days to compare.
 * @param array $values Associative array of dates (Y-m-d) => view counts.
 *
 * @return void Outputs the percentage difference.
 */
function ezd_analytics_diff( $days, $values ) {
    $data = [];

    // Collect values for the last $days * 2 period
    for ( $i = 0; $i < $days * 2; $i++ ) {
        $date        = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
        $data[$i]    = isset( $values[$date] ) ? (int) $values[$date] : 0;

        // Stop early if daily reporting
        if ( 'daily' === ezd_get_opt( 'reporting_frequency' ) && 1 === $i ) {
            break;
        }
    }

    // Split data into two halves
    $half            = ceil( count( $data ) / 2 );
    $latest_sum  = array_sum( array_slice( $data, 0, $half ) );
    $previous_sum = array_sum( array_slice( $data, $half ) );

    // Calculate difference percentage
    if ( $previous_sum > 0 ) {
        $diff_percent = ( ( $latest_sum - $previous_sum ) / $previous_sum ) * 100;
    } elseif ( $latest_sum > 0 ) {
       $diff_percent = $latest_sum > 100 ? $latest_sum : $latest_sum * 100;
    } else {
        $diff_percent = 0;
    }

    return round( $diff_percent, 2 );
}


/**
 * Get positive & negative vote counts within a given reporting range.
 *
 * @param int $reporting_day  Number of days (1, 7, 30, 31).
 * @return array {
 *     @type int $positive Total positive votes.
 *     @type int $negative Total negative votes.
 * }
 */
function ezd_get_total_votes_diff( $reporting_days ) {
    global $wpdb;

    $table = $wpdb->prefix . 'postmeta';
    $now   = current_time('mysql');

    $total_days = $reporting_days * 2;

    $start_date = gmdate( 'Y-m-d', strtotime( "-$total_days days", strtotime( $now ) ) );

    // Optimized: Fetch all daily totals in a single query
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $results = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            DATE(pm2.meta_value) as vote_date,
            SUM(CAST(pm1.meta_value AS UNSIGNED)) as vote_count
        FROM {$table} pm1
        INNER JOIN {$table} pm2 ON pm1.post_id = pm2.post_id
        WHERE (
            (pm1.meta_key = 'positive' AND pm2.meta_key = 'positive_time')
            OR
            (pm1.meta_key = 'negative' AND pm2.meta_key = 'negative_time')
        )
        AND pm2.meta_value >= %s
        GROUP BY DATE(pm2.meta_value)
    ", $start_date . ' 00:00:00' ) );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    $daily_votes = [];
    foreach ( $results as $row ) {
        $daily_votes[ $row->vote_date ] = (int) $row->vote_count;
    }

    $daily_total_votes = [];
    for ( $i = 0; $i < $total_days; $i++ ) {
        $date = gmdate( 'Y-m-d', strtotime( "-$i days", strtotime( $now ) ) );
        $daily_total_votes[ $date ] = isset( $daily_votes[ $date ] ) ? $daily_votes[ $date ] : 0;
    }

    // First half = latest days
    $half = ceil($total_days / 2);
    $latest_sum  = array_sum(array_slice($daily_total_votes, 0, $half)); // latest
    $previous_sum = array_sum(array_slice($daily_total_votes, $half));    // earlier

    // Calculate total percentage difference
    if ($previous_sum > 0) {
        $total_diff_percent = (($latest_sum - $previous_sum) / $previous_sum) * 100;
    } else {
        $total_diff_percent = $latest_sum > 100 ? $latest_sum : $latest_sum * 100;
    }

    $total_diff_percent = round($total_diff_percent, 2);

    return [
        'latest_total'  => $latest_sum,
        'total_diff_percent'=> $total_diff_percent,
    ];
}


/**
 * Get total posts difference percentage for a given reporting period.
 *
 * @param int $report_by_day Number of days to compare (e.g., 7, 30).
 * @return array {
 *     @type int   $recent_total   Total posts in recent period.
 *     @type int   $previous_total Total posts in previous period.
 *     @type float $diff_percent   Percentage difference (positive = increase, negative = decrease).
 * }
 */
function ezd_get_posts_diff_percentage( $report_by_day ) {
    $now = current_time('timestamp');

    // Recent period
    $recent_start   = gmdate( 'Y-m-d H:i:s', strtotime( "-".($report_by_day-1)." days", $now ) );
    $recent_end     = gmdate( 'Y-m-d H:i:s', $now );

    // Previous period
    $prev_start     = gmdate( 'Y-m-d H:i:s', strtotime( "-".($report_by_day*2-1)." days", $now ) );
    $prev_end       = gmdate( 'Y-m-d H:i:s', strtotime( "-$report_by_day days", $now ) );

    // Query recent period posts
    $recent_query = new WP_Query([
        'post_type'      => 'docs',
        'post_status'    => 'publish',
        'date_query'     => [
            [
                'after'     => $recent_start,
                'before'    => $recent_end,
                'inclusive' => true,
            ],
        ],
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ]);
    $recent_total = $recent_query->found_posts;

    // Query previous period posts
    $prev_query = new WP_Query([
        'post_type'      => 'docs',
        'post_status'    => 'publish',
        'date_query'     => [
            [
                'after'     => $prev_start,
                'before'    => $prev_end,
                'inclusive' => true,
            ],
        ],
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ]);
    $previous_total = $prev_query->found_posts;

    // Calculate percentage difference
    if ( $previous_total > 0 ) {
        $diff_percent = ( ($recent_total - $previous_total) / $previous_total ) * 100;
    } else {
        $diff_percent = $recent_total > 100 ? $recent_total : $recent_total * 100;
    }

    $diff_percent = round( $diff_percent, 2 );

    return [
        'recent_total'   => $recent_total,
        'diff_percent'   => $diff_percent,
    ];
}
