<?php

if ( ! eaz_fs()->is_plan( 'promax' ) ) {
	return;
}

// Load email templates
require_once __DIR__ . '/email-templates.php';

// Load test email functionality
require_once __DIR__ . '/test-email.php';

/**
 * Subscription Hooks
 */

add_action( 'eazydocs_docs_subscription', 'eazydocs_docs_subscription', 99, 2 );
add_action( 'eazydocs_suscription_modal_form', 'eazydocs_suscription_modal' );

/**
 * Subscription Button
 */
function eazydocs_docs_subscription( $doc_id, $class = '' ) {

	$ezd_subscription_data = get_post_meta( $doc_id, 'ezd_subscription_confirmed', true );
	$subscribedMails       = [];

	if ( ! empty( $ezd_subscription_data ) ) {

		$ezd_subscription_data = maybe_unserialize( $ezd_subscription_data );
		$unique_data           = array_map( "unserialize", array_unique( array_map( "serialize", $ezd_subscription_data ) ) );
		$ezd_subscription_data = array_values( $unique_data );

		if ( is_array( $ezd_subscription_data ) && ! empty( $ezd_subscription_data ) ) {
			foreach ( $ezd_subscription_data as $subscription ) {
				$subscribedMails[] = $subscription['email'];
			}
			wp_reset_postdata();
		}
	}

	// search current user email in array
	$current_user       = wp_get_current_user();
	$current_user_email = $current_user->user_email;
	$search             = array_search( $current_user_email, $subscribedMails );

	$subscribe_tab       = eazydocspro_get_option( 'subscriptions_tab' );
	$subscriptions_btn   = ! empty( $subscribe_tab['subscriptions_btn'] ) ? $subscribe_tab['subscriptions_btn'] : __( 'Subscribe', 'eazydocs-pro' );
	$unsubscriptions_btn = ! empty( $subscribe_tab['unsubscriptions_btn'] ) ? $subscribe_tab['unsubscriptions_btn'] : __( 'Unsubscribe', 'eazydocs-pro' );

	if ( false !== $search ) {
		$subscriptionBtn      = $unsubscriptions_btn;
		$subscriptionBtnClass = 'subscribed';
	} else {
		$subscriptionBtn      = $subscriptions_btn;
		$subscriptionBtnClass = is_user_logged_in() ? 'logged-user' : 'subscribe';
	}
	?>
	<button data-id="<?php echo esc_attr( $doc_id ); ?>"
		<?php
		if ( false !== $search ) :
			?>
			data-token="<?php echo esc_attr( $ezd_subscription_data[ $search ]['token'] ); ?>"
		<?php
		endif;
		?>
		class="ezd-subscription-btn <?php echo esc_attr( $subscriptionBtnClass . ' ' . $class ); ?>">
		<?php echo esc_html( $subscriptionBtn ); ?>
	</button>
	<?php
}

/**
 * Subscription Modal Form
 */
