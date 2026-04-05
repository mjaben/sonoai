<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\AIProvider;
use Antimanual\AIResponseCleaner;
use Antimanual\Embedding;
use Antimanual\PostGenerator;
use Antimanual\PostPromptBuilder;

/**
 * Documentation generation API endpoints.
 */
class DocsController {
    private const OUTLINE_MAX_LESSONS = 10;
    private const OUTLINE_MAX_SUB_LESSONS = 10;
    private const OUTLINE_MAX_TOPICS = 10;

    /**
     * Register REST routes for documentation generation.
     *
     * @param string $namespace REST namespace.
     */
    public function register_routes( string $namespace ) {
        register_rest_route( $namespace, '/generate-doc-outline', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_doc_outline' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        register_rest_route( $namespace, '/generate-doc', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_doc' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );
    }

    /**
     * Generate document outline.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function generate_doc_outline( $request ) {
        $mode              = $this->get_string_param( $request, 'mode', 'new' );
        $subject           = $this->get_string_param( $request, 'subject' );
        $language          = $this->get_string_param( $request, 'language', 'English' );
        $slug_lang         = $this->get_string_param( $request, 'slug_lang', 'English' );
        $slug_len          = intval( $request->get_param( 'slug_len' ) ?? 5 );
        $lessons_count     = intval( $request->get_param( 'lessons_count' ) ?? 5 );
        $sub_lessons_count = intval( $request->get_param( 'sub_lessons_count' ) ?? 4 );
        $topics_count      = intval( $request->get_param( 'topics_count' ) ?? 3 );
        $parent_doc_id     = intval( $request->get_param( 'parent_doc_id' ) ?? 0 );
        $improvement_presets = $this->get_string_array_param( $request, 'improvement_presets' );
        $tone              = $this->get_string_param( $request, 'tone', 'Professional but friendly and humane' );
        $use_kb            = $this->get_bool_param( $request, 'use_kb', false );
        $kb_source_ids     = $this->get_string_array_param( $request, 'kb_source_ids' );
        $files             = $_FILES['files'] ?? [];

        if ( ! in_array( $mode, [ 'new', 'append', 'improve' ], true ) ) {
            $mode = 'new';
        }

        if ( empty( $subject ) && 'improve' !== $mode ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Subject cannot be empty.', 'antimanual' ),
            ]);
        }

        if ( in_array( $mode, [ 'append', 'improve' ], true ) && $parent_doc_id <= 0 ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'A parent doc is required for this mode.', 'antimanual' ),
            ]);
        }

        if ( $lessons_count > 0 ) {
            $lessons_count = min( self::OUTLINE_MAX_LESSONS, $lessons_count );
        } else {
            $lessons_count = 'as much as appropriate (but no more than ' . self::OUTLINE_MAX_LESSONS . ')';
        }

        if ( $sub_lessons_count > 0 ) {
            $sub_lessons_count = min( self::OUTLINE_MAX_SUB_LESSONS, $sub_lessons_count );
        } else {
            $sub_lessons_count = 'as much as appropriate (but no more than ' . self::OUTLINE_MAX_SUB_LESSONS . ')';
        }

        if ( $topics_count > 0 ) {
            $topics_count = min( self::OUTLINE_MAX_TOPICS, $topics_count );
        } else {
            $topics_count = 'as much as appropriate (but no more than ' . self::OUTLINE_MAX_TOPICS . ')';
        }

        if ( ! ( $slug_len > 0 ) ) {
            $slug_len = 'as much as appropriate';
        }

        if ( 'improve' === $mode ) {
            return $this->generate_doc_improvement_outline(
                $parent_doc_id,
                $subject,
                $language,
                $slug_lang,
                $tone,
                $use_kb,
                $kb_source_ids,
                $improvement_presets,
                is_array( $files ) ? $files : []
            );
        }

        $knowledge_context = '';
        if ( $use_kb ) {
            $knowledge_context = $this->build_knowledge_context( $kb_source_ids, $subject );

            if ( empty( $knowledge_context ) ) {
                return rest_ensure_response([
                    'success' => false,
                    'message' => __( 'No knowledge base content found. Add content to Knowledge Base or disable "Use Existing Knowledge Base".', 'antimanual' ),
                ]);
            }
        }

        $system_prompt  = "
            You are a documentation outline generator.

            Follow these rules **strictly**:

            - Output ONLY valid JSON (not Markdown, not code blocks, not explanations):
            {
                \"title\": string,
                \"slug\": string,
                \"lessons\": [
                    {
                        \"title\": string,
                        \"slug\": string,
                        \"sub_lessons\": [
                            {
                                \"title\": string,
                                \"slug\": string,
                                \"topics\": [
                                    { \"title\": string, \"slug\": string }
                                ]
                            }
                        ]
                    }
                ]
            }

            - All titles should be concise and descriptive.
            - Do NOT exceed or fall short on counts. Your output must match the structure and counts exactly or it will be rejected.
            - Never generate more than " . self::OUTLINE_MAX_LESSONS . " lessons.
            - Never generate more than " . self::OUTLINE_MAX_SUB_LESSONS . " sub-lessons per lesson.
            - Never generate more than " . self::OUTLINE_MAX_TOPICS . " topics per sub-lesson.
            - Consider any uploaded files as the source of information for generating the outline.
        ";

        $user_prompt = "
            # STRICT REQUIREMENTS:
            - Language of the titles : '{$language}'
            - Language of the slugs : '{$slug_lang}'
            - Length of the slugs in words : {$slug_len}
            - Number of lessons to generate : {$lessons_count}
            - Number of sub-lessons for each lesson : {$sub_lessons_count}
            - Number of topics for each sub-lesson : {$topics_count}

            # INSTRUCTIONS:
            - Slugs should be URL-friendly, lowercase, and use hyphens to separate words.
            - DO NOT exceed or fall short in count.
            - DO NOT include extra keys or data. Just stick to the schema.
            - Now go ahead to generate the documentation outline based on the below SUBJECT.

            # TONE INSTRUCTION:
            - Adopt the following writing tone: 
                - {$tone}

            # SUBJECT:
            {$subject}
        ";

        if ( $use_kb ) {
            $user_prompt .= "

            # KNOWLEDGE BASE CONTEXT:
            {$knowledge_context}

            # KNOWLEDGE RULES:
            - Prioritize this knowledge context when building the outline.
            - Keep the outline aligned with the provided sources.
            ";
        }

        $input = [
            [
                'role'    => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $system_prompt,
                    ],
                ],
            ],
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $user_prompt,
                    ],
                ]
            ],
        ];

        $uploaded_files = $this->collect_uploaded_files( is_array( $files ) ? $files : [] );

        foreach ( $uploaded_files as $file ) {
            $input[1]['content'][] = [
                'type'     => 'input_file',
                'file_url' => esc_url_raw( $file['url'] ),
            ];
        }

        $response = AIProvider::get_reply( $input );

        foreach ( $uploaded_files as $file ) {
            wp_delete_file( $file['file'] );
        }

        if ( ! is_string( $response ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Failed to get response from AI provider.', 'antimanual' ),
            ]);
        }

        $response = AIResponseCleaner::clean_json_response( $response );

        $response = json_decode( $response, true );

        if ( ! is_array( $response ) || empty( $response['title'] ) || ! isset( $response['lessons'] ) || ! is_array( $response['lessons'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Failed to parse a valid documentation outline from the AI response.', 'antimanual' ),
            ]);
        }

        $response = $this->normalize_outline( $response );

        if ( empty( $response['lessons'] ) || ! is_array( $response['lessons'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Generated outline does not contain valid lessons.', 'antimanual' ),
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $response,
        ]);
    }

    /**
     * Analyze an existing documentation tree and return AI improvement suggestions.
     *
     * @param int      $parent_doc_id         Root doc ID.
     * @param string   $subject               Custom improvement instructions.
     * @param string   $language              Requested content language.
     * @param string   $slug_lang             Requested slug language.
     * @param string   $tone                  Writing tone.
     * @param bool     $use_kb                Whether to use knowledge-base context.
     * @param string[] $kb_source_ids         Selected KB IDs.
     * @param string[] $improvement_presets   Requested improvement presets.
     * @param array    $files                 Uploaded files array.
     * @return \WP_REST_Response
     */
    private function generate_doc_improvement_outline(
        int $parent_doc_id,
        string $subject,
        string $language,
        string $slug_lang,
        string $tone,
        bool $use_kb,
        array $kb_source_ids,
        array $improvement_presets,
        array $files
    ) {
        $existing_outline = $this->build_existing_doc_tree( $parent_doc_id );

        if ( empty( $existing_outline ) || empty( $existing_outline['post_id'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'The selected parent doc could not be loaded.', 'antimanual' ),
            ]);
        }

