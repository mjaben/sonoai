<?php
/**
 * Role-Based Visibility Helper Functions
 *
 * Standalone helper functions for checking role-based visibility.
 * These functions can be used throughout the plugin and in templates.
 *
 * @package eazyDocsPro
 * @since 2.10.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if role-based visibility feature is enabled.
 *
 * @return bool
 */
function ezd_is_role_visibility_enabled() {
	// Only available in pro.
	if ( ! function_exists( 'ezd_is_premium' ) || ! ezd_is_premium() ) {
		return false;
	}

	// CSF switchers may store boolean true/false OR string/integer 1/0.
	$enabled = ezd_get_opt( 'role_visibility_enable', true );
	return ( $enabled === true || $enabled === 1 || $enabled === '1' || $enabled === 'true' );
}

/**
 * Check if a user can access a specific doc based on role visibility.
 *
 * @param int      $post_id The post ID.
 * @param int|null $user_id The user ID (null for current user).
 *
 * @return bool
 */
function ezd_user_can_view_doc( $post_id, $user_id = null ) {
	// If feature is disabled, everyone can access.
	if ( ! ezd_is_role_visibility_enabled() ) {
		return true;
	}

	// Use the Role_Visibility class method if available.
	if ( class_exists( 'eazyDocsPro\Features\RoleVisibility\Role_Visibility' ) ) {
		return \eazyDocsPro\Features\RoleVisibility\Role_Visibility::user_can_access( $post_id, $user_id );
	}

	return true;
}

/**
 * Get the allowed roles for a specific doc.
 *
 * @param int $post_id The post ID.
 *
 * @return array Array of role slugs that can access the doc.
 */
function ezd_get_doc_allowed_roles( $post_id ) {
	if ( ! ezd_is_role_visibility_enabled() ) {
		return [];
	}

	if ( class_exists( 'eazyDocsPro\Features\RoleVisibility\Role_Visibility' ) ) {
		return \eazyDocsPro\Features\RoleVisibility\Role_Visibility::get_effective_roles( $post_id );
	}

	return [];
}

/**
 * Check if a doc has role restrictions.
 *
 * @param int $post_id The post ID.
 *
 * @return bool
 */
function ezd_doc_has_role_restrictions( $post_id ) {
	$roles = ezd_get_doc_allowed_roles( $post_id );
	return ! empty( $roles );
}

/**
 * Get the current user's roles.
 *
 * @param int|null $user_id User ID or null for current user.
 *
 * @return array Array of role slugs.
 */
function ezd_get_user_roles( $user_id = null ) {
	if ( is_null( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return [ 'guest' ];
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return [ 'guest' ];
	}

	return $user->roles;
}

/**
 * Set role visibility for a doc.
 *
 * @param int   $post_id The post ID.
 * @param array $roles   Array of role slugs to allow access.
 * @param bool  $apply_to_children Whether to apply to child docs.
 *
 * @return bool
 */
function ezd_set_doc_role_visibility( $post_id, $roles = [], $apply_to_children = false ) {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return false;
	}

	// Sanitize roles.
	$roles = array_map( 'sanitize_text_field', (array) $roles );

	// Save to post meta.
	update_post_meta( $post_id, 'ezd_role_visibility', $roles );

	// Apply to children if requested.
	if ( $apply_to_children ) {
		$children = get_children( [
			'post_parent' => $post_id,
			'post_type'   => 'docs',
			'post_status' => 'any',
		] );

		foreach ( $children as $child ) {
			ezd_set_doc_role_visibility( $child->ID, $roles, true );
		}
	}

	return true;
}

/**
 * Remove role visibility restrictions from a doc.
 *
 * @param int  $post_id The post ID.
 * @param bool $apply_to_children Whether to apply to child docs.
 *
 * @return bool
 */
function ezd_remove_doc_role_visibility( $post_id, $apply_to_children = false ) {
	return ezd_set_doc_role_visibility( $post_id, [], $apply_to_children );
}

/**
 * Get all docs with role restrictions.
 *
 * @param string|array $post_status Post status(es) to query.
 *
 * @return array Array of post IDs with role restrictions.
 */
function ezd_get_restricted_docs( $post_status = 'publish' ) {
	global $wpdb;

	$results = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT post_id 
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
		WHERE pm.meta_key = %s 
		AND pm.meta_value != '' 
		AND pm.meta_value != 'a:0:{}'
		AND p.post_type = 'docs'
		AND p.post_status IN ('" . implode( "','", array_map( 'esc_sql', (array) $post_status ) ) . "')",
		'ezd_role_visibility'
	) );

	return array_map( 'absint', $results );
}

/**
 * Get role visibility statistics.
 *
 * @return array Statistics about role visibility usage.
 */
function ezd_get_role_visibility_stats() {
	$restricted_docs = ezd_get_restricted_docs( [ 'publish', 'private', 'draft' ] );

	$total_docs = wp_count_posts( 'docs' );
	$public_count = isset( $total_docs->publish ) ? $total_docs->publish : 0;

	return [
		'total_docs'      => $public_count,
		'restricted_docs' => count( $restricted_docs ),
		'public_docs'     => $public_count - count( $restricted_docs ),
		'percentage'      => $public_count > 0 ? round( ( count( $restricted_docs ) / $public_count ) * 100, 1 ) : 0,
	];
}

/**
 * Filter docs array to only include accessible ones.
 *
 * @param array    $docs    Array of post objects or IDs.
 * @param int|null $user_id User ID to check access for.
 *
 * @return array Filtered array of accessible docs.
 */
function ezd_filter_accessible_docs( $docs, $user_id = null ) {
	if ( ! ezd_is_role_visibility_enabled() ) {
		return $docs;
	}

	return array_filter( $docs, function( $doc ) use ( $user_id ) {
		$post_id = is_object( $doc ) ? $doc->ID : ( is_array( $doc ) ? $doc['ID'] : $doc );
		return ezd_user_can_view_doc( $post_id, $user_id );
	} );
}