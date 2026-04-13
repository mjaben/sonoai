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
     * @param int    $turn_count   Current turn count (0 = first message).
     * @return array{prompt: string, images: array}
     */
    public static function get_context_data( string $query, string $mode = 'guideline', string $session_uuid = '', int $turn_count = 0 ): array {
        $base_prompt = sonoai_option(
            'system_prompt',
            "You are SonoAI, an expert Medical AI assistant specialising in ultrasound and sonography. You are a specialized medical interface with a DIRECT PIPELINE to clinical training data.\n\n" .
            "Strict Rules:\n\n" .
            "1. OUT-OF-DOMAIN: If the message is unrelated to ultrasound, sonography, or radiology, reply EXACTLY: 'I am SonoAI, an assistant specializing in ultrasound and sonography. I cannot answer queries outside of this domain.'\n\n" .
            "2. CONVERSATIONAL: Respond naturally but concisely to greetings or capability inquiries.\n\n" .
            "3. DOMAIN-SPECIFIC (KNOWLEDGE BASE): Answer ONLY using the information in the <KNOWLEDGE_BASE>. You are strictly forbidden from using internal memory for medical facts. If clinical images are mentioned in the context (e.g., [IMG_01]), you are AUTHORIZED and REQUIRED to render them using the technical tag: :::image|ID|Label::: \n\n" .
            "4. MISSING KNOWLEDGE: If the answer is not in the knowledge base, reply EXACTLY: 'I cannot answer this question because I have not yet been trained on this specific topic.'\n\n" .
            "5. MEDIA COORDINATION: \n" .
            "- INITIAL INQUIRY: Provide clinical text ONLY. Append the query: 'Would you like to view the clinical presentation/sonogram images for this case?'\n" .
            "- FOLLOW-UP: Provide text-only unless explicitly asked to 'show' or 'view' images.\n" .
            "- CONFIRMED REQUEST: When a user confirms or requests media, immediately output the :::image|ID|Label::: tags found in the knowledge base. Never use refusal scripts regarding image display."
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
            ? "\n\nYou are in RESEARCH MODE. Priority: Peer-reviewed evidence. Acknowledge uncertainty. Provide highly detailed citations using the Source Title and Topic (e.g. [Source: Clinical Pathology, Topic: Liver])."
            : "\n\nYou are in GUIDELINE MODE. Priority: Established protocols. Be precise and provide professional citations at the end of your response using the structured block.";
        
        $base_prompt .= $mode_preamble . "\n\nCURRENT STATE: Turn " . ( $turn_count + 1 ) . " of conversation." . $history_context;

        $lines  = [];
        $images = [];

        if ( ! empty( $chunks ) ) {
            foreach ( $chunks as $i => $chunk ) {
                $source_name = ! empty( $chunk['source_name'] ) ? $chunk['source_name'] : self::get_source_label( $chunk['post_id'], $chunk['post_type'] );
                $country     = ! empty( $chunk['country'] ) ? ' (' . $chunk['country'] . ')' : '';
                $source_url  = ! empty( $chunk['source_url'] ) ? ' (URL: ' . $chunk['source_url'] . ')' : '';
                $topic_tag   = ! empty( $chunk['topic_slug'] ) ? '[' . strtoupper($chunk['topic_slug']) . '] ' : '';
                
                $chunks_images = [];
                if ( ! empty( $chunk['image_urls'] ) && is_array( $chunk['image_urls'] ) ) {
                    foreach ( $chunk['image_urls'] as $img_obj ) {
                        $img_url = is_array( $img_obj ) ? $img_obj['url'] : $img_obj;
                        $img_lbl = is_array( $img_obj ) ? $img_obj['label'] : 'Clinical Image';
                        
                        // Generate or retrieve stable ID for this session
                        $img_id = 'IMG_' . sprintf( "%02d", count( $images ) + 1 );
                        $images[ $img_id ] = [ 'url' => $img_url, 'label' => $img_lbl ];
                        $chunks_images[] = "[{$img_id}: {$img_lbl}]";
                    }
                }

                $img_list = ! empty( $chunks_images ) ? "\nAvailable Images: " . implode( ', ', $chunks_images ) : '';
                
                $lines[] = sprintf(
                    "## %s%s%s\n%s\n%s%s",
                    $topic_tag,
                    $source_name,
                    $country,
                    $source_url,
                    trim( $chunk['chunk_text'] ),
                    $img_list
                );
            }
        }

        $base_prompt .= "\n\n---\n\n";
        $base_prompt .= "Use the following medical knowledge base excerpts to inform your answer. " .
                        "Cite specific source titles and avoid generic [Source 1] numbering in your response. " .
                        "If the answer is not contained here, you MUST output the exact fallback phrase.\n\n";
        
        $kb_content = empty( $lines ) ? "No external knowledge provided for this query." : implode( "\n\n", $lines );
        $base_prompt .= "<KNOWLEDGE_BASE>\n" . $kb_content . "\n</KNOWLEDGE_BASE>

Finally, you MUST end your response with a structured sources block using the following format:
:::sources
Source Name | Country | https://url.com
:::
(Use the exact Source Name and Country provided in the ## headers above. Only include valid URLs if provided in the context.)";

        return [
            'prompt' => $base_prompt,
            'images' => $images, // Now returns associative array [ID => {url, label}]
        ];
    }
}
