<?php
/**
 * EazyDocs Test Email Report
 * Sends a sample analytics report for testing email configuration.
 *
 * @package EazyDocs Pro
 */

add_action( 'wp_ajax_ezd_send_test_report', 'ezd_send_test_report' );

/**
 * Send a test email report with sample data.
 */
function ezd_send_test_report() {

	// Static chart labels (7 days)
	$labels = array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' );

	// Static dataset values (sample data)
	$total_views  = array( 150, 200, 180, 220, 300, 250, 270 );
	$total_search = array( 40, 50, 45, 55, 70, 65, 60 );
	$votes_arr    = array( 10, 15, 20, 25, 30, 28, 32 );
	$new_docs     = array( 1, 2, 0, 1, 3, 2, 1 );

	// Build chart config (static)
	$chartConfig = array(
		'type' => 'bar',
		'data' => array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label'           => 'Views',
					'data'            => $total_views,
					'borderColor'     => '#00e1ff',
					'backgroundColor' => '#00e1ff',
					'borderWidth'     => 0,
				),
				array(
					'label'           => 'Searches',
					'data'            => $total_search,
					'borderColor'     => '#09ff00',
					'backgroundColor' => '#09ff00',
					'borderWidth'     => 0,
				),
				array(
					'label'           => 'Reactions',
					'data'            => $votes_arr,
					'borderColor'     => '#ff0000',
					'backgroundColor' => '#ff0000',
					'borderWidth'     => 0,
				),
				array(
					'label'           => 'New Docs',
					'data'            => $new_docs,
					'borderColor'     => '#6634db',
					'backgroundColor' => '#6634db',
					'borderWidth'     => 0,
				),
			),
		),
		'options' => array(
			'plugins' => array(
				'legend' => array( 'display' => true, 'position' => 'bottom' ),
				'title'  => array( 'display' => true, 'text' => 'Weekly Performance' ),
			),
			'scales' => array(
				'y' => array( 'beginAtZero' => true ),
			),
		),
	);

	// Encode chart JSON for QuickChart API
	$chartUrl = 'https://quickchart.io/chart?c=' . urlencode( wp_json_encode( $chartConfig ) );

	// Use configured settings or defaults for the test email
	$site_name             = ezd_get_opt( 'reporting_site_name' ) ?: get_bloginfo( 'name' );
	$last_day_count        = esc_html__( 'Sample 7 Days Report', 'eazydocs-pro' );
	$last_dates            = gmdate( 'M d, Y', strtotime( '-6 days' ) ) . ' - ' . gmdate( 'M d, Y' );
	$reporting_heading     = ezd_get_opt( 'reporting_heading' ) ?: esc_html__( 'Your Documentation Performance', 'eazydocs-pro' );
	$reporting_description = ezd_get_opt( 'reporting_description' ) ?: esc_html__( 'Comprehensive analytics for your website documentation', 'eazydocs-pro' );

    ob_start();
    ?>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#b8cfff54; padding:20px 0;">
        <tr border="0">
            <td align="center">
            <table width="60%" cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif; background:#ffffff; border-radius:8px; overflow:hidden;">
                
                <!-- Header -->
                <tr style="background:#0008ff;">
                    <td style="padding:15px;border:none">
                        <table width="100%" style="border:none;margin:0;padding:0">
                            <tr border="0">
                                <td align="left" style="border:none">
                                    <a href="<?php echo esc_url( site_url() ); ?>" target="__blank"><img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/eazydocs-logo.png" alt="Logo" width="120" style="display:block;"></a>
                                </td>
                                <td align="right" style="color:#ffffff; font-size:14px; border:none">
                                    <strong><?php echo esc_html($last_day_count); ?></strong><br>
                                    <?php echo esc_html($last_dates); ?>
                                </td>
                            </tr> 
                        </table>
                    </td>
                </tr>

                <!-- Title -->
                <tr border="0">
                    <td align="center" style="padding:30px; background:#f7f9fb;">
                        <h2 style="margin:0; font-size:23px; color:#333;">
                            <?php echo esc_html($reporting_heading); ?>
                        </h2>
                        <p style="margin:5px 0 0; color:#555;font-size: 16px">
                            <?php echo esc_html($reporting_description); ?>
                        </p>
                    </td>
                </tr>

                <!-- Metrics -->
                <tr border="0">
					<td align="center" style="background:#f7f9fb">
						<table width="100%" cellspacing="15" border="0" style="margin-bottom:-10px;">

							<tr border="0">

								<!-- Total Views -->
                                <td style="box-shadow:0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;border-radius:.75rem;margin-right: 20px">

                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
                                        <tr border="0">
                                            <td align="center" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
                                                <img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/views.png" width="32" height="32" style="display:block;" alt="Views">
                                            </td>
                                        </tr>
                                    </table>
                                
                                    <strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
                                        <?php esc_html_e( 'Total Views', 'eazydocs-pro' ); ?>
                                    </strong>

                                    <span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
                                        <?php echo esc_html( array_sum($total_views) ); ?>
                                    </span>

                                    <span style="display:block;text-align:center;color:#03b33d;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
                                        22.45%
                                    </span>
                                </td>
                                    
								<!-- Total Searches -->
                                <td align="center" border="0" style="box-shadow: 0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;border-radius:.75rem;margin-right: 20px">
                                    
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
                                        <tr border="0">
                                            <td align="center" border="0" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
                                                <img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/search.png" width="32" height="32" style="display:block;" alt="Views">
                                            </td>
                                        </tr>
                                    </table>

                                    <strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
                                        <?php esc_html_e( 'Total Searches', 'eazydocs-pro' ); ?>
                                    </strong>

                                    <span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
                                        <?php echo esc_html( array_sum($total_search) ); ?>
                                    </span>

                                    <span style="display:block;text-align:center;color:#03b33d;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
                                        77.68%
                                    </span>
                                </td>

								<!-- Total Reactions -->
                                <td align="center" border="0" style="box-shadow: 0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;border-radius:.75rem;margin-right: 20px">

                                    <table border="0" role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
                                        <tr border="0">
                                            <td border="0" align="center" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
                                            <img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/love.png" width="23" height="23" style="display:block;" alt="Views">
                                            </td>
                                        </tr>
                                    </table>

                                    <strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
                                        <?php esc_html_e( 'Total Reactions', 'eazydocs-pro' ); ?>
                                    </strong>

                                    <span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
                                        <?php echo esc_html( array_sum($votes_arr) ); ?>
                                    </span>

                                    <span style="display:block;text-align:center;color:#ff616c;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
                                        -15.6%
                                    </span>
                                </td>
                                    

								<!-- New Docs -->
                                <td border="0" align="center" style="box-shadow: 0 10px 25px #0000001a;padding:1.5rem;background-color:#ffffff;border:1px solid #0000000f;;border-radius:.75rem;margin-right: 20px">

                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="border:none;margin-bottom:10px;">
                                        <tr border="0">
                                            <td border="0" align="center" bgcolor="#DBEAFE" width="64" height="64" style="border-radius:50%;">
                                                <img src="https://wordpress-plugins.spider-themes.net/eazydocs-pro/wp-content/uploads/2025/08/docs.png" width="32" height="32" style="display:block;" alt="Views">
                                            </td>
                                        </tr>
                                    </table>

                                    <strong style="min-width: 140px;display: block;text-align: center;color:#6b7280;letter-spacing:.025em;text-transform:uppercase;font-size:.875rem;line-height:1.25rem;margin:0;margin-bottom:.5rem;font-weight:600;">
                                        <?php esc_html_e( 'New Documents', 'eazydocs-pro' ); ?>
                                    </strong>

                                    <span style="display:block;text-align:center;color:#1f2937;font-weight:700;font-size:1.875rem;line-height:2.25rem;margin-bottom:.25rem;font-family:tahoma;">
                                        <?php echo esc_html( array_sum($new_docs) ); ?>
                                    </span>
                                    <span style="display:block;text-align:center;color:#f59e0b;font-weight:600;font-size:.875rem;line-height:1.25rem;font-family:tahoma;">
                                        54.45%
                                    </span>
                                </td>
								
							</tr>

						</table>
					</td>
				</tr>

                <!-- Chart -->
                <tr border="0" style="background: #f7f9fb;padding:15px 25px 25px;display: grid;">
                    <td align="center" style="padding:20px;background:#ffffff;border-radius: 10px;border:1px solid #0000000f;">
                        <h3 style="color:#1f2937;font-weight:600;font-size:1.125rem;line-height:1.75rem;margin:0;margin-bottom:1rem;text-align:left;display:block">
                            Weekly Performance Trend
                        </h3>
                        <img src="<?php echo esc_url($chartUrl); ?>" alt="Performance Chart" style="display:block;max-width:100%;height:auto;">
                    </td>
                </tr>

            </table>
        </td>
        </tr>
    </table>

	<?php
	$message = ob_get_clean();

	// Build email headers with proper site name
	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
	);

	// Set From header with site name
	$from_email = get_option( 'admin_email' );
	$headers[]  = 'From: ' . sanitize_text_field( $site_name ) . ' <' . sanitize_email( $from_email ) . '>';

	// Get recipient from settings or use admin email
	$to = ezd_get_opt( 'reporting_email' ) ?: get_option( 'admin_email' );

	// Build subject with site name prefix (matching the real reports)
	$subject_base = ezd_get_opt( 'reporting_heading' ) ?: esc_html__( 'Documentation Analytics Summary', 'eazydocs-pro' );
	$subject      = '[' . $site_name . '] ' . esc_html__( 'TEST: ', 'eazydocs-pro' ) . $subject_base;

	// Send the test email
	$sent = wp_mail( sanitize_email( $to ), sanitize_text_field( $subject ), $message, $headers );

	if ( $sent ) {
		wp_send_json_success(
			array(
				'sent'      => true,
				'recipient' => $to,
			)
		);
	} else {
		wp_send_json_error(
			array(
				'sent'    => false,
				'message' => esc_html__( 'Email could not be sent. Please check your server configuration.', 'eazydocs-pro' ),
			)
		);
	}
}
