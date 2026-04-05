<?php
/**
 * Email Templates for EazyDocs Subscriptions
 *
 * Provides modern, well-designed email templates for subscription notifications.
 *
 * @package EazyDocs Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get email template settings with defaults.
 *
 * @return array Email template settings.
 */
function ezd_get_email_template_settings() {
	$subscribe_tab = eazydocspro_get_option( 'subscriptions_tab' );

	// Brand color - Use site brand color from General Settings, or default
	$brand_color = ezd_get_opt( 'brand_color' );
	$brand_color = ! empty( $brand_color ) ? $brand_color : '#4F46E5';

	return array(
		'brand_color'       => sanitize_hex_color( $brand_color ),
		'site_name'         => wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
		'site_logo'         => ! empty( $subscribe_tab['email_logo_url'] )
			? esc_url( $subscribe_tab['email_logo_url'] )
			: '',
		'footer_text'       => ! empty( $subscribe_tab['email_footer_text'] )
			? $subscribe_tab['email_footer_text']
			: __( 'Thank you for being a valued subscriber.', 'eazydocs-pro' ),
		'show_excerpt'      => isset( $subscribe_tab['email_show_excerpt'] )
			? (bool) $subscribe_tab['email_show_excerpt']
			: true,
		'show_featured_img' => isset( $subscribe_tab['email_show_featured_image'] )
			? (bool) $subscribe_tab['email_show_featured_image']
			: true,
		'show_related'      => isset( $subscribe_tab['email_show_related'] )
			? (bool) $subscribe_tab['email_show_related']
			: true,
		'social_links'      => array(
			'twitter'  => ! empty( $subscribe_tab['social_twitter'] ) ? esc_url( $subscribe_tab['social_twitter'] ) : '',
			'facebook' => ! empty( $subscribe_tab['social_facebook'] ) ? esc_url( $subscribe_tab['social_facebook'] ) : '',
			'linkedin' => ! empty( $subscribe_tab['social_linkedin'] ) ? esc_url( $subscribe_tab['social_linkedin'] ) : '',
		),
	);
}

/**
 * Generate CSS color variations from brand color.
 *
 * @param string $hex Hex color code.
 * @return array Color variations.
 */
function ezd_get_color_variations( $hex ) {
	// Remove # if present
	$hex = ltrim( $hex, '#' );

	// Convert to RGB
	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );

	return array(
		'primary'       => '#' . $hex,
		'primary_light' => "rgba({$r}, {$g}, {$b}, 0.1)",
		'primary_hover' => "rgba({$r}, {$g}, {$b}, 0.9)",
		'gradient'      => "linear-gradient(135deg, #{$hex} 0%, rgba({$r}, {$g}, {$b}, 0.8) 100%)",
	);
}

/**
 * Get email header HTML.
 *
 * @param array $settings Email template settings.
 * @return string HTML for email header.
 */
function ezd_email_get_header( $settings ) {
	$colors    = ezd_get_color_variations( $settings['brand_color'] );
	$site_name = esc_html( $settings['site_name'] );
	$site_url  = esc_url( site_url() );
	$logo_html = '';

	if ( ! empty( $settings['site_logo'] ) ) {
		$logo_html = '<img src="' . esc_url( $settings['site_logo'] ) . '" alt="' . esc_attr( $site_name ) . '" style="max-height: 40px; width: auto; display: block;" />';
	} else {
		// Use site name as text logo
		$logo_html = '<span style="font-size: 22px; font-weight: 700; color: #ffffff; text-decoration: none;">' . $site_name . '</span>';
	}

	ob_start();
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title><?php echo esc_html( $site_name ); ?></title>
		<!--[if mso]>
		<noscript>
			<xml>
				<o:OfficeDocumentSettings>
					<o:PixelsPerInch>96</o:PixelsPerInch>
				</o:OfficeDocumentSettings>
			</xml>
		</noscript>
		<![endif]-->
		<style type="text/css">
			/* Reset styles */
			body, table, td, div, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
			table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
			img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
			
			/* Responsive styles */
			@media only screen and (max-width: 600px) {
				.wrapper { width: 100% !important; }
				.content-wrapper { padding: 20px !important; }
				.mobile-full { width: 100% !important; display: block !important; }
				.mobile-center { text-align: center !important; }
				.mobile-padding { padding: 15px !important; }
			}
		</style>
	</head>
	<body style="margin: 0; padding: 0; background-color: #f4f7fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
		<!-- Wrapper Table -->
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f4f7fa;">
			<tr>
				<td style="padding: 40px 20px;">
					<!-- Main Container -->
					<table class="wrapper" role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);">
						
						<!-- Header with Gradient Background -->
						<tr>
							<td style="background: <?php echo esc_attr( $colors['gradient'] ); ?>; padding: 30px 40px;">
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
									<tr>
										<td style="vertical-align: middle;">
											<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" style="text-decoration: none;">
												<?php echo $logo_html; ?>
											</a>
										</td>
										<td style="text-align: right; vertical-align: middle;">
											<span style="font-size: 12px; color: rgba(255, 255, 255, 0.9); text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
												<?php esc_html_e( 'Documentation Update', 'eazydocs-pro' ); ?>
											</span>
										</td>
									</tr>
								</table>
							</td>
						</tr>
	<?php
	return ob_get_clean();
}

