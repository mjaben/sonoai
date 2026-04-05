<?php
namespace eazyDocsPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use eazyDocsPro\Features\RoleVisibility\Role_Visibility;

/**
 * Class Doc_Visibility
 */
class Doc_Visibility {
	public function __construct() {
		add_action( 'admin_init', [ $this, 'ezd_doc_visibility' ] );
	}

	public function ezd_doc_visibility() {
		if (
			isset( $_GET['doc_visibility'], $_GET['doc_visibility_type'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), sanitize_text_field( $_GET['doc_visibility'] ) )
		) {
			// Check user capabilities
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'eazydocs-pro' ) );
			}

			$doc_id              = absint( $_GET['doc_visibility'] );
			$visibility_type     = sanitize_text_field( $_GET['doc_visibility_type'] );
			$doc_password_input  = '';

			if ( $visibility_type === 'protected' ) {
				$doc_password_input = isset( $_GET['doc_password_input'] ) ? sanitize_text_field( str_replace( ';hash;', '#', $_GET['doc_password_input'] ) ) : '';
				// If the popup didn't provide a password (e.g. keeping existing), preserve the current password.
				if ( '' === $doc_password_input && $doc_id ) {
					$current_post = get_post( $doc_id );
					if ( $current_post instanceof \WP_Post && ! empty( $current_post->post_password ) ) {
						$doc_password_input = $current_post->post_password;
					}
				}
				$visibility_type    = 'publish';
			}

			// Handle role visibility for private docs
			$role_visibility        = [];
			$apply_roles_to_children = false;
			
			if ( $visibility_type === 'private' ) {
				// Get role visibility from URL params
				if ( isset( $_GET['role_visibility'] ) && ! empty( $_GET['role_visibility'] ) ) {
					$role_visibility = array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( $_GET['role_visibility'] ) ) );
				}
				
				// Add guest if checked
				if ( isset( $_GET['role_visibility_guest'] ) && $_GET['role_visibility_guest'] === '1' ) {
					$role_visibility[] = 'guest';
				}
				
				$apply_roles_to_children = isset( $_GET['apply_roles_to_children'] ) && $_GET['apply_roles_to_children'] === '1';
			}

			if ( $doc_id ) {
				$this->ezd_apply_visibility_recursive( $doc_id, $visibility_type, $doc_password_input, $role_visibility, $apply_roles_to_children, true );
				wp_safe_redirect( admin_url( 'admin.php?page=eazydocs-builder' ) );
				exit;
			}
		}
	}

	/**
	 * Apply visibility settings recursively to doc and children.
	 *
	 * @param int    $post_id                  The post ID.
	 * @param string $visibility_type          The visibility type (publish, private).
	 * @param string $password                 Password for protected docs.
	 * @param array  $role_visibility          Array of allowed roles for private docs.
	 * @param bool   $apply_roles_to_children  Whether to apply roles to children.
	 * @param bool   $update_roles             Whether to update role visibility on this doc.
	 */
	private function ezd_apply_visibility_recursive( $post_id, $visibility_type, $password = '', $role_visibility = [], $apply_roles_to_children = false, $update_roles = false ) {
		$post = get_post( $post_id );

		if ( $post && $post->post_type === 'docs' ) {
			$title_parts = explode( '#', $post->post_title );
			$title_clean = $title_parts[0];

			wp_update_post( [
				'ID'            => $post_id,
				'post_title'    => $title_clean,
				'post_status'   => $visibility_type,
				'post_password' => $password,
			] );

			// Save role visibility for private docs
			if ( $visibility_type === 'private' ) {
				if ( $update_roles ) {
					if ( ! empty( $role_visibility ) ) {
						update_post_meta( $post_id, Role_Visibility::META_KEY, $role_visibility );
					} else {
						// Clear role visibility when no roles are selected.
						delete_post_meta( $post_id, Role_Visibility::META_KEY );
					}
				}
			} else {
				// Clear role visibility when not private
				delete_post_meta( $post_id, Role_Visibility::META_KEY );
			}

			$children = get_children( [
				'post_parent' => $post_id,
				'post_type'   => 'docs',
				'post_status' => 'any',
			] );

			foreach ( $children as $child ) {
				// Only pass role visibility to children if apply_roles_to_children is true
				$child_roles = $apply_roles_to_children ? $role_visibility : [];
				$child_update_roles = $apply_roles_to_children;
				$this->ezd_apply_visibility_recursive( $child->ID, $visibility_type, $password, $child_roles, $apply_roles_to_children, $child_update_roles );
			}
		}
	}
}