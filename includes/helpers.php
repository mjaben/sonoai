<?php
/**
 * SonoAI — Global helper functions.
 *
 * @package SonoAI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieve a SonoAI option.
 *
 * @param string $key     Option key (without the sonoai_ prefix).
 * @param mixed  $default Default value.
 * @return mixed
 */
function sonoai_option( string $key, $default = '' ) {
    $options = get_option( 'sonoai_settings', [] );
    return $options[ $key ] ?? $default;
}

/**
 * Save a single SonoAI option.
 *
 * @param string $key   Option key.
 * @param mixed  $value Value to store.
 */
function sonoai_set_option( string $key, $value ): void {
    $options        = get_option( 'sonoai_settings', [] );
    $options[ $key ] = $value;
    update_option( 'sonoai_settings', $options );
}

/**
 * Compute cosine similarity between two float vectors.
 * Optimized as a dot product (requires normalized vectors like OpenAI's).
 *
 * @param float[] $a
 * @param float[] $b
 * @return float Similarity score in [-1, 1].
 */
function sonoai_cosine_similarity( array $a, array $b ): float {
    $dot = 0.0;
    $len = min( count( $a ), count( $b ) );

    for ( $i = 0; $i < $len; $i++ ) {
        $dot += $a[ $i ] * $b[ $i ];
    }

    return $dot;
}

/**
 * Sanitize and strip all HTML from content for embedding.
 *
 * @param string $content Raw HTML content.
 * @return string Cleaned plain text.
 */
function sonoai_clean_content( string $content ): string {
    $content = wp_strip_all_tags( $content );
    $content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
    $content = preg_replace( '/\s+/', ' ', $content );
    return trim( $content );
}

/**
 * Get the sonoai uploads directory path and URL.
 *
 * @return array{path: string, url: string}
 */
function sonoai_upload_dir(): array {
    $wp_upload = wp_upload_dir();
    return [
        'path' => trailingslashit( $wp_upload['basedir'] ) . 'sonoai/',
        'url'  => trailingslashit( $wp_upload['baseurl'] ) . 'sonoai/',
    ];
}
