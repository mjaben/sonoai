<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI API Integration Class
 *
 * Handles all communication with the OpenAI API, including chat completions,
 * embeddings, and model management.
 *
 * @package Antimanual
 */
class OpenAI {
    private static $base_url    = 'https://api.openai.com';
    private static $api_version = 'v1';
    private        $api_key     = '';
    private        $model       = 'gpt-5-mini';
    private  $embed_model       = 'text-embedding-3-small';

    public function __construct() {
        $configs           = atml_get_openai_configs();
        $this->api_key     = $configs['api_key'] ?? atml_option( 'openai_api_key' );
        $this->model       = $configs['response_model'] ?? atml_get_default_openai_response_model();
    }

    /**
     * Get full API URL for an endpoint.
     *
     * @param string $endpoint The API endpoint.
     * @return string Full API URL.
     */
    public static function url( $endpoint = '' ) {
        return self::$base_url . '/' . self::$api_version . '/' . ltrim( $endpoint, '/' );
    }

    /**
     * Create a new conversation (legacy method for Assistants API).
     *
     * @param array $items Initial conversation items.
     * @return string|array Conversation ID or error array.
     */
    public function create_conversation( array $items ) {
        $items = array_map(
            fn( $item ) => [
                'type'    => $item['type'] ?? 'message',
                'role'    => $item['role'],
                'content' => $item['content'],
            ],
            $items
        );

        $conversation = $this->request(
            'conversations',
            'POST',
            [ 'items' => $items ],
        );

        if ( isset( $conversation['error'] ) || ! isset( $conversation['id'] ) ) {
            return [
                'error' => __( 'Failed to create conversation.', 'antimanual' ),
            ];
        }

        return $conversation['id'];
    }

    /**
     * Get AI reply for the given input
     * 
     * @param string|array $input             The input messages or content.
     * @param string       $conversation_id   Optional conversation ID for context.
     * @param string       $instructions      Optional system instructions.
     * @param int          $max_output_tokens Optional maximum output tokens (for controlling response length).
     * @return string|array The reply string, or an error array.
     */
    public function get_reply( $input, $conversation_id = '', $instructions = '', $max_output_tokens = 0 ) {
        $payload = [
            'model'        => $this->model,
            'conversation' => $conversation_id ?: null,
            'input'        => $input,
            'instructions' => $instructions ?: null,
        ];

        // Add max_tokens if specified (critical for long-form content generation).
        if ( $max_output_tokens > 0 ) {
            // Cap at 16000 tokens to stay within model limits.
            $payload['max_output_tokens'] = min( $max_output_tokens, 16000 );
        }

        $response = $this->request(
            'responses',
            'POST',
            $payload
        );

        // Check for incomplete responses (e.g., max_output_tokens exceeded).
        $status = $response['status'] ?? 'unknown';
        if ( 'incomplete' === $status ) {
            $reason = $response['incomplete_details']['reason'] ?? 'unknown';
            if ( 'max_output_tokens' === $reason ) {
                return [
                    'error' => __( 'The AI model ran out of tokens while generating content. This usually happens with reasoning models that use many tokens for "thinking". Please try a shorter article length or contact support.', 'antimanual' ),
                ];
            }
            return [
                'error' => sprintf(
                    /* translators: %s: reason for incomplete response */
                    __( 'AI response was incomplete: %s', 'antimanual' ),
                    $reason
                ),
            ];
        }

        $reply = self::extract_response_text( $response );

        if ( '' === $reply ) {
            return [
                'error' => __( 'Failed to get response from OpenAI.', 'antimanual' ),
            ];
        }

        return [
            'reply'           => $reply,
            'conversation_id' => $response['conversation_id'] ?? '',
        ];
    }

    /**
     * Get messages for a specific conversation.
     *
     * @param string $conversation_id The conversation ID.
     * @return array Array of messages or error array.
     */
    public function get_messages ( string $conversation_id ) {
        $response = $this->request(
            "conversations/{$conversation_id}/items",
            'GET'
        );

        if ( isset( $response['error'] ) || ! isset( $response['data'] ) ) {
            return [
                'error' => __( 'Failed to retrieve messages for this conversation.', 'antimanual' ),
            ];
        }

        $conversation = array_map(
            fn( $item ) => [
                'id'      => $item['id'],
                'role'    => $item['role'],
                'type'    => $item['type'],
                'content' => self::extract_content_text( $item['content'] ?? [] ),
            ],
            $response['data'],
        );

        $messages = array_filter( $conversation, fn( $item ) => 'message' === $item['type'] );

        return $messages;
    }

