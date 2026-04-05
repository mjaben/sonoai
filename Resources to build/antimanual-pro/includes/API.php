<?php

namespace Antimanual_Pro;

class API {
    static $instance;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function add_to_kb_pdf( $request ) {
        $file = $_FILES['file'] ?? null;

        if ( ! $file ) {
            return [
                'success' => false,
                'message' => __( 'Please upload a PDF file.', 'antimanual' ),
            ];
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $moved_file = wp_handle_upload( $file, [ 'test_form' => false ] );
        $file_path  = $moved_file['file'] ?? '';
        $url        = $moved_file['url'] ?? '';

        $parser     = new \Smalot\PdfParser\Parser();
        $pdf        = $parser->parseFile( $file_path );
        $content    = $pdf->getText();

        $chunks     = \Antimanual\Embedding::insert( [
            'content' => $content,
            'type'    => 'pdf',
            'url'     => $url,
        ] );

        if ( is_wp_error( $chunks ) ) {
            return [
                'success' => false,
                'message' => $chunks->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'data'    => $chunks,
        ];
    }

    public static function add_to_kb_url( $request ) {
        $payload = json_decode( $request->get_body(), true );
        $url     = trailingslashit( $payload['url'] ?? '' );

        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return [
                'success' => false,
                'message' => __( 'Please provide a valid URL.', 'antimanual' ),
            ];
        }

        $content = wp_remote_fopen( $url );
        $content = \Soundasleep\Html2Text::convert( $content, [ 'ignore_errors' => true ] );

        $chunks = \Antimanual\Embedding::insert( [
            'content' => $content,
            'type'    => 'url',
            'url'     => $url,
        ] );

        if ( is_wp_error( $chunks ) ) {
            return [
                'success' => false,
                'message' => $chunks->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'data'    => $chunks,
        ];
    }

    public static function add_to_kb_txt( $request ) {
        $payload = json_decode( $request->get_body(), true );
        $content = $payload['content'] ?? '';

        if ( empty( $content ) ) {
            return [
                'success' => false,
                'message' => __( 'Content is empty.', 'antimanual' ),
            ];
        }

        $chunks = \Antimanual\Embedding::insert( [
            'content' => $content,
            'type'    => 'txt',
            'url'     => '/?txt=' . sanitize_title( wp_trim_words( $content, 20 ) ),
        ] );

        if ( is_wp_error( $chunks ) ) {
            return [
                'success' => false,
                'message' => $chunks->get_error_message(),
            ];
        }

        return  [
            'success' => true,
            'data'    => $chunks,
        ] ;
    }
}