function eazydocs_suscription_modal( $doc_id ) {

	$subscribe_tab = eazydocspro_get_option( 'subscriptions_tab' );

	// Name
	$subscriptions_heading          = ! empty( $subscribe_tab['subscriptions_heading'] ) ? $subscribe_tab['subscriptions_heading'] : __( 'Subscribe', 'eazydocs-pro' );
	$subscriptions_name_label       = ! empty( $subscribe_tab['subscriptions_name_label'] ) ? $subscribe_tab['subscriptions_name_label'] : __( 'Name', 'eazydocs-pro' );
	$subscriptions_name_placeholder = ! empty( $subscribe_tab['subscriptions_name_placeholder'] ) ? $subscribe_tab['subscriptions_name_placeholder'] : __( 'Enter your name', 'eazydocs-pro' );

	// Email
	$subscriptions_email_label       = ! empty( $subscribe_tab['subscriptions_email_label'] ) ? $subscribe_tab['subscriptions_email_label'] : __( 'Email address', 'eazydocs-pro' );
	$subscriptions_email_placeholder = ! empty( $subscribe_tab['subscriptions_email_placeholder'] ) ? $subscribe_tab['subscriptions_email_placeholder'] : __( 'Enter your email address', 'eazydocs-pro' );

	// Submit & cancel btn
	$subscriptions_submit_btn = ! empty( $subscribe_tab['subscriptions_submit_btn'] ) ? $subscribe_tab['subscriptions_submit_btn'] : __( 'Subscribe', 'eazydocs-pro' );
	$subscriptions_cancel_btn = ! empty( $subscribe_tab['subscriptions_cancel_btn'] ) ? $subscribe_tab['subscriptions_cancel_btn'] : __( 'Cancel', 'eazydocs-pro' );

	?>
	<div class="ezd-subscription-form-wrap" id="<?php echo esc_attr( $doc_id ); ?>">
		<div class="ezd-subscription-inner">
			<h2><?php echo esc_html( $subscriptions_heading ); ?></h2>
			<span class="ezd-subscription-close">&times;</span>

			<form action="#" method="POST" class="ezd-subscription-form">
				<input type="hidden" name="ezd_subscription_id" value="<?php echo esc_attr( $doc_id ); ?>">
				<input type="hidden" name="ezd_doc_id" value="<?php echo esc_attr( get_the_ID() ); ?>">

				<label> <?php echo esc_html( $subscriptions_name_label ); ?> </label>
				<input required type="text" name="ezd_subscription_name"
				       placeholder="<?php echo esc_attr( $subscriptions_name_placeholder ); ?>">

				<label> <?php echo esc_html( $subscriptions_email_label ); ?> </label>
				<input required type="email" name="ezd_subscription_email"
				       placeholder="<?php echo esc_attr( $subscriptions_email_placeholder ); ?>">

				<button type="submit"
				        class="ezd-subscription-submit"><?php echo esc_html( $subscriptions_submit_btn ); ?></button>
				<span class="ezd-subscription-cancel"><?php echo esc_html( $subscriptions_cancel_btn ); ?></span>
			</form>
		</div>
	</div>
	<?php
}


// AJAX handler for subscription form
add_action( 'wp_ajax_ezd_subscription_form', 'ezd_subscription_form' );
add_action( 'wp_ajax_nopriv_ezd_subscription_form', 'ezd_subscription_form' );

function ezd_subscription_form() {

	$ezd_subscription_id    = isset( $_POST['ezd_subscription_id'] ) ? absint( $_POST['ezd_subscription_id'] ) : 0;
	$ezd_subscription_name  = sanitize_text_field( $_POST['ezd_subscription_name'] ?? '' );
	$ezd_subscription_email = sanitize_email( $_POST['ezd_subscription_email'] ?? '' );
	$ezd_doc_id             = sanitize_text_field( $_POST['ezd_doc_id'] ?? '' );

	if ( isset( $ezd_subscription_email ) && ! empty( $ezd_subscription_email ) && isset( $ezd_subscription_name ) && ! empty( $ezd_subscription_name ) ) {

		$subscribe_tab = eazydocspro_get_option( 'subscriptions_tab' );
		$email_exist   = ! empty( $subscribe_tab['subscriptions_email_exist'] ) ? $subscribe_tab['subscriptions_email_exist'] : __( 'Email already exists!', 'eazydocs-pro' );

		// Check if the email already exists
		$existing_data = get_post_meta( $ezd_subscription_id, 'ezd_subscription_data', true );
		$existing_data = ! empty( $existing_data ) ? maybe_unserialize( $existing_data ) : [];

		foreach ( $existing_data as $data ) {
			if ( $data['email'] === $ezd_subscription_email ) {
				// Email already exists, return a message
				wp_send_json_error( $email_exist );
			}
		}

		// Generate a unique token for confirmation
		$confirmation_token = wp_generate_password( 32, false );

		// Add new data to the existing array
		$new_data = [
			'name'  => $ezd_subscription_name,
			'email' => $ezd_subscription_email,
			'token' => $confirmation_token,
		];

		$existing_data[] = $new_data;

		// Serialize and save the array to the post meta
		if ( ! empty( $existing_data ) ) {
			update_post_meta( $ezd_subscription_id, 'ezd_subscription_data', maybe_serialize( $existing_data ) );
		}

		// Send confirmation email using the new template
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$subject  = sprintf(
		/* translators: %s: Site name */
			__( '[%s] Confirm Your Subscription', 'eazydocs-pro' ),
			$blogname
		);

		// Get the parent doc ID for display
		$parent_doc_id = ezd_get_doc_parent_id( $ezd_subscription_id );

		// Build email using new template
		$message = ezd_get_confirmation_email( [
			'doc_id'          => $parent_doc_id ?: $ezd_subscription_id,
			'subscriber_name' => $ezd_subscription_name,
			'token'           => $confirmation_token,
			'redirect_url'    => get_permalink( $ezd_doc_id ),
		] );

		// Email headers
		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $blogname . ' <' . get_option( 'admin_email' ) . '>',
		];

		wp_mail( $ezd_subscription_email, wp_specialchars_decode( $subject ), $message, $headers );

		wp_send_json_success( $_POST['subscriptions_success'] );
		wp_die();
	}
}

