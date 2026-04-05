<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'EazyDocsPro_Install_Core' ) ) :

	/**
	 * EazyDocs Upsell Class
	 */
	class EazyDocsPro_Install_Core {

		/**
		 * Instantiate the class
		 *
		 * @param string $affiliate
		 */
		function __construct() {
			add_action( 'init', [ $this, 'init_hooks' ] );
		}

		/**
		 * Initialize the hooks
		 *
		 * @return void
		 */
		public function init_hooks() {

			if ( class_exists( 'EazyDocs' ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			add_action( 'admin_notices', [ $this, 'activation_notice' ] );

			add_action( 'wp_ajax_EazyDocsPro_Install_Core_installer', [ $this, 'install_eazydocs_core' ] );
		}

		/**
		 * Show the plugin installation notice
		 *
		 * @return void
		 */
		public function activation_notice() {
			?>
			<script>
                jQuery(document).ready(function ($) {
                    $('#eazydocs-install-core').on('click', function (e) {
                        var self = $(this);
                        e.preventDefault();
                        self.addClass('install-now updating-message');
                        self.text('<?php echo esc_js( 'Installing...' ); ?>');

                        $.ajax({
                            url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                            type: 'post',
                            data: {
                                action: 'EazyDocsPro_Install_Core_installer',
            					_wpnonce: '<?php echo esc_js( wp_create_nonce( 'EazyDocsPro_Install_Core_installer' ) ); ?>',
                            },
                            success: function (response) {
            					self.text('<?php echo esc_js( __( 'Installed', 'eazydocs-pro' ) ); ?>');
            					window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=eazydocs' ) ); ?>';
                            },
                            error: function (error) {
                                self.removeClass('install-now updating-message');
                                alert(error);
                            },
                            complete: function () {
                                self.attr('disabled', 'disabled');
                                self.removeClass('install-now updating-message');
                            }
                        });
                    });
                });
			</script>
			<?php
		}


		/**
		 * Fail if plugin installtion/activation fails
		 *
		 * @param Object $thing
		 *
		 * @return void
		 */
		public function fail_on_error( $thing ) {
			if ( is_wp_error( $thing ) ) {
				wp_send_json_error( $thing->get_error_message() );
			}
		}

		/**
		 * Install EazyDocs
		 *
		 * @return void
		 */
		public function install_eazydocs_core() {
			check_ajax_referer( 'EazyDocsPro_Install_Core_installer' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'You don\'t have permission to install the plugins', 'eazydocs-pro' ) );
			}
			$is_installed = isset( $_POST['installed'] ) ? $_POST['installed'] : false;
			$nx_status    = $this->install_plugin( 'eazydocs', 'eazydocs.php' );
			$this->fail_on_error( $nx_status );

			wp_send_json_success();
		}

		/**
		 * Install and activate a plugin
		 *
		 * @param string $slug
		 * @param string $file
		 *
		 * @return WP_Error|null
		 */
		public function install_plugin( $slug, $file ) {
			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$plugin_basename = $slug . '/' . $file;

			// if exists and not activated
			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_basename ) ) {
				return activate_plugin( $plugin_basename );
			}

			// seems like the plugin doesn't exists. Download and activate it
			$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );

			$api    = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
			$result = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return activate_plugin( $plugin_basename );
		}
	}
endif;