        $knowledge_context = '';
        if ( $use_kb ) {
            $knowledge_context = $this->build_knowledge_context( $kb_source_ids, $subject ?: $existing_outline['title'] );

            if ( empty( $knowledge_context ) ) {
                return rest_ensure_response([
                    'success' => false,
                    'message' => __( 'No knowledge base content found. Add content to Knowledge Base or disable "Use Existing Knowledge Base".', 'antimanual' ),
                ]);
            }
        }

        $analysis_context = wp_json_encode(
            $this->prepare_outline_for_analysis( $existing_outline ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ( ! is_string( $analysis_context ) ) {
            $analysis_context = '{}';
        }

        $preset_labels = $this->map_improvement_presets_to_labels( $improvement_presets );
        $preset_text   = ! empty( $preset_labels )
            ? implode( ', ', $preset_labels )
            : __( 'General documentation quality review', 'antimanual' );

        $system_prompt = '
            You are an expert documentation editor and information architect.

            You will review an existing hierarchical documentation tree and suggest the safest, highest-value improvements.

            Return ONLY valid JSON in this exact shape:
            {
                "title": string,
                "slug": string,
                "post_id": number,
                "action": "keep" | "update",
                "reason": string,
                "focus": string,
                "lessons": [
                    {
                        "post_id": number|null,
                        "title": string,
                        "slug": string,
                        "action": "keep" | "update" | "add",
                        "reason": string,
                        "focus": string,
                        "sub_lessons": [
                            {
                                "post_id": number|null,
                                "title": string,
                                "slug": string,
                                "action": "keep" | "update" | "add",
                                "reason": string,
                                "focus": string,
                                "topics": [
                                    {
                                        "post_id": number|null,
                                        "title": string,
                                        "slug": string,
                                        "action": "keep" | "update" | "add",
                                        "reason": string,
                                        "focus": string
                                    }
                                ]
                            }
                        ]
                    }
                ]
            }

            Rules:
            - Include EVERY existing doc item from the provided tree exactly once.
            - Existing items must keep their original post_id.
            - Use "keep" when no content change is needed.
            - Use "update" when an existing item should be refreshed, expanded, clarified, or corrected.
            - Use "add" only for new suggested items.
            - Never delete or omit existing items.
            - Keep titles concise and documentation-friendly.
            - Keep "reason" and "focus" short and actionable.
            - New additions should be inserted in the most appropriate place in the hierarchy.
        ';

        $user_prompt = "
            # REVIEW GOAL
            Analyze the existing documentation and suggest improvements.

            # LANGUAGE RULES
            - Content language: {$language}
            - Slug language: {$slug_lang}

            # WRITING TONE
            - {$tone}

            # IMPROVEMENT PRESETS
            - {$preset_text}

            # CUSTOM INSTRUCTIONS
            - " . ( $subject ?: __( 'No extra instructions were provided. Perform a balanced documentation review.', 'antimanual' ) ) . "

            # EXISTING DOC TREE
            {$analysis_context}

            # WHAT TO LOOK FOR
            - Missing pages or child docs that users would expect.
            - Outdated, shallow, or unclear documentation.
            - Sections that should better explain setup, usage, troubleshooting, or workflows.
            - Opportunities to improve the user guide flow and structure.
        ";

        if ( $use_kb ) {
            $user_prompt .= "

            # KNOWLEDGE BASE CONTEXT
            {$knowledge_context}

            # KNOWLEDGE RULES
            - Treat the knowledge base as a source of truth for freshness and completeness.
            - Prefer update suggestions when the existing docs appear incomplete or outdated compared to the provided knowledge.
            ";
        }

        $input = [
            [
                'role'    => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $system_prompt,
                    ],
                ],
            ],
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $user_prompt,
                    ],
                ],
            ],
        ];

        $uploaded_files = $this->collect_uploaded_files( $files );

        foreach ( $uploaded_files as $file ) {
            $input[1]['content'][] = [
                'type'     => 'input_file',
                'file_url' => esc_url_raw( $file['url'] ),
            ];
        }

        $response = AIProvider::get_reply( $input );

        foreach ( $uploaded_files as $file ) {
            wp_delete_file( $file['file'] );
        }

        if ( ! is_string( $response ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Failed to get response from AI provider.', 'antimanual' ),
            ]);
        }

        $response = AIResponseCleaner::clean_json_response( $response );
        $response = json_decode( $response, true );

        if ( ! is_array( $response ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Failed to parse improvement suggestions from the AI response.', 'antimanual' ),
            ]);
        }

        $normalized = $this->normalize_improvement_outline( $response, $existing_outline );

        return rest_ensure_response([
            'success' => true,
            'data'    => $normalized,
        ]);
    }

    /**
     * Generate document content.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function generate_doc( $request ) {
        $doc_outline_param = $request->get_param( 'doc_outline' ) ?? [];
        $mode              = $this->get_string_param( $request, 'mode', 'new' );
        $object_id         = sanitize_text_field( (string) ( $request->get_param( 'object_id' ) ?? '' ) );
        $parent_post_id    = intval( $request->get_param( 'parent_post_id' ) ?? 0 );
        $existing_post_id  = intval( $request->get_param( 'existing_post_id' ) ?? 0 );
        $improvement_action = $this->get_string_param( $request, 'improvement_action', '' );
        $improvement_reason = $this->get_string_param( $request, 'improvement_reason', '' );
        $improvement_focus  = $this->get_string_param( $request, 'improvement_focus', '' );
        $tone              = $this->get_string_param( $request, 'tone', 'Professional' );
        $status            = $this->normalize_post_status( $this->get_string_param( $request, 'status', 'draft' ) );
        $use_kb            = $this->get_bool_param( $request, 'use_kb', false );
        $kb_source_ids     = $this->get_string_array_param( $request, 'kb_source_ids' );
        $files             = $_FILES['files'] ?? [];

        $focus_keywords            = $this->get_string_array_param( $request, 'focus_keywords' );
        $focus_keyword             = $focus_keywords[0] ?? '';
        $optimize_for_seo          = $this->get_bool_param( $request, 'optimize_for_seo', true );
        $generate_meta_description = $this->get_bool_param( $request, 'generate_meta_description', true );
        $include_toc               = $this->get_bool_param( $request, 'include_toc', true );
        $include_faq               = $this->get_bool_param( $request, 'include_faq', false );
        $faq_block_type_raw        = sanitize_key( (string) ( $request->get_param( 'faq_block_type' ) ?? 'default' ) );
        $faq_block_type            = in_array( $faq_block_type_raw, [ 'default', 'advanced' ], true ) ? $faq_block_type_raw : 'default';
        $suggest_internal_links    = $this->get_bool_param( $request, 'suggest_internal_links', true );
        $show_internal_links_pro_tip       = $this->get_bool_param( $request, 'show_internal_links_pro_tip', true );
        $internal_links_pro_tip_label      = $this->get_string_param( $request, 'internal_links_pro_tip_label', 'Pro Tip:' );
        $internal_links_pro_tip_bg_color   = sanitize_hex_color( (string) ( $request->get_param( 'internal_links_pro_tip_bg_color' ) ?? '' ) ) ?: '#f5f1dd';
        $generate_featured_image           = $this->get_bool_param( $request, 'generate_featured_image', false );
        $use_post_title_in_featured_image  = $this->get_bool_param( $request, 'use_post_title_in_featured_image', true );
        $include_content_images    = $this->get_bool_param( $request, 'include_content_images', false );
        $include_image_caption     = $this->get_bool_param( $request, 'include_image_caption', false );
        $include_examples          = $this->get_bool_param( $request, 'include_examples', true );
        $target_audience           = $this->get_string_param( $request, 'target_audience', 'general' );
        $content_depth             = $this->get_string_param( $request, 'content_depth', 'balanced' );
        $word_count_target         = $this->get_string_param( $request, 'word_count_target', 'medium' );
        $language                  = $this->get_string_param( $request, 'language', 'English' );
        $generate_excerpt          = $this->get_bool_param( $request, 'generate_excerpt', true );
        $excerpt_length            = intval( $request->get_param( 'excerpt_length' ) ?? 50 );
        $excerpt_length            = max( 10, min( 200, $excerpt_length ) );

        // Per-taxonomy settings: { "doc_category": { "enabled": true, "count": "3" }, ... }
        $raw_taxonomy_settings = $request->get_param( 'taxonomy_settings' );
        if ( is_string( $raw_taxonomy_settings ) ) {
            $raw_taxonomy_settings = json_decode( $raw_taxonomy_settings, true );
        }
        $taxonomy_settings = is_array( $raw_taxonomy_settings ) ? $raw_taxonomy_settings : [];

        // Build taxonomy info for enabled taxonomies on the docs post type.
        $taxonomy_info = [];
        $docs_taxonomies = get_object_taxonomies( 'docs', 'objects' );
        foreach ( $docs_taxonomies as $tax_obj ) {
            if ( 'post_format' === $tax_obj->name || ! $tax_obj->public ) {
                continue;
            }
            $setting = $taxonomy_settings[ $tax_obj->name ] ?? null;
            // Skip disabled taxonomies.
            if ( is_array( $setting ) && empty( $setting['enabled'] ) ) {
                continue;
            }
            $count = is_array( $setting ) ? intval( $setting['count'] ?? 3 ) : 3;
            $count = max( 1, min( 10, $count ) );
            $taxonomy_info[] = [
                'slug'  => $tax_obj->name,
                'label' => $tax_obj->labels->singular_name ?: $tax_obj->label,
                'count' => $count,
            ];
        }

        $outline = [];
        if ( is_string( $doc_outline_param ) ) {
            $outline = json_decode( $doc_outline_param, true );
        } elseif ( is_array( $doc_outline_param ) ) {
            $outline = $doc_outline_param;
        }

        if ( ! is_array( $outline ) || ! isset( $outline['id'] ) || ! isset( $outline['lessons'] ) || ! is_array( $outline['lessons'] ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Invalid documentation outline format.', 'antimanual' ),
            ]);
        }

        if ( empty( $object_id ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'object_id is required', 'antimanual' ),
            ]);
        }

        if ( ! in_array( $mode, [ 'new', 'append', 'improve' ], true ) ) {
            $mode = 'new';
        }

        $targeted_obj = $outline['id'] === $object_id ? $outline : null;
        $order        = 0;

        foreach ( $outline['lessons'] as $_ => $lesson ) {
            if ( ! is_array( $lesson ) ) {
                continue;
            }

            $order++;

            if ( ( $lesson['id'] ?? '' ) === $object_id ) {
                $targeted_obj = $lesson;
                break;
            }

            $sub_lessons = $lesson['sub_lessons'] ?? [];
            if ( ! is_array( $sub_lessons ) ) {
                continue;
            }

            foreach ( $sub_lessons as $__ => $sub_lesson ) {
                if ( ! is_array( $sub_lesson ) ) {
                    continue;
                }

                $order++;

                if ( ( $sub_lesson['id'] ?? '' ) === $object_id ) {
                    $targeted_obj = $sub_lesson;
                    break 2;
                }

                $topics = $sub_lesson['topics'] ?? [];
                if ( ! is_array( $topics ) ) {
                    continue;
                }

                foreach ( $topics as $___ => $topic ) {
                    if ( ! is_array( $topic ) ) {
                        continue;
                    }

                    $order++;

                    if ( ( $topic['id'] ?? '' ) === $object_id ) {
                        $targeted_obj = $topic;
                        break 3;
                    }
                }
            }
        }

        if ( ! $targeted_obj ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( "object_id doesn't exist in the given outline.", 'antimanual' ),
            ]);
        }

        if ( empty( $targeted_obj['title'] ?? '' ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Title cannot be empty', 'antimanual' ),
            ]);
        }

        if ( 'improve' === $mode && 'keep' === $improvement_action ) {
            return rest_ensure_response([
                'success' => true,
                'data'    => $targeted_obj,
            ]);
        }

        $existing_post = null;
        if ( 'improve' === $mode && $existing_post_id > 0 ) {
            $existing_post = get_post( $existing_post_id );

            if ( ! $existing_post || 'docs' !== $existing_post->post_type ) {
                return rest_ensure_response([
                    'success' => false,
                    'message' => __( 'The existing doc to update could not be found.', 'antimanual' ),
                ]);
            }
        }

        $knowledge_context = '';
        if ( $use_kb ) {
            $knowledge_context = $this->build_knowledge_context( $kb_source_ids, (string) ( $targeted_obj['title'] ?? '' ) );

            if ( empty( $knowledge_context ) ) {
                return rest_ensure_response([
                    'success' => false,
                    'message' => __( 'No knowledge base content found. Add content to Knowledge Base or disable "Use Existing Knowledge Base".', 'antimanual' ),
                ]);
            }
        }

        $word_count_guidance_map = [
            'very-short' => '150-300 words',
            'short'      => '300-500 words',
            'medium'     => '500-800 words',
            'long'       => '800-1200 words',
            'very-long'  => '1200-2000 words',
        ];
        $word_count_guidance = $word_count_guidance_map[ $word_count_target ] ?? $word_count_guidance_map['medium'];

        $content_depth_guidance_map = [
            'concise'       => 'Keep the writing concise and focused, with minimal digressions.',
            'balanced'      => 'Provide balanced detail with practical explanations.',
            'comprehensive' => 'Provide deep, comprehensive explanations with strong technical depth.',
        ];
        $content_depth_guidance = $content_depth_guidance_map[ $content_depth ] ?? $content_depth_guidance_map['balanced'];

        $target_audience_guidance_map = [
            'developers' => 'Target software developers and technical contributors.',
            'end-users'  => 'Target end users with practical, non-technical explanations.',
            'admins'     => 'Target administrators and maintainers who manage the system.',
            'general'    => 'Target a general audience with clear and accessible explanations.',
        ];
        $target_audience_guidance = $target_audience_guidance_map[ $target_audience ] ?? $target_audience_guidance_map['general'];

        $advanced_instructions = [];

        // ── Shared SEO + Advanced Feature instructions via PostPromptBuilder ────
        $seo_result = PostPromptBuilder::build_seo_instructions( [
            'focus_keywords'            => $focus_keywords,
            'optimize_for_seo'          => $optimize_for_seo,
            'generate_meta_description' => $generate_meta_description,
            'generate_featured_image'   => $generate_featured_image,
        ] );

        $adv_result = PostPromptBuilder::build_advanced_instructions( [
            'include_toc'            => $include_toc,
            'include_faq'            => $include_faq,
            'faq_block_type'         => $faq_block_type,
            'include_conclusion'     => false, // Docs articles don't use the conclusion toggle.
            'suggest_internal_links' => $suggest_internal_links,
            'include_content_images' => $include_content_images,
        ] );

        $shared_instructions = trim( $seo_result['instructions'] . $adv_result['instructions'] );
        if ( ! empty( $shared_instructions ) ) {
            $advanced_instructions[] = $shared_instructions;
        }

        // ── Docs-specific instructions (not applicable to posts) ─────────────
        if ( $include_examples ) {
            $advanced_instructions[] = 'Include practical examples where relevant to improve clarity.';
        }

        if ( empty( $advanced_instructions ) ) {
            $advanced_instructions[] = 'Keep the content clear, accurate, and aligned with the provided outline.';
        }

        $advanced_instructions_text = implode( "\n                - ", $advanced_instructions );

        $outline_context = wp_json_encode( $outline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $outline_context ) ) {
            $outline_context = '{}';
        }

        $system_prompt = "
            You are a professional WordPress content writer.

            Your job is to write a documentation article formatted strictly in WordPress Gutenberg block format and output valid JSON.

            You must return ONLY valid JSON:
            {
                \"content\": \"...gutenberg blocks...\",
                \"meta_description\": \"...\",
                \"image_prompt\": \"...\",
                \"excerpt\": \"...\",
                \"terms\": { \"taxonomy_slug\": [\"Term 1\", \"Term 2\"] }
            }

            The \"meta_description\" can be an empty string if not requested.
            The \"image_prompt\" can be an empty string if not requested.
            The \"excerpt\" can be an empty string if not requested.
            The \"terms\" can be an empty object if not requested.

            **Important Rules**:
            - Do NOT use Markdown, HTML tags, or inline styling.
            - Use only Gutenberg block syntax (with `<!-- wp:... -->` and corresponding JSON attributes).
            - The \"content\" field must contain only Gutenberg block content.
            - Do not output explanations, markdown fences, or text outside JSON.
            - Consider any uploaded files as the source of information for writing the article.

            **Structure Tips**:
            - Begin with a paragraph block.
            - Use multiple heading blocks to break the article into sections.
            - Include list blocks, paragraph blocks, and any other relevant Gutenberg blocks to make the content rich and readable.
        ";

        $user_prompt = "
            # DOC OUTLINE:
            {$outline_context}

            # TASK:
            Write a full-length documentation article for the title: \"{$targeted_obj['title']}\" (ID: {$targeted_obj['id']}).

            # RULES:
            - Begin directly with a paragraph block (do NOT include a heading — the title already exists in the page).
            - Use ONLY **WordPress Gutenberg block syntax**. Every single content section must be properly wrapped in Gutenberg blocks like:

            <!-- wp:paragraph -->
            <p>...</p>
            <!-- /wp:paragraph -->

            <!-- wp:heading {\"level\":2} -->
            <h2>...</h2>
            <!-- /wp:heading -->

            <!-- wp:list -->
            <ul><li>...</li></ul>
            <!-- /wp:list -->

            - If including links, code snippets, or images:
            - Wrap links in proper paragraph or list blocks.
            - Wrap code in:
                <!-- wp:code -->
                <pre class=\"wp-block-code\"><code>...</code></pre>
                <!-- /wp:code -->
            - Wrap images using:
                <!-- wp:image {\"alt\":\"...\",\"caption\":\"...\"} -->
                <figure class=\"wp-block-image\">...</figure>
                <!-- /wp:image -->

            - DO NOT return Markdown (`##`, `-`, etc.) or raw HTML (`<h2>`, `<img>`, etc.).
            - DO NOT return unwrapped content. Every line of content must be within a Gutenberg block comment.

            # CONTEXT:
            Use the full DOC OUTLINE above to understand the position and role of this section. This section may be a lesson or sub-lesson — use the style and depth appropriate to that level.

            # STYLE:
            - Keep tone consistent with the outline's theme.
            - Use clear, technical language suited for documentation.
            - Format for readability: break up long text with headings and lists.
            - Target word count: {$word_count_guidance}.
            - {$content_depth_guidance}
            - {$target_audience_guidance}
            - Apply these additional requirements:
                - {$advanced_instructions_text}

            # TONE INSTRUCTION:
                - Adopt the following writing tone: {$tone}
        ";

        // Excerpt instructions.
        if ( $generate_excerpt ) {
            $user_prompt .= sprintf(
                '
            # EXCERPT:
            - Generate a compelling excerpt/summary of exactly %d words that captures the essence of the article.
            - Place it in the "excerpt" field of the JSON response.
            ',
                $excerpt_length
            );
        }

        // Taxonomy instructions.
        if ( ! empty( $taxonomy_info ) ) {
            $taxonomy_lines = [];
            foreach ( $taxonomy_info as $tax ) {
                $taxonomy_lines[] = sprintf(
                    '- For taxonomy "%s" (slug: "%s"): generate exactly %d relevant terms.',
                    $tax['label'],
                    $tax['slug'],
                    $tax['count']
                );
            }
            $taxonomy_list = implode( "\n            ", $taxonomy_lines );
            $user_prompt .= '
            # TAXONOMY TERMS:
            ' . $taxonomy_list . '
            - Place them in the "terms" object keyed by taxonomy slug, each containing an array of term strings.
            - Example: { "terms": { "doc_category": ["Getting Started", "API Reference"] } }
            ';
        }

        if ( $existing_post ) {
            $existing_content = (string) $existing_post->post_content;
            $user_prompt     .= '

            # EXISTING CONTENT TO REVISE:
            ' . $existing_content . '

            # IMPROVEMENT GOAL:
            - This is an existing doc that should be revised instead of created from scratch.
            - Suggested action: ' . ( $improvement_action ?: 'update' ) . '
            - Why it needs work: ' . ( $improvement_reason ?: 'Improve clarity, freshness, and usefulness.' ) . '
            - Focus for this revision: ' . ( $improvement_focus ?: 'Refresh the content and make the guidance more complete.' ) . '
            - Preserve correct information where possible, but update outdated, weak, or unclear sections.
            ';
        }

        if ( $use_kb ) {
            $user_prompt .= "

            # KNOWLEDGE BASE CONTEXT:
            {$knowledge_context}

            # KNOWLEDGE RULES:
            - Use this knowledge context as a primary source of truth.
            - Keep generated content aligned with the provided sources.
            ";
        }

        $input = [
            [
                'role'    => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $system_prompt,
                    ],
                ],
            ],
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $user_prompt,
                    ],
                ]
            ],
        ];

        $uploaded_files = $this->collect_uploaded_files( is_array( $files ) ? $files : [] );

        foreach ( $uploaded_files as $file ) {
            $input[1]['content'][] = [
                'type'     => 'input_file',
                'file_url' => esc_url_raw( $file['url'] ),
            ];
        }

        $response = AIProvider::get_reply( $input );

        foreach ( $uploaded_files as $file ) {
            wp_delete_file( $file['file'] );
        }

        if ( ! is_string( $response ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Failed to get response from AI provider.', 'antimanual' ),
            ]);
        }

        $content          = '';
        $meta_description = '';
        $image_prompt     = '';
        $excerpt          = '';
        $terms            = [];

        $cleaned_response = AIResponseCleaner::clean_json_response( $response );
        $parsed_response  = json_decode( $cleaned_response, true );

        if ( is_array( $parsed_response ) && ! empty( $parsed_response['content'] ) ) {
            $content = AIResponseCleaner::clean_gutenberg_content( (string) $parsed_response['content'] );
            if ( $generate_meta_description ) {
                $meta_description = sanitize_text_field( (string) ( $parsed_response['meta_description'] ?? '' ) );
            }
            if ( $generate_featured_image ) {
                $image_prompt = sanitize_text_field( (string) ( $parsed_response['image_prompt'] ?? '' ) );
            }
            if ( $generate_excerpt && ! empty( $parsed_response['excerpt'] ) ) {
                $excerpt = sanitize_text_field( (string) $parsed_response['excerpt'] );
            }
            if ( ! empty( $taxonomy_info ) && ! empty( $parsed_response['terms'] ) && is_array( $parsed_response['terms'] ) ) {
                $terms = $parsed_response['terms'];
            }
        } else {
            // Fallback: try to extract the "content" value via regex from a partially valid JSON response.
            // This prevents the raw JSON wrapper (e.g. `{ "content": "`) from leaking into post content.
            $raw_content = $this->extract_content_from_partial_json( $cleaned_response );
            $content     = AIResponseCleaner::clean_gutenberg_content( $raw_content ?: $response );
        }

        if ( empty( $content ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => __( 'Generated content was empty.', 'antimanual' ),
            ]);
        }

        $targeted_obj['content'] = $content ?? '';
        $targeted_obj['order']   = $order;

        // Resolve [INTERNAL_LINK: topic] placeholders with real links.
        if ( $suggest_internal_links ) {
            $targeted_obj['content'] = PostGenerator::resolve_internal_links( $targeted_obj['content'] );

            if ( $show_internal_links_pro_tip ) {
                $targeted_obj['content'] = PostGenerator::insert_internal_links_pro_tip_block(
                    $targeted_obj['content'],
                    $targeted_obj['title'],
                    $internal_links_pro_tip_label,
                    $internal_links_pro_tip_bg_color
                );
            }
        }

        $post_args = [
            'post_title'   => $targeted_obj['title'],
            'post_content' => $targeted_obj['content'],
            'post_parent'  => $existing_post ? intval( $existing_post->post_parent ) : $parent_post_id,
            'post_type'    => 'docs',
            'post_status'  => $status,
            'post_name'    => sanitize_title( (string) ( $targeted_obj['slug'] ?? $targeted_obj['title'] ) ),
            'menu_order'   => $targeted_obj['order'],
        ];

        if ( $generate_excerpt && ! empty( $excerpt ) ) {
            $post_args['post_excerpt'] = $excerpt;
        }

        if ( $existing_post ) {
            $post_args['ID'] = $existing_post->ID;
            $post_id         = wp_update_post( $post_args, true );
        } else {
            $post_id = wp_insert_post( $post_args, true );
        }

        if ( is_wp_error( $post_id ) ) {
            return rest_ensure_response([
                'success' => false,
                'message' => $existing_post
                    ? __( "Couldn't update the existing doc in the database.", 'antimanual' )
                    : __( "Couldn't insert the generated doc into the database.", 'antimanual' ),
            ]);
        }

        if ( $generate_meta_description && ! empty( $meta_description ) ) {
            update_post_meta( $post_id, '_atml_meta_description', $meta_description );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );
            update_post_meta( $post_id, 'rank_math_description', $meta_description );
        }

        if ( ! empty( $focus_keyword ) ) {
            update_post_meta( $post_id, '_atml_focus_keyword', $focus_keyword );
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword );
            update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_keyword );
        }

        if ( $generate_featured_image ) {
            $final_image_prompt = ! empty( $image_prompt )
                ? $image_prompt
                : sprintf( 'A clean, professional featured image for: %s', $targeted_obj['title'] );

            if ( $use_post_title_in_featured_image ) {
                $title_context = trim( wp_strip_all_tags( (string) $targeted_obj['title'] ) );
                if ( '' !== $title_context ) {
                    $short_title_text = PostGenerator::build_featured_image_title_text( $title_context );
                    $title_text_instruction = sprintf(
                        'The image MUST include short, meaningful title text derived from the post title. Use this short text in the image: "%s". Keep the typography clear, legible, and high-contrast.',
                        $short_title_text
                    );
                    $final_image_prompt = sprintf(
                        'Create a professional featured image for this article. %1$s Additional visual guidance: %2$s',
                        $title_text_instruction,
                        $final_image_prompt
                    );
                }
            }

            $image_url = AIProvider::generate_image( $final_image_prompt );
            if ( ! is_wp_error( $image_url ) && is_string( $image_url ) ) {
                $image_id = $this->sideload_image( $image_url, $post_id, $targeted_obj['title'] );
                if ( ! is_wp_error( $image_id ) ) {
                    set_post_thumbnail( $post_id, $image_id );
                    $targeted_obj['image_id'] = $image_id;
                }
            }
        }

        // Resolve [CONTENT_IMAGE: description] placeholders into real AI-generated images.
        if ( $include_content_images ) {
            $resolved_content = PostGenerator::resolve_content_images( $targeted_obj['content'], $post_id, $include_image_caption, $language );
            if ( $resolved_content !== $targeted_obj['content'] ) {
                $targeted_obj['content'] = $resolved_content;
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_content' => $resolved_content,
                ] );
            }
        }

        $targeted_obj['post_id'] = $post_id;
        $targeted_obj['url']     = get_permalink( $post_id );

        // Assign generated taxonomy terms.
        if ( ! empty( $terms ) && is_array( $terms ) ) {
            foreach ( $terms as $taxonomy => $term_names ) {
                $taxonomy = sanitize_key( $taxonomy );
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    continue;
                }

                if ( ! is_array( $term_names ) ) {
                    continue;
                }

                $tax_obj  = get_taxonomy( $taxonomy );
                $term_ids = [];

                foreach ( $term_names as $term_name ) {
                    $term_name = sanitize_text_field( $term_name );

                    if ( empty( $term_name ) ) {
                        continue;
                    }

                    $existing_term = get_term_by( 'name', $term_name, $taxonomy );

                    if ( $existing_term ) {
                        $term_ids[] = $existing_term->term_id;
                    } else {
                        $new_term = wp_insert_term( $term_name, $taxonomy );

                        if ( ! is_wp_error( $new_term ) ) {
                            $term_ids[] = $new_term['term_id'];
                        }
                    }
                }

                if ( ! empty( $term_ids ) ) {
                    wp_set_object_terms( $post_id, $term_ids, $taxonomy );
                }
            }
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $targeted_obj,
        ]);
    }

    /**
     * Read a boolean request param with support for FormData "undefined" values.
     *
     * @param \WP_REST_Request $request The REST request.
     * @param string           $key     Request key.
     * @param bool             $default Default value.
     * @return bool
     */
    private function get_bool_param( $request, string $key, bool $default = false ): bool {
        $value = $request->get_param( $key );

        if ( null === $value || '' === $value || 'undefined' === $value ) {
            return $default;
        }

        if ( is_bool( $value ) ) {
            return $value;
        }

        $parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        return null === $parsed ? $default : $parsed;
    }

    /**
     * Read a string request param with support for FormData "undefined" values.
     *
     * @param \WP_REST_Request $request The REST request.
     * @param string           $key     Request key.
     * @param string           $default Default value.
     * @return string
     */
    private function get_string_param( $request, string $key, string $default = '' ): string {
        $value = $request->get_param( $key );

        if ( null === $value || '' === $value || 'undefined' === $value ) {
            return $default;
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Read an array of strings from request param.
     *
     * Supports JSON-encoded strings sent via FormData.
     *
     * @param \WP_REST_Request $request The REST request.
     * @param string           $key     Request key.
     * @return string[]
     */
    private function get_string_array_param( $request, string $key ): array {
        $value = $request->get_param( $key );

        if ( null === $value || '' === $value || 'undefined' === $value ) {
            return [];
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                $value = $decoded;
            } else {
                $value = [ $value ];
            }
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $items = [];
        foreach ( $value as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }

            $cleaned = sanitize_text_field( (string) $item );
            if ( '' !== $cleaned ) {
                $items[] = $cleaned;
            }
        }

        return array_values( array_unique( $items ) );
    }

    /**
     * Normalize post status to a supported docs status.
     *
     * @param string $status Requested post status.
     * @return string
     */
    private function normalize_post_status( string $status ): string {
        $status          = sanitize_key( $status );
        $allowed_status  = [ 'draft', 'publish', 'private' ];

        if ( ! in_array( $status, $allowed_status, true ) ) {
            return 'draft';
        }

        return $status;
    }

    /**
     * Attempt to extract the "content" field value from a partially valid or malformed JSON string.
     *
     * Used as a fallback when full JSON parsing fails, to avoid the raw JSON wrapper
     * (e.g. `{ "content": "`) from leaking into saved post content.
     *
     * @param string $json The raw or partially-cleaned JSON string.
     * @return string The extracted content value, or an empty string if not found.
     */
    private function extract_content_from_partial_json( string $json ): string {
        // Match: "content": "...value..." handling escaped quotes inside the value.
        if ( preg_match( '/"content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $json, $matches ) ) {
            return stripslashes( $matches[1] );
        }

        return '';
    }

    /**
     * Collect and upload request files safely.
     *
     * @param array $files Raw files array from $_FILES['files'].
     * @return array<int, array{file:string,url:string,type?:string}>
     */
    private function collect_uploaded_files( array $files ): array {
        if ( ! atml_is_public_site() ) {
            return [];
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $names     = $files['name'] ?? [];
        $types     = $files['type'] ?? [];
        $tmp_names = $files['tmp_name'] ?? [];
        $errors    = $files['error'] ?? [];
        $sizes     = $files['size'] ?? [];

        if ( ! is_array( $tmp_names ) ) {
            $names     = [ $names ];
            $types     = [ $types ];
            $tmp_names = [ $tmp_names ];
            $errors    = [ $errors ];
            $sizes     = [ $sizes ];
        }

        $uploaded_files = [];

        foreach ( $tmp_names as $i => $tmp_name ) {
            $tmp_name = is_string( $tmp_name ) ? $tmp_name : '';
            $error    = intval( $errors[ $i ] ?? UPLOAD_ERR_NO_FILE );

            if ( empty( $tmp_name ) || UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $tmp_name ) ) {
                continue;
            }

            $file_array = [
                'name'     => sanitize_file_name( (string) ( $names[ $i ] ?? ( 'upload-' . $i . '.pdf' ) ) ),
                'type'     => sanitize_text_field( (string) ( $types[ $i ] ?? '' ) ),
                'tmp_name' => $tmp_name,
                'error'    => $error,
                'size'     => intval( $sizes[ $i ] ?? 0 ),
            ];

            $filetype     = wp_check_filetype( $file_array['name'] );
            $allowed_exts = [ 'pdf', 'txt', 'csv', 'doc', 'docx' ];

            if ( empty( $filetype['ext'] ) || ! in_array( strtolower( (string) $filetype['ext'] ), $allowed_exts, true ) ) {
                continue;
            }

            $moved_file = wp_handle_upload( $file_array, [ 'test_form' => false ] );

            if ( is_array( $moved_file ) && ! empty( $moved_file['file'] ) && ! empty( $moved_file['url'] ) ) {
                $uploaded_files[] = $moved_file;
            }
        }

        return $uploaded_files;
    }

    /**
     * Build context from knowledge-base chunks.
     *
     * @param string[] $source_ids Optional selected knowledge IDs.
     * @param string   $query      Optional semantic query.
     * @return string
     */
    private function build_knowledge_context( array $source_ids, string $query = '' ): string {
        return \Antimanual\KnowledgeContextBuilder::build_context( $source_ids, $query );
    }

    /**
     * Build an existing docs tree from a parent doc.
     *
     * @param int $post_id Root docs post ID.
     * @param int $depth   Current depth.
     * @return array<string,mixed>
     */
    private function build_existing_doc_tree( int $post_id, int $depth = 0 ): array {
        $post = get_post( $post_id );

        if ( ! $post || 'docs' !== $post->post_type ) {
            return [];
        }

        $child_key = 0 === $depth ? 'lessons' : ( 1 === $depth ? 'sub_lessons' : 'topics' );
        $children  = get_posts( [
            'post_type'      => 'docs',
            'post_parent'    => $post_id,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ] );

        $node = [
            'id'                 => 'existing-' . $post->ID,
            'title'              => $post->post_title ?: __( '(No Title)', 'antimanual' ),
            'slug'               => $post->post_name ?: sanitize_title( $post->post_title ?: 'doc-' . $post->ID ),
            'url'                => get_permalink( $post->ID ),
            'post_id'            => $post->ID,
            'existing'           => true,
            'suggestionAction'   => 'keep',
            'suggestionReason'   => '',
            'suggestionFocus'    => '',
            'improvementApplied' => true,
            $child_key           => [],
        ];

        foreach ( $children as $child ) {
            $node[ $child_key ][] = $this->build_existing_doc_tree( intval( $child->ID ), $depth + 1 );
        }

        return $node;
    }

    /**
     * Prepare an existing docs tree for AI analysis with trimmed content summaries.
     *
     * @param array<string,mixed> $node Existing outline node.
     * @param int                 $depth Current depth.
     * @return array<string,mixed>
     */
    private function prepare_outline_for_analysis( array $node, int $depth = 0 ): array {
        $post_id   = intval( $node['post_id'] ?? 0 );
        $post      = $post_id > 0 ? get_post( $post_id ) : null;
        $child_key = 0 === $depth ? 'lessons' : ( 1 === $depth ? 'sub_lessons' : 'topics' );
        $children  = isset( $node[ $child_key ] ) && is_array( $node[ $child_key ] ) ? $node[ $child_key ] : [];

        $prepared = [
            'post_id'         => $post_id,
            'title'           => sanitize_text_field( (string) ( $node['title'] ?? '' ) ),
            'slug'            => sanitize_title( (string) ( $node['slug'] ?? '' ) ),
            'summary'         => $post ? $this->summarize_post_content_for_analysis( (string) $post->post_content ) : '',
            'child_doc_count' => count( $children ),
            $child_key        => [],
        ];

        foreach ( $children as $child ) {
            if ( is_array( $child ) ) {
                $prepared[ $child_key ][] = $this->prepare_outline_for_analysis( $child, $depth + 1 );
            }
        }

        return $prepared;
    }

    /**
     * Build a short plain-text summary from Gutenberg content for AI analysis.
     *
     * @param string $content Post content.
     * @return string
     */
    private function summarize_post_content_for_analysis( string $content ): string {
        $content = preg_replace( '/<!--[\s\S]*?-->/', ' ', $content );
        $content = is_string( $content ) ? $content : '';
        $content = wp_strip_all_tags( $content, true );
        $content = preg_replace( '/\s+/', ' ', $content );
        $content = is_string( $content ) ? trim( $content ) : '';

        if ( '' === $content ) {
            return '';
        }

        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $content, 0, 2500 );
        }

        return substr( $content, 0, 2500 );
    }

    /**
     * Convert improvement preset slugs to readable labels.
     *
     * @param string[] $presets Selected preset slugs.
     * @return string[]
     */
    private function map_improvement_presets_to_labels( array $presets ): array {
        $map = [
            'missing_docs'      => __( 'Suggest missing docs', 'antimanual' ),
            'refresh_outdated'  => __( 'Refresh outdated information', 'antimanual' ),
            'improve_guidance'  => __( 'Improve the user guide', 'antimanual' ),
            'clarify_structure' => __( 'Clarify the structure and readability', 'antimanual' ),
        ];

        $labels = [];
        foreach ( $presets as $preset ) {
            $preset = sanitize_key( $preset );
            if ( isset( $map[ $preset ] ) ) {
                $labels[] = $map[ $preset ];
            }
        }

        return array_values( array_unique( $labels ) );
    }

    /**
     * Normalize an AI improvement outline while preserving existing docs.
     *
     * @param array<string,mixed> $outline          AI response.
     * @param array<string,mixed> $existing_outline Existing docs tree.
     * @return array<string,mixed>
     */
    private function normalize_improvement_outline( array $outline, array $existing_outline ): array {
        return $this->normalize_improvement_node( $outline, $existing_outline, 0 );
    }

    /**
     * Normalize a single improvement node recursively.
     *
     * @param array<string,mixed> $node          AI response node.
     * @param array<string,mixed> $existing_node Existing tree node.
     * @param int                 $depth         Current depth.
     * @return array<string,mixed>
     */
    private function normalize_improvement_node( array $node, array $existing_node, int $depth ): array {
        $child_key = 0 === $depth ? 'lessons' : ( 1 === $depth ? 'sub_lessons' : 'topics' );
        $children  = isset( $node[ $child_key ] ) && is_array( $node[ $child_key ] ) ? $node[ $child_key ] : [];

        $normalized = [
            'id'                 => sanitize_text_field( (string) ( $existing_node['id'] ?? wp_generate_uuid4() ) ),
            'title'              => sanitize_text_field( (string) ( $node['title'] ?? $existing_node['title'] ?? '' ) ),
            'slug'               => sanitize_title( (string) ( $node['slug'] ?? $existing_node['slug'] ?? '' ) ),
            'url'                => esc_url_raw( (string) ( $existing_node['url'] ?? '' ) ),
            'post_id'            => intval( $existing_node['post_id'] ?? 0 ),
            'existing'           => true,
            'suggestionAction'   => $this->normalize_improvement_action( (string) ( $node['action'] ?? 'keep' ), true, 0 === $depth ),
            'suggestionReason'   => sanitize_text_field( (string) ( $node['reason'] ?? '' ) ),
            'suggestionFocus'    => sanitize_text_field( (string) ( $node['focus'] ?? '' ) ),
            'improvementApplied' => 'keep' === $this->normalize_improvement_action( (string) ( $node['action'] ?? 'keep' ), true, 0 === $depth ),
            $child_key           => [],
        ];

        if ( '' === $normalized['title'] ) {
            $normalized['title'] = sanitize_text_field( (string) ( $existing_node['title'] ?? __( 'Documentation', 'antimanual' ) ) );
        }

        if ( '' === $normalized['slug'] ) {
            $normalized['slug'] = sanitize_title( $normalized['title'] );
        }

        $existing_children = isset( $existing_node[ $child_key ] ) && is_array( $existing_node[ $child_key ] ) ? $existing_node[ $child_key ] : [];
        $existing_map      = [];

        foreach ( $existing_children as $existing_child ) {
            if ( is_array( $existing_child ) ) {
                $existing_map[ intval( $existing_child['post_id'] ?? 0 ) ] = $existing_child;
            }
        }

        $used_existing = [];

        foreach ( $children as $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }

            $child_post_id = intval( $child['post_id'] ?? 0 );

            if ( $child_post_id > 0 && isset( $existing_map[ $child_post_id ] ) ) {
                $normalized[ $child_key ][] = $this->normalize_improvement_node( $child, $existing_map[ $child_post_id ], $depth + 1 );
                $used_existing[ $child_post_id ] = true;
                continue;
            }

            $normalized[ $child_key ][] = $this->normalize_new_improvement_node( $child, $depth + 1 );
        }

        foreach ( $existing_children as $existing_child ) {
            $existing_post_id = intval( $existing_child['post_id'] ?? 0 );
            if ( $existing_post_id > 0 && isset( $used_existing[ $existing_post_id ] ) ) {
                continue;
            }

            if ( is_array( $existing_child ) ) {
                $normalized[ $child_key ][] = $this->clone_existing_improvement_node( $existing_child, $depth + 1 );
            }
        }

        return $normalized;
    }

    /**
     * Normalize a newly suggested node.
     *
     * @param array<string,mixed> $node  AI response node.
     * @param int                 $depth Current depth.
     * @return array<string,mixed>
     */
    private function normalize_new_improvement_node( array $node, int $depth ): array {
        $child_key = 0 === $depth ? 'lessons' : ( 1 === $depth ? 'sub_lessons' : 'topics' );
        $children  = isset( $node[ $child_key ] ) && is_array( $node[ $child_key ] ) ? $node[ $child_key ] : [];
        $title     = sanitize_text_field( (string) ( $node['title'] ?? '' ) );
        $slug      = sanitize_title( (string) ( $node['slug'] ?? $title ) );

        $normalized = [
            'id'                 => wp_generate_uuid4(),
            'title'              => '' !== $title ? $title : __( 'New Doc Item', 'antimanual' ),
            'slug'               => '' !== $slug ? $slug : sanitize_title( $title ?: 'new-doc-item' ),
            'post_id'            => 0,
            'existing'           => false,
            'suggestionAction'   => 'add',
            'suggestionReason'   => sanitize_text_field( (string) ( $node['reason'] ?? '' ) ),
            'suggestionFocus'    => sanitize_text_field( (string) ( $node['focus'] ?? '' ) ),
            'improvementApplied' => false,
            $child_key           => [],
        ];

        foreach ( $children as $child ) {
            if ( is_array( $child ) ) {
                $normalized[ $child_key ][] = $this->normalize_new_improvement_node( $child, $depth + 1 );
            }
        }

        return $normalized;
    }

    /**
     * Clone an existing node as a default "keep" suggestion.
     *
     * @param array<string,mixed> $node  Existing outline node.
     * @param int                 $depth Current depth.
     * @return array<string,mixed>
     */
    private function clone_existing_improvement_node( array $node, int $depth ): array {
        $child_key = 0 === $depth ? 'lessons' : ( 1 === $depth ? 'sub_lessons' : 'topics' );
        $children  = isset( $node[ $child_key ] ) && is_array( $node[ $child_key ] ) ? $node[ $child_key ] : [];

        $cloned = [
            'id'                 => sanitize_text_field( (string) ( $node['id'] ?? wp_generate_uuid4() ) ),
            'title'              => sanitize_text_field( (string) ( $node['title'] ?? '' ) ),
            'slug'               => sanitize_title( (string) ( $node['slug'] ?? '' ) ),
            'url'                => esc_url_raw( (string) ( $node['url'] ?? '' ) ),
            'post_id'            => intval( $node['post_id'] ?? 0 ),
            'existing'           => true,
            'suggestionAction'   => 'keep',
            'suggestionReason'   => '',
            'suggestionFocus'    => '',
            'improvementApplied' => true,
            $child_key           => [],
        ];

        foreach ( $children as $child ) {
            if ( is_array( $child ) ) {
                $cloned[ $child_key ][] = $this->clone_existing_improvement_node( $child, $depth + 1 );
            }
        }

        return $cloned;
    }

    /**
     * Normalize allowed improvement actions.
     *
     * @param string $action      Requested action.
     * @param bool   $is_existing Whether the node already exists.
     * @param bool   $is_root     Whether the node is the root doc.
     * @return string
     */
    private function normalize_improvement_action( string $action, bool $is_existing, bool $is_root = false ): string {
        $action = sanitize_key( $action );

        if ( $is_root ) {
            return 'update' === $action ? 'update' : 'keep';
        }

        if ( $is_existing ) {
            return 'update' === $action ? 'update' : 'keep';
        }

        return 'add';
    }

    /**
     * Normalize AI-generated outline into a strict structure.
     *
     * @param array<string,mixed> $outline Raw outline response.
     * @return array<string,mixed>
     */
    private function normalize_outline( array $outline ): array {
        $title   = sanitize_text_field( (string) ( $outline['title'] ?? '' ) );
        $slug    = sanitize_title( (string) ( $outline['slug'] ?? $title ) );
        $lessons = isset( $outline['lessons'] ) && is_array( $outline['lessons'] ) ? $outline['lessons'] : [];

        if ( '' === $title ) {
            $title = __( 'Documentation', 'antimanual' );
        }

        $normalized = [
            'id'      => wp_generate_uuid4(),
            'title'   => $title,
            'slug'    => '' !== $slug ? $slug : sanitize_title( $title ),
            'lessons' => [],
        ];

        foreach ( $lessons as $lesson ) {
            if ( ! is_array( $lesson ) ) {
                continue;
            }

            if ( count( $normalized['lessons'] ) >= self::OUTLINE_MAX_LESSONS ) {
                break;
            }

            $lesson_title = sanitize_text_field( (string) ( $lesson['title'] ?? '' ) );
            if ( '' === $lesson_title ) {
                continue;
            }

            $lesson_slug = sanitize_title( (string) ( $lesson['slug'] ?? $lesson_title ) );
            $sub_lessons = isset( $lesson['sub_lessons'] ) && is_array( $lesson['sub_lessons'] ) ? $lesson['sub_lessons'] : [];

            $normalized_lesson = [
                'id'          => wp_generate_uuid4(),
                'title'       => $lesson_title,
                'slug'        => '' !== $lesson_slug ? $lesson_slug : sanitize_title( $lesson_title ),
                'sub_lessons' => [],
            ];

            foreach ( $sub_lessons as $sub_lesson ) {
                if ( ! is_array( $sub_lesson ) ) {
                    continue;
                }

                if ( count( $normalized_lesson['sub_lessons'] ) >= self::OUTLINE_MAX_SUB_LESSONS ) {
                    break;
                }

                $sub_title = sanitize_text_field( (string) ( $sub_lesson['title'] ?? '' ) );
                if ( '' === $sub_title ) {
                    continue;
                }

                $sub_slug = sanitize_title( (string) ( $sub_lesson['slug'] ?? $sub_title ) );
                $topics   = isset( $sub_lesson['topics'] ) && is_array( $sub_lesson['topics'] ) ? $sub_lesson['topics'] : [];

                $normalized_sub_lesson = [
                    'id'     => wp_generate_uuid4(),
                    'title'  => $sub_title,
                    'slug'   => '' !== $sub_slug ? $sub_slug : sanitize_title( $sub_title ),
                    'topics' => [],
                ];

                foreach ( $topics as $topic ) {
                    if ( ! is_array( $topic ) ) {
                        continue;
                    }

                    if ( count( $normalized_sub_lesson['topics'] ) >= self::OUTLINE_MAX_TOPICS ) {
                        break;
                    }

                    $topic_title = sanitize_text_field( (string) ( $topic['title'] ?? '' ) );
                    if ( '' === $topic_title ) {
                        continue;
                    }

                    $topic_slug = sanitize_title( (string) ( $topic['slug'] ?? $topic_title ) );

                    $normalized_sub_lesson['topics'][] = [
                        'id'    => wp_generate_uuid4(),
                        'title' => $topic_title,
                        'slug'  => '' !== $topic_slug ? $topic_slug : sanitize_title( $topic_title ),
                    ];
                }

                $normalized_lesson['sub_lessons'][] = $normalized_sub_lesson;
            }

            $normalized['lessons'][] = $normalized_lesson;
        }

        return $normalized;
    }

    /**
     * Download an image and sideload it to the media library.
     *
     * @param string $url     Image URL.
     * @param int    $post_id Post ID.
     * @param string $desc    Optional attachment description.
     * @return int|\WP_Error
     */
    private function sideload_image( string $url, int $post_id, string $desc = '' ) {
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $tmp = download_url( $url );

        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $file_array = [
            'name'     => sanitize_title( $desc ?: 'featured-image' ) . '.png',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id, $desc );
        if ( is_wp_error( $attachment_id ) ) {
            \wp_delete_file( $tmp );
        }

        return $attachment_id;
    }
}
