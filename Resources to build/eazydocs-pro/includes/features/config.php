<?php

// include files

// Check for promax plan or development bypass
$is_promax = eaz_fs()->is_plan('promax');

if ( $is_promax ) {
    
    // Subscriptions
    if ( eazydocspro_get_option('subscriptions')  == 1 ) {
        require_once EAZYDOCSPRO_PATH . '/includes/features/subscription/subscribe.php';
    }

    // Selected Comment
    if ( eazydocspro_get_option('enable-selected-comment')  == 1 ) {
        require_once EAZYDOCSPRO_PATH . '/includes/features/selected_comment/selected_comment.php';
    }
}

// Role-Based Visibility Feature (available for all Pro users)
require_once EAZYDOCSPRO_PATH . '/includes/features/role-visibility/functions.php';
require_once EAZYDOCSPRO_PATH . '/includes/features/role-visibility/Role_Visibility.php';
new \eazyDocsPro\Features\RoleVisibility\Role_Visibility();