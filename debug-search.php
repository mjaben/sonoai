<?php
require_once __DIR__ . '/../../../wp-load.php';
require_once __DIR__ . '/includes/Embedding.php';

use SonoAI\Embedding;

$query = "liver"; // Adjust to something you trained
$results = Embedding::search($query, 5);

echo "RESULTS:\n";
foreach ($results as $res) {
    echo "ID: " . ($res['post_id'] ?? '0') . " | Name: " . ($res['source_name'] ?? 'NULL') . " | URL: " . ($res['source_url'] ?? 'NULL') . "\n";
}