// AJAX handler for confirming subscription
add_action( 'wp_ajax_ezd_confirm_subscription', 'ezd_confirm_subscription' );
add_action( 'wp_ajax_nopriv_ezd_confirm_subscription', 'ezd_confirm_subscription' );

function ezd_confirm_subscription() {
	$token     = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
	$token_id  = isset( $_POST['token_id'] ) ? sanitize_text_field( $_POST['token_id'] ) : '';
	$token_int = (int) $token_id;

	$ezd_subscription_data = get_post_meta( $token_int, 'ezd_subscription_data', true );

	if ( ! empty( $ezd_subscription_data ) ) {
		$existing_data = ! empty( $ezd_subscription_data ) ? maybe_unserialize( $ezd_subscription_data ) : [];
		$unique_data   = array_map( "unserialize", array_unique( array_map( "serialize", $existing_data ) ) );
		$existing_data = array_values( $unique_data );

		if ( is_array( $existing_data ) && ! empty( $existing_data ) ) {
			// Filter only the data with the matched token
			$matched_data = array_filter(
				$existing_data,
				function ( $subscription ) use ( $token ) {
					return $subscription['token'] === $token;
				}
			);

			if ( ! empty( $matched_data ) ) {
				$matched_subscription = reset( $matched_data );

				// Update 'ezd_subscription_confirmed' meta with matched subscription data
				$ezd_subscription_confirmed = get_post_meta( $token_int, 'ezd_subscription_confirmed', true );
				$confirmed_data             = ! empty( $ezd_subscription_confirmed ) ? maybe_unserialize( $ezd_subscription_confirmed ) : [];
				$confirmed_data[]           = $matched_subscription;

				if ( ! empty( $confirmed_data ) ) {
					update_post_meta( $token_int, 'ezd_subscription_confirmed', maybe_serialize( $confirmed_data ) );
				}

				// Send success response with the matched data
				wp_send_json_success( $matched_subscription );
			}
		}
		wp_die();
	}
}

// AJAX handler for removing subscription
add_action( 'wp_ajax_ezd_unsubscription_create', 'ezd_unsubscription_create' );
add_action( 'wp_ajax_nopriv_ezd_unsubscription_create', 'ezd_unsubscription_create' );

function ezd_unsubscription_create() {
	// Retrieve and sanitize input values
	$token     = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
	$token_id  = isset( $_POST['token_id'] ) ? sanitize_text_field( $_POST['token_id'] ) : '';
	$token_int = (int) $token_id;

	if ( empty( $token ) || empty( $token_int ) ) {
		wp_send_json_error( [ 'message' => 'Invalid request data.' ] );
		wp_die();
	}

	// Unsubscribe user from subscription data
	$subscription_removed = ezd_remove_subscription_data( $token_int, 'ezd_subscription_data', $token );

	// Unsubscribe user from confirmed subscription data
	$confirmed_removed = ezd_remove_subscription_data( $token_int, 'ezd_subscription_confirmed', $token );

	// Check if unsubscription was successful
	if ( $subscription_removed || $confirmed_removed ) {
		wp_send_json_success( [ 'message' => 'Unsubscription successful.' ] );
	} else {
		wp_send_json_error( [ 'message' => 'No matching subscription found.' ] );
	}

	wp_die();
}

