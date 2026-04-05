<?php
/**
 * Test Email Sender for Subscription Notifications
 *
 * Allows admins to preview subscription email templates.
 *
 * @package EazyDocs Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register AJAX handler for sending test subscription emails.
 */
add_action( 'wp_ajax_ezd_send_test_subscription_email', 'ezd_send_test_subscription_email' );

/**
 * Send a test subscription notification email.
 *
 * @return void
 */
function ezd_send_test_subscription_email() {
	check_ajax_referer( 'ezd_test_email_nonce', 'nonce' );

	// Verify nonce and capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to send test emails.', 'eazydocs-pro' ),
		) );
	}
	
	$email_type = isset( $_POST['email_type'] ) ? sanitize_text_field( $_POST['email_type'] ) : 'new_content';
	$to_email   = isset( $_POST['to_email'] ) ? sanitize_email( $_POST['to_email'] ) : get_option( 'admin_email' );
	
	if ( ! is_email( $to_email ) ) {
		wp_send_json_error( array(
			'message' => __( 'Please provide a valid email address.', 'eazydocs-pro' ),
		) );
	}
	
	// Get current user info for personalization
	$current_user = wp_get_current_user();
	$settings     = ezd_get_email_template_settings();
	$site_name    = $settings['site_name'];
	
	// Find a sample doc post for the test email
	$sample_doc = get_posts( array(
		'post_type'      => 'docs',
		'posts_per_page' => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	
	$sample_post_id   = ! empty( $sample_doc ) ? $sample_doc[0]->ID : 0;
	$sample_parent_id = $sample_post_id ? ezd_get_doc_parent_id( $sample_post_id ) : 0;
	
	// If no docs exist, create dummy content info
	if ( ! $sample_post_id ) {
		$sample_post_id   = 1;
		$sample_parent_id = 1;
	}
	
	$subject = '';
	$message = '';
	
	switch ( $email_type ) {
		case 'confirmation':
			$subject = sprintf(
				/* translators: %s: Site name */
				__( '[%s] TEST: Confirm Your Subscription', 'eazydocs-pro' ),
				$site_name
			);
			
			$message = ezd_get_confirmation_email( array(
				'doc_id'          => $sample_parent_id ?: $sample_post_id,
				'subscriber_name' => $current_user->display_name,
				'token'           => wp_generate_password( 32, false ),
				'redirect_url'    => $sample_post_id ? get_permalink( $sample_post_id ) : home_url(),
			) );
			break;
			
		case 'unsubscribe':
			$subject = sprintf(
				/* translators: %s: Site name */
				__( '[%s] TEST: Unsubscription Confirmed', 'eazydocs-pro' ),
				$site_name
			);
			
			$message = ezd_get_unsubscribe_confirmation_email( array(
				'doc_id'          => $sample_parent_id ?: $sample_post_id,
				'subscriber_name' => $current_user->display_name,
			) );
			break;
			
		case 'new_content':
		default:
			$subject = sprintf(
				/* translators: %s: Site name */
				__( '[%s] TEST: New Documentation Update', 'eazydocs-pro' ),
				$site_name
			);
			
			$message = ezd_get_new_content_email( array(
				'post_id'         => $sample_post_id,
				'parent_doc_id'   => $sample_parent_id,
				'subscriber_name' => $current_user->display_name,
				'token'           => wp_generate_password( 32, false ),
			) );
			break;
	}
	
	// Email headers
	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>',
	);
	
	// Send the test email
	$sent = wp_mail( $to_email, $subject, $message, $headers );
	
	if ( $sent ) {
		wp_send_json_success( array(
			'message'   => sprintf(
				/* translators: %s: Email address */
				__( 'Test email sent successfully to %s!', 'eazydocs-pro' ),
				$to_email
			),
			'recipient' => $to_email,
			'type'      => $email_type,
		) );
	} else {
		wp_send_json_error( array(
			'message' => __( 'Failed to send test email. Please check your mail server configuration.', 'eazydocs-pro' ),
		) );
	}
}

/**
 * Add test email button to subscription settings.
 *
 * @return void
 */
