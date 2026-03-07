<?php
/**
 * SonoAI — AI Provider factory.
 *
 * Abstracts OpenAI vs Gemini so the rest of the plugin doesn't care which
 * provider is active. Adapted directly from the Antimanual AIProvider pattern.
 *
 * @package SonoAI
 */

namespace SonoAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIProvider {

    private static ?AIProvider $instance = null;
    private string $name;

    private function __construct() {
        $this->name = sonoai_option( 'active_provider', 'openai' );
    }

    public static function instance(): AIProvider {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Force refresh (e.g. after settings change). */
    public static function refresh(): void {
        self::$instance = null;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public static function get_name(): string {
        return self::instance()->name;
    }

    public static function is_openai(): bool {
        return 'openai' === self::get_name();
    }

    public static function is_gemini(): bool {
        return 'gemini' === self::get_name();
    }

    public static function has_api_key(): bool {
        return ! empty( self::get_api_key() );
    }

    public static function get_api_key(): string {
        return self::is_gemini()
            ? (string) sonoai_option( 'gemini_api_key', '' )
            : (string) sonoai_option( 'openai_api_key', '' );
    }

    public static function get_chat_model(): string {
        return self::is_gemini()
            ? (string) sonoai_option( 'gemini_chat_model', 'gemini-1.5-pro' )
            : (string) sonoai_option( 'openai_chat_model', 'gpt-4o' );
    }

    public static function get_embedding_model(): string {
        return self::is_gemini()
            ? (string) sonoai_option( 'gemini_embedding_model', 'text-embedding-004' )
            : (string) sonoai_option( 'openai_embedding_model', 'text-embedding-3-small' );
    }

    // ── Embedding ─────────────────────────────────────────────────────────────

    /**
     * Generate a vector embedding for the given text.
     *
     * @param string $text
     * @return float[]|\WP_Error
     */
    public static function generate_embedding( string $text ) {
        if ( ! self::has_api_key() ) {
            return new \WP_Error( 'no_api_key', __( 'AI API key is not configured.', 'sonoai' ) );
        }

        return self::is_gemini()
            ? self::gemini_embedding( $text )
            : self::openai_embedding( $text );
    }

    // ── Chat / Vision ─────────────────────────────────────────────────────────

    /**
     * Get a chat reply from the AI, optionally with an image.
     *
     * @param array  $messages Array of {role, content} objects.
     * @param string $image_b64 Base64-encoded image (optional).
     * @return string|\WP_Error
     */
    public static function get_reply( array $messages, string $image_b64 = '' ) {
        if ( ! self::has_api_key() ) {
            return new \WP_Error( 'no_api_key', __( 'AI API key is not configured.', 'sonoai' ) );
        }

        return self::is_gemini()
            ? self::gemini_chat( $messages, $image_b64 )
            : self::openai_chat( $messages, $image_b64 );
    }

    // ── OpenAI internals ──────────────────────────────────────────────────────

    private static function openai_embedding( string $text ) {
        $response = wp_remote_post(
            'https://api.openai.com/v1/embeddings',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::get_api_key(),
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'model' => self::get_embedding_model(),
                    'input' => $text,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['data'][0]['embedding'] ) ) {
            $err = $body['error']['message'] ?? 'Unknown OpenAI error';
            return new \WP_Error( 'openai_embed_error', $err );
        }

        return $body['data'][0]['embedding'];
    }

    private static function openai_chat( array $messages, string $image_b64 = '' ) {
        // If an image is supplied, attach it to the LAST user message as a vision payload.
        if ( ! empty( $image_b64 ) ) {
            foreach ( array_reverse( array_keys( $messages ) ) as $idx ) {
                if ( ( $messages[ $idx ]['role'] ?? '' ) === 'user' ) {
                    $text_content = $messages[ $idx ]['content'];
                    $messages[ $idx ]['content'] = [
                        [ 'type' => 'text', 'text' => $text_content ],
                        [
                            'type'      => 'image_url',
                            'image_url' => [ 'url' => 'data:image/jpeg;base64,' . $image_b64 ],
                        ],
                    ];
                    break;
                }
            }
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::get_api_key(),
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'model'    => self::get_chat_model(),
                    'messages' => $messages,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
            $err = $body['error']['message'] ?? 'Unknown OpenAI error';
            return new \WP_Error( 'openai_chat_error', $err );
        }

        return $body['choices'][0]['message']['content'];
    }

    // ── Gemini internals ──────────────────────────────────────────────────────

    private static function gemini_embedding( string $text ) {
        $model    = self::get_embedding_model();
        $api_key  = self::get_api_key();
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$api_key}";

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'model'   => 'models/' . $model,
                    'content' => [ 'parts' => [ [ 'text' => $text ] ] ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['embedding']['values'] ) ) {
            $err = $body['error']['message'] ?? 'Unknown Gemini error';
            return new \WP_Error( 'gemini_embed_error', $err );
        }

        return $body['embedding']['values'];
    }

    private static function gemini_chat( array $messages, string $image_b64 = '' ) {
        $api_key  = self::get_api_key();
        $model    = self::get_chat_model();
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

        // Convert OpenAI-style messages to Gemini format.
        $system_parts  = [];
        $gemini_contents = [];

        foreach ( $messages as $msg ) {
            $role    = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if ( 'system' === $role ) {
                $system_parts[] = [ 'text' => $content ];
                continue;
            }

            $parts = [ [ 'text' => $content ] ];

            // Attach image to the last user message.
            if ( 'user' === $role && ! empty( $image_b64 ) ) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data'      => $image_b64,
                    ],
                ];
                $image_b64 = ''; // Only attach once.
            }

            $gemini_contents[] = [
                'role'  => 'user' === $role ? 'user' : 'model',
                'parts' => $parts,
            ];
        }

        $body = [ 'contents' => $gemini_contents ];
        if ( ! empty( $system_parts ) ) {
            $body['system_instruction'] = [ 'parts' => $system_parts ];
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $err = $data['error']['message'] ?? 'Unknown Gemini error';
            return new \WP_Error( 'gemini_chat_error', $err );
        }

        return $data['candidates'][0]['content']['parts'][0]['text'];
    }
}
