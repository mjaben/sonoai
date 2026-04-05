<?php
/**
 * SonoAI — RAG context builder.
 *
 * Retrieves the most semantically relevant content chunks from the knowledge
 * base and assembles them into a system-prompt block.
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
    public static function get_context( string $query, string $mode = '' ): string {
        $limit      = (int) sonoai_option( 'rag_results', 5 );
        $min_sim    = (float) sonoai_option( 'rag_min_similarity', 0.70 );

        $chunks = Embedding::search( $query, max( 1, $limit ), [], $min_sim, $mode );

        if ( empty( $chunks ) ) {
            return '';
        }

        $lines = [];
        foreach ( $chunks as $i => $chunk ) {
            $source    = self::get_source_label( $chunk['post_id'], $chunk['post_type'] );
            $topic_tag = ! empty( $chunk['topic_slug'] ) ? '[' . esc_html( $chunk['topic_slug'] ) . '] ' : '';
            $lines[]   = sprintf(
                "## Source %d — %s%s\n%s",
                $i + 1,
                $topic_tag,
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

        $pt_obj = get_post_type_object( $post_type );
        if ( $pt_obj && ! empty( $pt_obj->labels->singular_name ) ) {
            $label = '[' . $pt_obj->labels->singular_name . '] ' . $label;
        }

        return $label;
    }

    /**
     * Build the full system prompt and return context images.
     *
     * @param string $query        User's question (used for context retrieval).
     * @param string $mode         Current chat mode (guideline|research).
     * @param string $session_uuid Optional session UUID for memory-aware RAG.
     * @return array{prompt: string, images: string[]}
     */
    public static function get_context_data( string $query, string $mode = 'guideline', string $session_uuid = '' ): array {
        $base_prompt = sonoai_option(
            'system_prompt',
            "You are SonoAI, an expert AI assistant specialising in ultrasound and sonography. " .
            "You help sonographers, radiologists, and medical students understand ultrasound images and clinical cases.\n\n" .
            "You MUST classify the user's message before responding and strictly follow these rules:\n\n" .
            "1. OUT-OF-DOMAIN: If the user asks a question, makes a request, or attempts to discuss a topic outside the domain of ultrasound, sonography, radiology, or relevant medicine, you MUST reply EXACTLY with the phrase 'I am SonoAI, an assistant specializing in ultrasound and sonography...' and nothing else.\n\n" .
            "2. CONVERSATIONAL: Respond naturally but concisely. Do not provide facts on out-of-domain topics.\n\n" .
            "3. DOMAIN-SPECIFIC: Answer ONLY using the information provided in the <KNOWLEDGE_BASE> block. You are STRICTLY FORBIDDEN from using pre-trained internal memory. If the information is not in the knowledge base, you cannot answer it.\n\n" .
            "4. MISSING KNOWLEDGE: If the answer is not in the provided context, you MUST reply EXACTLY: 'I cannot answer this question because I have not yet been trained on this specific topic.'"
        );

        // Memory Retrieval
        $history_context = '';
        if ( ! empty( $session_uuid ) ) {
            $history = RedisManager::instance()->get_memory( $session_uuid, 3 );
            if ( ! empty( $history ) ) {
                $history_lines = array_map( fn( $m ) => "[{$m['role']}]: {$m['content']}", $history );
                $history_context = "\n\n<CONVERSATION_CONTEXT>\n" . implode( "\n", $history_lines ) . "\n</CONVERSATION_CONTEXT>";
                
                // Refine query with context if it's short/ambiguous
                if ( mb_strlen( $query ) < 30 ) {
                    $context_summary = end( $history )['content'] ?? '';
                    $query = "{$context_summary} {$query}";
                }
            }
        }

        $limit      = (int) sonoai_option( 'rag_results', 5 );
        $min_sim    = (float) sonoai_option( 'rag_min_similarity', 0.65 ); // Lowering slightly for metadata matches
        $chunks     = Embedding::search( $query, max( 1, $limit ), [], $min_sim, $mode );

        // Mode-specific preamble
        $mode_preamble = ( $mode === 'research' ) 
            ? "\n\nYou are in RESEARCH MODE. Priority: Peer-reviewed evidence. Acknowledge uncertainty."
            : "\n\nYou are in GUIDELINE MODE. Priority: Established protocols. Be precise and cite source numbers.";
        
        $base_prompt .= $mode_preamble . $history_context;

        $lines  = [];
        $images = [];

        if ( ! empty( $chunks ) ) {
            foreach ( $chunks as $i => $chunk ) {
                $source    = self::get_source_label( $chunk['post_id'], $chunk['post_type'] );
                $topic_tag = ! empty( $chunk['topic_slug'] ) ? '[' . $chunk['topic_slug'] . '] ' : '';
                $lines[]   = sprintf(
                    "## Source %d — %s%s\n%s",
                    $i + 1,
                    $topic_tag,
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
                        "If the answer is not contained here, you MUST output the exact fallback phrase.\n\n";
        
        $kb_content = empty( $lines ) ? "No external knowledge provided for this query." : implode( "\n\n", $lines );
        $base_prompt .= "<KNOWLEDGE_BASE>\n" . $kb_content . "\n</KNOWLEDGE_BASE>";

        return [
            'prompt' => $base_prompt,
            'images' => array_values( array_unique( $images ) ),
        ];
    }
}