add_action( 'admin_footer', 'ezd_add_test_email_ui' );

/**
 * Output the test email UI and JavaScript.
 *
 * @return void
 */
function ezd_add_test_email_ui() {
	$screen = get_current_screen();
	
	// Only show on EazyDocs settings page
	if ( ! $screen || strpos( $screen->id, 'eazydocs' ) === false ) {
		return;
	}
	
	$admin_email = get_option( 'admin_email' );
	?>
	<style>
		.ezd-test-email-section {
			background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
			border: 1px solid #e0e7ff;
			border-radius: 12px;
			padding: 24px;
			margin: 40px;
			max-width: 600px;
		}
		.ezd-test-email-section h4 {
			margin: 0 0 16px;
			font-size: 15px;
			font-weight: 600;
			color: #1e293b;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.ezd-test-email-section h4::before {
			content: '📧';
			font-size: 18px;
		}
		.ezd-test-email-row {
			display: flex;
			gap: 12px;
			margin-bottom: 12px;
			flex-wrap: wrap;
		}
		.ezd-test-email-row input[type="email"] {
			flex: 1;
			min-width: 200px;
			padding: 10px 14px;
			border: 1px solid #cbd5e1;
			border-radius: 8px;
			font-size: 14px;
			transition: border-color 0.2s, box-shadow 0.2s;
		}
		.ezd-test-email-row input[type="email"]:focus {
			outline: none;
			border-color: #4f46e5;
			box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
		}
		.ezd-test-email-row select {
			padding: 10px 14px;
			border: 1px solid #cbd5e1;
			border-radius: 8px;
			font-size: 14px;
			background: #fff;
			min-width: 180px;
		}
		.ezd-test-email-btn {
			padding: 10px 20px;
			background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
			color: #fff;
			border: none;
			border-radius: 8px;
			font-size: 14px;
			font-weight: 500;
			cursor: pointer;
			transition: transform 0.2s, box-shadow 0.2s;
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}
		.ezd-test-email-btn:hover {
			transform: translateY(-1px);
			box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
		}
		.ezd-test-email-btn:disabled {
			opacity: 0.6;
			cursor: not-allowed;
			transform: none;
		}
		.ezd-test-email-btn .spinner {
			display: none;
		}
		.ezd-test-email-btn.loading .spinner {
			display: inline-block;
		}
		.ezd-test-email-btn.loading .btn-text {
			display: none;
		}
		.ezd-test-email-result {
			margin-top: 12px;
			padding: 12px 16px;
			border-radius: 8px;
			font-size: 14px;
			display: none;
		}
		.ezd-test-email-result.success {
			background: #f0fdf4;
			border: 1px solid #bbf7d0;
			color: #166534;
		}
		.ezd-test-email-result.error {
			background: #fef2f2;
			border: 1px solid #fecaca;
			color: #991b1b;
		}
		.ezd-test-email-hint {
			font-size: 13px;
			color: #64748b;
			margin-top: 8px;
		}
	</style>
	
	<script>
	jQuery(document).ready(function($) {
		// Find the subscription settings section - try multiple selectors
		var $subscriptionSection = null;
		var selectors = [
			'.csf-section-subscriptions_opt',
			'#csf-section-subscriptions_opt',
			'[data-section-id="subscriptions_opt"]',
			'.csf-nav-item a:contains("Update Notifications")' // Find nav item
		];
		
		for (var i = 0; i < selectors.length; i++) {
			$subscriptionSection = $(selectors[i]);
			if ($subscriptionSection.length) break;
		}
		
		// If we found a nav item, find its corresponding content section
		if ($subscriptionSection.length && $subscriptionSection.is('a')) {
			var href = $subscriptionSection.attr('href');
			if (href) {
				$subscriptionSection = $(href);
			}
		}
		
		// Alternative: find field containing "subscriptions_tab"
		if (!$subscriptionSection || !$subscriptionSection.length) {
			$subscriptionSection = $('[data-depend-id="subscriptions_tab"]').closest('.csf-section');
		}
		
		// Another fallback: find by tabbed field containing Email Design
		if (!$subscriptionSection || !$subscriptionSection.length) {
			$subscriptionSection = $('.csf-field-tabbed:has(.csf-tab-item:contains("Email Design"))').closest('.csf-section');
		}
		
		var testEmailHtml = `
			<div class="ezd-test-email-section">
				<h4><?php esc_html_e( 'Preview Subscription Emails', 'eazydocs-pro' ); ?></h4>
				<div class="ezd-test-email-row">
					<input type="email" id="ezd-test-email-address" value="<?php echo esc_attr( $admin_email ); ?>" placeholder="<?php esc_attr_e( 'Enter email address', 'eazydocs-pro' ); ?>" />
					<select id="ezd-test-email-type">
						<option value="new_content"><?php esc_html_e( 'New Content Alert', 'eazydocs-pro' ); ?></option>
						<option value="confirmation"><?php esc_html_e( 'Confirmation Email', 'eazydocs-pro' ); ?></option>
						<option value="unsubscribe"><?php esc_html_e( 'Unsubscribe Notice', 'eazydocs-pro' ); ?></option>
					</select>
					<button type="button" class="ezd-test-email-btn" id="ezd-send-test-email">
						<span class="btn-text"><?php esc_html_e( 'Send Test Email', 'eazydocs-pro' ); ?></span>
						<span class="spinner"></span>
					</button>
				</div>
				<div class="ezd-test-email-result" id="ezd-test-email-result"></div>
				<p class="ezd-test-email-hint">
					<?php esc_html_e( 'Send yourself a test email to preview how your subscription notifications will look.', 'eazydocs-pro' ); ?>
				</p>
			</div>
		`;
		
		// Append after the tabbed section in the subscription settings
		var $tabbedSection = null;
		if ($subscriptionSection && $subscriptionSection.length) {
			$tabbedSection = $subscriptionSection.find('.csf-field-tabbed').first();
		}
		
		// Fallback: Just find the tabbed section with Email Design directly
		if (!$tabbedSection || !$tabbedSection.length) {
			$tabbedSection = $('.csf-field-tabbed:has(.csf-tabbed-nav a:contains("Email Design"))').first();
		}
		
		if ($tabbedSection && $tabbedSection.length) {
			$tabbedSection.after(testEmailHtml);
		} else if ($subscriptionSection && $subscriptionSection.length) {
			$subscriptionSection.append(testEmailHtml);
		} else {
			// Last resort: append after any switcher for subscriptions
			var $switcher = $('[data-depend-id="subscriptions"]').closest('.csf-field');
			if ($switcher.length) {
				// Find parent section and append there
				var $parentSection = $switcher.closest('.csf-section');
				if ($parentSection.length) {
					$parentSection.find('.csf-field-tabbed').first().after(testEmailHtml);
				}
			}
		}
		
		// Handle send test email
		$(document).on('click', '#ezd-send-test-email', function() {
			var $btn = $(this);
			var $result = $('#ezd-test-email-result');
			var email = $('#ezd-test-email-address').val();
			var type = $('#ezd-test-email-type').val();
			
			if (!email) {
				$result.removeClass('success').addClass('error').text('<?php esc_html_e( 'Please enter an email address.', 'eazydocs-pro' ); ?>').show();
				return;
			}
			
			$btn.addClass('loading').prop('disabled', true);
			$result.hide();
			
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ezd_send_test_subscription_email',
					nonce: '<?php echo wp_create_nonce( "ezd_test_email_nonce" ); ?>',
					to_email: email,
					email_type: type
				},
				success: function(response) {
					if (response.success) {
						$result.removeClass('error').addClass('success').text(response.data.message).show();
					} else {
						$result.removeClass('success').addClass('error').text(response.data.message).show();
					}
				},
				error: function() {
					$result.removeClass('success').addClass('error').text('<?php esc_html_e( 'An error occurred. Please try again.', 'eazydocs-pro' ); ?>').show();
				},
				complete: function() {
					$btn.removeClass('loading').prop('disabled', false);
				}
			});
		});
	});
	</script>
	<?php
}