/**
 * Get email footer HTML.
 *
 * @param array  $settings       Email template settings.
 * @param string $unsubscribe_url Unsubscribe URL.
 * @param string $doc_title       Documentation title.
 * @return string HTML for email footer.
 */
function ezd_email_get_footer( $settings, $unsubscribe_url, $doc_title ) {
	$colors      = ezd_get_color_variations( $settings['brand_color'] );
	$site_name   = esc_html( $settings['site_name'] );
	$site_url    = esc_url( site_url() );
	$footer_text = esc_html( $settings['footer_text'] );
	$year        = gmdate( 'Y' );

	// Social links HTML
	$social_html  = '';
	$social_icons = array(
		'twitter'  => 'https://cdn-icons-png.flaticon.com/512/733/733579.png',
		'facebook' => 'https://cdn-icons-png.flaticon.com/512/733/733547.png',
		'linkedin' => 'https://cdn-icons-png.flaticon.com/512/733/733561.png',
	);

	foreach ( $settings['social_links'] as $platform => $url ) {
		if ( ! empty( $url ) ) {
			$social_html .= sprintf(
				'<a href="%s" target="_blank" style="display: inline-block; margin: 0 8px;"><img src="%s" alt="%s" width="24" height="24" style="display: block;" /></a>',
				esc_url( $url ),
				esc_url( $social_icons[ $platform ] ),
				esc_attr( ucfirst( $platform ) )
			);
		}
	}

	ob_start();
	?>
						<!-- Divider -->
						<tr>
							<td style="padding: 0 40px;">
								<div style="border-top: 1px solid #e5e7eb; margin: 0;"></div>
							</td>
						</tr>
						
						<!-- Footer -->
						<tr>
							<td style="padding: 30px 40px; background-color: #fafbfc;">
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
									<?php if ( ! empty( $social_html ) ) : ?>
									<tr>
										<td style="text-align: center; padding-bottom: 20px;">
											<?php echo $social_html; ?>
										</td>
									</tr>
									<?php endif; ?>
									<tr>
										<td style="text-align: center;">
											<p style="font-size: 14px; color: #6b7280; margin: 0 0 10px; line-height: 1.6;">
												<?php echo $footer_text; ?>
											</p>
											<p style="font-size: 13px; color: #9ca3af; margin: 0 0 15px; line-height: 1.5;">
												<?php
												printf(
													/* translators: %s: Documentation title */
													esc_html__( 'You received this email because you subscribed to updates from %s.', 'eazydocs-pro' ),
													'<strong>' . esc_html( $doc_title ) . '</strong>'
												);
												?>
											</p>
											<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto;">
												<tr>
													<td>
														<a href="<?php echo esc_url( $site_url ); ?>" style="font-size: 13px; color: <?php echo esc_attr( $colors['primary'] ); ?>; text-decoration: none;">
															<?php esc_html_e( 'Visit Website', 'eazydocs-pro' ); ?>
														</a>
													</td>
													<td style="padding: 0 12px; color: #d1d5db;">|</td>
													<td>
														<a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="font-size: 13px; color: #6b7280; text-decoration: none;">
															<?php esc_html_e( 'Unsubscribe', 'eazydocs-pro' ); ?>
														</a>
													</td>
												</tr>
											</table>
										</td>
									</tr>
									<tr>
										<td style="text-align: center; padding-top: 20px;">
											<p style="font-size: 12px; color: #9ca3af; margin: 0;">
												© <?php echo esc_html( $year ); ?> <?php echo esc_html( $site_name ); ?>. 
												<?php esc_html_e( 'All rights reserved.', 'eazydocs-pro' ); ?>
											</p>
										</td>
									</tr>
								</table>
							</td>
						</tr>
						
					</table>
					<!-- End Main Container -->
				</td>
			</tr>
		</table>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/**
 * Get new content notification email HTML.
 *
 * @param array $args {
 *    Email arguments.
 *
 *     @type int    $post_id       The new post ID.
 *     @type int    $parent_doc_id The parent documentation ID.
 *     @type string $subscriber_name Subscriber's name.
 *     @type string $token         Subscriber's token for unsubscribe.
 * }
 * @return string Complete email HTML.
 */
function ezd_get_new_content_email( $args ) {
	$settings = ezd_get_email_template_settings();
	$colors   = ezd_get_color_variations( $settings['brand_color'] );

	// Post data
	$post             = get_post( $args['post_id'] );
	$post_title       = get_the_title( $args['post_id'] );
	$post_url         = get_permalink( $args['post_id'] );
	$post_excerpt     = '';
	$post_date        = get_the_date( 'F j, Y', $args['post_id'] );
	$parent_doc_title = get_the_title( $args['parent_doc_id'] );

	// Get excerpt
	if ( $settings['show_excerpt'] && $post ) {
		$post_excerpt = ! empty( $post->post_excerpt )
			? $post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
	}

	// Get featured image
	$featured_image = '';
	if ( $settings['show_featured_img'] && has_post_thumbnail( $args['post_id'] ) ) {
		$featured_image = get_the_post_thumbnail_url( $args['post_id'], 'medium' );
	}

	// Get reading time estimate
	$word_count   = $post ? str_word_count( wp_strip_all_tags( $post->post_content ) ) : 0;
	$reading_time = max( 1, ceil( $word_count / 200 ) );

	// Unsubscribe URL
	$unsubscribe_url = add_query_arg(
		array(
			'unsubscribe_token' => $args['token'],
			'token_id'          => $args['parent_doc_id'],
		),
		$post_url
	);

	// Get related posts
	$related_posts = array();
	if ( $settings['show_related'] ) {
		$related_args  = array(
			'post_type'      => 'docs',
			'posts_per_page' => 3,
			'post__not_in'   => array( $args['post_id'] ),
			'post_parent'    => $args['parent_doc_id'] ?: wp_get_post_parent_id( $args['post_id'] ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$related_query = new WP_Query( $related_args );
		if ( $related_query->have_posts() ) {
			while ( $related_query->have_posts() ) {
				$related_query->the_post();
				$related_posts[] = array(
					'title' => get_the_title(),
					'url'   => get_permalink(),
				);
			}
			wp_reset_postdata();
		}
	}

	// Build email
	$email_html = ezd_email_get_header( $settings );

	ob_start();
	?>
						<!-- Main Content -->
						<tr>
							<td class="content-wrapper" style="padding: 40px;">
								
								<!-- Greeting -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
									<tr>
										<td>
											<p style="font-size: 18px; color: #1f2937; margin: 0 0 8px; font-weight: 600;">
												<?php
												printf(
													/* translators: %s: Subscriber name */
													esc_html__( 'Hello %s,', 'eazydocs-pro' ),
													esc_html( $args['subscriber_name'] )
												);
												?>
											</p>
											<p style="font-size: 15px; color: #6b7280; margin: 0 0 25px; line-height: 1.6;">
												<?php
												printf(
													/* translators: %s: Documentation title */
													esc_html__( 'We\'ve just published new content in %s that we think you\'ll find helpful.', 'eazydocs-pro' ),
													'<strong style="color: #374151;">' . esc_html( $parent_doc_title ) . '</strong>'
												);
												?>
											</p>
										</td>
									</tr>
								</table>
								
								<!-- New Post Card -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f9fafb; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb;">
									
									<?php if ( ! empty( $featured_image ) ) : ?>
									<!-- Featured Image -->
									<tr>
										<td>
											<a href="<?php echo esc_url( $post_url ); ?>" target="_blank">
												<img src="<?php echo esc_url( $featured_image ); ?>" alt="<?php echo esc_attr( $post_title ); ?>" style="width: 100%; height: auto; display: block; max-height: 200px; object-fit: cover;" />
											</a>
										</td>
									</tr>
									<?php endif; ?>
									
									<tr>
										<td style="padding: 25px;">
											<!-- New Badge -->
											<span style="display: inline-block; background: <?php echo esc_attr( $colors['primary_light'] ); ?>; color: <?php echo esc_attr( $colors['primary'] ); ?>; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 10px; border-radius: 4px; margin-bottom: 12px;">
												<?php esc_html_e( 'New Article', 'eazydocs-pro' ); ?>
											</span>
											
											<!-- Post Title -->
											<h2 style="font-size: 20px; font-weight: 700; color: #1f2937; margin: 0 0 12px; line-height: 1.4;">
												<a href="<?php echo esc_url( $post_url ); ?>" target="_blank" style="color: #1f2937; text-decoration: none;">
													<?php echo esc_html( $post_title ); ?>
												</a>
											</h2>
											
											<!-- Meta Info -->
											<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 15px;">
												<tr>
													<td style="padding-right: 15px;">
														<span style="font-size: 13px; color: #9ca3af;">
															📅 <?php echo esc_html( $post_date ); ?>
														</span>
													</td>
													<td>
														<span style="font-size: 13px; color: #9ca3af;">
															⏱️ 
															<?php
															printf(
																/* translators: %d: Reading time in minutes */
																esc_html( _n( '%d min read', '%d min read', $reading_time, 'eazydocs-pro' ) ),
																$reading_time
															);
															?>
														</span>
													</td>
												</tr>
											</table>
											
											<?php if ( ! empty( $post_excerpt ) ) : ?>
											<!-- Excerpt -->
											<p style="font-size: 14px; color: #6b7280; margin: 0 0 20px; line-height: 1.6;">
												<?php echo esc_html( $post_excerpt ); ?>
											</p>
											<?php endif; ?>
											
											<!-- CTA Button -->
											<table role="presentation" cellspacing="0" cellpadding="0" border="0">
												<tr>
													<td style="border-radius: 8px; background: <?php echo esc_attr( $colors['gradient'] ); ?>;">
														<a href="<?php echo esc_url( $post_url ); ?>" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none; letter-spacing: 0.3px;">
															<?php esc_html_e( 'Read Full Article', 'eazydocs-pro' ); ?> →
														</a>
													</td>
												</tr>
											</table>
										</td>
									</tr>
								</table>
								
								<?php if ( ! empty( $related_posts ) ) : ?>
								<!-- Related Articles Section -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top: 35px;">
									<tr>
										<td>
											<h3 style="font-size: 16px; font-weight: 600; color: #1f2937; margin: 0 0 15px; padding-bottom: 12px; border-bottom: 2px solid <?php echo esc_attr( $colors['primary_light'] ); ?>;">
												<?php esc_html_e( 'You Might Also Like', 'eazydocs-pro' ); ?>
											</h3>
											<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
												<?php foreach ( $related_posts as $related ) : ?>
												<tr>
													<td style="padding: 10px 0; border-bottom: 1px solid #f3f4f6;">
														<a href="<?php echo esc_url( $related['url'] ); ?>" target="_blank" style="font-size: 14px; color: <?php echo esc_attr( $colors['primary'] ); ?>; text-decoration: none; font-weight: 500;">
															📄 <?php echo esc_html( $related['title'] ); ?>
														</a>
													</td>
												</tr>
												<?php endforeach; ?>
											</table>
										</td>
									</tr>
								</table>
								<?php endif; ?>
								
							</td>
						</tr>
	<?php
	$email_html .= ob_get_clean();
	$email_html .= ezd_email_get_footer( $settings, $unsubscribe_url, $parent_doc_title );

	return $email_html;
}

/**
 * Get confirmation email HTML (double opt-in).
 *
 * @param array $args {
 *     Email arguments.
 *
 *     @type int    $doc_id          The documentation ID subscribed to.
 *     @type string $subscriber_name Subscriber's name.
 *     @type string $token           Confirmation token.
 *     @type string $redirect_url    URL to redirect after confirmation.
 * }
 * @return string Complete email HTML.
 */
function ezd_get_confirmation_email( $args ) {
	$settings  = ezd_get_email_template_settings();
	$colors    = ezd_get_color_variations( $settings['brand_color'] );
	$doc_title = get_the_title( $args['doc_id'] );
	$site_name = $settings['site_name'];

	// Build confirmation URL
	$confirm_url = add_query_arg(
		array(
			'token'    => $args['token'],
			'token_id' => $args['doc_id'],
		),
		$args['redirect_url']
	);

	// Build email
	$email_html = ezd_email_get_header( $settings );

	ob_start();
	?>
						<!-- Main Content -->
						<tr>
							<td class="content-wrapper" style="padding: 40px; text-align: center;">
								
								<!-- Icon -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto 25px;">
									<tr>
										<td style="width: 80px; height: 80px; background: <?php echo esc_attr( $colors['primary_light'] ); ?>; border-radius: 50%; text-align: center; vertical-align: middle;">
											<span style="font-size: 36px; line-height: 80px;">✉️</span>
										</td>
									</tr>
								</table>
								
								<!-- Heading -->
								<h1 style="font-size: 26px; font-weight: 700; color: #1f2937; margin: 0 0 12px;">
									<?php esc_html_e( 'Confirm Your Subscription', 'eazydocs-pro' ); ?>
								</h1>
								
								<p style="font-size: 15px; color: #6b7280; margin: 0 0 30px; line-height: 1.6; max-width: 400px; margin-left: auto; margin-right: auto;">
									<?php
									printf(
										/* translators: 1: Subscriber name, 2: Documentation title */
										esc_html__( 'Hi %1$s, thank you for subscribing to receive updates from %2$s.', 'eazydocs-pro' ),
										'<strong style="color: #374151;">' . esc_html( $args['subscriber_name'] ) . '</strong>',
										'<strong style="color: #374151;">' . esc_html( $doc_title ) . '</strong>'
									);
									?>
								</p>
								
								<!-- Confirmation Button -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto 30px;">
									<tr>
										<td style="border-radius: 10px; background: <?php echo esc_attr( $colors['gradient'] ); ?>; box-shadow: 0 4px 14px <?php echo esc_attr( $colors['primary_light'] ); ?>;">
											<a href="<?php echo esc_url( $confirm_url ); ?>" target="_blank" style="display: inline-block; padding: 16px 32px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; letter-spacing: 0.5px;">
												<?php esc_html_e( 'Confirm Subscription', 'eazydocs-pro' ); ?>
											</a>
										</td>
									</tr>
								</table>
								
								<!-- Alternative Link -->
								<p style="font-size: 13px; color: #9ca3af; margin: 0 0 20px; line-height: 1.5;">
									<?php esc_html_e( 'Or copy and paste this link into your browser:', 'eazydocs-pro' ); ?>
								</p>
								<p style="font-size: 12px; color: <?php echo esc_attr( $colors['primary'] ); ?>; margin: 0 0 30px; word-break: break-all; background: #f3f4f6; padding: 12px 15px; border-radius: 6px; max-width: 100%;">
									<?php echo esc_url( $confirm_url ); ?>
								</p>
								
								<!-- Security Note -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: #fffbeb; border-radius: 8px; border: 1px solid #fcd34d;">
									<tr>
										<td style="padding: 15px 20px;">
											<p style="font-size: 13px; color: #92400e; margin: 0; line-height: 1.5;">
												⚠️ <?php esc_html_e( 'If you did not request this subscription, please ignore this email. You will not receive any updates unless you click the confirmation button above.', 'eazydocs-pro' ); ?>
											</p>
										</td>
									</tr>
								</table>
								
							</td>
						</tr>
	<?php
	$email_html .= ob_get_clean();

	// Footer for confirmation email (no unsubscribe needed)
	ob_start();
	?>
						<!-- Footer -->
						<tr>
							<td style="padding: 25px 40px; background-color: #fafbfc; text-align: center;">
								<p style="font-size: 12px; color: #9ca3af; margin: 0;">
									© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?>. 
									<?php esc_html_e( 'All rights reserved.', 'eazydocs-pro' ); ?>
								</p>
							</td>
						</tr>
						
					</table>
					<!-- End Main Container -->
				</td>
			</tr>
		</table>
	</body>
	</html>
	<?php
	$email_html .= ob_get_clean();

	return $email_html;
}

/**
 * Get unsubscribe confirmation email HTML.
 *
 * @param array $args {
 *     Email arguments.
 *
 *     @type int    $doc_id          The documentation ID.
 *     @type string $subscriber_name Subscriber's name.
 * }
 * @return string Complete email HTML.
 */
function ezd_get_unsubscribe_confirmation_email( $args ) {
	$settings  = ezd_get_email_template_settings();
	$colors    = ezd_get_color_variations( $settings['brand_color'] );
	$doc_title = get_the_title( $args['doc_id'] );
	$site_name = $settings['site_name'];
	$site_url  = site_url();

	// Build email
	$email_html = ezd_email_get_header( $settings );

	ob_start();
	?>
						<!-- Main Content -->
						<tr>
							<td class="content-wrapper" style="padding: 40px; text-align: center;">
								
								<!-- Icon -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto 25px;">
									<tr>
										<td style="width: 80px; height: 80px; background: #fef3c7; border-radius: 50%; text-align: center; vertical-align: middle;">
											<span style="font-size: 36px; line-height: 80px;">👋</span>
										</td>
									</tr>
								</table>
								
								<!-- Heading -->
								<h1 style="font-size: 26px; font-weight: 700; color: #1f2937; margin: 0 0 12px;">
									<?php esc_html_e( 'You\'ve Been Unsubscribed', 'eazydocs-pro' ); ?>
								</h1>
								
								<p style="font-size: 15px; color: #6b7280; margin: 0 0 25px; line-height: 1.6; max-width: 400px; margin-left: auto; margin-right: auto;">
									<?php
									printf(
										/* translators: 1: Subscriber name, 2: Documentation title */
										esc_html__( 'Hi %1$s, you have been successfully unsubscribed from updates about %2$s.', 'eazydocs-pro' ),
										'<strong style="color: #374151;">' . esc_html( $args['subscriber_name'] ) . '</strong>',
										'<strong style="color: #374151;">' . esc_html( $doc_title ) . '</strong>'
									);
									?>
								</p>
								
								<!-- Info Box -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: #f0fdf4; border-radius: 8px; border: 1px solid #bbf7d0; margin-bottom: 30px;">
									<tr>
										<td style="padding: 15px 20px;">
											<p style="font-size: 13px; color: #166534; margin: 0; line-height: 1.5;">
												✅ <?php esc_html_e( 'You will no longer receive email notifications for this documentation. You can always resubscribe anytime.', 'eazydocs-pro' ); ?>
											</p>
										</td>
									</tr>
								</table>
								
								<!-- Back to Site Button -->
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto;">
									<tr>
										<td style="border-radius: 8px; background: #6b7280; ">
											<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none;">
												<?php esc_html_e( 'Visit Our Website', 'eazydocs-pro' ); ?>
											</a>
										</td>
									</tr>
								</table>
								
							</td>
						</tr>
						
						<!-- Footer -->
						<tr>
							<td style="padding: 25px 40px; background-color: #fafbfc; text-align: center;">
								<p style="font-size: 12px; color: #9ca3af; margin: 0;">
									© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?>. 
									<?php esc_html_e( 'All rights reserved.', 'eazydocs-pro' ); ?>
								</p>
							</td>
						</tr>
						
					</table>
					<!-- End Main Container -->
				</td>
			</tr>
		</table>
	</body>
	</html>
	<?php
	$email_html .= ob_get_clean();

	return $email_html;
}