/**
 * Remove subscription data from post meta.
 *
 * @param int    $post_id Post ID where subscription data is stored.
 * @param string $meta_key The meta key to fetch and update.
 * @param string $token Subscription token to remove.
 *
 * @return bool True if data was updated, false otherwise.
 */
function ezd_remove_subscription_data( $post_id, $meta_key, $token ) {
	$subscription_data = get_post_meta( $post_id, $meta_key, true );

	if ( empty( $subscription_data ) ) {
		return false;
	}

	$existing_data = maybe_unserialize( $subscription_data );
	$updated_data  = array_filter( $existing_data, function ( $subscription ) use ( $token ) {
		return $subscription['token'] !== $token;
	} );

	// If data was changed, update post meta
	if ( count( $existing_data ) !== count( $updated_data ) ) {
		update_post_meta( $post_id, $meta_key, maybe_serialize( array_values( $updated_data ) ) );

		return true;
	}

	return false;
}


/**
 * Subscription Confirmation
 */
function ezd_subscription_confirmation() {

	$token    = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
	$token_id = isset( $_GET['token_id'] ) ? sanitize_text_field( $_GET['token_id'] ) : '';
	$token_id = (int) $token_id;

	if ( ! empty( $token ) && ! empty( $token_id ) ) {
		$subscriptionBtn       = 0;
		$confirmBtn            = 0;
		$ezd_subscription_data = get_post_meta( ezd_get_doc_parent_id( $token_id ), 'ezd_subscription_confirmed', true );

		if ( ! empty( $ezd_subscription_data ) ) {

			$ezd_subscription_data = maybe_unserialize( $ezd_subscription_data );
			$unique_data           = array_map( "unserialize", array_unique( array_map( "serialize", $ezd_subscription_data ) ) );
			$ezd_subscription_data = array_values( $unique_data );

			foreach ( $ezd_subscription_data as $subscription ) {
				if ( $subscription['token'] === $token ) {
					$confirmBtn = 1;
					break;
				}
			}
		}

		$ezd_subscription_data = get_post_meta( ezd_get_doc_parent_id( $token_id ), 'ezd_subscription_data', true );

		if ( ! empty( $ezd_subscription_data ) ) {

			$ezd_subscription_data = maybe_unserialize( $ezd_subscription_data );
			$unique_data           = array_map( "unserialize", array_unique( array_map( "serialize", $ezd_subscription_data ) ) );
			$ezd_subscription_data = array_values( $unique_data );

			foreach ( $ezd_subscription_data as $subscription ) {
				if ( $subscription['token'] === $token ) {
					$subscriptionBtn = 1;
					break;
				}
			}
		}

		$subscriptionBtn = 1 === $confirmBtn ? 0 : $subscriptionBtn;

		if ( 1 === $subscriptionBtn ) :
			$html = '<span data-token="' . esc_attr( $token ) . '" data-id="' . ezd_get_doc_parent_id( $token_id ) . '" class="confirmation-link">Confirm now</span>';

			return $html;
		endif;
	}
}


/**
 * Unsubscription Confirmation
 */
function ezd_unsubscription_confirmation() {

	$token    = isset( $_GET['unsubscribe_token'] ) ? sanitize_text_field( $_GET['unsubscribe_token'] ) : '';
	$token_id = isset( $_GET['token_id'] ) ? sanitize_text_field( $_GET['token_id'] ) : '';
	$token_id = (int) $token_id;
	$confirmBtn = 0;

	if ( ! empty( $token ) && ! empty( $token_id ) ) {

		$ezd_subscription_data = get_post_meta( $token_id, 'ezd_subscription_confirmed', true );

		if ( ! empty( $ezd_subscription_data ) ) {

			$ezd_subscription_data = maybe_unserialize( $ezd_subscription_data );
			$unique_data           = array_map( "unserialize", array_unique( array_map( "serialize", $ezd_subscription_data ) ) );
			$ezd_subscription_data = array_values( $unique_data );

			foreach ( $ezd_subscription_data as $subscription ) {
				if ( $subscription['token'] === $token ) {
					$confirmBtn = 1;
					break;
				}
			}
		}

		return $confirmBtn;
	}

	return $confirmBtn;
}


