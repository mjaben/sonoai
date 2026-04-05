<?php
namespace eazyDocsPro\User;

/**
 * Class Profile_Fields
 * Handles the custom user profile fields for EazyDocs Pro plugin
 * 
 * @package eazyDocsPro\User
 */
class Profile_Fields {

    /**
     * Initialize the class and set up hooks
     */
    public function __construct() {
        // Add custom fields to user profile
        add_action( 'show_user_profile', [ $this, 'add_custom_user_profile_fields' ] );
        add_action( 'edit_user_profile', [ $this, 'add_custom_user_profile_fields' ] );

        // Save custom fields from user profile
        add_action( 'personal_options_update', [ $this, 'save_custom_user_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_custom_user_profile_fields' ] );
    }

    /**
     * Add custom fields to user profile
     *
     * @param WP_User $user Current user object
     */
    public function add_custom_user_profile_fields( $user ) {
        // Get existing values
        $job_title = get_user_meta( $user->ID, 'ezd_user_job_title', true );
        $bio_details = get_user_meta( $user->ID, 'ezd_user_bio_details', true );
        ?>
        <h2><?php esc_html_e( 'EazyDocs Profile Information', 'eazydocs-pro' ); ?></h2>
        <table class="form-table">
            <tr>
                <th>
                    <label for="ezd_user_job_title"><?php esc_html_e( 'Job Title', 'eazydocs-pro' ); ?></label>
                </th>
                <td>
                    <input type="text" name="ezd_user_job_title" id="ezd_user_job_title" value="<?php echo esc_attr( $job_title ); ?>" class="regular-text" />
                    <p class="description">
                        <?php esc_html_e( 'Enter your job title to be displayed on your profile page.', 'eazydocs-pro' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th>
                    <label for="ezd_user_bio_details"><?php esc_html_e( 'Detailed Biography', 'eazydocs-pro' ); ?></label>
                </th>
                <td>
                    <?php
                    $editor_settings = array(
                        'textarea_name' => 'ezd_user_bio_details',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny'         => false,
                        'quicktags'     => true,
                        'tinymce'       => array(
                            'toolbar1' => 'bold,italic,underline,strikethrough,bullist,numlist,blockquote,hr,alignleft,aligncenter,alignright,link,unlink,formatselect',
                            'toolbar2' => '',
                        ),
                    );
                    wp_editor( $bio_details, 'ezd_user_bio_details', $editor_settings );
                    ?>
                    <p class="description">
                        <?php esc_html_e( 'Enter a detailed biography to be displayed on your profile page. You can use formatting options like bold, italic, lists, and links. Use the Biographical Info field above for a short bio.', 'eazydocs-pro' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th>
                    <label for="ezd_user_github"><?php esc_html_e( 'GitHub', 'eazydocs-pro' ); ?></label>
                </th>
                <td>
                    <input type="url" name="ezd_user_github" id="ezd_user_github" value="<?php echo esc_url( get_user_meta( $user->ID, 'ezd_user_github', true ) ); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e( 'Enter your GitHub profile URL.', 'eazydocs-pro' ); ?></p>
                </td>
            </tr>
            <tr>
                <th>
                    <label for="ezd_user_linkedin"><?php esc_html_e( 'LinkedIn', 'eazydocs-pro' ); ?></label>
                </th>
                <td>
                    <input type="url" name="ezd_user_linkedin" id="ezd_user_linkedin" value="<?php echo esc_url( get_user_meta( $user->ID, 'ezd_user_linkedin', true ) ); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e( 'Enter your LinkedIn profile URL.', 'eazydocs-pro' ); ?></p>
                </td>
            </tr>
            <tr>
                <th>
                    <label for="ezd_user_twitter"><?php esc_html_e( 'Twitter/X', 'eazydocs-pro' ); ?></label>
                </th>
                <td>
                    <input type="url" name="ezd_user_twitter" id="ezd_user_twitter" value="<?php echo esc_url( get_user_meta( $user->ID, 'ezd_user_twitter', true ) ); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e( 'Enter your Twitter/X profile URL.', 'eazydocs-pro' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save custom user profile fields
     *
     * @param int $user_id User ID
     * @return bool
     */
    public function save_custom_user_profile_fields( $user_id ) {
        // Check for permissions
        if ( !current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }

        // Update job title
        if ( isset( $_POST['ezd_user_job_title'] ) ) {
            update_user_meta( $user_id, 'ezd_user_job_title', sanitize_text_field( $_POST['ezd_user_job_title'] ) );
        }

        // Update detailed biography
        if ( isset( $_POST['ezd_user_bio_details'] ) ) {
            // Apply wp_kses to allow only specific HTML elements
            $allowed_html = array(
                'a'          => array(
                    'href'     => array(),
                    'title'    => array(),
                    'target'   => array(),
                    'rel'      => array(),
                ),
                'br'         => array(),
                'em'         => array(),
                'strong'     => array(),
                'b'          => array(),
                'i'          => array(),
                'u'          => array(),
                'p'          => array(),
                'ul'         => array(),
                'ol'         => array(),
                'li'         => array(),
                'h1'         => array(),
                'h2'         => array(),
                'h3'         => array(),
                'h4'         => array(),
                'h5'         => array(),
                'h6'         => array(),
                'blockquote' => array(),
                'code'       => array(),
                'pre'        => array(),
                'hr'         => array(),
                'table'      => array(),
                'thead'      => array(),
                'tbody'      => array(),
                'tr'         => array(),
                'th'         => array(),
                'td'         => array(),
            );

            update_user_meta( $user_id, 'ezd_user_bio_details', wp_kses( $_POST['ezd_user_bio_details'], $allowed_html ) );
        }

        // Update GitHub
        if ( isset( $_POST['ezd_user_github'] ) ) {
            update_user_meta( $user_id, 'ezd_user_github', esc_url_raw( $_POST['ezd_user_github'] ) );
        }
        // Update LinkedIn
        if ( isset( $_POST['ezd_user_linkedin'] ) ) {
            update_user_meta( $user_id, 'ezd_user_linkedin', esc_url_raw( $_POST['ezd_user_linkedin'] ) );
        }
        // Update Twitter/X
        if ( isset( $_POST['ezd_user_twitter'] ) ) {
            update_user_meta( $user_id, 'ezd_user_twitter', esc_url_raw( $_POST['ezd_user_twitter'] ) );
        }

        return true;
    }
}
