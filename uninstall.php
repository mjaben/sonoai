<?php
/**
 * SonoAI — Uninstall script.
 * Fired when the plugin is deleted from the WordPress dashboard.
 *
 * @package SonoAI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$opts = get_option( 'sonoai_settings', [] );

// Only delete data if the user explicitly opted in via settings.
if ( empty( $opts['delete_on_uninstall'] ) ) {
    return;
}

global $wpdb;

// 1. Drop Custom Tables
$embeddings_table = $wpdb->prefix . 'sonoai_embeddings';
$sessions_table   = $wpdb->prefix . 'sonoai_sessions';

$wpdb->query( "DROP TABLE IF EXISTS `$embeddings_table`" );
$wpdb->query( "DROP TABLE IF EXISTS `$sessions_table`" );

// 2. Delete Options
delete_option( 'sonoai_settings' );
delete_option( 'sonoai_db_version' );

// 3. Clear Uploaded Image Directory
$upload_dir = wp_upload_dir();
$sonoai_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'sonoai';

if ( is_dir( $sonoai_dir ) ) {
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator( $sonoai_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $files as $fileinfo ) {
        $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
        $todo( $fileinfo->getRealPath() );
    }

    rmdir( $sonoai_dir );
}
