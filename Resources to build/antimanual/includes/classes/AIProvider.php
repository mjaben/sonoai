<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Provider Factory - Single source of truth for AI provider selection.
 * 
 * This class provides a centralized way to get the appropriate AI provider
 * (OpenAI or Gemini) based on user configuration. All AI-related operations
 * should go through this class to ensure consistency.
 * 
 * @package Antimanual
 * @since 1.5.0
 */
class AIProvider {
    /**
     * Singleton instance.
     *
     * @var AIProvider|null
     */
    private static $instance = null;

    /**
     * Current provider name ('openai' or 'gemini').
     *
     * @var string
     */
    private $provider_name = 'openai';

    /**
     * Current provider instance.
     *
     * @var OpenAI|Gemini
     */
    private $provider = null;

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {
        $this->provider_name = atml_option( 'last_active_provider' ) ?: 'openai';
        $this->provider = $this->create_provider();
    }

    /**
     * Get the singleton instance.
     *
     * @return AIProvider
     */
    public static function instance(): AIProvider {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the current AI provider instance.
     *
     * @return OpenAI|Gemini The AI provider instance.
     */
    public static function get() {
        return self::instance()->provider;
    }

    /**
     * Get the current provider name.
     *
     * @return string 'openai' or 'gemini'
     */
    public static function get_name(): string {
        return self::instance()->provider_name;
    }

    /**
     * Check if the current provider is Gemini.
     *
     * @return bool
     */
    public static function is_gemini(): bool {
        return 'gemini' === self::instance()->provider_name;
    }

    /**
     * Check if the current provider is OpenAI.
     *
     * @return bool
     */
    public static function is_openai(): bool {
        return 'openai' === self::instance()->provider_name;
    }

    /**
     * Check if the current provider has a valid API key configured.
     *
     * @return bool
     */
    public static function has_api_key(): bool {
        if ( self::is_gemini() ) {
            return ! empty( atml_option( 'gemini_api_key' ) );
        }
        return ! empty( atml_option( 'openai_api_key' ) );
    }

    /**
     * Get the API key for the current provider.
     *
     * @return string
     */
    public static function get_api_key(): string {
        if ( self::is_gemini() ) {
            return atml_option( 'gemini_api_key' ) ?: '';
        }
        return atml_option( 'openai_api_key' ) ?: '';
    }

    /**
     * Create the provider instance based on user preference.
     *
     * @return OpenAI|Gemini
     */
    private function create_provider() {
        return 'gemini' === $this->provider_name
            ? new Gemini()
            : new OpenAI();
    }

    /**
     * Force refresh of the provider instance.
     * 
     * Useful when settings change and you need to reload the provider.
     *
     * @return AIProvider
     */
    public static function refresh(): AIProvider {
        self::$instance = null;
        return self::instance();
    }

    /**
     * Get a reply from the AI provider.
     * 
     * This is a convenience method that wraps the provider's get_reply method
     * and normalizes successful responses to plain text for non-conversational
     * features. Direct provider access should be used when conversation IDs are needed.
     *
     * @param array  $input             Array of message objects or structured input.
     * @param string $conversation_id   Optional conversation ID.
     * @param string $instructions      Optional system instructions.
     * @param int    $max_output_tokens Optional maximum output tokens (for controlling response length).
     * @return string|array The reply string, or an error array.
     */
    public static function get_reply( $input, string $conversation_id = '', string $instructions = '', int $max_output_tokens = 0 ) {
        if ( ! self::has_api_key() ) {
            $provider_name = self::is_gemini() ? 'Gemini' : 'OpenAI';
            return [
                'error' => sprintf( 
                    /* translators: %s: AI provider name */
                    __( '%s API key is not configured.', 'antimanual' ), 
                    $provider_name 
                ),
            ];
        }

        $response = self::get()->get_reply( $input, $conversation_id, $instructions, $max_output_tokens );

        if ( is_array( $response ) && ! isset( $response['error'] ) && isset( $response['reply'] ) ) {
            return (string) $response['reply'];
        }

        return $response;
    }

    /**
     * Generate an image using the current provider.
     *
     * @param string $prompt The image prompt.
     * @param string $size   Image size.
     * @return string|\WP_Error URL of the generated image or WP_Error.
     */
    public static function generate_image( string $prompt, string $size = '1024x1024' ) {
        if ( ! self::has_api_key() ) {
            return new \WP_Error( 'api_key_missing', __( 'AI API key is not configured.', 'antimanual' ) );
        }

        if ( ! method_exists( self::get(), 'generate_image' ) ) {
            return new \WP_Error( 'not_supported', __( 'Image generation is not supported by the current provider.', 'antimanual' ) );
        }

        return self::get()->generate_image( $prompt, $size );
    }

    /**
     * Generate an embedding using the current provider.
     *
     * @param string $content The content to generate an embedding for.
     * @return array|\WP_Error The embedding array or WP_Error on failure.
     */
    public static function generate_embedding( string $content ) {
        if ( ! self::has_api_key() ) {
            $provider_name = self::is_gemini() ? 'Gemini' : 'OpenAI';
            return new \WP_Error(
                'api_key_missing',
                sprintf( 
                    /* translators: %s: AI provider name */
                    __( '%s API key is not configured.', 'antimanual' ), 
                    $provider_name 
                )
            );
        }

        return self::get()->generate_embedding( $content );
    }

    /**
     * Get the current embedding model name.
     *
     * @return string
     */
    public static function get_embedding_model(): string {
        $provider = self::get();

        if ( method_exists( $provider, 'get_embedding_model' ) ) {
            return $provider->get_embedding_model();
        }

        // Fallback when the provider doesn't implement get_embedding_model().
        return self::is_gemini() ? 'gemini-embedding-001' : 'text-embedding-3-small';
    }

    /**
     * Create a new conversation.
     *
     * @param array $items Initial conversation items.
     * @return string|array The conversation ID or error array.
     */
    public static function create_conversation( array $items = array() ) {
        if ( ! self::has_api_key() ) {
            $provider_name = self::is_gemini() ? 'Gemini' : 'OpenAI';
            return [
                'error' => sprintf( 
                    /* translators: %s: AI provider name */
                    __( '%s API key is not configured.', 'antimanual' ), 
                    $provider_name 
                ),
            ];
        }

        return self::get()->create_conversation( $items );
    }

    /**
     * Get the display name for the current provider.
     *
     * @return string Human-readable provider name.
     */
    public static function get_display_name(): string {
        return self::is_gemini() ? 'Gemini' : 'OpenAI';
    }
}
