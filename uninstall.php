<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) 
    exit();

include_once(plugin_dir_path(__FILE__).'/includes/wc-beans-settings.php');

delete_option(WC_Beans_Settings::OPT_NAME);
// TODO: erase database table
?>