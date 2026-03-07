<?php
/**
 * SonoAI — Sonogram image handler.
 *
 * Validates, stores, encodes, and cleans up uploaded sonogram images.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ImageHandler {

    private static ?ImageHandler $instance = null;

    /** Allowed MIME types for sonogram uploads. */
    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Max file size: 10 MB. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    public static function instance(): ImageHandler {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ── Upload handling ───────────────────────────────────────────────────────

    /**
     * Validate and save an uploaded file.
     *
     * @param array $file  Entry from $_FILES array.
     * @return array{url: string, path: string}|\WP_Error
     */
    public static function save( array $file ) {
        // Size check.
        if ( $file['size'] > self::MAX_BYTES ) {
            return new \WP_Error(
                'file_too_large',
                sprintf( __( 'Image must be smaller than %d MB.', 'sonoai' ), self::MAX_BYTES / 1024 / 1024 )
            );
        }

        // MIME type check (use finfo for reliability over $_FILES['type']).
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
            return new \WP_Error(
                'invalid_mime',
                __( 'Only JPEG, PNG, WebP, or GIF images are supported.', 'sonoai' )
            );
        }

        // Destination directory.
        $dir = sonoai_upload_dir();
        if ( ! file_exists( $dir['path'] ) ) {
            wp_mkdir_p( $dir['path'] );
        }

        // Unique filename.
        $ext      = self::ext_from_mime( $mime );
        $filename = wp_unique_filename( $dir['path'], uniqid( 'sono_' ) . '.' . $ext );
        $dest     = $dir['path'] . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new \WP_Error( 'move_failed', __( 'Failed to save uploaded file.', 'sonoai' ) );
        }

        return [
            'path' => $dest,
            'url'  => $dir['url'] . $filename,
        ];
    }

    /**
     * Encode a stored image as base64 for the Vision API.
     *
     * @param string $path Absolute file path.
     * @return string|false Base64 string or false on failure.
     */
    public static function encode_base64( string $path ) {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = file_get_contents( $path );
        return $data !== false ? base64_encode( $data ) : false;
    }

    /**
     * Encode an image from a remote URL for Vision API.
     *
     * @param string $url External image URL.
     * @return string|false
     */
    public static function encode_url_base64( string $url ) {
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $data = wp_remote_retrieve_body( $response );
        return ! empty( $data ) ? base64_encode( $data ) : false;
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────

    /**
     * Delete uploaded images older than N days.
     *
     * @param int $days
     */
    public static function cleanup_old_images( int $days = 7 ): void {
        $dir       = sonoai_upload_dir();
        $threshold = time() - ( $days * DAY_IN_SECONDS );

        if ( ! is_dir( $dir['path'] ) ) {
            return;
        }

        foreach ( glob( $dir['path'] . 'sono_*' ) as $file ) {
            if ( is_file( $file ) && filemtime( $file ) < $threshold ) {
                wp_delete_file( $file );
            }
        }
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private static function ext_from_mime( string $mime ): string {
        return match ( $mime ) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };
    }
}