// AJAX handler for confirming subscription
add_action( 'wp_ajax_ezd_confirm_unsubscription', 'ezd_confirm_unsubscription' );
add_action( 'wp_ajax_nopriv_ezd_confirm_unsubscription', 'ezd_confirm_unsubscription' );

function ezd_confirm_unsubscription() {
	$token     = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
	$token_id  = isset( $_POST['token_id'] ) ? sanitize_text_field( $_POST['token_id'] ) : '';
	$token_int = (int) $token_id;

	$ezd_subscription_data = get_post_meta( $token_int, 'ezd_subscription_data', true );

	if ( ! empty( $ezd_subscription_data ) ) {
		$remove_data   = [];
		$existing_data = ! empty( $ezd_subscription_data ) ? maybe_unserialize( $ezd_subscription_data ) : [];
		$unique_data   = array_map( "unserialize", array_unique( array_map( "serialize", $existing_data ) ) );
		$existing_data = array_values( $unique_data );

		if ( is_array( $existing_data ) && ! empty( $existing_data ) ) {
			foreach ( $existing_data as $key => $subscription ) {
				// Check if the current subscription's token matches the search token
				if ( $subscription['token'] === $token ) {
					$remove_data = [
						'name'  => $subscription['name'],
						'email' => $subscription['email'],
						'token' => $subscription['token'],
					];

					// Remove the data from the array
					unset( $existing_data[ $key ] );

					// Update the 'ezd_subscription_data' meta field
					if ( ! empty( $existing_data ) ) {
						update_post_meta( $token_int, 'ezd_subscription_data', maybe_serialize( $existing_data ) );
					}
				}
			}
		}
	}

	$ezd_subscription_confirmed = get_post_meta( $token_int, 'ezd_subscription_confirmed', true );

	if ( ! empty( $ezd_subscription_confirmed ) ) {
		$removen_confirmed_data = [];
		$existing_data          = ! empty( $ezd_subscription_confirmed ) ? maybe_unserialize( $ezd_subscription_confirmed ) : [];
		$unique_data            = array_map( "unserialize", array_unique( array_map( "serialize", $existing_data ) ) );
		$existing_data          = array_values( $unique_data );

		if ( is_array( $existing_data ) && ! empty( $existing_data ) ) {
			foreach ( $existing_data as $key => $subscription ) {
				// Check if the current subscription's token matches the search token
				if ( $subscription['token'] === $token ) {
					$remove_data = [
						'name'  => $subscription['name'],
						'email' => $subscription['email'],
						'token' => $subscription['token'],
					];

					// Remove the data from the array
					unset( $existing_data[ $key ] );

					// Update the 'ezd_subscription_data' meta field
					if ( ! empty( $existing_data ) ) {
						update_post_meta( $token_int, 'ezd_subscription_confirmed', maybe_serialize( $existing_data ) );
					}
				}
			}
		}
		wp_send_json_success( $existing_data );
	}

	wp_die();

}

// Add your custom function to the transition_post_status hook
add_action( 'transition_post_status', 'send_email_on_publish_docs', 10, 3 );

