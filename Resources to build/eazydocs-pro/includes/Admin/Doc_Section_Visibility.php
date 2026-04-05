<?php
namespace eazyDocsPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Section_Duplicate
 * @package eazyDocsPro\Duplicator
 */
class Doc_Section_Visibility {
	public function __construct() {
		add_action( 'admin_init', [ $this, 'ezd_doc_section_visibility' ] );
	}
	function ezd_doc_section_visibility() { 

		if ( isset( $_GET['section_doc_visibility'] ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'eazydocs-pro' ) );
		}

		$doc_id 					= $_GET['section_doc_visibility'] ?? '';
		$doc_visibility_type 		= $_GET['doc_visibility_type'] ?? '';
		$doc_depth_one 				= $_GET['doc_depth_one'] ?? '';
		$doc_depth_two 				= $_GET['doc_depth_two'] ?? '';
		
		$doc_password_input 		= '';
		if( $doc_visibility_type == 'protected' ){
			$doc_password_input 	= $_GET['doc_password_input'] ?? '';
			$doc_visibility_type 	= 'publish';
		}
		
		if( ! empty( $doc_id ) && $doc_depth_one == 'yes' ){
			$parent_doc_id 			= $doc_id.',';
			$doc_args = get_children( [
				'post_type'			=> 'docs',
				'post_parent'		=> $doc_id
			] );
			
			$sec_ids                = '';
			$child_sec_ids          = '';
			$child_ids              = '';
			foreach ( $doc_args as $pid ) {
				$sec_ids .= $pid->ID.',';
				
				$dp2 = get_children( [
					'post_parent' 	=> $pid->ID
				] );
				foreach ( $dp2 as $dp3 ) {
					$child_sec_ids .= $dp3->ID.',';
					
					$childs = get_children( [
						'post_parent'  => $dp3->ID,
						'post_type'    => 'docs'
						]
					);
					
					foreach ( $childs as $child ) {
						$child_sec_ids .= $child->ID.',';
					}
				}
			}
			
			$docs_ids 				= $parent_doc_id . $sec_ids . $child_sec_ids . $child_ids;
			$docs_ids 				= rtrim($docs_ids, ",");
			$doc_ids                = explode( ',', $docs_ids );
			$doc_ids_int            = array_map( 'intval', $doc_ids );
			
			foreach ( $doc_ids_int as $doc_id_int ) {				
				$doc_title 			= get_the_title($doc_id_int);
				$doc_title_by_id 	= explode('#', $doc_title); 
				$doc_status = [
					'post_title'	=> $doc_title_by_id[0],
					'ID' 			=> $doc_id_int,
					'post_type'		=> 'docs',
					'post_status'	=> $doc_visibility_type,
					'post_password'	=> $doc_password_input
				  ];
				wp_update_post($doc_status);
			}
			header( "Location:" . admin_url( 'admin.php?page=eazydocs-builder' ) );
		}
		
		if( ! empty( $doc_id ) && $doc_depth_two == 'yes' ){
			$parent_doc_id	 		= $doc_id.',';
			$doc_args = get_children( [
				'post_type'			=> 'docs',
				'post_parent'		=> $doc_id
			] );
			
			$sec_ids                = '';
			$child_sec_ids          = '';
			$child_ids              = '';
			foreach ( $doc_args as $pid ) {
				$sec_ids .= $pid->ID.',';
				
				$dp2 = get_children( [
					'post_parent' 	=> $pid->ID
				] );
				foreach ( $dp2 as $dp3 ) {
					$child_sec_ids .= $dp3->ID.',';
					
					$childs = get_children( [
						'post_parent'  => $dp3->ID,
						'post_type'    => 'docs'
						]
					);
					
					foreach ( $childs as $child ) {
						$child_sec_ids .= $child->ID.',';
					}
				}
			}
			
			$docs_ids 				= $parent_doc_id . $sec_ids . $child_sec_ids . $child_ids;
			$docs_ids 				= rtrim($docs_ids, ",");
			$doc_ids                = explode( ',', $docs_ids );
			$doc_ids_int            = array_map( 'intval', $doc_ids );
			
			foreach ( $doc_ids_int as $doc_id_int ) {				
				$doc_title 			= get_the_title($doc_id_int);
				$doc_title_by_id 	= explode('#', $doc_title); 
				$doc_status = [
					'post_title'	=> $doc_title_by_id[0],
					'ID' 			=> $doc_id_int,
					'post_type'		=> 'docs',
					'post_status'	=> $doc_visibility_type,
					'post_password'	=> $doc_password_input
				  ];
				wp_update_post($doc_status);
			}
			header( "Location:" . admin_url( 'admin.php?page=eazydocs-builder' ) );
		}
	}
}