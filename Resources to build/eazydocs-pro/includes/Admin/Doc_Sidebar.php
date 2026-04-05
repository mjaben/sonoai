<?php

namespace eazyDocsPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Doc_Sidebar
 *
 * @package eazyDocsPro\Duplicator
 */
class Doc_Sidebar {
    public function __construct() {
        add_action( 'admin_init', [ $this, 'ezd_doc_sidebar' ] );
    }

    /**
     * Handle admin request to save doc sidebar content
     */
    public function ezd_doc_sidebar() {

        if (
            ! empty( $_GET['doc_sidebar'] ) &&
            ! empty( $_GET['_wpnonce'] ) &&
            wp_verify_nonce( wp_unslash($_GET['_wpnonce']), wp_unslash($_GET['doc_sidebar']) )
        ) {

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'eazydocs-pro' ) );
            }

            $current_post         = absint( $_GET['doc_sidebar'] ?? 0 );
            $ezd_doc_content_type = sanitize_text_field( $_GET['content_type'] ?? '' );
            $left_side_sidebar    = $_GET['left_side_sidebar'] ?? '';
            $content_type         = sanitize_text_field( $_GET['shortcode_right'] ?? '' );
            $page_contents_right  = $_GET['shortcode_content_right'] ?? '';

            // --- Right-side content ---
            if ( $content_type === 'widget_data_right' ) {
                $shortcode_content_right = $left_side_sidebar;
            } else {
                $page_content_right      = substr( $this->ezd_chrEncode( $page_contents_right ), 6 );
                $shortcode_content_right = substr_replace( $page_content_right, "", -6 );
                $shortcode_content_right = str_replace( [ 'style@', ';hash;', 'style&equals;' ], [ 'style=', '#', 'style' ], $shortcode_content_right );
            }

            // --- Left-side content ---
            $page_contents = $_GET['shortcode_content'] ?? '';

            if ( $ezd_doc_content_type === 'widget_data' ) {
                $shortcode_content = $left_side_sidebar;
            } else {
                $page_content      = substr( $this->ezd_chrEncode( $page_contents ), 6 );
                $shortcode_content = substr_replace( $page_content, "", -6 );
                $shortcode_content = str_replace( [ 'style@', ';hash;', 'style&equals;' ], [ 'style=', '#', 'style' ], $shortcode_content );
            }

            // --- Update post meta ---
            if ( $current_post > 0 ) {

                if ( ! empty( $ezd_doc_content_type ) ) {
                    update_post_meta( $current_post, 'ezd_doc_left_sidebar_type', $ezd_doc_content_type );
                }

                if ( ! empty( $content_type ) ) {
                    update_post_meta( $current_post, 'ezd_doc_right_sidebar_type', $content_type );
                }

                if ( ! empty( $shortcode_content ) ) {
                    update_post_meta( $current_post, 'ezd_doc_left_sidebar', $shortcode_content );
                }

                if ( ! empty( $shortcode_content_right ) ) {
                    update_post_meta( $current_post, 'ezd_doc_right_sidebar', $shortcode_content_right );
                }
            }

            // Redirect back to admin page
            wp_safe_redirect( admin_url( 'admin.php?page=eazydocs-builder' ) );
            exit;
        }
    }

    /**
     * Helper to clean special characters
     */
    private function ezd_chrEncode( $data ) {
        if ( ! is_string( $data ) ) {
            return $data;
        }

        $replacements = [
            'â€™'   => '&#39;',
            'Ã©'    => 'é',
            'â€'    => '-',
            '-œ'    => '&#34;',
            'â€œ'   => '&#34;',
            'Ãª'    => 'ê',
            'Ã¶'    => 'ö',
            'â€¦'   => '...',
            '-¦'    => '...',
            'â€“'   => '–',
            'â€²s'  => '’',
            '-²s'   => '’',
            'â€˜'   => '&#39;',
            '-˜'    => '&#39;',
            '-“'    => '-',
            'Ã¨'    => 'è',
            'ï¼ˆ'  => '(',
            'ï¼‰'  => ')',
            'â€¢'   => '&bull;',
            '-¢'    => '&bull;',
            'Â§ï‚§' => '&bull;',
            'Â®'    => '&reg;',
            'â„¢'   => '&trade;',
            'Ã±'    => 'ñ',
            'Å‘s'   => 'ő',
            '\\"'   => '&quot;',
            "\r"    => '',
            "\\r"   => '',
            "\n"    => '',
            "\\n"   => '',
            "\\'"   => '',
            "\\"    => '',
        ];

        return strtr( $data, $replacements );
    }
}
