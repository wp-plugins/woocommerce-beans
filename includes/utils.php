<?php
/**
 * Created by PhpStorm.
 * User: PC
 * Date: 15/02/2015
 * Time: 22:57
 */

/**
 * Shortcut for loading an option
 *
 * @param  string $key: the identifier of the option
 * @return mixed
 */
function beans_get_opt($key){
    $opt = get_option(BEANS_OPT_NAME);
    return $opt[$key];
}


/**
 * Here are the text used on the front
 *
 * @param  string $key: the identifier of the text
 * @return string
 */
function beans_get_text($key){
    $business_name = beans_get_opt('business_name');

    $beans_text = array(
        'join_us' =>  sprintf(__(
            '%1$s reward program is now available on Beans. '.
            'Join us with your Facebook or Beans account.',
            'woocommerce-beans'),
            $business_name ? $business_name: 'Our'
        ),
    );

    return $beans_text[$key];
}


/**
 * Print beans with necessary beans name customization
 *
 * @param  float $beans to format
 * @return string
 */
function print_beans($beans) {
    $beans = floatval($beans);
    $beans = floor($beans) == round($beans, BEANS_DECIMALS) ?
        number_format($beans) : number_format($beans, BEANS_DECIMALS);
    $beans_name = defined ('BEANS_NAME') ? BEANS_NAME: 'beans';
    return "<span class='beans-unit'> $beans $beans_name</span>";
}

function beans_info_tag($card_uid, $beans_rate){
    if(beans_get_opt('beans_rate_msg')){
        $info_html = sprintf(
            __('Exchange %1$s for %2$s in our shop.', 'woocommerce-beans'),
            print_beans($beans_rate),
            wc_price(1)
        );
    }else{
        $info_html = sprintf(
            __('%1$s // %2$s', 'woocommerce-beans'),
            print_beans($beans_rate),
            wc_price(1)
        );
    }

    return " <a class='beans-info' target='_blank'  onclick='Beans.show(); return false;' ".
           " href='//".BEANS_WEBSITE."/\$$card_uid/'> ".
           "     $info_html <span class='beans-info-tag' >i</span> ".
           " </a> ";
}

function beans_join_button(){
    $button_text = __('Join', 'woocommerce-beans');
    $style = beans_get_opt('default_button_style') ? 'button' : 'beans-button';
    return "<button class='$style' onclick='Beans.connect(1);' type='button'>$button_text</button>";
    // type='button' is very important to avoid form submit
}

function beans_log_info($info, $first_line=false){
    $log = date('Y-m-d H:i:s.uP') ." => ".$info.PHP_EOL;
    if ($first_line)
        $log = PHP_EOL.PHP_EOL.$log;
    file_put_contents(BEANS_INFO_LOG, $log, FILE_APPEND);
}

/**
 * Load Localisation files.
 *
 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
 * Locales found in:
 * 		- WP_LANG_DIR/woocommerce-beans/LOCALE.mo (which if not found falls back to:)
 * 	 	- woocommerce-beans/languages/woocommerce-beans-LOCALE.mo (if exists)
 */
function beans_set_locale() {
    $locale = apply_filters('plugin_locale', get_locale(), 'woocommerce-beans');
    load_textdomain('woocommerce-beans', WP_LANG_DIR . "/woocommerce-beans/$locale.mo");
    load_textdomain('woocommerce-beans', WP_LANG_DIR . "/woocommerce-beans/woocommerce-beans-$locale.mo");
    load_plugin_textdomain('woocommerce-beans', false, 'woocommerce-beans/languages');

    if(is_admin()){
        load_textdomain('woocommerce-beans-admin', WP_LANG_DIR . "/woocommerce-beans/woocommerce-beans-admin-$locale.mo");
        load_plugin_textdomain('woocommerce-beans-admin', false, 'woocommerce-beans/languages');
        # todo: generate pot after commit to svn and update readme
    }
}
