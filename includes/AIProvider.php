<?php
/**
 * SonoAI — AI Provider factory.
 *
 * Abstracts OpenAI vs Gemini vs Anthropic vs Mistral.
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

    public static function is_openai(): bool { return 'openai' === self::get_name(); }
    public static function is_gemini(): bool { return 'gemini' === self::get_name(); }
    public static function is_anthropic(): bool { return 'anthropic' === self::get_name(); }
    public static function is_mistral(): bool { return 'mistral' === self::get_name(); }

    public static function has_api_key(): bool {
        return ! empty( self::get_api_key() );
    }

    public static function get_api_key(): string {
        $name = self::get_name();
        return (string) sonoai_option( $name . '_api_key', '' );
    }

    public static function get_chat_model(): string {
        $name = self::get_name();
        $defaults = [
            'openai'    => 'gpt-4o',
            'gemini'    => 'gemini-2.0-flash',
            'anthropic' => 'claude-3-5-sonnet-20241022',
            'mistral'   => 'mistral-large-latest',
        ];
        return (string) sonoai_option( $name . '_chat_model', $defaults[$name] ?? 'gpt-4o' );
    }

    public static function get_embedding_model(): string {
        $name = self::get_name();
        if ( $name === 'anthropic' ) {
            // Anthropic has no embedding API, default to OpenAI logic
            return (string) sonoai_option( 'openai_embedding_model', 'text-embedding-3-small' );
        }
        $defaults = [
            'openai'  => 'text-embedding-3-small',
            'gemini'  => 'text-embedding-004',
            'mistral' => 'mistral-embed',
        ];
        return (string) sonoai_option( $name . '_embedding_model', $defaults[$name] ?? 'text-embedding-3-small' );
    }

    // ── Embedding ─────────────────────────────────────────────────────────────

    /**
     * Generate a vector embedding for the given text.
     *
     * @param string $text
     * @return float[]|\WP_Error
     */
    public static function generate_embedding( string $text ) {
        $name = self::get_name();
        
        // Anthropic has no embedding API, default to OpenAI logic
        if ( $name === 'anthropic' ) {
            $name = 'openai'; 
        }

        $key = (string) sonoai_option( $name . '_api_key', '' );
        if ( empty( $key ) ) {
            return new \WP_Error( 'no_api_key', __( 'Embedding API key is not configured.', 'sonoai' ) );
        }

        if ( $name === 'gemini' ) {
            return self::gemini_embedding( $text );
        } elseif ( $name === 'mistral' ) {
            return self::mistral_embedding( $text );
        } else {
            return self::openai_embedding( $text, $key );
        }
    }

    // ── Chat / Vision ─────────────────────────────────────────────────────────

    /**
     * Stream a chat reply from the AI.
     */
    public static function stream_reply( array $messages, callable $chunk_callback ) {
        if ( ! self::has_api_key() ) {
            return new \WP_Error( 'no_api_key', __( 'AI API key is not configured.', 'sonoai' ) );
        }

        $name = self::get_name();
        if ( $name === 'gemini' ) return self::gemini_chat_stream( $messages, $chunk_callback );
        if ( $name === 'anthropic' ) return self::anthropic_chat_stream( $messages, $chunk_callback );
        if ( $name === 'mistral' ) return self::mistral_chat_stream( $messages, $chunk_callback );
        return self::openai_chat_stream( $messages, $chunk_callback );
    }

    /**
     * Get a chat reply from the AI.
     *
     * @param array  $messages Array of {role, content} objects.
     * @return string|\WP_Error
     */
    public static function get_reply( array $messages ) {
        if ( ! self::has_api_key() ) {
            return new \WP_Error( 'no_api_key', __( 'AI API key is not configured.', 'sonoai' ) );
        }

        $name = self::get_name();
        if ( $name === 'gemini' ) return self::gemini_chat( $messages );
        if ( $name === 'anthropic' ) return self::anthropic_chat( $messages );
        if ( $name === 'mistral' ) return self::mistral_chat( $messages );
        return self::openai_chat( $messages );
    }

    // ── OpenAI internals ──────────────────────────────────────────────────────

    private static function openai_embedding( string $text, string $api_key = '' ) {
        if ( empty( $api_key ) ) $api_key = self::get_api_key();
        
        $response = wp_remote_post(
            'https://api.openai.com/v1/embeddings',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
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

    private static function openai_chat( array $messages ) {

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

    private static function gemini_chat( array $messages ) {
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

    // ── Mistral internals ─────────────────────────────────────────────────────

    private static function mistral_embedding( string $text ) {
        $response = wp_remote_post(
            'https://api.mistral.ai/v1/embeddings',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::get_api_key(),
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'model' => self::get_embedding_model(),
                    'input' => [ $text ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['data'][0]['embedding'] ) ) {
            $err = $body['message'] ?? 'Unknown Mistral error';
            return new \WP_Error( 'mistral_embed_error', $err );
        }

        return $body['data'][0]['embedding'];
    }

    private static function mistral_chat( array $messages ) {
        $response = wp_remote_post(
            'https://api.mistral.ai/v1/chat/completions',
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

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
            $err = $body['message'] ?? 'Unknown Mistral error';
            return new \WP_Error( 'mistral_chat_error', $err );
        }

        return $body['choices'][0]['message']['content'];
    }

    private static function mistral_chat_stream( array $messages, callable $callback ) {
        $url = 'https://api.mistral.ai/v1/chat/completions';
        $payload = wp_json_encode( [
            'model'    => self::get_chat_model(),
            'messages' => $messages,
            'stream'   => true,
        ] );

        $headers = [
            'Authorization: Bearer ' . self::get_api_key(),
            'Content-Type: application/json',
        ];

        return self::execute_curl_stream( $url, $headers, $payload, function($line) use ($callback) {
            if ( str_starts_with( $line, 'data: ' ) ) {
                $data_str = substr( $line, 6 );
                if ( $data_str === '[DONE]' ) return '';
                $decoded = json_decode( $data_str, true );
                if ( isset( $decoded['choices'][0]['delta']['content'] ) ) {
                    $text = $decoded['choices'][0]['delta']['content'];
                    $callback( $text );
                    return $text;
                }
            }
            return '';
        } );
    }

    // ── Anthropic internals ───────────────────────────────────────────────────

    private static function format_anthropic_payload( array $messages ) {
        $system = '';
        $anthropic_msgs = [];
        foreach ( $messages as $msg ) {
            if ( $msg['role'] === 'system' ) {
                $system .= $msg['content'] . "\n";
            } else {
                $anthropic_msgs[] = [ 'role' => $msg['role'] === 'user' ? 'user' : 'assistant', 'content' => $msg['content'] ];
            }
        }
        $payload = [
            'model'      => self::get_chat_model(),
            'max_tokens' => 4096,
            'messages'   => $anthropic_msgs
        ];
        if ( ! empty( trim( $system ) ) ) {
            $payload['system'] = trim( $system );
        }
        return wp_json_encode( $payload );
    }

    private static function anthropic_chat( array $messages ) {
        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'timeout' => 60,
                'headers' => [
                    'x-api-key'         => self::get_api_key(),
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ],
                'body' => self::format_anthropic_payload( $messages ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['content'][0]['text'] ) ) {
            $err = $body['error']['message'] ?? 'Unknown Anthropic error';
            return new \WP_Error( 'anthropic_chat_error', $err );
        }

        return $body['content'][0]['text'];
    }

    private static function anthropic_chat_stream( array $messages, callable $callback ) {
        $url = 'https://api.anthropic.com/v1/messages';
        $payload_arr = json_decode( self::format_anthropic_payload( $messages ), true );
        $payload_arr['stream'] = true;
        
        $headers = [
            'x-api-key: ' . self::get_api_key(),
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ];

        return self::execute_curl_stream( $url, $headers, wp_json_encode( $payload_arr ), function($line) use ($callback) {
            if ( str_starts_with( $line, 'data: ' ) ) {
                $data_str = substr( $line, 6 );
                $decoded = json_decode( $data_str, true );
                if ( isset( $decoded['type'] ) && $decoded['type'] === 'content_block_delta' ) {
                    if ( isset( $decoded['delta']['text'] ) ) {
                        $text = $decoded['delta']['text'];
                        $callback( $text );
                        return $text;
                    }
                }
            }
            return '';
        } );
    }

    // ── Streaming internals (Original unmodified logic) ───────────────────────

    private static function execute_curl_stream( string $url, array $headers, string $payload, callable $line_parser ) {
        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );

        $full_text = '';
        $buffer = '';

        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $ch, $chunk ) use ( &$buffer, &$full_text, $line_parser ) {
            $buffer .= $chunk;
            while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
                $line = substr( $buffer, 0, $pos + 1 );
                $buffer = substr( $buffer, $pos + 1 );
                $parsed = $line_parser( trim( $line ) );
                if ( $parsed ) {
                    $full_text .= $parsed;
                }
            }
            return strlen( $chunk );
        } );

        curl_exec( $ch );
        if ( curl_errno( $ch ) ) {
            $err = curl_error( $ch );
            curl_close( $ch );
            return new \WP_Error( 'curl_error', $err );
        }
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $http_code >= 400 ) {
            return new \WP_Error( 'api_error', "HTTP Error $http_code" );
        }

        return $full_text;
    }
}
