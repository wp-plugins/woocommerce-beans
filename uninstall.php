<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) 
    exit();

include_once(plugin_dir_path(__FILE__).'/includes/wc-beans-settings.php');

WC_Beans_Settings::db_uninstall();
delete_option(WC_Beans_Settings::OPT_NAME);
delete_option(WC_Beans_Settings::OPT_DB_VERSION);

?>