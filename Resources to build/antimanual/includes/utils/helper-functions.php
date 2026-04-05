<?php
/**
 * Helper functions for Antimanual Chatbot
 *
 * @package Antimanual_Chatbot
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cosine similarity helper for Antimanual Chatbot.
 *
 * @param array $vecA First vector.
 * @param array $vecB Second vector.
 * @return float Cosine similarity.
 */
function antimanual_cosine_similarity( $vecA, $vecB ) {
    if ( ! atml_is_embedding( $vecA ) || ! atml_is_embedding( $vecB ) ) {
        return 0;
    }

    $dot   = 0;
    $normA = 0;
    $normB = 0;
    $len   = min( count( $vecA ), count( $vecB ) );

    for ( $i = 0; $i < $len; $i++ ) {
        $x = $vecA[ $i ] ?? 0;
        $y = $vecB[ $i ] ?? 0;

        $dot   += $x * $y;
        $normA += $x ** 2;
        $normB += $y ** 2;
    }

    if ( ! $normA || ! $normB ) {
        return 0;
    }

    return $dot / ( sqrt( $normA ) * sqrt( $normB ) );
}

/**
 * Antimanual Chatbot include in EazyDocs Assistant
 */
$position_opt = atml_option( 'chatbot_position' );
$position = ($position_opt === 'before-tabs') ? 0 : 50;