// Define your custom function
function send_email_on_publish_docs( $new_status, $old_status, $post ) {
	// Check if the post type is 'docs' and the new status is 'publish'
	if ( 'docs' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status ) {

		$args = [
			'post_type'      => 'docs',
			'posts_per_page' => - 1,
		];

		$query             = new WP_Query( $args );
		$subscribed_posts  = [];
		$subscribed_emails = [];
		$subscribed_token  = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) : $query->the_post();
				$ezd_subscription_confirmed = get_post_meta( get_the_ID(), 'ezd_subscription_confirmed', true );
				$ezd_subscription_confirmed = maybe_unserialize( $ezd_subscription_confirmed );

				if ( ! empty( $ezd_subscription_confirmed ) ) {
					foreach ( $ezd_subscription_confirmed as $confirmed ) {
						$subscribed_emails[] = $confirmed['email'];
						$subscribed_posts[]  = get_the_ID();
						$subscribed_token[]  = $confirmed['token'];
					}
				}
			endwhile;
			wp_reset_postdata();
		}

		$parent_id = get_post_ancestors( $post->ID );
		$ancestors = end( $parent_id );

		if ( in_array( $ancestors, $subscribed_posts ) ) {

			// Combine the arrays into a single array for each post
			$combined_data = [];
			for ( $i = 0; $i < count( $subscribed_posts ); $i ++ ) {
				$post_id = $subscribed_posts[ $i ];
				$email   = $subscribed_emails[ $i ];
				$token   = $subscribed_token[ $i ];

				if ( ! isset( $combined_data[ $post_id ] ) ) {
					// Initialize an array for the post if it doesn't exist
					$combined_data[ $post_id ] = [
						'emails' => [],
						'tokens' => [],
					];
				}

				// Add email and token to the post array
				$combined_data[ $post_id ]['emails'][] = $email;
				$combined_data[ $post_id ]['tokens'][] = $token;
			}

			// Output the combined data

			$all_mails  = array_unique( $combined_data[ $ancestors ]['emails'] );
			$all_tokens = array_unique( $combined_data[ $ancestors ]['tokens'] );

			// Check if both arrays have the same length
			if ( count( $all_mails ) === count( $all_tokens ) ) {
				$count    = count( $all_mails );
				$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

				// Get subscriber names from confirmed data
				$confirmed_data   = get_post_meta( $ancestors, 'ezd_subscription_confirmed', true );
				$confirmed_data   = ! empty( $confirmed_data ) ? maybe_unserialize( $confirmed_data ) : [];
				$subscriber_names = [];
				foreach ( $confirmed_data as $subscriber ) {
					$subscriber_names[ $subscriber['email'] ] = $subscriber['name'];
				}

				for ( $i = 0; $i < $count; $i ++ ) {
					$to              = $all_mails[ $i ];
					$subscriber_name = isset( $subscriber_names[ $to ] ) ? $subscriber_names[ $to ] : __( 'Subscriber', 'eazydocs-pro' );

					$subject = sprintf(
					/* translators: %s: Site name */
						__( '[%s] New Documentation Update', 'eazydocs-pro' ),
						$blogname
					);

					// Build email using new template
					$message = ezd_get_new_content_email( [
						'post_id'         => $post->ID,
						'parent_doc_id'   => $ancestors,
						'subscriber_name' => $subscriber_name,
						'token'           => $all_tokens[ $i ],
					] );

					// Email headers
					$headers = [
						'Content-Type: text/html; charset=UTF-8',
						'From: ' . $blogname . ' <' . get_option( 'admin_email' ) . '>',
					];

					// Send the email
					wp_mail( $to, $subject, $message, $headers );
				}
			}

		}
	}
}

