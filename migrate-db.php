<?php
require_once __DIR__ . '/../../../wp-load.php';

global $wpdb;
$table = $wpdb->prefix . 'sonoai_embeddings';

$columns = $wpdb->get_col( "DESCRIBE `$table`" );

if ( ! in_array( 'country', $columns ) ) {
    $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `country` varchar(100) DEFAULT NULL AFTER `topic_slug`" );
    echo "Added 'country' column.\n";
}

if ( ! in_array( 'source_name', $columns ) ) {
    $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `source_name` varchar(255) DEFAULT NULL AFTER `country`" );
    echo "Added 'source_name' column.\n";
}

if ( ! in_array( 'source_url', $columns ) ) {
    $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `source_url` text DEFAULT NULL AFTER `source_name`" );
    echo "Added 'source_url' column.\n";
}

echo "Database migration complete.\n";
