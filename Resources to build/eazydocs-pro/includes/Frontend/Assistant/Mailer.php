<?php
namespace eazyDocsPro\Frontend\Assistant;

class Mailer {

    public function __construct() {
        add_action('wp_ajax_eazydocs_send_message',  [ $this, 'eazydocs_send_message' ] );
        add_action('wp_ajax_nopriv_eazydocs_send_message', [ $this, 'eazydocs_send_message' ] );
        add_filter('wp_mail_content_type', function () {
            return 'text/html';
        });
    }

    public function eazydocs_send_message() {

		$admin_email = ezd_get_opt('assistant_tab_settings');
		$admin_email = $admin_email['assistant_contact_mail'] ?? get_option( 'admin_email' );

        // Avoid multiple execution
        if ( did_action('eazydocs_send_message_once') ) {
            wp_send_json_error("Duplicate request blocked.");
        }
        do_action('eazydocs_send_message_once');

        $name    = sanitize_text_field($_POST['name'] ?? '');
        $email   = sanitize_email($_POST['email'] ?? '');
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = sanitize_textarea_field($_POST['comment'] ?? '');

        if ( ! is_user_logged_in() ) {
			if ( ! $email ) {
				wp_send_json_error( __( 'Please enter a valid email address.', 'eazydocs-pro' ) );
			}
		} else {
			$email = wp_get_current_user()->user_email;
		}

		if ( empty( $subject ) ) {
			wp_send_json_error( __( 'Please provide a subject line.', 'eazydocs-pro' ) );
		}

		if ( empty( $message ) ) {
			wp_send_json_error( __( 'Please provide the message details.', 'eazydocs-pro' ) );
		}

        // ---------------------------
        // Akismet check on live site
        // ---------------------------
        $akismet_blocked = false;

        if ( class_exists('Akismet') && get_option('wordpress_api_key') ) {

            if ( function_exists('akismet_http_post') ) {
                global $akismet_api_host, $akismet_api_port;

                $data = [
                    'blog'                 => get_option('home'),
                    'comment_type'         => 'contact-form',
                    'comment_author'       => $name,
                    'comment_author_email' => $email,
                    'comment_content'      => $message,
                    'user_ip'              => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent'           => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'referrer'             => $_SERVER['HTTP_REFERER'] ?? '',
                ];

                $query_string = http_build_query($data);

                // Akismet requires 4 parameters: query, path, host, port
                $response = akismet_http_post($query_string, '/1.1/comment-check', $akismet_api_host, $akismet_api_port);
                if ( is_array($response) && isset($response[1]) && trim($response[1]) === 'true' ) {
                    $akismet_blocked = true;
                    wp_send_json_error("Your message was flagged as spam.");
                }
            }
        }
        
        // Proceed to send the mail
		$wp_email 	= 'wordpress@' . preg_replace( '#^www\.#', '', strtolower( $_SERVER['SERVER_NAME'] ) );
		$blogname 	= wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$email_to 	= $admin_email;

		/* translators: Contact query subject */
		$subject 	 = sprintf( __( '[%1$s] Contact Query: "%2$s"', 'eazydocs-pro' ), $blogname, $subject );
		/* translators: Contact query email */
		$email_body  = sprintf( __( 'New Message From EazyDocs Pro Assistant. Source: %s', 'eazydocs-pro' ), $wp_email ) . "<br>";
		/* translators: Contact query From */
		$email_body .= sprintf( __( 'From: %s', 'eazydocs-pro' ), $email ) . "<br><br>";
		/* translators: Contact query Message */
		$email_body .= sprintf( __( 'Message: %s', 'eazydocs-pro' ), "<br>" . $message ) . "<br>";
		
		$from 		= "From: \"{$author}\" <{$wp_email}>";
		$reply_to 	= "Reply-To: \"{$email}\" <{$email}>";
		$sent       = wp_mail( $email_to, wp_specialchars_decode( $subject ), $email_body );

        if ( ! $sent ) {
            wp_send_json_error("Mail sending failed.");
        }
        wp_send_json_success("Message sent successfully!");
    }
}