    /**
     * Generate embedding for text content.
     *
     * @param string $content Text content to embed.
     * @return array|\WP_Error Embedding vector or WP_Error on failure.
     */
    public function generate_embedding( $content ) {
        $data = $this->request(
            '/embeddings',
            'POST',
            [
                'model' => $this->embed_model,
                'input' => $content,
            ]
        );

        if ( is_wp_error( $data ) ) {
            return new \WP_Error( 'embedding_failed', __( 'Failed to generate embedding. Error: ', 'antimanual' ) . $data->get_error_message() );
        }

        if ( isset( $data['error'] ) ) {
            $error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : wp_json_encode( $data['error'] );
            return new \WP_Error( 'embedding_failed', __( 'Failed to generate embedding. Error: ', 'antimanual' ) . $error_msg );
        }

        $embedding = $data['data'][0]['embedding'] ?? null;

        if ( ! atml_is_embedding( $embedding ) ) {
            return new \WP_Error( 'embedding_failed', __( 'Failed to generate embedding. Unexpected response format.', 'antimanual' ) );
        }

        return $embedding;
    }

    /**
     * Get the embedding model name.
     *
     * @return string
     */
    public function get_embedding_model(): string {
        return $this->embed_model;
    }

	/**
	 * Generate an image using OpenAI's Image API.
	 *
	 * Uses the latest supported GPT Image model.
	 *
	 * @param string $prompt The image prompt.
	 * @param string $size   Image size. Default '1024x1024'.
	 * @return string|\WP_Error URL of the generated image or WP_Error.
	 */
		public function generate_image( string $prompt, string $size = '1024x1024' ) {
			$image_model = trim(
				(string) apply_filters(
					'antimanual_openai_image_model',
					'gpt-image-1.5',
					$prompt,
					$size
				)
			);

		if ( '' === $image_model ) {
			$image_model = 'gpt-image-1.5';
		}

		$response = $this->request(
			'images/generations',
			'POST',
			$this->build_image_generation_payload( $image_model, $prompt, $size )
		);

		if ( isset( $response['error'] ) ) {
			return new \WP_Error(
				'image_generation_failed',
				$this->normalize_image_generation_error( $response['error'] )
			);
		}

		return $this->extract_generated_image_url( $response );
	}

	/**
	 * Build an OpenAI image-generation payload for the requested model.
	 *
	 * @param string $model  Image model identifier.
	 * @param string $prompt Image prompt.
	 * @param string $size   Requested image size.
	 * @return array
	 */
	private function build_image_generation_payload( string $model, string $prompt, string $size ): array {
		$payload = [
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => $size,
		];

		if ( $this->is_gpt_image_model( $model ) ) {
			$payload['output_format'] = 'png';
		}

		return $payload;
	}

	/**
	 * Determine whether the given model is part of the GPT Image family.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	private function is_gpt_image_model( string $model ): bool {
		return 0 === strpos( $model, 'gpt-image-' ) || 'chatgpt-image-latest' === $model;
	}

	/**
	 * Normalize OpenAI image errors into a readable message.
	 *
	 * @param mixed $error Error payload returned by the API.
	 * @return string
	 */
	private function normalize_image_generation_error( $error ): string {
		if ( is_array( $error ) ) {
			return (string) ( $error['message'] ?? __( 'Unknown error from OpenAI image generation.', 'antimanual' ) );
		}

		return is_string( $error ) && '' !== trim( $error )
			? $error
			: __( 'Unknown error from OpenAI image generation.', 'antimanual' );
	}

	/**
	 * Extract a usable image URL from an OpenAI image-generation response.
	 *
	 * GPT Image responses typically return base64 image data.
	 *
	 * @param array $response Decoded API response.
	 * @return string|\WP_Error
	 */
	private function extract_generated_image_url( array $response ) {
		$image_url = $response['data'][0]['url'] ?? '';

		if ( is_string( $image_url ) && '' !== trim( $image_url ) ) {
			return $image_url;
		}

		$image_data = $response['data'][0]['b64_json'] ?? '';

		if ( ! is_string( $image_data ) || '' === trim( $image_data ) ) {
			return new \WP_Error( 'image_generation_failed', __( 'No image data returned from OpenAI.', 'antimanual' ) );
		}

		$mime_type = $response['data'][0]['mime_type'] ?? 'image/png';

		return $this->store_generated_image( $image_data, $mime_type );
	}

	/**
	 * Persist base64 image data to a temporary file under uploads.
	 *
	 * @param string $image_data Base64-encoded image payload.
	 * @param string $mime_type  Mime type reported by the API.
	 * @return string|\WP_Error
	 */
	private function store_generated_image( string $image_data, string $mime_type = 'image/png' ) {
		$decoded = base64_decode( $image_data, true );

		if ( false === $decoded || '' === $decoded ) {
			return new \WP_Error( 'image_generation_failed', __( 'Failed to decode image data from OpenAI.', 'antimanual' ) );
		}

		$extension_map = [
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		];

		$extension = $extension_map[ strtolower( $mime_type ) ] ?? 'png';
		$upload_dir = wp_get_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'atml-temp';

		if ( ! file_exists( $temp_dir ) && ! wp_mkdir_p( $temp_dir ) ) {
			return new \WP_Error( 'image_generation_failed', __( 'Failed to prepare the temporary upload directory.', 'antimanual' ) );
		}

		$filename  = 'openai-image-' . wp_generate_uuid4() . '.' . $extension;
		$file_path = trailingslashit( $temp_dir ) . $filename;
		$file_url  = trailingslashit( $upload_dir['baseurl'] ) . 'atml-temp/' . $filename;

		$written = file_put_contents( $file_path, $decoded );

		if ( false === $written ) {
			return new \WP_Error( 'image_generation_failed', __( 'Failed to save generated image file.', 'antimanual' ) );
		}

		return $file_url;
	}

