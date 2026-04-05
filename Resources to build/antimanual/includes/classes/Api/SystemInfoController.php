<?php

namespace Antimanual\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System Info REST endpoint.
 *
 * Returns server environment details and WordPress configuration
 * alongside Antimanual's minimum requirements for comparison.
 */
class SystemInfoController {

	/**
	 * Antimanual minimum requirements.
	 */
	const REQUIREMENTS = [
		'php_version'       => '7.4',
		'wp_version'        => '5.0',
		'mysql_version'     => '5.6',
		'max_execution_time' => 30,
		'memory_limit'      => 128, // MB
		'upload_max_filesize' => 2, // MB
	];

	/**
	 * Register REST routes.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace ) {
		register_rest_route( $namespace, '/system-info', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_system_info' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );
	}

	/**
	 * Gather and return system information.
	 *
	 * @return \WP_REST_Response The system info response.
	 */
	public function get_system_info() {
		global $wpdb;

		$server_info = $this->get_server_info();
		$wp_info     = $this->get_wordpress_info();
		$db_info     = $this->get_database_info( $wpdb );
		$plugin_info = $this->get_plugin_info();
		$tables_info = $this->get_plugin_tables( $wpdb );

		return rest_ensure_response( [
			'success'      => true,
			'data'         => [
				'server'       => $server_info,
				'wordpress'    => $wp_info,
				'database'     => $db_info,
				'plugin'       => $plugin_info,
				'tables'       => $tables_info,
				'requirements' => self::REQUIREMENTS,
			],
		] );
	}