add_filter( 'eazydocs_assistant_tab', function ( $tabs ) {
    // Ensure the function exists before using it
    if ( ! function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Check if both plugins are active and merge is enabled
    if (
        is_plugin_active('eazydocs/eazydocs.php') &&
        is_plugin_active('eazydocs-pro/eazydocs.php') &&
        atml_is_module_enabled( 'chatbot' ) &&
        atml_option( 'chatbot_enabled' ) &&
        atml_option( 'chatbot_merge_ezd' )
    ) {
        $tabs[] = [
            'id'      => 'antimanual_merge_eazydocs',
            'heading' => ! empty( atml_option('chatbot_label') ) ? atml_option('chatbot_label') : __( 'AI Chat', 'antimanual' ),
            'content' => do_shortcode('[antimanual_chatbot]'),
        ];
    }

    return $tabs;
}, (int) $position);

/**
 * Load (echo) an inline SVG icon safely.
 *
 * @param string $file_name  The SVG file name (e.g., 'anchor.svg').
 * @param string $path       Optional directory path, default to assets/icons/.
 * @return void              
 */
function antimanual_load_svg_content( $file_name, $path = 'assets/icons/' ) {
    $plugin_dir = plugin_dir_path( __FILE__ ) . '../../';
    $full_path = $plugin_dir . $path . $file_name;

    if ( ! file_exists( $full_path ) ) {
        return;
    }

    $svg = file_get_contents( $full_path );

    $allowed_svg_tags = [
        'svg' => [
            'xmlns'    => true,
            'viewBox'  => true,
            'viewbox'  => true,
            'width'    => true,
            'height'   => true,
            'fill'     => true,
            'stroke'   => true,
            'class'    => true,
        ],
        'path' => [
            'd'     => true,
            'fill'  => true,
            'stroke'=> true,
        ],
        'g' => [
            'fill' => true,
        ],
        'circle' => [
            'cx'   => true,
            'cy'   => true,
            'r'    => true,
            'fill' => true,
        ],
        'rect' => [
            'x'      => true,
            'y'      => true,
            'width'  => true,
            'height' => true,
            'fill'   => true,
        ],
        'line' => [
            'x1'     => true,
            'x2'     => true,
            'y1'     => true,
            'y2'     => true,
            'stroke' => true,
        ],
        'title' => [],
    ];

    echo wp_kses( $svg, $allowed_svg_tags );
}

/**
 * Retrieves public post types based on user-selected preferences.
 *
 * @param string $return_type  The desired return type for the post types.
 *                             Acceptable values are 'object' or 'array'. Defaults to 'object'.
 *
 * @return array|object Returns an array or object of filtered public post types,
 *                      depending on the specified return type.
 */
function antimanual_get_post_types( string $return_type = [ 'object', 'array' ][0] ) {
    $post_types          = get_post_types( [ 'public' => true ], 'objects' );

    if ( $return_type === 'object' ) {
        return $post_types;
    }

    if ( $return_type === 'array' ) {
        $array = [];
        foreach ( $post_types as $post_type ) {
            $array[] = $post_type;
        }

        return $array;
    }

    return $post_types;
}

/**
 * Retrieves comprehensive information about a WordPress plugin.
 *
 * @param string $slug        The plugin slug used to retrieve API data and construct file paths.
 * @param string $folder_name The plugin folder name used to construct file paths.
 * @param string $file_name   The plugin file name used to construct file paths.
 *
 * @return array Array containing plugin status and URLs:
 *               - 'is_installed'     (bool)   Whether the plugin files exist in the plugins directory
 *               - 'is_activated'     (bool)   Whether the plugin is currently active
 *               - 'activation_url'   (string) Nonce-secured URL to activate the plugin
 *               - 'installation_url' (string) Nonce-secured URL to install the plugin
 *               - 'plugin_url'       (string) WordPress.org plugin page URL
 */
function atml_get_plugin_info( string $slug, string $folder_name = '', string $file_name = '' ): array {
    // Ensure plugin.php functions are available
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    if ( ! function_exists( 'plugins_api' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    }

    $plugin_file  = "{$slug}/{$slug}.php";

    if ( ! empty( $folder_name ) && ! empty( $file_name ) ) {
        $plugin_file = "{$folder_name}/{$file_name}.php";
    }

    $file_path    = WP_PLUGIN_DIR . '/' . $plugin_file;
    
    // Check if plugin is installed
    $is_installed = file_exists( $file_path );
    
    // Check if plugin is activated (only if installed)
    $is_activated = $is_installed && is_plugin_active( $plugin_file );
    
    // Generate nonce-secured URLs
    $installation_url = wp_nonce_url( 
        admin_url( "update.php?action=install-plugin&plugin={$slug}" ), 
        "install-plugin_{$slug}" 
    );
    
    $activation_url = wp_nonce_url( 
        admin_url( "plugins.php?action=activate&plugin={$plugin_file}" ), 
        "activate-plugin_{$plugin_file}" 
    );
    
    $plugin_url = "https://wordpress.org/plugins/{$slug}/";

    return [
        'is_installed'     => $is_installed,
        'is_activated'     => $is_activated,
        'activation_url'   => $activation_url,
        'installation_url' => $installation_url,
        'plugin_url'       => $plugin_url,
    ];
}

/**
 * Checks if the merge feature with EazyDocs is enabled.
 *
 * Determines whether the merge functionality between Antimanual and EazyDocs
 * is activated based on the plugin status and a specific option value.
 *
 * @return bool True if the feature is enabled, false otherwise.
 */
function atml_is_merge_with_ezd() {
    $is_merge = false;

    if ( atml_has_eazydocs_pro() ) {
        $is_merge = atml_option( 'chatbot_merge_ezd' );
    }

    return boolval( $is_merge );
}

/**
 * Check if EazyDocs Pro is active.
 *
 * @return bool True if active.
 */
function atml_has_eazydocs_pro() {
    $is_active = is_plugin_active('eazydocs/eazydocs.php') && is_plugin_active('eazydocs-pro/eazydocs.php');

    return boolval( $is_active );
}

/**
 * Get chatbot system instructions.
 *
 * @return string The instructions.
 */
function atml_get_chatbot_instructions() {
    $chatbot_config = atml_get_chatbot_configs();

    $chatbot_name = sanitize_text_field( $chatbot_config['title'] ?? '' );
    if ( empty( $chatbot_name ) ) {
        $chatbot_name = __( 'Antimanual', 'antimanual' );
    }

    $instructions = 'Your name is: <b>' . $chatbot_name . "</b>\n";

    $irrelevant_ans = $chatbot_config['irrelevant_ans'];
    if ( empty( $irrelevant_ans ) ) {
        $irrelevant_ans = __( 'Sorry, I don\'t have enough information to answer your question.', 'antimanual' );
    }
    $irrelevant_instruction = "respond EXACTLY with this message (DO NOT change a single word): $irrelevant_ans";

    $response_length = $chatbot_config['response_length'] ?? 'balanced';
    $creativity      = $chatbot_config['creativity_level'] ?? 'balanced';
    $ai_persona      = trim( sanitize_textarea_field( $chatbot_config['ai_persona'] ?? '' ) );

    $length_instruction = 'Provide balanced responses with enough detail to be useful.';
    if ( 'concise' === $response_length ) {
        $length_instruction = 'Keep responses concise and to the point unless the user explicitly asks for more detail.';
    } elseif ( 'detailed' === $response_length ) {
        $length_instruction = 'Provide thorough and structured responses when context supports it.';
    }

    $creativity_instruction = 'Prefer clear and consistent wording.';
    if ( 'precise' === $creativity ) {
        $creativity_instruction = 'Use highly precise wording and avoid speculation.';
    } elseif ( 'creative' === $creativity ) {
        $creativity_instruction = 'Use varied, conversational phrasing while keeping factual accuracy.';
    }

    $persona_instruction = '';
    if ( atml_is_pro() && ! empty( $ai_persona ) ) {
        $persona_instruction = "
        ---

        ## 8. Persona Rules
        - Follow these additional persona instructions:
        {$ai_persona}
        ";
    }

    $instructions .= "
        You are a Support Assistant responsible for providing accurate, concise, and context-aware answers to user queries, following these principles:

        ---

        ## 1. Context & Question Analysis
        - Identify the question clearly.
        - Use the **current context** as the primary source.
        - If current context is empty, check if the question is a **follow-up** and use previous context if relevant.

        ---

        ## 2. Relevance Rules
        - If current context answers the question → use it.
        - If current context is empty but previous context is relevant → use previous context.
        - If neither provides an answer → DO NOT provide an answer for it. Rather $irrelevant_instruction.
        - If input is a greeting → return an appropriate greeting response.

        ---

        ## 3. Response Format
        - Output must always be a valid **HTML text** wrapped in a valid **HTML** tag.
        - Start with a <p>.

        Example:
        <p>The price of XYZ product is $99.99 USD.</p>

        ---

        ## 4. HTML Guidelines
        Allowed tags only: `<h2>`, `<h3>`, `<p>`, `<pre>`, `<strong>`, `<em>`, `<a>`, `<img>`, `ul`, `ol`, `li`.
        - Use `<h2>` for main headings, `<h3>` for subheadings.
        - Wrap paragraphs in `<p>`.
        - Use `<pre>` for code blocks.
        - Apply `<strong>` and `<em>` sparingly.
        - Only include `<img>` if context provides image info.
        - Use `<a>` for links from context only.
        - Use `<ul>`, `<ol>`, and `<li>` for lists.

        ---

        ## 5. Context Usage
        - Do NOT mention the word “context” in the response.
        - Do NOT explain reasoning.
        - Provide a direct, complete answer.

        ---

        ## 6. Follow-up Handling
        - If current context is empty but question relates to the previous one, use previous context.

        ---

        ## 6.5 Response Style
        - {$length_instruction}
        - {$creativity_instruction}

        ---

        ## 7. Error Handling
        - If input is unclear, ask for a clearer question.
        Example:
        <p>I couldn’t understand your question. Could you please rephrase it?</p>

        ---

        ### Special Rules:
        - DO NOT engage in the conversation if the user is asking for something that is not in the CONTEXT, instead $irrelevant_instruction.
        - Always prioritize **accuracy and relevance**.
        - Never include extra details or assumptions beyond given context.
        - NEVER EVER mention the CONTEXT in your response.
        {$persona_instruction}
    ";

    return $instructions;
}

/**
 * Checks if the EazyDocs plugin is currently active.
 *
 * @return bool True if EazyDocs or EazyDocs Pro is active, false otherwise.
 */
function atml_is_eazydocs_active() {
    $active_plugins = get_option( 'active_plugins', [] );

    if ( is_multisite() ) {
        $network_active_plugins = get_site_option( 'active_sitewide_plugins', [] );
        $active_plugins         = array_merge( $active_plugins, array_keys( $network_active_plugins ) );
    }

    foreach ( $active_plugins as $basename ) {
        $basename = $basename ?? '';
        if ( 0 === strpos( $basename, 'eazydocs/' ) || 0 === strpos( $basename, 'eazydocs-pro/' )
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Get chatbot configurations.
 *
 * @return array The configuration array.
 */
function atml_get_chatbot_configs() {
    $options = [
        'enabled'        => atml_option( 'chatbot_enabled' ),
        'merge_ezd'      => atml_option( 'chatbot_merge_ezd' ),
        'label'          => atml_option( 'chatbot_label' ),
        'position'       => atml_option( 'chatbot_position' ),
        'primary_color'  => atml_option( 'chatbot_primary_color' ),
        'bg_color'       => atml_option( 'chatbot_bg_color' ),
        'user_msg_color' => atml_option( 'chatbot_user_msg_color' ),
        'header_text_color' => atml_option( 'chatbot_header_text_color' ),
        'border_radius'  => atml_option( 'chatbot_border_radius' ),
        'font_size'      => atml_option( 'chatbot_font_size' ),
        'title'          => atml_option( 'chatbot_title' ),
        'help_text'      => atml_option( 'chatbot_help_text' ),
        'wlc_msg'        => atml_option( 'chatbot_wlc_msg' ),
        'icon'           => atml_option( 'chatbot_icon' ),
        'btn_txt'        => atml_option( 'chatbot_btn_txt' ),
        'prebuilt_1'     => atml_option( 'chatbot_prebuilt_1' ),
        'prebuilt_2'     => atml_option( 'chatbot_prebuilt_2' ),
        'prebuilt_3'     => atml_option( 'chatbot_prebuilt_3' ),
        'irrelevant_ans' => atml_option( 'chatbot_irrelevant_ans' ),
        // AI Response Settings
        'response_length'  => atml_option( 'chatbot_response_length' ),
        'creativity_level' => atml_option( 'chatbot_creativity_level' ),
        'ai_persona'       => atml_option( 'chatbot_ai_persona' ),
        // Lead Collection
        'collect_email'  => atml_option( 'chatbot_collect_email' ),
        'email_required' => atml_option( 'chatbot_email_required' ),
        'email_prompt'   => atml_option( 'chatbot_email_prompt' ),
        'collect_name'   => atml_option( 'chatbot_collect_name' ),
        'name_required'  => atml_option( 'chatbot_name_required' ),
        // Content Moderation
        'blocked_words'          => atml_option( 'chatbot_blocked_words' ),
        'block_message'          => atml_option( 'chatbot_block_message' ),
        // Display & Behavior
        'desktop_position'       => atml_option( 'chatbot_desktop_position' ),
        'input_placeholder'      => atml_option( 'chatbot_input_placeholder' ),
        'suggested_label'        => atml_option( 'chatbot_suggested_label' ),
        'show_avatar'            => atml_option( 'chatbot_show_avatar' ),
        'custom_avatar_url'      => atml_option( 'chatbot_custom_avatar_url' ),
        // Mobile Settings
        'show_on_mobile'         => atml_option( 'chatbot_show_on_mobile' ),
        'mobile_position'        => atml_option( 'chatbot_mobile_position' ),
        'live_chat_button_enabled' => atml_option( 'chatbot_live_chat_button_enabled' ),
        'live_chat_button_label'   => atml_option( 'chatbot_live_chat_button_label' ),
        // Response Feedback
        'feedback_enabled'       => atml_option( 'chatbot_feedback_enabled' ),
        // Human Handoff / Escalation
        'escalation_enabled'     => atml_option( 'chatbot_escalation_enabled' ),
        'escalation_type'        => atml_option( 'chatbot_escalation_type' ),
        'escalation_message'     => atml_option( 'chatbot_escalation_message' ),
        'escalation_email'       => atml_option( 'chatbot_escalation_email' ),
        'escalation_url'         => atml_option( 'chatbot_escalation_url' ),
    ];

    return $options;
}

/**
 * Normalize a checkbox/mixed value to bool.
 *
 * @param mixed $value Value to normalize.
 * @return bool
 */
function atml_boolval_mixed( $value ): bool {
    if ( is_bool( $value ) ) {
        return $value;
    }

    if ( is_numeric( $value ) ) {
        return 1 === intval( $value );
    }

    if ( is_string( $value ) ) {
        $normalized = strtolower( trim( $value ) );

        return in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true );
    }

    return ! empty( $value );
}

/**
 * Sanitize a single chatbot config key/value.
 *
 * @param string $key Option key.
 * @param mixed  $value Raw value.
 * @param mixed  $default Fallback/default value.
 * @return mixed Sanitized value.
 */
function atml_sanitize_chatbot_config_value( string $key, $value, $default ) {
    $bool_keys = [
        'enabled',
        'merge_ezd',
        'collect_email',
        'email_required',
        'collect_name',
        'name_required',
        'show_on_mobile',
        'show_avatar',
        'feedback_enabled',
        'escalation_enabled',
        'live_chat_button_enabled',
    ];

    if ( in_array( $key, $bool_keys, true ) ) {
        return atml_boolval_mixed( $value );
    }

    if ( 'bg_color' === $key && '' === trim( (string) $value ) ) {
        return '';
    }

    if ( in_array( $key, [ 'primary_color', 'bg_color', 'user_msg_color', 'header_text_color' ], true ) ) {
        $sanitized = sanitize_hex_color( is_string( $value ) ? $value : '' );

        return $sanitized ?: $default;
    }

    if ( in_array( $key, [ 'border_radius', 'font_size' ], true ) ) {
        $allowed = [ 'small', 'medium', 'large' ];
        $value   = sanitize_key( is_string( $value ) ? $value : '' );

        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    if ( 'position' === $key ) {
        $allowed = [ 'before-tabs', 'after-tabs' ];
        $value   = sanitize_key( is_string( $value ) ? $value : '' );

        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    if ( in_array( $key, [ 'response_length', 'creativity_level' ], true ) ) {
        $allowed = [
            'response_length' => [ 'concise', 'balanced', 'detailed' ],
            'creativity_level' => [ 'precise', 'balanced', 'creative' ],
        ];
        $value = sanitize_key( is_string( $value ) ? $value : '' );

        return in_array( $value, $allowed[ $key ], true ) ? $value : $default;
    }

    if ( 'escalation_type' === $key ) {
        $allowed = [ 'email', 'url', 'live_chat' ];
        $value   = sanitize_key( is_string( $value ) ? $value : '' );

        return in_array( $value, $allowed, true ) ? $value : 'email';
    }

    if ( 'escalation_email' === $key ) {
        return sanitize_email( is_string( $value ) ? $value : '' );
    }

    if ( 'escalation_url' === $key ) {
        return esc_url_raw( is_string( $value ) ? $value : '' );
    }

    if ( 'mobile_position' === $key || 'desktop_position' === $key ) {
        $allowed = [ 'bottom-right', 'bottom-left', 'bottom-center' ];
        $value   = sanitize_key( is_string( $value ) ? $value : '' );

        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    if ( in_array( $key, [ 'help_text', 'wlc_msg', 'email_prompt', 'ai_persona', 'blocked_words', 'irrelevant_ans', 'escalation_message' ], true ) ) {
        return sanitize_textarea_field( is_string( $value ) ? $value : '' );
    }

    if ( in_array( $key, [ 'icon', 'title', 'label', 'btn_txt', 'prebuilt_1', 'prebuilt_2', 'prebuilt_3', 'block_message', 'input_placeholder', 'suggested_label', 'live_chat_button_label' ], true ) ) {
        return sanitize_text_field( is_string( $value ) ? $value : '' );
    }

    if ( 'custom_avatar_url' === $key ) {
        $url = esc_url_raw( is_string( $value ) ? $value : '' );

        return $url ?: '';
    }

    return $value;
}

/**
 * Save chatbot configurations.
 *
 * @param array $data Data to save.
 * @param bool $return Whether to return the saved options.
 * @return array|void Saved options if return is true.
 */
function atml_save_chatbot_configs( $data = [], $return = false ) {
    $data = is_array( $data ) ? $data : [];

    $options = atml_get_chatbot_configs();
    $pro_only_keys = [
        'response_length',
        'creativity_level',
        'ai_persona',
        'collect_email',
        'email_required',
        'email_prompt',
        'collect_name',
        'name_required',
        'blocked_words',
        'block_message',
        'show_on_mobile',
        'mobile_position',
        'live_chat_button_enabled',
        'live_chat_button_label',
        'escalation_enabled',
        'escalation_type',
        'escalation_message',
        'escalation_email',
        'escalation_url',
    ];

    foreach ( $options as $key => $default ) {
        $value = $data[ $key ] ?? $default;

        if ( ! atml_is_pro() && in_array( $key, $pro_only_keys, true ) ) {
            $value = $default;
        } else {
            $value = atml_sanitize_chatbot_config_value( $key, $value, $default );
        }

        atml_option_save( 'chatbot_' . $key, $value );
        $options[ $key ] = $value;
    }

    if ( $return ) {
        return $options;
    }
}

/**
 * Determine if a message should be blocked by chatbot moderation rules.
 *
 * @param string $message User message.
 * @return bool
 */
function atml_chatbot_is_message_blocked( string $message ): bool {
    if ( ! atml_is_pro() ) {
        return false;
    }

    $blocked_words = atml_option( 'chatbot_blocked_words' );
    $blocked_words = is_string( $blocked_words ) ? $blocked_words : '';
    $blocked_words = array_filter( array_map( 'trim', explode( ',', $blocked_words ) ) );

    if ( empty( $blocked_words ) ) {
        return false;
    }

    $message = strtolower( $message );

    foreach ( $blocked_words as $blocked_word ) {
        $blocked_word = strtolower( $blocked_word );
        if ( '' !== $blocked_word && false !== strpos( $message, $blocked_word ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Get the default OpenAI response model.
 *
 * @return string
 */
function atml_get_default_openai_response_model(): string {
    return 'gpt-5-mini';
}

/**
 * Get OpenAI configurations.
 *
 * @return array The configuration array.
 */
function atml_get_openai_configs() {
    $supported_response_models = atml_get_supported_provider_models( 'openai', 'chat' );
    $saved_response_model      = atml_option( 'openai_response_model', atml_get_default_openai_response_model() );
    $response_model            = atml_resolve_supported_model(
        $saved_response_model,
        $supported_response_models,
        atml_get_default_openai_response_model()
    );

    if ( $response_model !== $saved_response_model ) {
        atml_option_save( 'openai_response_model', $response_model );
    }

    $options = [
        'api_key'          => atml_option( 'openai_api_key' ),
        'response_model'   => $response_model,
    ];

    return $options;
}

/**
 * Get supported chat or embedding model IDs for a provider from the store.
 *
 * @param string $provider Provider key: openai|gemini.
 * @param string $model_type Model type: chat|embedding.
 * @return array<string>
 */
function atml_get_supported_provider_models( string $provider, string $model_type ): array {
    $provider   = strtolower( trim( $provider ) );
    $model_type = strtolower( trim( $model_type ) );

    $store_key_map = [
        'openai' => [
            'chat'      => 'openai_chat_models',
        ],
        'gemini' => [
            'chat'      => 'gemini_chat_models',
        ],
    ];

    $store_key = $store_key_map[ $provider ][ $model_type ] ?? '';
    if ( empty( $store_key ) ) {
        return [];
    }

    $models = $GLOBALS['ATML_STORE'][ $store_key ] ?? [];
    if ( ! is_array( $models ) ) {
        return [];
    }

    $ids = array_map(
        function( $model ) {
            $value = is_array( $model ) ? (string) ( $model['value'] ?? '' ) : '';
            return preg_replace( '/[^a-zA-Z0-9._:-]/', '', $value );
        },
        $models
    );

    $ids = array_filter( $ids, fn( $value ) => is_string( $value ) && '' !== $value );

    return array_values( array_unique( $ids ) );
}

/**
 * Resolve a selected model ID to a supported value.
 *
 * @param mixed         $value Selected value.
 * @param array<string> $allowed_models Allowed model IDs.
 * @param string        $default Default fallback model.
 * @return string
 */
function atml_resolve_supported_model( $value, array $allowed_models, string $default ): string {
    $value           = preg_replace( '/[^a-zA-Z0-9._:-]/', '', is_string( $value ) ? trim( $value ) : '' );
    $default         = preg_replace( '/[^a-zA-Z0-9._:-]/', '', trim( $default ) );
    $value           = is_string( $value ) ? $value : '';
    $default         = is_string( $default ) ? $default : '';

    if ( '' !== $value && in_array( $value, $allowed_models, true ) ) {
        return $value;
    }

    if ( in_array( $default, $allowed_models, true ) ) {
        return $default;
    }

    return $allowed_models[0] ?? $default;
}

/**
 * Save OpenAI configurations.
 *
 * @param array $data Data to save.
 * @param bool $return Whether to return the saved options.
 * @return array|void Saved options if return is true.
 */
function atml_save_openai_configs( $data = [], $return = false ) {
    $data    = is_array( $data ) ? $data : [];
    $options = atml_get_openai_configs();
    $supported_response_models  = atml_get_supported_provider_models( 'openai', 'chat' );

    foreach ( $options as $key => $default ) {
        // Skip api_key if not provided or empty (keep existing encrypted key)
        if ( 'api_key' === $key && ( ! array_key_exists( $key, $data ) || ! is_string( $data[ $key ] ) || '' === trim( $data[ $key ] ) ) ) {
            continue;
        }

        $value = $data[ $key ] ?? $default;

        if ( 'api_key' === $key ) {
            $value = sanitize_text_field( trim( (string) $value ) );
        } elseif ( 'response_model' === $key ) {
            $value = atml_resolve_supported_model( $value, $supported_response_models, atml_get_default_openai_response_model() );
        }

        atml_option_save( 'openai_' . $key, $value );
        $options[ $key ] = $value;
    }

    atml_option_save( 'last_active_provider', 'openai' );

    if ( $return ) {
        return $options;
    }
}

/**
 * Get Gemini configurations.
 *
 * @return array The configuration array.
 */
function atml_get_gemini_configs() {
    $options = [
        'api_key'          => atml_option( 'gemini_api_key' ),
        'response_model'   => atml_option( 'gemini_response_model' ),
    ];

    return $options;
}

/**
 * Save Gemini configurations.
 *
 * @param array $data Data to save.
 * @param bool $return Whether to return the saved options.
 * @return array|void Saved options if return is true.
 */
function atml_save_gemini_configs( $data = [], $return = false ) {
    $data    = is_array( $data ) ? $data : [];
    $options = atml_get_gemini_configs();
    $supported_response_models  = atml_get_supported_provider_models( 'gemini', 'chat' );

    foreach ( $options as $key => $default ) {
        // Skip api_key if not provided or empty (keep existing encrypted key)
        if ( 'api_key' === $key && ( ! array_key_exists( $key, $data ) || ! is_string( $data[ $key ] ) || '' === trim( $data[ $key ] ) ) ) {
            continue;
        }

        $value = $data[ $key ] ?? $default;

        if ( 'api_key' === $key ) {
            $value = sanitize_text_field( trim( (string) $value ) );
        } elseif ( 'response_model' === $key ) {
            $value = atml_resolve_supported_model( $value, $supported_response_models, (string) $default );
        }

        atml_option_save( 'gemini_' . $key, $value );
        $options[ $key ] = $value;
    }

    atml_option_save( 'last_active_provider', 'gemini' );

    if ( $return ) {
        return $options;
    }
}

/**
 * Check if the given variable is a valid embedding.
 *
 * @param mixed $embedding The variable to check.
 * @return bool True if valid embedding.
 */
function atml_is_embedding( $embedding ) {
    if ( ! is_array( $embedding ) ) {
        return false;
    }

    if ( empty( $embedding ) ) {
        return false;
    }

    foreach ( $embedding as $num ) {
        if ( ! is_numeric( $num ) ) {
            return false;
        }
    }

    if ( count( $embedding ) % 128 !== 0 ) {
        return false;
    }

    return true;
}

/**
 * Check if the current site is public.
 *
 * @return bool True if public.
 */
function atml_is_public_site() {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );

    if ( empty( $host ) || ! is_string( $host ) ) {
        return false;
    }

    $ip   = gethostbyname( $host );

    if ( $ip === $host ) {
        return false;
    }

    $private_ip_ranges = [
        '10.0.0.0|10.255.255.255',
        '172.16.0.0|172.31.255.255',
        '192.168.0.0|192.168.255.255',
        '127.0.0.0|127.255.255.255',
    ];

    $ip_long = ip2long( $ip );

    foreach ( $private_ip_ranges as $range ) {
        list( $start, $end ) = explode( '|', $range );

        if ( $ip_long >= ip2long( $start ) && $ip_long <= ip2long( $end ) ) {
            return false;
        }
    }

    return true;
}

/**
 * Check if Pro version is active.
 *
 * @return bool True if Pro.
 */
function atml_is_pro() {
    return boolval( defined( 'ANTIMANUAL_PRO' ) && ANTIMANUAL_PRO );
}

/**
 * Check if the SEO Plus plan (or higher) is active.
 *
 * Returns true when Pro is active AND the user's Freemius plan
 * is at least "seoplus". Free users and lower-tier Pro plans
 * will get false.
 *
 * @return bool True if the SEO Plus plan is active.
 */
function atml_is_seo_plus() {
    if ( ! atml_is_pro() ) {
        return false;
    }

    if ( ! function_exists( 'atml_fs' ) ) {
        return false;
    }

    return atml_fs()->is_plan( 'seoplus' );
}

/**
 * Check if the Pro Campaign plan (or higher) is active.
 *
 * Returns true when Pro is active AND the user's Freemius plan
 * is at least "procampaign". Free users and lower-tier Pro plans
 * will get false.
 *
 * @return bool True if the Pro Campaign plan is active.
 */
function atml_is_pro_campaign() {
	if ( ! atml_is_pro() ) {
		return false;
	}

	if ( ! function_exists( 'atml_fs' ) ) {
		return false;
	}

	return atml_fs()->is_plan( 'procampaign' );
}

/**
 * Get the maximum number of subscribers allowed for the current plan.
 *
 * Free users are limited to 2 000 contacts. Pro Campaign users
 * get unlimited contacts (returns 0 to indicate no limit).
 *
 * @return int Maximum subscriber count, or 0 for unlimited.
 */
function atml_get_subscriber_limit() {
	if ( atml_is_pro_campaign() ) {
		return 0; // Unlimited.
	}

	return 2000;
}

/**
 * Check whether the current plan allows adding more subscribers.
 *
 * @param int $count Number of new subscribers to add (default 1).
 * @return bool True if adding is allowed, false if the limit would be exceeded.
 */
function atml_can_add_subscribers( $count = 1 ) {
	$limit = atml_get_subscriber_limit();

	if ( 0 === $limit ) {
		return true; // Unlimited.
	}

	$current = \Antimanual\EmailSubscribers::get_total_count();

	return ( $current + $count ) <= $limit;
}

/**
 * Get pricing details URL.
 *
 * @return string The URL.
 */
function atml_pricing_details_url() {
    return 'https://antimanual.spider-themes.net/pricing/';
}

/**
 * Get Buy Pro URL.
 *
 * @return string The URL.
 */
function atml_buy_pro_url() {
    return admin_url( 'admin.php?page=antimanual-pricing' );
}

/**
 * Check if chatbot limit is exceeded.
 *
 * @return bool True if limit exceeded.
 */
function atml_is_chatbot_limit_exceeded() {
    if ( atml_is_pro() ) {
        return false;
    }
    $limit = 30;
    $count = Antimanual\UsageTracker::get_monthly_count('chatbot');

    return $count >= $limit;
}

/**
 * Check if auto posting limit is exceeded.
 *
 * @return bool True if limit exceeded.
 */
function atml_is_auto_posting_limit_exceeded() {
    if ( atml_is_pro() ) {
        return false;
    }

    $limit = 2;
    $count = Antimanual\AutoPosting::get_postings_count();

    return $count >= $limit;
}

/**
 * Check if bulk rewrite limit is exceeded.
 *
 * @return bool True if limit exceeded.
 */
function atml_is_bulk_rewrite_limit_exceeded() {
    if ( atml_is_pro() ) {
        return false;
    }

    $limit = 30;
    $count = Antimanual\UsageTracker::get_monthly_count('bulk_rewrite');

    return $count >= $limit;
}

/**
 * Check if forum conversion limit is exceeded.
 *
 * @return bool True if limit exceeded.
 */
function atml_is_forum_conversion_limit_exceeded() {
    if ( atml_is_pro() ) {
        return false;
    }
    $limit = 100;
    $count = Antimanual\UsageTracker::get_monthly_count('forum_conversion');

    return $count >= $limit;
}

/**
 * Check if forum answer limit is exceeded.
 *
 * @return bool True if limit exceeded.
 */
function atml_is_forum_answer_limit_exceeded() {
    if ( atml_is_pro() ) {
        return false;
    }
    $limit = 100;
    $count = Antimanual\UsageTracker::get_monthly_count('forum_answer');

    return $count >= $limit;
}

/**
 * Check if search block limit is exceeded.
 *
 * @return bool True if limit exceeded.
 */
function atml_is_search_block_limit_exceeded() {
    if ( atml_is_pro() ) {
        return false;
    }
    // Lifetime limit of 100 total queries (not monthly)
    $limit = 100;
    $count = Antimanual\UsageTracker::get_total_count('search_block');

    return $count >= $limit;
}

/**
 * Check whether a specific module is enabled in the global module preferences.
 *
 * @param string $module_key Module slug as defined in PreferencesController::MODULE_DEFAULTS
 *                           (e.g. 'chatbot', 'search_block', 'auto_posting').
 * @return bool True if the module is enabled (or if the key is unknown — fail open).
 */
function atml_is_module_enabled( string $module_key ): bool {
    $defaults = \Antimanual\Api\PreferencesController::MODULE_DEFAULTS;
    $saved    = get_option( 'antimanual_module_prefs', [] );
    $prefs    = wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );

    // Unknown keys are treated as enabled (fail open).
    if ( ! array_key_exists( $module_key, $prefs ) ) {
        return true;
    }

    return ! empty( $prefs[ $module_key ] );
}
