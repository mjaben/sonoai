<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini AI Provider.
 *
 * Handles communication with Google's Gemini AI API for chat,
 * embeddings, and image generation.
 *
 * @package Antimanual
 */
class Gemini {

	/**
	 * Base URL for the Gemini API.
	 *
	 * @var string
	 */
	private static $base_url = 'https://generativelanguage.googleapis.com/v1beta/models';

	/**
	 * API key for authentication.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Chat model name.
	 *
	 * @var string
	 */
	private $model = 'gemini-3-flash-preview';

	/**
	 * Embedding model name.
	 *
	 * @var string
	 */
	private $embed_model = 'gemini-embedding-001';

	/**
	 * Constructor.
	 *
	 * Initialises API key and model from saved options.
	 */
	public function __construct() {
		$this->api_key = atml_option( 'gemini_api_key' );
		$this->model   = trim( atml_option( 'gemini_response_model' ) );
	}

	/**
	 * Build the API endpoint URL.
	 *
	 * @param string $model  The model name.
	 * @param string $action The API action (e.g. generateContent).
	 * @return string Full API URL.
	 */
	private function url( $model, $action ) {
		return self::$base_url . '/' . $model . ':' . $action . '?key=' . $this->api_key;
	}

	/**
	 * Create a conversation placeholder.
	 *
	 * Gemini doesn't have server-side conversation storage like OpenAI,
	 * so we use a marker to indicate Gemini conversations need history sent manually.
	 *
	 * @param array $items Initial conversation items (not used for Gemini).
	 * @return string A marker ID indicating this is a Gemini conversation.
	 */
	public function create_conversation( array $items ) {
		return 'gemini-conversation-' . wp_generate_uuid4();
	}

	/**
	 * Get AI reply for the given input.
	 *
	 * For Gemini, we need to send the full conversation history since
	 * Gemini doesn't store conversations server-side.
	 *
	 * @param array  $input             Array of message objects (role, content) OR structured input.
	 * @param string $conversation_id   Not used for actual API calls, but indicates Gemini conversation.
	 * @param string $instructions      System instructions.
	 * @param int    $max_output_tokens Optional maximum output tokens.
	 * @return string|array The reply string, or an error array.
	 */
	public function get_reply( $input, $conversation_id = '', $instructions = '', $max_output_tokens = 0 ) {
		$contents = [];

		foreach ( $input as $message ) {
			// Handle both simple string content and structured content arrays.
			$content = $message['content'] ?? '';

			// If content is an array (structured format from doc generation), extract parts.
			if ( is_array( $content ) ) {
				$parts = [];
				foreach ( $content as $part ) {
					if ( isset( $part['text'] ) ) {
						$parts[] = [ 'text' => $part['text'] ];
					} elseif ( isset( $part['type'] ) && $part['type'] === 'input_text' && isset( $part['text'] ) ) {
						$parts[] = [ 'text' => $part['text'] ];
					} elseif ( isset( $part['type'] ) && $part['type'] === 'input_file' && isset( $part['file_url'] ) ) {
						// Convert OpenAI's input_file format to Gemini's inline_data format.
						$file_part = $this->convert_file_to_inline_data( $part['file_url'] );
						if ( $file_part ) {
							$parts[] = $file_part;
						}
					}
				}

				// Map roles: OpenAI uses 'system', 'user', 'assistant'
				// Gemini uses 'user', 'model'.
				$role = $message['role'] ?? 'user';

				// System messages should go to systemInstruction, not contents.
				if ( $role === 'system' ) {
					$text_content = '';
					foreach ( $parts as $p ) {
						if ( isset( $p['text'] ) ) {
							$text_content .= $p['text'] . "\n";
						}
					}
					$instructions = trim( $text_content ) . "\n" . $instructions;
					continue;
				}

				$gemini_role = ( $role === 'user' ) ? 'user' : 'model';

				if ( ! empty( $parts ) ) {
					$contents[] = [
						'role'  => $gemini_role,
						'parts' => $parts,
					];
				}
				continue;
			}

			// Handle simple string content.
			$role = $message['role'] ?? 'user';

			// System messages should go to systemInstruction, not contents.
			if ( $role === 'system' ) {
				$instructions = $content . "\n" . $instructions;
				continue;
			}

			$gemini_role = ( $role === 'user' ) ? 'user' : 'model';

			$contents[] = [
				'role'  => $gemini_role,
				'parts' => [
					[ 'text' => $content ],
				],
			];
		}

		$payload = [
			'contents' => $contents,
		];

		// Use systemInstruction field supported in v1beta.
		if ( ! empty( $instructions ) ) {
			$payload['systemInstruction'] = [
				'parts' => [
					[ 'text' => $instructions ],
				],
			];
		}

		// Build generationConfig for output control.
		$generation_config = [];

		// Set max output tokens to prevent truncation on long articles.
		if ( $max_output_tokens > 0 ) {
			// Cap at 65536 tokens (Gemini model limit).
			$generation_config['maxOutputTokens'] = min( $max_output_tokens, 65536 );
		}

		// Detect JSON output requests from system instructions.
		if ( ! empty( $instructions ) && $this->is_json_output_requested( $instructions ) ) {
			$generation_config['responseMimeType'] = 'application/json';
		}

		if ( ! empty( $generation_config ) ) {
			$payload['generationConfig'] = $generation_config;
		}

		$response = $this->request( $this->model, 'generateContent', $payload );

		if ( isset( $response['error'] ) ) {
			return [
				'error' => $response['error']['message'] ?? __( 'Failed to get response from Gemini.', 'antimanual' ),
			];
		}

		// Extract text from Gemini response structure.
		$reply = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( $reply === null ) {
			return [
				'error' => __( 'Failed to get valid response from Gemini.', 'antimanual' ),
			];
		}

		return $this->clean_reply( $reply );
	}

