<?php
namespace eazyDocsPro\Duplicator;

/**
 * Class EazyDocs_Duplicate
 */
class EazyDocs_Duplicate {
	public function __construct() {
		add_action( 'admin_action_doc_duplicate', [ $this, 'eazydocs_duplicate' ] );
	}

	public function eazydocs_duplicate() {
		if (
			isset( $_GET['duplicate'], $_GET['action'], $_GET['_wpnonce'] ) &&
			sanitize_text_field( $_GET['action'] ) === 'doc_duplicate' &&
			wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), sanitize_text_field( $_GET['duplicate'] ) )
		) {
			$duplicate_id  = absint( $_GET['duplicate'] );
			// Check user capabilities
			if ( ! current_user_can( 'edit_doc', $duplicate_id ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'eazydocs-pro' ) );
			}
			$original_post = get_post( $duplicate_id );

			if ( ! $original_post || is_wp_error( $original_post ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=eazydocs-builder' ) );
				exit;
			}

			$rand = wp_rand( 1, 9999 );

			// ✅ Set the same parent as original
			$new_parent_id = ezd_duplicate_single_doc( $original_post, $original_post->post_parent, $rand );

			if ( $new_parent_id && ! is_wp_error( $new_parent_id ) ) {
				ezd_duplicate_doc_children_recursive( $original_post->ID, $new_parent_id, $rand );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=eazydocs-builder' ) );
			exit;
			}
		}

	}

/**
 * Duplicate a single doc.
 */
function ezd_duplicate_single_doc( $post, $new_parent_id, $rand ) {
	$slug = sanitize_title( $post->post_title );

	$args = [
		'post_title'   => $post->post_title . ' #' . $rand,
		'post_name'    => $slug,
		'post_content' => $post->post_content,
		'post_type'    => 'docs',
		'post_status'  => 'draft',
		'post_parent'  => $new_parent_id,
		'menu_order'   => $post->menu_order + 1,
	];

	$new_post_id = wp_insert_post( $args );

	if ( ! is_wp_error( $new_post_id ) ) {
		wp_update_post( [
			'ID'        => $new_post_id,
			'post_name' => $slug . '-' . $new_post_id
		] );

		// Optional: copy post meta
		$post_meta = get_post_meta( $post->ID );
		foreach ( $post_meta as $key => $value ) {
			update_post_meta( $new_post_id, $key, maybe_unserialize( $value[0] ) );
		}
	}

	return $new_post_id;
}

/**
 * Recursively duplicate all children.
 */
function ezd_duplicate_doc_children_recursive( $original_parent_id, $new_parent_id, $rand ) {
	$children = get_children( [
		'post_parent' => $original_parent_id,
		'post_type'   => 'docs',
		'post_status' => 'any',
	] );

	foreach ( $children as $child ) {
		$new_child_id = ezd_duplicate_single_doc( $child, $new_parent_id, $rand );

		if ( $new_child_id && ! is_wp_error( $new_child_id ) ) {
			ezd_duplicate_doc_children_recursive( $child->ID, $new_child_id, $rand );
		}
	}
}