    /**
     * Fetch available models from OpenAI API.
     *
     * @return array|\WP_Error Array of available models or WP_Error on failure.
     */
    public static function list_models( string $api_key ) {
        if ( empty( $api_key ) ) {
            return new \WP_Error( 'missing_api_key', __( 'API key is required.', 'antimanual' ) );
        }

        $response = wp_remote_get(
            self::url( 'models' ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'request_failed', $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 401 === $status_code ) {
            return new \WP_Error( 'invalid_api_key', __( 'Invalid API key. Please check your OpenAI API key.', 'antimanual' ) );
        }

        if ( 403 === $status_code ) {
            return new \WP_Error( 'access_denied', __( 'Access denied. Your API key may not have the required permissions.', 'antimanual' ) );
        }

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_message = $body['error']['message'] ?? __( 'Failed to fetch models from OpenAI.', 'antimanual' );
            return new \WP_Error( 'api_error', $error_message );
        }

        if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
            return new \WP_Error( 'invalid_response', __( 'Unexpected response format from OpenAI.', 'antimanual' ) );
        }

        // Filter and categorize models
        $chat_models = [];

        // Define known model prefixes for categorization
        $chat_prefixes = [ 'gpt-', 'o1', 'o3', 'chatgpt' ];

        foreach ( $body['data'] as $model ) {
            $model_id = $model['id'] ?? '';

            if ( empty( $model_id ) ) {
                continue;
            }



            // Check if it's a chat model
            foreach ( $chat_prefixes as $prefix ) {
                if ( strpos( $model_id, $prefix ) === 0 ) {
                    $chat_models[] = [
                        'value' => $model_id,
                        'label' => self::format_model_label( $model_id ),
                    ];
                    break;
                }
            }
        }

        // Sort models by name
        usort( $chat_models, fn( $a, $b ) => strcmp( $b['value'], $a['value'] ) );

        return [
            'valid'       => true,
            'chat_models' => $chat_models,
        ];
    }

    /**
     * Extract displayable text from a Responses API payload.
     *
     * @param array $response Decoded API response.
     * @return string
     */
    public static function extract_response_text( array $response ): string {
        $output_text = $response['output_text'] ?? '';
        if ( is_string( $output_text ) && '' !== trim( $output_text ) ) {
            return trim( $output_text );
        }

        $parts = [];

        foreach ( $response['output'] ?? [] as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $content_text = self::extract_content_text( $item['content'] ?? [] );
            if ( '' !== $content_text ) {
                $parts[] = $content_text;
            }
        }

        return trim( implode( "\n\n", $parts ) );
    }

    /**
     * Extract text from response or conversation content parts.
     *
     * @param array $content Content parts array from the OpenAI API.
     * @return string
     */
    public static function extract_content_text( array $content ): string {
        $parts = [];

        foreach ( $content as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $text = $part['text'] ?? '';

            if ( is_array( $text ) ) {
                $text = $text['value'] ?? '';
            }

            if ( is_string( $text ) && '' !== trim( $text ) ) {
                $parts[] = trim( $text );
            }
        }

        return trim( implode( "\n\n", $parts ) );
    }

    /**
     * Format a model ID into a human-readable label.
     *
     * @param string $model_id The model ID.
     * @return string The formatted label.
     */
    private static function format_model_label( string $model_id ): string {
        // Remove date suffixes like -2024-01-25
        $label = preg_replace( '/-\d{4}-\d{2}-\d{2}$/', '', $model_id );

        // Convert to title case with proper spacing
        $label = str_replace( [ '-', '_' ], ' ', $label );
        $label = ucwords( $label );

        // Fix common abbreviations
        $label = str_replace( [ 'Gpt', 'Ada', 'Turbo' ], [ 'GPT', 'Ada', 'Turbo' ], $label );

        return $label;
    }

    /**
     * Make an HTTP request to the OpenAI API.
     *
     * @param string $endpoint API endpoint.
     * @param string $method   HTTP method (GET, POST, etc.).
     * @param array  $payload  Request body parameters.
     * @return array|mixed Decoded JSON response or error array.
     */
    private function request( string $endpoint, string $method = 'GET', array $payload = [] ) {
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 360,
        ];

        if ( 'POST' === $method || 'PUT' === $method ) {
            $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE, 2048 );

            if ( false === $json ) {
                return [
                    'error' => sprintf(
                        /* translators: %s: JSON encoding error message */
                        __( 'JSON encoding failed: %s', 'antimanual' ),
                        json_last_error_msg(),
                    ),
                ];
            }

            $args['body'] = $json;
        }

        $url      = $this->url( $endpoint );
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [
                'error' => $response->get_error_message(),
            ];
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
