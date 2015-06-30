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
    if(isset($opt[$key])) return $opt[$key];
    return null;
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
    $color = defined ('BEANS_SECONDARY_COLOR') ? BEANS_SECONDARY_COLOR: '#4FB655';
    return "<span class='beans-unit' style='color: $color'> $beans $beans_name</span>";
}

function beans_info_tag($beans_address, $beans_rate){
//    $msg = beans_get_opt('beans_rate_msg_text');
    $one_ccy = str_replace('amount', 'beans-currency', wc_price(1));
    $info_html = sprintf(
        __('%1$s are worth %2$s', 'woocommerce-beans'),
        print_beans($beans_rate),
        $one_ccy
    );
    $connect_num = beans_get_opt('auto_enroll') ? 2:1;

    return " <a class='beans-info' target='_blank'  onclick='Beans.show(0, $connect_num); return false;' ".
           " href='//".BEANS_WEBSITE."/$beans_address/'> ".
           "     $info_html <span class='beans-info-tag' >i</span> ".
           " </a> ";
}

function beans_join_button(){
    $button_text = __('Join', 'woocommerce-beans');
    $style = beans_get_opt('default_button_style') ? 'button' : 'beans-button';
    $connect_num = beans_get_opt('auto_enroll') ? 2:1;
    return "<button class='$style' onclick='Beans.connect($connect_num);' type='button'>$button_text</button>";
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
 * @return bool
 */
function beans_set_locale() {
    $locale = apply_filters('plugin_locale', get_locale(), 'woocommerce-beans');

    if($loaded = load_plugin_textdomain('woocommerce-beans', false, 'woocommerce-beans/languages')) return $loaded;
    if($loaded = load_textdomain('woocommerce-beans', WP_LANG_DIR . "/woocommerce-beans/$locale.mo")) return $loaded;
    if($loaded = load_textdomain('woocommerce-beans', WP_LANG_DIR . "/woocommerce-beans/woocommerce-beans-$locale.mo")) return $loaded;

    return false;
}

function get_beans_account(){
    if(!$_SESSION['beans_account']) return false;
    return Beans::get('account/'.$_SESSION['beans_account']);
}
