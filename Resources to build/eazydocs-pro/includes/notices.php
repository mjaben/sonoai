<?php
/**
 * Notice
 * Activate the EazyDocs
 * @return void
 */

include_once EAZYDOCSPRO_PATH . '/includes/Admin/core_installer.php';
new EazyDocsPro_Install_Core('');

add_action( 'admin_notices', function(){

	$has_installed = get_plugins();
	$button_text = isset( $has_installed['eazydocs/eazydocs.php'] ) ? __( 'Activate Now!', 'eazydocs-pro' ) : __( 'Install Now!', 'eazydocs-pro' );

	if( ! class_exists( 'EazyDocs' ) ) :
		?>
        <div class="error notice is-dismissible bottom-10">
            <p>
                <?php 
                echo wp_kses_post( sprintf(
                    '<strong>%1$s</strong> %2$s <strong>%3$s</strong> %4$s',
                    __( 'EazyDocs Pro', 'eazydocs-pro' ),
                    __( 'requires the', 'eazydocs-pro' ),
                    __( 'EazyDocs', 'eazydocs-pro' ),
                    __( 'Free version plugin to be installed and activated. Please get the plugin now!', 'eazydocs-pro' )
                ) );
                ?>
                
                <button id="eazydocs-install-core" class="button button-primary">
                    <?php echo esc_html($button_text); ?>
                </button>
            </p>
        </div>
	<?php
	endif;

});