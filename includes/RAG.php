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
     * Refine the user's conversational query into a technical search query.
     * Extracts medical entities and standalone topics to optimize vector retrieval.
     */
    private static function refine_search_query( string $query, array $history = [] ): string {
        // Fast path for extremely short queries
        if ( mb_strlen( $query ) < 10 ) {
            return $query;
        }

        $history_context = '';
        if ( ! empty( $history ) ) {
            $last_msgs = array_slice( $history, -2 );
            $history_lines = array_map( fn( $m ) => "[{$m['role']}]: {$m['content']}", $last_msgs );
            $history_context = "\nConversation History:\n" . implode( "\n", $history_lines );
        }

        $prompt = [
            [
                'role'    => 'system',
                'content' => "You are a Search Optimizer. Your task is to transform a conversational user message into a high-precision clinical search query.\n\n" .
                             "Rules:\n" .
                             "- Extract core clinical entities, anatomical structures, and specific medical procedures.\n" .
                             "- Include any Organization names (e.g., RCOG, AIUM, ISUOG) or Years/Versions (e.g., 2024, v6) mentioned.\n" .
                             "- Remove all fluff (e.g., 'i am pursuing', 'what skill do i need').\n" .
                             "- If history indicates a topic (e.g., SITM), resolve synonyms (e.g., Special Interest Training Module).\n" .
                             "- Output ONLY the technical terms as a standalone search phrase."
            ],
            [
                'role'    => 'user',
                'content' => "Message: \"{$query}\"{$history_context}\n\nTechnical Search Query:"
            ]
        ];

        $refined = AIProvider::get_reply( $prompt );
        
        if ( is_wp_error( $refined ) || empty( trim( $refined ) ) ) {
            return $query; // Fallback to original on error
        }

        return trim( str_replace( ['"', "'"], '', $refined ) );
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
            "4. MISSING KNOWLEDGE: \n" .
            "- NEW TOPICS: If the query is about a topic completely absent from the <KNOWLEDGE_BASE>, reply EXACTLY: 'I cannot answer this question because I have not yet been trained on this specific topic.'\n" .
            "- MORE INFO: If the user asks for 'more' or 'further' details on a topic you have already answered, but no additional info exists in the <KNOWLEDGE_BASE>, summarize the key findings you have already shared and ask the user if they would like to focus on any specific finding or anatomical structure mentioned in those results.\n\n" .
            "5. MEDIA COORDINATION: \n" .
            "- IMAGE AVAILABILITY: Check the [METADATA] block below. \n" .
            "- IF IMAGES EXIST: Append the query: 'Would you like to view the associated sonogram images or clinical presentation?' \n" .
            "- IF NO IMAGES EXIST: Do NOT mention images. \n" .
            "- CONFIRMED REQUEST: When a user requests to 'show' or 'view' images, you are AUTHORIZED to output the :::image|ID|Label::: tags found in the underlying context data.\n\n" .
            "6. SOURCES: You MUST end every single response with the :::sources block. Do NOT include source names or citations in the middle of your response. Use only the :::sources format at the very end."
        );

        // Memory Retrieval
        $history = [];
        $history_context = '';
        if ( ! empty( $session_uuid ) ) {
            $history = RedisManager::instance()->get_memory( $session_uuid, 3 );
            if ( ! empty( $history ) ) {
                $history_lines = array_map( fn( $m ) => "[{$m['role']}]: {$m['content']}", $history );
                $history_context = "\n\n<CONVERSATION_CONTEXT>\n" . implode( "\n", $history_lines ) . "\n</CONVERSATION_CONTEXT>";
            }
        }

        // --- INTELLIGENT QUERY REFINEMENT ---
        // Decouple the SEARCH query from the RESPONSE context.
        // We search using a technical "clean" query, but respond to the "conversational" query.
        $search_query = self::refine_search_query( $query, $history );

        $limit      = (int) sonoai_option( 'rag_results', 5 );
        $min_sim    = (float) sonoai_option( 'rag_min_similarity', 0.70 );
        $chunks     = Embedding::search( $search_query, max( 1, $limit ), [], $min_sim, $mode );

        // Mode-specific preamble
        $mode_preamble = ( $mode === 'research' ) 
            ? "\n\nYou are in RESEARCH MODE. Priority: Peer-reviewed evidence. Acknowledge uncertainty. Be precise and concise."
            : "\n\nYou are in GUIDELINE MODE. Priority: Established protocols. Be precise and provide professional citations at the end of your response using the structured block.";
        
        $base_prompt .= $mode_preamble . "\n\nCURRENT STATE: Turn " . ( $turn_count + 1 ) . " of conversation." . $history_context;

        $lines  = [];
        $images = [];

        if ( ! empty( $chunks ) ) {
            foreach ( $chunks as $i => $chunk ) {
                // Source attribution: use Source Name and URL fields only. Topic is EXCLUDED from citations.
                $source_name = ! empty( $chunk['source_name'] ) ? $chunk['source_name'] : '';
                $source_url  = ! empty( $chunk['source_url'] )  ? $chunk['source_url']  : '';
                $country     = ! empty( $chunk['country'] )      ? $chunk['country']     : '';
                // Topic used only as internal context header context, never as a citation
                $topic_label = ! empty( $chunk['topic_slug'] ) ? '[' . strtoupper( $chunk['topic_slug'] ) . '] ' : '';

                // Build source attribution line for context header (clean, no topic)
                $source_attr_parts = array_filter( [ $source_name, $country ? '(' . $country . ')' : '', $source_url ? 'URL: ' . $source_url : '' ] );
                $source_attr       = implode( ' | ', $source_attr_parts );

                // Collect image data for this chunk
                $chunks_images = [];
                if ( ! empty( $chunk['image_urls'] ) && is_array( $chunk['image_urls'] ) ) {
                    foreach ( $chunk['image_urls'] as $img_obj ) {
                        $img_url = is_array( $img_obj ) ? $img_obj['url'] : $img_obj;
                        $img_lbl = is_array( $img_obj ) ? $img_obj['label'] : 'Clinical Image';
                        $img_id = 'IMG_' . sprintf( "%02d", count( $images ) + 1 );
                        $images[ $img_id ] = [ 'url' => $img_url, 'label' => $img_lbl ];
                        $chunks_images[] = "[{$img_id}: {$img_lbl}]";
                    }
                }

                // Store source meta separately for citation block instruction
                $lines[] = sprintf(
                    "## %s%s\n%s",
                    $topic_label,
                    $source_attr ?: 'Clinical Reference',
                    trim( $chunk['chunk_text'] )
                );

                // Attach image IDs to chunk for context awareness
                if ( ! empty( $chunks_images ) ) {
                    $lines[ count( $lines ) - 1 ] .= "\n" . implode( ' ', $chunks_images );
                }

                // Store source metadata for the citation instruction
                $source_map[] = [
                    'name'    => $source_name,
                    'url'     => $source_url,
                    'country' => $country,
                ];
            }
        }

        $base_prompt .= "\n\n---\n\n";

        // Technical Metadata for image awareness
        if ( ! empty( $images ) ) {
            $base_prompt .= "[METADATA: The Knowledge Base contains " . count( $images ) . " associated images. You are permitted to offer them.]\n\n";
        } else {
            $base_prompt .= "[METADATA: There are NO images associated with this context. Do NOT offer to show images.]\n\n";
        }

        $base_prompt .= "Use the following medical knowledge base excerpts to inform your answer. " .
                        "Cite specific source titles and avoid generic [Source 1] numbering in your response. " .
                        "If the answer is not contained here, you MUST output the exact fallback phrase.\n\n";
        
        $kb_content = empty( $lines ) ? "No external knowledge provided for this query." : implode( "\n\n", $lines );
        $base_prompt .= "<KNOWLEDGE_BASE>\n" . $kb_content . "\n</KNOWLEDGE_BASE>\n\n";

        // Build mode-aware citation instruction
        $source_map = $source_map ?? [];
        $has_sources = ! empty( array_filter( $source_map, fn( $s ) => ! empty( $s['name'] ) || ! empty( $s['url'] ) ) );

        if ( $has_sources ) {
            if ( $mode === 'research' ) {
                $base_prompt .= "You MUST end your response with exactly ONE :::sources block. Topic names are NOT sources. Only use Source Name and Source URL from the context. Format:\n" .
                                ":::sources\n" .
                                "Source Name | https://url.com\n" .
                                ":::";
            } else {
                // Guideline mode — include country
                $base_prompt .= "You MUST end your response with exactly ONE :::sources block. Topic names are NOT sources. Only use Source Name, Country, and Source URL from the context. Format:\n" .
                                ":::sources\n" .
                                "Source Name | Country | https://url.com\n" .
                                ":::";
            }
        } else {
            $base_prompt .= "There are no citable sources for this response. Do NOT output a :::sources block. Do NOT invent or fabricate source names or URLs.";
        }

        return [
            'prompt' => $base_prompt,
            'images' => $images, // Now returns associative array [ID => {url, label}]
        ];
    }
}
