<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) 
    exit();

include_once(plugin_dir_path(__FILE__).'woocommerce-beans.php');

if($page = get_post( get_option(WC_Beans_Settings::REWARD_PROGRAM_PAGE ))){
    wp_delete_post($page->ID);
}

WC_Beans_Settings::db_uninstall();

delete_option(BEANS_OPT_NAME);