<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) 
    exit();

include_once(plugin_dir_path(__FILE__).'woocommerce-beans.php');

WC_Beans_Settings::db_uninstall();
delete_option(BEANS_OPT_NAME);