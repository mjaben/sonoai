<?php
/**
 * Class Docs
 */
class User_Feedback {
	/**
	 * The post type name.
	 *
	 * @var string
	 */
	private $post_type = 'ezd_feedback';

	/**
	 * Initialize the class
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'user_feedback' ] );
	}

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public function user_feedback() {
		/**
		 * Docs slug
		 * @var string
		 */
		$slug = 'ezd_feedback';

		$labels = [
			'name'               => _x( 'User Feedback', 'Post Type General Name', 'eazydocs-pro' ),
			'singular_name'      => _x( 'User Feedback', 'Post Type Singular Name', 'eazydocs-pro' ),
			'menu_name'          => __( 'User Feedback', 'eazydocs-pro' ),
			'parent_item_colon'  => __( 'Parent Feedback', 'eazydocs-pro' ),
			'all_items'          => __( 'All Feedbacks', 'eazydocs-pro' ),
			'view_item'          => __( 'View Feedback', 'eazydocs-pro' ),
			'add_new_item'       => __( 'Add Feedback', 'eazydocs-pro' ),
			'add_new'            => __( 'Add New', 'eazydocs-pro' ),
			'edit_item'          => __( 'Edit Feedback', 'eazydocs-pro' ),
			'update_item'        => __( 'Update Feedback', 'eazydocs-pro' ),
			'search_items'       => __( 'Search Feedback', 'eazydocs-pro' ),
			'not_found'          => __( 'Not Feedback found', 'eazydocs-pro' ),
			'not_found_in_trash' => __( 'Not found in Trash', 'eazydocs-pro' ),
		];
		$rewrite = [
			'slug'       => $slug,
			'with_front' => false,
			'pages'      => false,
			'feeds'      => false,
		];
		$args = [
			'labels'              => $labels,
			'supports'            => [ 'title', 'editor' ],
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'menu_icon'           => 'dashicons-media-document',
			'can_export'          => false,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'show_in_rest'        => false,
			'rewrite'             => $rewrite,
			'map_meta_cap'          => false,
		];

		register_post_type( $this->post_type, apply_filters( 'eazydocs_post_type', $args ) );
	}
	
}
new User_Feedback();