<?php
// Template Name: EazyDocs Assistant Embed
// Description: Standalone template for rendering the EazyDocs Assistant UI

if ( ! defined( 'ABSPATH' ) ) {
    require_once dirname( __FILE__, 5 ) . '/wp-load.php';
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Assistant', 'eazydocs-pro'); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url( site_url('/wp-content/plugins/eazydocs/assets/css/ezd-docs-widgets.css') ); ?>" type="text/css" media="all" />
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<?php wp_footer(); ?>
</body>
</html>
