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
        $limit      = (int) sonoai_option( 'rag_results', 5 );
        $min_sim    = (float) sonoai_option( 'rag_min_similarity', 0.70 );

        $chunks = Embedding::search( $query, max( 1, $limit ), [], $min_sim );

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
            "Use clear, professional medical terminology while remaining accessible.\n\n" .
            "You MUST classify the user's message before responding and strictly follow these rules:\n\n" .
            "1. OUT-OF-DOMAIN: If the user asks a question, makes a request, or attempts to discuss a topic outside the domain of ultrasound, sonography, radiology, or relevant medicine, you MUST reply EXACTLY with the following phrase, and nothing else:\n\n" .
            "I am SonoAI, an assistant specializing in ultrasound and sonography. I cannot answer questions or discuss topics outside of this medical domain.\n\n" .
            "2. CONVERSATIONAL: If the user is greeting you, asking about your capabilities, or engaging in light, relevant conversation, respond naturally but concisely. Do not provide facts or instructions on out-of-domain topics.\n\n" .
            "3. DOMAIN-SPECIFIC: If the user is asking a domain-specific, factual, or medical question, you MUST ONLY answer using the EXACT information provided in the <KNOWLEDGE_BASE> block. You are STRICTLY FORBIDDEN from using your pre-trained internal memory to answer these questions. If the answer is not explicitly written in the provided knowledge base, you cannot answer it.\n\n" .
            "4. MISSING KNOWLEDGE: If the user asks a factual question within your domain, but the answer cannot be found entirely within the provided context, or if the context is empty, you MUST reply EXACTLY with the following phrase, and nothing else:\n\n" .
            "I cannot answer this question because I have not yet been trained on this specific topic."
        );

        $limit      = (int) sonoai_option( 'rag_results', 5 );
        $min_sim    = (float) sonoai_option( 'rag_min_similarity', 0.70 );
        $chunks     = Embedding::search( $query, max( 1, $limit ), [], $min_sim );

        $lines  = [];
        $images = [];

        if ( ! empty( $chunks ) ) {
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
        }

        $base_prompt .= "\n\n---\n\n";
        $base_prompt .= "Use the following knowledge base excerpts to inform your answer. " .
                        "If the answer is not contained here, you MUST output the exact fallback phrase mentioned above.\n\n";
        
        $kb_content = empty( $lines ) ? "No external knowledge provided for this query." : implode( "\n\n", $lines );
        $base_prompt .= "<KNOWLEDGE_BASE>\n" . $kb_content . "\n</KNOWLEDGE_BASE>";

        return [
            'prompt' => $base_prompt,
            'images' => array_values( array_unique( $images ) ),
        ];
    }
}