add_action( 'wp_enqueue_scripts', function () {

	$subscribe_tab         = eazydocspro_get_option( 'subscriptions_tab' );
	$subscriptions_btn     = ! empty( $subscribe_tab['subscriptions_btn'] ) ? $subscribe_tab['subscriptions_btn'] : __( 'Subscribe', 'eazydocs-pro' );
	$subscriptions_success = ! empty( $subscribe_tab['subscriptions_success'] ) ? $subscribe_tab['subscriptions_success'] : __( 'Confirmation email send successfully', 'eazydocs-pro' );
	$special_character     = ! empty( $subscribe_tab['subscriptions_special_character'] ) ? $subscribe_tab['subscriptions_special_character'] : __( 'Special characters are not allowed', 'eazydocs-pro' );

	$unsubscriptions_btn        = ! empty( $subscribe_tab['unsubscriptions_btn'] ) ? $subscribe_tab['unsubscriptions_btn'] : __( 'Unsubscribe', 'eazydocs-pro' );
	$unsubscriptions_heading    = ! empty( $subscribe_tab['unsubscriptions_heading'] ) ? $subscribe_tab['unsubscriptions_heading'] : __( 'Unsubscribe', 'eazydocs-pro' );
	$unsubscriptions_desc       = ! empty( $subscribe_tab['unsubscriptions_desc'] ) ? $subscribe_tab['unsubscriptions_desc'] : __( "Are you sure you want to unsubscribe from our mailings?", 'eazydocs-pro' );
	$unsubscriptions_submit_btn = ! empty( $subscribe_tab['unsubscriptions_submit_btn'] ) ? $subscribe_tab['unsubscriptions_submit_btn'] : __( 'Confirm', 'eazydocs-pro' );
	$unsubscriptions_cancel_btn = ! empty( $subscribe_tab['unsubscriptions_cancel_btn'] ) ? $subscribe_tab['unsubscriptions_cancel_btn'] : __( 'Cancel', 'eazydocs-pro' );
	$unsubscriptions_post_title = ! empty( $subscribe_tab['unsubscriptions_post_title'] ) ? $subscribe_tab['unsubscriptions_post_title'] : '';

	wp_enqueue_style( 'eazydocs-subscription', EAZYDOCSPRO_CSS . '/subscribe.css' );
	wp_enqueue_script( 'eazydocs-subscription', EAZYDOCSPRO_URL . '/includes/features/subscription/subscribe.js', [ 'jquery' ], true, true );

	$parent_id = get_post_ancestors( get_the_ID() );

	wp_localize_script( 'eazydocs-subscription', 'eazydocs_subscription', [
		'subscription_confirmation'   => ezd_subscription_confirmation(),
		'unsubscription_confirmation' => ezd_unsubscription_confirmation(),
		'doc_post_title'              => get_the_title( end( $parent_id ) ),
		'subscriptions_success'       => $subscriptions_success,
		'character_not_allowed'       => $special_character,

		'subscriptions_btn' => $subscriptions_btn,
		'unsubscriptions_btn' => $unsubscriptions_btn,

		'unsubscriptions_heading'    => $unsubscriptions_heading,
		'unsubscriptions_desc'       => $unsubscriptions_desc,
		'unsubscriptions_submit_btn' => $unsubscriptions_submit_btn,
		'unsubscriptions_cancel_btn' => $unsubscriptions_cancel_btn,
		'unsubscriptions_post_title' => $unsubscriptions_post_title,
	] );
} );

// Handle Automatic Subscription Confirmation for logged in user
function ezd_auto_confirm_subscription() {
    // Verify user login
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(__('You need to log in to subscribe automatically.', 'eazydocs-pro'));
    }

    $user       = wp_get_current_user();
    $post_id    = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if ( ! $post_id ) {
        wp_send_json_error(__('Invalid post ID.', 'eazydocs-pro'));
    }

    // Generate a unique token
    $token = wp_generate_password(32, false);

    // Prepare subscription data
    $subscription_data = [
        'name'  => $user->display_name,
        'email' => $user->user_email,
        'token' => $token
    ];

    // Get existing subscriptions
    $existing_subscriptions = get_post_meta($post_id, 'ezd_subscription_confirmed', true);
    $existing_subscriptions = !empty($existing_subscriptions) ? maybe_unserialize($existing_subscriptions) : [];

    // Prevent duplicate subscriptions
    foreach ( $existing_subscriptions as $subscription ) {
        if ( $subscription['email'] === $user->user_email ) {
            wp_send_json_error(__('You are already subscribed.', 'eazydocs-pro'));
        }
    }

    // Add new subscription
    $existing_subscriptions[] = $subscription_data;
    update_post_meta( $post_id, 'ezd_subscription_confirmed', maybe_serialize($existing_subscriptions ) );
    update_post_meta( $post_id, 'ezd_subscription_data', maybe_serialize($existing_subscriptions ) );

    wp_send_json_success( [
        'message' => __('Subscription confirmed successfully!', 'eazydocs-pro'),
        'token'   => $token
    ]);
}
add_action('wp_ajax_ezd_auto_confirm_subscription', 'ezd_auto_confirm_subscription');