	/**
	 * Collect server environment details.
	 *
	 * @return array Server information.
	 */
	private function get_server_info() {
		$memory_limit_raw = ini_get( 'memory_limit' );

		return [
			'php_version'         => phpversion(),
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unknown', 'antimanual' ),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'memory_limit'        => $memory_limit_raw,
			'memory_limit_mb'     => $this->convert_to_mb( $memory_limit_raw ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'upload_max_mb'       => $this->convert_to_mb( ini_get( 'upload_max_filesize' ) ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'max_input_vars'      => (int) ini_get( 'max_input_vars' ),
			'php_sapi'            => php_sapi_name(),
			'php_extensions'      => $this->get_required_extensions(),
			'curl_version'        => function_exists( 'curl_version' ) ? curl_version()['version'] : __( 'Not available', 'antimanual' ),
			'openssl_version'     => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : __( 'Not available', 'antimanual' ),
		];
	}

	/**
	 * Collect WordPress environment details.
	 *
	 * @return array WordPress information.
	 */
	private function get_wordpress_info() {
		global $wp_version;

		$theme = wp_get_theme();

		return [
			'version'          => $wp_version,
			'site_url'         => get_site_url(),
			'home_url'         => get_home_url(),
			'multisite'        => is_multisite(),
			'permalink_structure' => get_option( 'permalink_structure' ) ?: __( 'Plain', 'antimanual' ),
			'debug_mode'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : __( 'Not set', 'antimanual' ),
			'max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : __( 'Not set', 'antimanual' ),
			'language'         => get_locale(),
			'timezone'         => wp_timezone_string(),
			'active_theme'     => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'active_plugins'   => $this->get_active_plugins_count(),
		];
	}

	/**
	 * Collect database details.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 * @return array Database information.
	 */
	private function get_database_info( $wpdb ) {
		$db_version = $wpdb->db_version();
		$db_server  = $wpdb->db_server_info();

		// Determine if MariaDB.
		$is_mariadb = false !== stripos( $db_server, 'mariadb' );

		return [
			'version'     => $db_version,
			'is_mariadb'  => $is_mariadb,
			'charset'     => $wpdb->charset,
			'collate'     => $wpdb->collate,
			'prefix'      => $wpdb->prefix,
			'max_allowed' => $this->get_db_variable( $wpdb, 'max_allowed_packet' ),
		];
	}

	/**
	 * Collect plugin-specific info.
	 *
	 * @return array Plugin information.
	 */
	private function get_plugin_info() {
		return [
			'version'     => defined( 'ANTIMANUAL_VERSION' ) ? ANTIMANUAL_VERSION : __( 'Unknown', 'antimanual' ),
			'is_pro'      => function_exists( 'atml_is_pro' ) ? atml_is_pro() : false,
		];
	}

	/**
	 * Check required PHP extensions and return their availability.
	 *
	 * @return array Extension name => bool loaded.
	 */
	private function get_required_extensions() {
		$extensions = [
			'curl',
			'json',
			'mbstring',
			'openssl',
			'zip',
			'dom',
			'xml',
			'gd',
		];

		$result = [];
		foreach ( $extensions as $ext ) {
			$result[ $ext ] = extension_loaded( $ext );
		}

		return $result;
	}

	/**
	 * Convert PHP ini shorthand notation (e.g. '128M') to megabytes.
	 *
	 * @param string $value The ini value.
	 * @return int Value in megabytes.
	 */
	private function convert_to_mb( $value ) {
		$value = trim( $value );

		if ( empty( $value ) || '-1' === $value ) {
			return -1;
		}

		$unit = strtolower( substr( $value, -1 ) );
		$num  = (int) $value;

		switch ( $unit ) {
			case 'g':
				return $num * 1024;
			case 'k':
				return (int) round( $num / 1024 );
			case 'm':
			default:
				return $num;
		}
	}

	/**
	 * Get a MySQL server variable value.
	 *
	 * @param \wpdb  $wpdb     WordPress database object.
	 * @param string $variable Variable name.
	 * @return string Variable value or empty string.
	 */
	private function get_db_variable( $wpdb, $variable ) {
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SHOW VARIABLES LIKE %s', $variable )
		);

		return $result ? $result->Value : '';
	}

	/**
	 * Get count of active plugins.
	 *
	 * @return int Number of active plugins.
	 */
	private function get_active_plugins_count() {
		$active_plugins = get_option( 'active_plugins', [] );

		return is_array( $active_plugins ) ? count( $active_plugins ) : 0;
	}

	/**
	 * Get information about Antimanual database tables.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 * @return array List of table details.
	 */
	private function get_plugin_tables( $wpdb ) {
		$prefix = $wpdb->prefix;

		// All custom tables created by Antimanual.
		$table_definitions = [
			[
				'name'        => $prefix . 'antimanual_embeddings',
				'description' => __( 'Knowledge Base Embeddings', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'antimanual_query_votes',
				'description' => __( 'Chatbot Query Votes', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'antimanual_usage',
				'description' => __( 'AI Usage Tracking', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'antimanual_auto_posting_queue',
				'description' => __( 'Auto-Posting Queue', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'atml_email_subscribers',
				'description' => __( 'Email Subscribers', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'atml_email_campaigns',
				'description' => __( 'Email Campaigns', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'atml_email_send_log',
				'description' => __( 'Email Send Log', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'atml_email_sequence_steps',
				'description' => __( 'Email Sequence Steps', 'antimanual' ),
			],
			[
				'name'        => $prefix . 'atml_email_sequence_log',
				'description' => __( 'Email Sequence Log', 'antimanual' ),
			],
		];

		// Batch-fetch table status in a single query.
		$status_rows  = $wpdb->get_results(
			$wpdb->prepare( 'SHOW TABLE STATUS FROM `' . $wpdb->dbname . '` WHERE Name LIKE %s OR Name LIKE %s', $prefix . 'antimanual%', $prefix . 'atml_%' ),
			ARRAY_A
		);

		// Index by table name for O(1) lookups.
		$status_map = [];
		if ( $status_rows ) {
			foreach ( $status_rows as $row ) {
				$status_map[ $row['Name'] ] = $row;
			}
		}

		$tables = [];
		foreach ( $table_definitions as $def ) {
			$exists = isset( $status_map[ $def['name'] ] );
			$info   = $exists ? $status_map[ $def['name'] ] : null;

			$tables[] = [
				'name'        => $def['name'],
				'description' => $def['description'],
				'exists'      => $exists,
				'rows'        => $exists ? (int) ( $info['Rows'] ?? 0 ) : 0,
				'size'        => $exists ? $this->format_bytes( ( (int) ( $info['Data_length'] ?? 0 ) ) + ( (int) ( $info['Index_length'] ?? 0 ) ) ) : '0 B',
				'engine'      => $exists ? ( $info['Engine'] ?? '' ) : '',
			];
		}

		return $tables;
	}

	/**
	 * Format bytes into a human-readable string.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string Formatted size.
	 */
	private function format_bytes( $bytes ) {
		if ( $bytes <= 0 ) {
			return '0 B';
		}

		$units = [ 'B', 'KB', 'MB', 'GB' ];
		$index = (int) floor( log( $bytes, 1024 ) );
		$index = min( $index, count( $units ) - 1 );

		return round( $bytes / ( 1024 ** $index ), 1 ) . ' ' . $units[ $index ];
	}
}
