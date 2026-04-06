<?php
require_once __DIR__ . '/../../../wp-load.php';

global $wpdb;
$e_tbl = $wpdb->prefix . 'sonoai_embeddings';
$k_tbl = $wpdb->prefix . 'sonoai_kb_items';

$count = $wpdb->query( "
    UPDATE `$e_tbl` e 
    INNER JOIN `$k_tbl` k ON e.knowledge_id = k.knowledge_id 
    SET 
        e.source_name = k.source_title, 
        e.source_url = k.source_url, 
        e.country = k.country
    WHERE e.source_name IS NULL OR e.source_name = ''
" );

echo "Backfilled $count embedding rows from knowledge base metadata.\n";