	/**
	 * Clean up the reply from Gemini.
	 *
	 * Gemini often wraps content in markdown code blocks even when not requested.
	 * This method removes the wrapping code blocks if they exist.
	 *
	 * @param string $text The text to clean.
	 * @return string The cleaned text.
	 */
	private function clean_reply( string $text ): string {
		$text = trim( $text );

		// If the entire response is wrapped in a markdown code block, remove the wrapper.
		if ( 0 === strpos( $text, '```' ) ) {
			$text = preg_replace( '/^```[a-zA-Z]*\s+/', '', $text );
			$text = preg_replace( '/\s+```$/', '', $text );
			$text = trim( $text );
		}

		return $text;
	}

	/**
	 * Check if the system instructions request JSON output.
	 *
	 * Detects common JSON-related phrases in the instructions to determine
	 * whether Gemini should be configured with responseMimeType "application/json".
	 *
	 * @param string $instructions The system instructions text.
	 * @return bool True if JSON output is expected.
	 */
	private function is_json_output_requested( string $instructions ): bool {
		$json_indicators = [
			'valid JSON',
			'JSON object',
			'JSON response',
			'JSON Response Format',
			'output the JSON',
			'response must be',
			'"content":',
			'"title":',
		];

		foreach ( $json_indicators as $indicator ) {
			if ( false !== strpos( $instructions, $indicator ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a file URL to Gemini's inline_data format.
	 *
	 * Downloads the file, determines its MIME type, and base64 encodes it.
	 * Caches the result for 24 hours to avoid repeated downloads.
	 *
	 * @param string $file_url The URL of the file to convert.
	 * @return array|null The inline_data part array, or null if conversion failed.
	 */
	private function convert_file_to_inline_data( string $file_url ): ?array {
		// Check for cached version.
		$cache_key = 'atml_gemini_file_' . md5( $file_url );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$file_content = null;
		$content_type = '';

		// Optimization: Try to read local files directly to avoid HTTP loopback.
		$upload_dir = wp_get_upload_dir();
		$base_url   = set_url_scheme( $upload_dir['baseurl'] );
		$check_url  = set_url_scheme( $file_url );

		if ( strpos( $check_url, $base_url ) === 0 ) {
			$local_path = str_replace( $base_url, $upload_dir['basedir'], $check_url );

			// Security: Resolve path and ensure it's within uploads directory.
			$real_base = realpath( $upload_dir['basedir'] );
			$real_path = realpath( $local_path );

			if ( $real_base && $real_path && strpos( $real_path, $real_base ) === 0 && file_exists( $real_path ) ) {
				$file_content = file_get_contents( $real_path );

				if ( $file_content !== false ) {
					if ( function_exists( 'mime_content_type' ) ) {
						$content_type = mime_content_type( $real_path );
					}

					if ( ! $content_type && function_exists( 'wp_check_filetype' ) ) {
						$filetype     = wp_check_filetype( $real_path );
						$content_type = $filetype['type'];
					}
				}
			}
		}

		// Fallback to HTTP request if local read failed or file is remote.
		if ( $file_content === null || $file_content === false ) {
			$response = wp_remote_get( $file_url, [
				'timeout' => 30,
			] );

			if ( is_wp_error( $response ) ) {
				return null;
			}

			$file_content = wp_remote_retrieve_body( $response );
			if ( empty( $file_content ) ) {
				return null;
			}

			$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		}

		// Clean up content-type (remove charset, etc.).
		if ( $content_type ) {
			$content_type = explode( ';', $content_type )[0];
			$content_type = trim( $content_type );
		}

		// Fallback: detect MIME type from file extension.
		if ( empty( $content_type ) || $content_type === 'application/octet-stream' ) {
			$content_type = $this->get_mime_type_from_url( $file_url );
		}

		// Validate that we have a supported MIME type for Gemini.
		if ( ! $this->is_supported_mime_type( $content_type ) ) {
			return null;
		}

		$base64_data = base64_encode( $file_content );

		$result = [
			'inline_data' => [
				'mime_type' => $content_type,
				'data'      => $base64_data,
			],
		];

		// Cache for 24 hours.
		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Get MIME type from URL based on file extension.
	 *
	 * @param string $url The file URL.
	 * @return string The MIME type.
	 */
	private function get_mime_type_from_url( string $url ): string {
		$path      = wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$mime_types = [
			// Images.
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'heic' => 'image/heic',
			'heif' => 'image/heif',
			// Documents.
			'pdf'  => 'application/pdf',
			// Audio.
			'mp3'  => 'audio/mp3',
			'wav'  => 'audio/wav',
			'aiff' => 'audio/aiff',
			'aac'  => 'audio/aac',
			'ogg'  => 'audio/ogg',
			'flac' => 'audio/flac',
			// Video.
			'mp4'  => 'video/mp4',
			'mpeg' => 'video/mpeg',
			'mov'  => 'video/quicktime',
			'avi'  => 'video/x-msvideo',
			'wmv'  => 'video/x-ms-wmv',
			'webm' => 'video/webm',
			// Text.
			'txt'  => 'text/plain',
			'html' => 'text/html',
			'css'  => 'text/css',
			'js'   => 'text/javascript',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'csv'  => 'text/csv',
			'md'   => 'text/markdown',
		];

		return $mime_types[ $extension ] ?? 'application/octet-stream';
	}

	/**
	 * Check if a MIME type is supported by Gemini for inline data.
	 *
	 * @param string $mime_type The MIME type to check.
	 * @return bool True if supported, false otherwise.
	 */
	private function is_supported_mime_type( string $mime_type ): bool {
		$supported = [
			// Images.
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/heic',
			'image/heif',
			// Documents.
			'application/pdf',
			// Audio.
			'audio/mp3',
			'audio/mpeg',
			'audio/wav',
			'audio/aiff',
			'audio/aac',
			'audio/ogg',
			'audio/flac',
			// Video.
			'video/mp4',
			'video/mpeg',
			'video/quicktime',
			'video/x-msvideo',
			'video/x-ms-wmv',
			'video/webm',
			// Text.
			'text/plain',
			'text/html',
			'text/css',
			'text/javascript',
			'application/json',
			'application/xml',
			'text/csv',
			'text/markdown',
		];

		return in_array( $mime_type, $supported, true );
	}

	/**
	 * Get messages from a conversation.
	 *
	 * For Gemini, conversations are stored locally in WordPress, not on Gemini's servers.
	 * This method is kept for interface compatibility but returns an empty array.
	 *
	 * @param string $conversation_id The conversation ID.
	 * @return array Empty array (use Conversation::list_messages instead).
	 */
	public function get_messages( string $conversation_id ): array {
		return [];
	}

	/**
	 * Generate an image using Gemini's image model.
	 *
	 * Uses the gemini-3-pro-image-preview model with the generateContent endpoint
	 * and responseModalities set to ["Image"]. The response contains base64-encoded
	 * image data which is saved to a temporary file and returned as a URL.
	 *
	 * @param string $prompt The image generation prompt.
	 * @param string $size   Image size (not used by Gemini, kept for interface compatibility).
	 * @return string|\WP_Error URL of the generated image or WP_Error on failure.
	 */
	public function generate_image( string $prompt, string $size = '1024x1024' ) {
		$image_model = 'gemini-3-pro-image-preview';

		$payload = [
			'contents'         => [
				[
					'parts' => [
						[ 'text' => 'Generate only an image, no text. ' . $prompt ],
					],
				],
			],
			'generationConfig' => [
				'responseModalities' => [ 'Text', 'Image' ],
				'imageConfig'        => [
					'aspectRatio' => '16:9',
				],
			],
		];

		$response = $this->request( $image_model, 'generateContent', $payload );

		if ( isset( $response['error'] ) ) {
			$error_message = $response['error']['message'] ?? __( 'Failed to generate image with Gemini.', 'antimanual' );
			return new \WP_Error( 'image_generation_failed', $error_message );
		}

		// Extract inline image data from the response.
		$parts = $response['candidates'][0]['content']['parts'] ?? [];

		$image_data = null;
		$mime_type  = 'image/png';

		foreach ( $parts as $part ) {
			if ( isset( $part['inlineData'] ) ) {
				$image_data = $part['inlineData']['data'] ?? null;
				$mime_type  = $part['inlineData']['mimeType'] ?? 'image/png';
				break;
			}
		}

		if ( empty( $image_data ) ) {
			return new \WP_Error( 'image_generation_failed', __( 'No image data returned from Gemini.', 'antimanual' ) );
		}

		$decoded = base64_decode( $image_data );

		if ( false === $decoded || empty( $decoded ) ) {
			return new \WP_Error( 'image_generation_failed', __( 'Failed to decode image data from Gemini.', 'antimanual' ) );
		}

		// Determine file extension from MIME type.
		$ext_map = [
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		];

		$extension = $ext_map[ $mime_type ] ?? 'png';

		// Save to a temporary file in wp-content/uploads.
		$upload_dir = wp_get_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'atml-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$filename  = 'gemini-image-' . wp_generate_uuid4() . '.' . $extension;
		$file_path = trailingslashit( $temp_dir ) . $filename;
		$file_url  = trailingslashit( $upload_dir['baseurl'] ) . 'atml-temp/' . $filename;

		$written = file_put_contents( $file_path, $decoded );

		if ( false === $written ) {
			return new \WP_Error( 'image_generation_failed', __( 'Failed to save generated image file.', 'antimanual' ) );
		}

		return $file_url;
	}

	/**
	 * Generate an embedding vector for the given content.
	 *
	 * @param string $content Content to generate embedding for.
	 * @return array|\WP_Error Embedding vector array or WP_Error on failure.
	 */
	public function generate_embedding( $content ) {
		$cache_key    = 'atml_gemini_embedding_' . sha1( $content );
		$cached_value = get_transient( $cache_key );

		if ( false !== $cached_value ) {
			return $cached_value;
		}

		$payload = [
			'content' => [
				'parts' => [
					[ 'text' => $content ],
				],
			],
		];

		$response = $this->request( $this->embed_model, 'embedContent', $payload );

		if ( isset( $response['error'] ) ) {
			return new \WP_Error( 'embedding_failed', $response['error']['message'] ?? __( 'Gemini embedding failed.', 'antimanual' ) );
		}

		$embedding = $response['embedding']['values'] ?? null;

		if ( ! atml_is_embedding( $embedding ) ) {
			return new \WP_Error( 'embedding_failed', __( 'Failed to generate embedding. Unexpected response format from Gemini.', 'antimanual' ) );
		}

		set_transient( $cache_key, $embedding, DAY_IN_SECONDS );

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
	 * Make an API request to Gemini.
	 *
	 * @param string $model   The model to use.
	 * @param string $action  The API action.
	 * @param array  $payload The request payload.
	 * @return array The decoded response.
	 */
	private function request( $model, $action, $payload ) {
		$url  = $this->url( $model, $action );
		$args = [
			'method'  => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'timeout' => 360,
		];

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'error' => [ 'message' => $response->get_error_message() ],
			];
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * List available Gemini models for the configured API key.
	 *
	 * Fetches models from Google's API and filters to only show models
	 * that support generateContent (chat models).
	 *
	 * @param string $type Type of models to return: 'chat', 'embedding', or 'all'.
	 * @return array|\WP_Error Array of available model names or WP_Error on failure.
	 */
	public function list_available_models( $type = 'all' ) {
		return self::fetch_available_models( $this->api_key, $type );
	}

	/**
	 * Fetch available Gemini models for a given API key.
	 *
	 * This is a static method that can be called without instantiating the class,
	 * useful for validating API keys during setup.
	 *
	 * @param string $api_key The Gemini API key to use.
	 * @param string $type    Type of models to return: 'chat', 'embedding', or 'all'.
	 * @return array|\WP_Error Array of available model data or WP_Error on failure.
	 */
	public static function fetch_available_models( $api_key, $type = 'all' ) {
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'API key is required.', 'antimanual' ) );
		}

		$url = self::$base_url . '?key=' . $api_key . '&pageSize=100';

		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'request_failed', $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 400 === $http_code || 401 === $http_code ) {
			return new \WP_Error( 'invalid_api_key', __( 'Invalid API key. Please check your Gemini API key.', 'antimanual' ) );
		}

		if ( 403 === $http_code ) {
			return new \WP_Error( 'access_denied', __( 'Access denied. Your API key may not have the required permissions.', 'antimanual' ) );
		}

		if ( $http_code < 200 || $http_code >= 300 ) {
			$error_message = $body['error']['message'] ?? __( 'Failed to fetch models from Gemini API.', 'antimanual' );
			return new \WP_Error( 'api_error', $error_message );
		}

		$models      = $body['models'] ?? [];
		$chat_models = [];

		foreach ( $models as $model ) {
			$name              = $model['name'] ?? '';
			$display_name      = $model['displayName'] ?? '';
			$supported_actions = $model['supportedGenerationMethods'] ?? [];

			// Extract just the model ID from "models/gemini-2.5-flash" format.
			$model_id = str_replace( 'models/', '', $name );

			// Generate label from display name or format from model ID.
			$label = ! empty( $display_name ) ? $display_name : self::format_model_label( $model_id );

			if ( in_array( 'generateContent', $supported_actions, true ) ) {
				$chat_models[] = [
					'value' => $model_id,
					'label' => $label,
				];
			}
		}

		// Sort models by value (name) in descending order (newest first).
		usort( $chat_models, fn( $a, $b ) => strcmp( $b['value'], $a['value'] ) );

		if ( 'chat' === $type ) {
			return $chat_models;
		}

		return [
			'valid'       => true,
			'chat_models' => $chat_models,
		];
	}

	/**
	 * Format a model ID into a human-readable label.
	 *
	 * @param string $model_id The model ID.
	 * @return string The formatted label.
	 */
	private static function format_model_label( string $model_id ): string {
		$label = str_replace( [ '-', '_' ], ' ', $model_id );
		$label = ucwords( $label );

		return $label;
	}

	/**
	 * Get cached available models or fetch from API.
	 *
	 * Models are cached in a transient for 1 hour to reduce API calls.
	 *
	 * @param string $type Type of models to return: 'chat', 'embedding', or 'all'.
	 * @return array|\WP_Error Array of available model data or WP_Error on failure.
	 */
	public function get_cached_available_models( $type = 'all' ) {
		$cache_key = 'antimanual_gemini_models_' . md5( $this->api_key );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			if ( 'chat' === $type ) {
				return $cached['chat'] ?? [];
			}
			if ( 'embedding' === $type ) {
				return $cached['embedding'] ?? [];
			}
			return $cached;
		}

		$models = $this->list_available_models( 'all' );

		if ( is_wp_error( $models ) ) {
			return $models;
		}

		// Cache for 1 hour.
		set_transient( $cache_key, $models, HOUR_IN_SECONDS );

		if ( 'chat' === $type ) {
			return $models['chat'] ?? [];
		}

		if ( 'embedding' === $type ) {
			return $models['embedding'] ?? [];
		}

		return $models;
	}

	/**
	 * Clear the cached available models.
	 *
	 * @return void
	 */
	public function clear_models_cache() {
		$cache_key = 'antimanual_gemini_models_' . md5( $this->api_key );
		delete_transient( $cache_key );
	}
}
