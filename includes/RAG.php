<?php
/**
 * SonoAI — RAG context builder.
 *
 * Retrieves the most semantically relevant content chunks from the knowledge
 * base (EazyDocs + Forummax) and assembles them into a system-prompt block.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAG {

    private static ?RAG $instance = null;

    public static function instance(): RAG {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Build the context string to inject into the system prompt.
     *
     * @param string $query User's question.
     * @return string Context block ready for injection; empty string if nothing found.
     */
    public static function get_context( string $query ): string {
        $post_types = self::active_post_types();
        $limit      = (int) sonoai_option( 'rag_results', 5 );

        $chunks = Embedding::search( $query, max( 1, $limit ), $post_types );

        if ( empty( $chunks ) ) {
            return '';
        }

        $lines = [];
        foreach ( $chunks as $i => $chunk ) {
            $source = self::get_source_label( $chunk['post_id'], $chunk['post_type'] );
            $lines[] = sprintf(
                "## Source %d — %s\n%s",
                $i + 1,
                $source,
                trim( $chunk['chunk_text'] )
            );
        }

        return implode( "\n\n", $lines );
    }

    /**
     * Return the list of CPT slugs currently enabled for RAG context.
     *
     * @return string[]
     */
    private static function active_post_types(): array {
        $types = [];
        if ( sonoai_option( 'rag_use_docs', '1' ) === '1' ) {
            $types[] = 'docs';
        }
        if ( sonoai_option( 'rag_use_topics', '1' ) === '1' ) {
            $types[] = 'topic';
        }
        return $types;
    }

    /**
     * Get a human-readable source label for a chunk.
     */
    private static function get_source_label( int $post_id, string $post_type ): string {
        if ( $post_id <= 0 ) {
            return ucfirst( $post_type );
        }

        $title = get_the_title( $post_id );
        $label = $title ?: sprintf( '%s #%d', ucfirst( $post_type ), $post_id );

        if ( 'docs' === $post_type ) {
            $label = '[Case] ' . $label;
        } elseif ( 'topic' === $post_type ) {
            $label = '[Forum Topic] ' . $label;
        }

        return $label;
    }

    /**
     * Build the full system prompt and return context images.
     *
     * @param string $query   User's question (used for context retrieval).
     * @return array{prompt: string, images: string[]}
     */
    public static function get_context_data( string $query ): array {
        $base_prompt = sonoai_option(
            'system_prompt',
            "You are SonoAI, an expert AI assistant specialising in ultrasound and sonography. " .
            "You help sonographers, radiologists, and medical students understand ultrasound images and clinical cases. " .
            "When analysing sonogram images, describe what you observe, relevant anatomy, and educational notes. " .
            "Always remind users that your responses are for educational purposes only and not a substitute for professional clinical judgment. " .
            "Use clear, professional medical terminology while remaining accessible."
        );

        $post_types = self::active_post_types();
        $limit      = (int) sonoai_option( 'rag_results', 5 );
        $chunks     = Embedding::search( $query, max( 1, $limit ), $post_types );

        if ( empty( $chunks ) ) {
            return [ 'prompt' => $base_prompt, 'images' => [] ];
        }

        $lines  = [];
        $images = [];

        foreach ( $chunks as $i => $chunk ) {
            $source  = self::get_source_label( $chunk['post_id'], $chunk['post_type'] );
            $lines[] = sprintf(
                "## Source %d — %s\n%s",
                $i + 1,
                $source,
                trim( $chunk['chunk_text'] )
            );
            if ( ! empty( $chunk['image_urls'] ) && is_array( $chunk['image_urls'] ) ) {
                $images = array_merge( $images, $chunk['image_urls'] );
            }
        }

        $base_prompt .= "\n\n---\n\n";
        $base_prompt .= "Use the following knowledge base excerpts to inform your answer. " .
                        "If they are relevant, reference them. If they are not, rely on your training data.\n\n";
        $base_prompt .= "<KNOWLEDGE_BASE>\n" . implode( "\n\n", $lines ) . "\n</KNOWLEDGE_BASE>";

        return [
            'prompt' => $base_prompt,
            'images' => array_values( array_unique( $images ) ),
        ];
    }
}
