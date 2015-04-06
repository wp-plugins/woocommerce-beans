<?php
/**
 * Plugin Name: WooCommerce Beans
 * Plugin URI: https://business.trybeans.com/
 * Description: Beans extension for woocommerce. Advanced reward program for woocommerce that helps you engage your customers.
 * Version: 0.9.64
 * Author: Beans
 * Author URI: https://business.trybeans.com/
 * Text Domain: woocommerce-beans
 * Domain Path: /languages
 *
 * @author Beans
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
    exit;

//Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
    return;

define('BEANS_VERSION',                 '0.9.64');
define('BEANS_DECIMALS',                2);
define('BEANS_OPT_NAME',                'wc_beans_options');
define('BEANS_COUPON_UID',              'beans_redeem');
define('BEANS_WEBSITE',                 'www.trybeans.com');
define('BEANS_BUSINESS_WEBSITE',        'business.trybeans.com');
define('BEANS_PLUGIN',                  plugin_basename(__FILE__));
define('BEANS_INFO_LOG',                plugin_dir_path(__FILE__).'info.log');
define('BEANS_ERROR_LOG',               plugin_dir_path(__FILE__).'error.log');
define('BEANS_CSS_FILE',                plugin_dir_path(__FILE__).'assets/css/local.beans.css');
define('BEANS_CSS_MASTER',              plugin_dir_path(__FILE__).'assets/css/master.beans.css');


if( file_exists(BEANS_ERROR_LOG) && filesize(BEANS_ERROR_LOG)>100000)
    unlink(BEANS_ERROR_LOG);

if( file_exists(BEANS_INFO_LOG) && filesize(BEANS_INFO_LOG)>100000)
    unlink(BEANS_INFO_LOG);

include_once(plugin_dir_path(__FILE__).'includes/beans.php');

function wc_version() {
    if ( ! function_exists( 'get_plugins' ) )
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    $plugin_folder = get_plugins( '/woocommerce' );
    return $plugin_folder['woocommerce.php']['Version'];
}

Beans::$signature = '[CMS]Wordpress '.get_bloginfo('version').' WooCommerce '.wc_version().' Woocommerce-Beans '.BEANS_VERSION;
Beans::$fail_silently = true;
Beans::set_error_log(3, BEANS_ERROR_LOG);

include_once(plugin_dir_path(__FILE__).'includes/utils.php');
include_once(plugin_dir_path(__FILE__).'includes/class-wc-beans-order.php');
include_once(plugin_dir_path(__FILE__).'includes/wc-beans-settings.php');


if ( ! class_exists( 'WC_Beans' ) ) :

    class WC_Beans{
        protected static $_instance = null;
        private $opt = null;
        private $debug = false;

        function __construct(){

            // Add hooks for action
            add_action('init',                                                    array( $this, 'initialize' ) );
            add_action('wp_logout',                                               array( $this, 'clear_session' ));
            add_action('wp_login',                                                array( $this, 'init_session_hook' ), 10, 2);
            add_action('wp_loaded',                                               array( $this, 'form_post_handler' ), 30 );
            add_action('wp_enqueue_scripts',                                      array( $this, 'enqueue_scripts' ));
            add_filter('woocommerce_get_shop_coupon_data',                        array( $this, 'get_coupon'), 10, 2);
            add_filter('woocommerce_checkout_order_processed',                    array( $this, 'create_order'), 10, 1);
            add_filter('woocommerce_order_status_changed',                        array( $this, 'update_order'), 10, 3);
            add_filter('woocommerce_update_cart_action_cart_updated',             array( $this, 'cancel_redeem_beans'));

            // Add hooks for display
            add_action('woocommerce_single_product_summary',                      array( $this, 'render_product_page' ), 15);
            add_action('woocommerce_before_my_account',                           array( $this, 'render_account_page' ), 15);
            add_action('woocommerce_after_cart_table',                            array( $this, 'render_cart_checkout_page' ), 10);
            add_action('woocommerce_before_checkout_form',                        array( $this, 'render_cart_checkout_page' ), 15);
            add_action('woocommerce_order_details_after_order_table',             array( $this, 'render_order_page' ), 15);
            add_action('woocommerce_after_shop_loop_item_title',                  array( $this, 'render_shop_page' ), 15);

        $this->opt = get_option(BEANS_OPT_NAME);
    }

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function initialize(){

            beans_set_locale();

            WC_Beans_Settings::check_opt();

            Beans::init($this->opt['secret_key']);

            if (!session_id())
                session_start();

            if(isset($_GET['beans-debug']))
                $this->debug = true;

            if(!isset($_SESSION['beans_account']))
                $_SESSION['beans_account'] = false;

            if(!isset($_SESSION['beans_coupon_data']))
                $_SESSION['beans_coupon_data'] = false;

            if(!isset($_SESSION['beans_account_in_db']))
                $_SESSION['beans_account_in_db'] = false;

            if(empty($_SESSION['beans_rule_currency_spent']) && $this->opt['rule_currency_spent_id'])
                $_SESSION['beans_rule_currency_spent'] = Beans::get('rule/'.$this->opt['rule_currency_spent_id']);

            if(empty($_SESSION['beans_rate'])){
                $data = array('iso'=> strtoupper(get_woocommerce_currency()));
                $currency = Beans::get('currency/iso', $data);
                $_SESSION['beans_rate'] = $currency['beans'];
            }

            if(empty($_SESSION['beans_name']) && $this->opt['card_id']) {
                $card  = Beans::get('card/' . $this->opt['card_id']);
                $_SESSION['beans_name'] = $card['beans_name'] ;
            }

            if(!empty($_SESSION['beans_name']))
                define('BEANS_NAME', $_SESSION['beans_name']);

            $this->init_beans_account();

            if($this->debug) $this->run_debug();
        }

        public function enqueue_scripts(){
            if(is_admin()) return;

            wp_enqueue_script('beans-wc-script', plugins_url( 'assets/js/local.beans.js' , __FILE__ ));
            wp_localize_script('beans-wc-script', 'beans_data', array(
                'card_id'  =>  $this->opt['card_id'],
                'beans_popup' =>  $this->opt['beans_popup'],
            ));
            wp_enqueue_style( 'beans-wc-style2', plugins_url( 'assets/css/master.beans.css' , __FILE__ ));
            wp_enqueue_style( 'beans-wc-style1', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
        }

        public function init_session_hook($user_login, $user){
            $user_id = $user->ID;
            // Look in the database
            if($user_id){
                $_SESSION['beans_account'] = get_user_meta($user_id, 'beans_account_id', true);
                if($_SESSION['beans_account'])
                    $_SESSION['beans_account_in_db'] = True;
            }
        }

        public function init_beans_account(){
            $user_id = get_current_user_id();

            // 1. Look in the database
            if(!$_SESSION['beans_account'] && $user_id){
                $_SESSION['beans_account'] = get_user_meta($user_id, 'beans_account_id', true);
                if($_SESSION['beans_account'])
                    $_SESSION['beans_account_in_db'] = True;
            }

            // 2. Make an API call
            if(!$_SESSION['beans_account'] && !empty($_COOKIE['beans_user'])){
                $response = Beans::get_token_from_cookie();
                if(!empty($response['account__id']))
                    $_SESSION['beans_account']= $response['account__id'];
            }

            // Finally save info to db if necessary
            if($_SESSION['beans_account'] && !$_SESSION['beans_account_in_db'] && $user_id){
                update_user_meta($user_id, 'beans_account_id', $_SESSION['beans_account']);
                $_SESSION['beans_account_in_db'] = True;
            }
        }

        public function clear_session(){
            unset($_SESSION['beans_name']);
            unset($_SESSION['beans_rate']);
            unset($_SESSION['beans_account']);
            unset($_SESSION['beans_to_redeem']);
            unset($_SESSION['beans_coupon_data']);
            unset($_SESSION['beans_account_in_db']);
            unset($_SESSION['beans_rule_currency_spent']);
            setcookie("beans_user", "", time()-10, "/");
        }

        private static function get_current_cart(){
            global $woocommerce;

            if(empty($woocommerce->cart->cart_contents))
                $woocommerce->cart->calculate_totals();

            return $woocommerce->cart;
        }

        public function form_post_handler() {

            if(isset($_POST['beans_redeem'])){

                $this->cancel_redeem_beans();
                $this->redeem_beans();
                unset($_POST['beans_redeem']);

            }elseif(isset($_POST['beans_cancel_redeem'])
                ||(isset($_GET['remove_coupon']) && $_GET['remove_coupon']==BEANS_COUPON_UID)){

                $this->cancel_redeem_beans();
                unset($_POST['beans_cancel_redeem']);
            }
        }

        public function cancel_redeem_beans(){
            self::get_current_cart()->remove_coupon(BEANS_COUPON_UID);
            $_SESSION['beans_coupon_data'] = false;
        }

        public function redeem_beans(){

            $account = get_beans_account();

            if(!isset($account['beans'])) return;

            if($account['beans']/$_SESSION['beans_rate'] < 1) {
                wc_add_notice(
                    sprintf( __( 'Not enough %1$s to redeem.', 'woocommerce-beans' ),
                        $_SESSION['beans_name'] )
                    ,'error' );
                return;
            }

            $cart = self::get_current_cart();
            //TODO: Do this better
            $min_coupon = (int) ($cart->subtotal * $_SESSION['beans_rate'] );
            $min_coupon = round($min_coupon * $this->opt['range_min_redeem'] / 100 ,-2,PHP_ROUND_HALF_UP);
            if ($account['beans'] < $min_coupon ){
                wc_add_notice(
                    sprintf(__( 'Not enough %1$s to redeem. Minimal redeem is %2$s.', 'woocommerce-beans' ),
                        $_SESSION['beans_name'], print_beans($min_coupon)),
                    'error' );
                return;
            }

            $max_coupon = (int)  ($cart->subtotal * $_SESSION['beans_rate']) ;
            $max_coupon = round($max_coupon * $this->opt['range_max_redeem'] / 100,-2,PHP_ROUND_HALF_DOWN);
            if ($account['beans'] > $max_coupon ){
                wc_add_notice(
                    sprintf( __( 'Maximal redeem is %1$s.', 'woocommerce-beans' ),
                        print_beans($max_coupon)) ,
                    'success' );
            }

            $cart->add_discount(BEANS_COUPON_UID);
        }

        public function get_coupon($coupon, $coupon_code){

            $this->debug ? beans_log_info("get_beans_coupon: /$coupon_code/") : null;

            if( $coupon_code != BEANS_COUPON_UID)     return $coupon;
            if( !$_SESSION['beans_account'] )         return $coupon;

            if($_SESSION['beans_coupon_data'])
                return $_SESSION['beans_coupon_data'];

            $account = Beans::get('account/'.$_SESSION['beans_account']);

            $max_coupon = $this->opt['range_max_redeem'] / 100 * self::get_current_cart()->subtotal;
            $coupon_value = (int) min($account['beans']/$_SESSION['beans_rate'], $max_coupon);
            $_SESSION['beans_to_redeem'] = $coupon_value*$_SESSION['beans_rate'];

            $coupon_data = array();

            $coupon_data['id']                         = true;
            $coupon_data['individual_use']             = null;
            $coupon_data['product_ids']                = null;
            $coupon_data['exclude_product_ids']        = null;
            $coupon_data['usage_limit']                = null;
            $coupon_data['usage_limit_per_user']       = null;
            $coupon_data['limit_usage_to_x_items']     = null;
            $coupon_data['usage_count']                = null;
            $coupon_data['apply_before_tax']           = null;
            $coupon_data['free_shipping']              = null;
            $coupon_data['product_categories']         = null;
            $coupon_data['exclude_product_categories'] = null;
            $coupon_data['exclude_sale_items']         = null;
            $coupon_data['minimum_amount']             = null;
            $coupon_data['maximum_amount']             = null;
            $coupon_data['customer_email']             = null;

            $coupon_data['type']                       = 'fixed_cart';
            $coupon_data['discount_type']              = 'fixed_cart';
            $coupon_data['amount']                     = $coupon_value;
            $coupon_data['coupon_amount']              = $coupon_value;
            $coupon_data['expiry_date']                = strtotime('+1 day', time());

            $this->debug ? beans_log_info(print_r($coupon_data, true)) : null;

            $_SESSION['beans_coupon_data'] = $coupon_data;

            return $coupon_data;
        }

        public function create_order($order_id){

            $beans_order = new WC_Beans_Order($order_id);
            $beans_order->account_id = $_SESSION['beans_account'];
            $beans_order->save();
            $beans_order->make_debit();
            $beans_order->send_followup_email();

            unset($_SESSION['beans_coupon_data']);
            unset($_SESSION['beans_to_redeem']);
        }

        public function update_order($order_id, $order_status, $new_status){
            $beans_order = new WC_Beans_Order($order_id);
            if($this->is_order_validated($new_status))
                $beans_order->make_credit();
        }

        public function is_order_validated($status){
            if($status=='processing' || $status=='completed')
                return true;
            else
                return false;
        }

        public function render_shop_page($page){

            global $post;

            $product        = get_product( $post->ID );
            $regular_price  = $product->get_price();
            $min_price      = $product->min_variation_price;
            $max_price      = $product->max_variation_price;

            if($min_price == $max_price){
                $max_price = null;
                $min_price = null;
            }

            if (!empty($min_price) && !empty($max_price)){

                $beans_to_buy_min   = (int) ($min_price * $_SESSION['beans_rate']);
                $beans_to_buy_max   = (int) ($max_price * $_SESSION['beans_rate']);

                $beans_to_buy       = $beans_to_buy_min."-".$beans_to_buy_max;

            }else{

                $beans_to_buy   = (int) ($regular_price * $_SESSION['beans_rate']);

            }

            if ($this->opt['beans_on_shop_page']){
                ?>
                <span  class="price beans-price">
                <?php echo print_beans($beans_to_buy); ?>
            </span>
            <?php
            }

        }

        public function render_account_page($page){
            $this->render_cart_checkout_page('account');
        }

        public function render_cart_checkout_page($page){

        $beans_to_earn  = (int) (self::get_current_cart()->cart_contents_total * $_SESSION['beans_rule_currency_spent']['beans']);
        $button_style = beans_get_opt('default_button_style') ? 'button' : 'beans-button';

            if($_SESSION['beans_account'] && $page == 'account'){
            $account = Beans::get('account/'.$_SESSION['beans_account']);
            $info_html = sprintf(
                __('You have  %1$s.','woocommerce-beans'),
                '' .print_beans($account['beans'])
            );
            $button_text = __('View account history', 'woocommerce-beans');
            $form_html = "<a class='$button_style'  target='_blank' onclick='Beans.show(); return false;' ".
                         "href='//".BEANS_WEBSITE."/".$this->opt['beans_address']."/'>$button_text</a>";
        }
        elseif ($_SESSION['beans_account'] && $_SESSION['beans_coupon_data'] && $_SESSION['beans_to_redeem'] > 0) {
            $info_html = sprintf(
                __('You have chosen to redeem %1$s.','woocommerce-beans'),
                print_beans($_SESSION['beans_to_redeem'])
            );
            $button_text = __('Cancel', 'woocommerce-beans');
            $form_html = "<input class='$button_style' type='submit' name='beans_cancel_redeem' value='$button_text'>";

        }elseif ($_SESSION['beans_account']){
            $account = Beans::get('account/'.$_SESSION['beans_account']);
            $info_html = sprintf(
                __('You have  %1$s.','woocommerce-beans'),
                '' .print_beans($account['beans'])
            );
            $button_text = __('Redeem', 'woocommerce-beans');
            $form_html = "<input class='$button_style' type='submit' name='beans_redeem' value='$button_text'/>";

        }else{
            $info_html =  beans_get_text('join_us');
            $form_html = beans_join_button();
        }
        ?>

        <div class="beans-div-cart-page">
            <div class="beans-cart-page-contain">
                <div class="beans-info-div" >
                    <div class="beans-info-contain">
                        <?php


                            if($this->opt['beans_description_on_cart_page'] && $page != 'account' && $_SESSION['beans_account']){
                                $this->render_beans_description();
                            }else {
                                echo $info_html;
                                if ($page != 'account') {
                                    echo "<br/>";
                                    printf(__('Earn %1$s with this purchase.', 'woocommerce-beans'), print_beans($beans_to_earn));
                                }
                                echo "<br/>";
                            }
                            echo beans_info_tag($this->opt['beans_address'], $_SESSION['beans_rate']);
                        ?>
                    </div>
                </div>
                <div class="beans-action-div">
                    <form action="" method="post">
                        <?php echo $form_html; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_order_page($wc_order){

        if(!$wc_order) return;

        $beans_order = new WC_Beans_Order($wc_order->id, false);
        $beans_order->wc_order = $wc_order;

        $beans_to_credit = $beans_order->get_beans_to_credit($_SESSION['beans_rule_currency_spent']);
        $form_html = '';

        if($beans_order->wc_order_id && !$beans_order->account_id && $_SESSION['beans_account']){
            // Are we sure the session beans_account is the good one?
            $beans_order->account_id = $_SESSION['beans_account'];
            $beans_order->save();
            $order_status = $wc_order->get_status();
            if($this->is_order_validated($order_status))
                $beans_order->make_credit();
        }

        $debit = $beans_order->debit_id ? Beans::get('debit/'.$beans_order->debit_id) : null;
        $credit = $beans_order->credit_id ? Beans::get('credit/'.$beans_order->credit_id) : null;
        $debit_info = '';
        if($debit){
            $debit_info = sprintf(
                __('You have been debited %1$s for this purchase.','woocommerce-beans'),
                print_beans($debit['beans'])
            );
        }

        if (!$beans_order->wc_order_id){
            $info_html = sprintf(
                __('No information could be found on this order.','woocommerce-beans'),
                print_beans($beans_to_credit)
            );
        }elseif ($credit){
            $info_html = sprintf(
                __('You have been credited %1$s for this purchase.','woocommerce-beans'),
                print_beans($credit['beans'])
            );
        }elseif ($beans_order->account_id){
            $info_html = sprintf(
                __('You will be credited %1$s for this purchase.','woocommerce-beans'),
                print_beans($beans_to_credit)
            );
        }else{
            $info_html = sprintf(
                __('Join our reward program to earn %1$s with this purchase.', 'woocommerce-beans'),
                print_beans($beans_to_credit)
            );
            $form_html = beans_join_button();
        }
        ?>
        <h2>Beans Details</h2>
        <div class="beans-div-cart-page">
            <div class="beans-cart-page-contain">
                <div class="beans-info-div" >
                    <div class="beans-info-contain">
                        <?php
                            echo $info_html."<br/>";
                            if($debit_info)
                                echo $debit_info."<br/>";
                            echo beans_info_tag($this->opt['beans_address'], $_SESSION['beans_rate']);
                        ?>
                    </div>
                </div>
                <div class="beans-action-div">
                    <?php echo $form_html; ?>
                </div>
            </div>
        </div>
    <?php
    }

        public function render_beans_description_msg($color,$msg){
            //TODO:Add a color option
            /*echo '
                <table style="margin:0px 0px 0.5em;">
                     <tr>
                          <td style="width:15px;">
                            <div style="width:15px;height:12px;background:'.$color.';border-radius: 3px;"></div>
                          </td>
                          <td>
                            <div style="width:100%;height:16px;background:none;border-radius: 3px;">'.$msg.'</div>
                          </td>
                     </tr>
                </table>';*/

                echo $msg.'<br/>' ;


        }

        public function render_beans_description(){

            $beans_to_buy = self::get_current_cart()->subtotal * $_SESSION['beans_rate'];

                $acct = get_beans_account();
                $beans_amount = $acct['beans'];
                $min_beans_redeem = $beans_to_buy * $this->opt['range_min_redeem'] / 100;
                $max_beans_redeem = $beans_to_buy * $this->opt['range_max_redeem'] / 100;
                $percent_complete= round(min(round($beans_amount,-2, PHP_ROUND_HALF_DOWN),round($max_beans_redeem,-2, PHP_ROUND_HALF_DOWN))/ $beans_to_buy * 100,2);

                $beans_to_earn  = (int) (self::get_current_cart()->cart_contents_total * $_SESSION['beans_rule_currency_spent']['beans']);
                $percent_to_earn = round(min($beans_to_earn,$max_beans_redeem)/ $beans_to_buy * 100,2);
                $msg_b_to_earn = sprintf(__('Earn %1$s with this purchase.', 'woocommerce-beans'), print_beans($beans_to_earn));

                if($_SESSION['beans_to_redeem'] && $_SESSION['beans_coupon_data']){
                    $info_html = sprintf(
                        __('You have chosen to redeem %1$s.','woocommerce-beans'),
                        print_beans($_SESSION['beans_to_redeem'])
                    );

                    $this->render_beans_description_msg('green',$info_html);

                }else{
                    $info_html = sprintf(
                        __('You have  %1$s.','woocommerce-beans'),
                        '' .print_beans($acct['beans'])
                    );

                    if($beans_amount < $min_beans_redeem){
                        $this->render_beans_description_msg('red',' '.$info_html.' Not enough to redeem');
                    }/*elseif($beans_amount<$max_beans_redeem){
                        $this->render_beans_description_msg('green',' '.$info_html.' Redeem and get '.$percent_complete.'% discount');
                    }elseif($percent_complete<100){
                        $this->render_beans_description_msg('green',' '.$info_html.' Redeem and get '.$percent_complete.'% discount!!');
                    }*/else{
                        $this->render_beans_description_msg('green',' '.$info_html.' Redeem and get '.$percent_complete.'% discount!!');
                    }
                }

                $this->render_beans_description_msg('blue',' '.$msg_b_to_earn);

        }
    public function render_product_page($page){

        global $post;

        $product        = get_product( $post->ID );
        $regular_price  = $product->get_price();
        $min_price      = $product->min_variation_price;
        $max_price      = $product->max_variation_price;

        if (!empty($min_price) && !empty($max_price) && $min_price!=$max_price){

            $beans_to_earn_min  = (int) ($min_price * $_SESSION['beans_rule_currency_spent']['beans']);
            $beans_to_earn_max  = (int) ($max_price * $_SESSION['beans_rule_currency_spent']['beans']);

            $beans_to_buy_min   = (int) ($min_price * $_SESSION['beans_rate']);
            $beans_to_buy_max   = (int) ($max_price * $_SESSION['beans_rate']);

            $get_product_msg = sprintf(__('Get this product for  %1$s - %2$s.', 'woocommerce-beans'),
                                         print_beans($beans_to_buy_min), print_beans($beans_to_buy_max));
            $buy_product_msg = sprintf(__('Buy this product and earn %1$s - %2$s.', 'woocommerce-beans'),
                                         print_beans($beans_to_earn_min), print_beans($beans_to_earn_max));

        }else{

            $beans_to_earn  = (int) ($regular_price * $_SESSION['beans_rule_currency_spent']['beans']);
            $beans_to_buy   = (int) ($regular_price * $_SESSION['beans_rate']);

            $get_product_msg = sprintf(__('Get this product for  %1$s.', 'woocommerce-beans'), print_beans($beans_to_buy));
            $buy_product_msg = sprintf(__('Buy this product and earn %1$s.', 'woocommerce-beans'), print_beans($beans_to_earn));

        }


            echo '<div class="beans-block-product-page" >';

            if ($this->opt['beans_on_product_page']) {

                    echo $get_product_msg.'<br/>' ;
            }
                echo $buy_product_msg.'<br/>';
                echo beans_info_tag($this->opt['beans_address'], $_SESSION['beans_rate']);
            ?>
        </div>
        <?php
    }

    public function run_debug(){

        // ----------- Coupon Debugging --------------

        $code = BEANS_COUPON_UID;
        beans_log_info("Start debugging coupon: /$code/", true);
        $code  = apply_filters( 'woocommerce_coupon_code', $code );
        beans_log_info("Applying woocommerce_coupon_code coupon: /$code/");

        if($coupon = apply_filters( 'woocommerce_get_shop_coupon_data', false, $code )){
            beans_log_info("Getting coupon was successful: /$code/");
            beans_log_info(print_r($coupon, true));
        }else{
            beans_log_info("Getting coupon failed: /$code/");
            beans_log_info(print_r($coupon, true));
        }

        beans_log_info("List of applied filter to: woocommerce_get_shop_coupon_data");

        $secret_tmp = $this->opt['secret_key'];
        $this->opt['secret_key'] = null;

        global $wp_filter;
        beans_log_info(print_r($wp_filter['woocommerce_get_shop_coupon_data'], true));

        $this->opt['secret_key'] = $secret_tmp;

        // ------------- Localization Debugging ---------------

        $locale = apply_filters('plugin_locale', get_locale(), 'woocommerce-beans');
        beans_log_info("Start debugging localization: /$locale/", true);
        $locale_path = WP_LANG_DIR . "/woocommerce-beans/$locale.mo";
        beans_log_info("Localization path 1: /$locale_path/ exists=".file_exists($locale_path));
        $locale_path = plugin_dir_path(__FILE__) . "/languages/woocommerce-beans-$locale.mo";
        beans_log_info("Localization path 2: /$locale_path/ exists=".file_exists($locale_path));

    }

    /**
     * Log necessary information for debugging purpose.
     *
     * @param string $info to print
     * @param bool $first_line if true add a blank line
     * @return void
     */
}


endif;


/**
 * Use instance to avoid multiple api call so Beans can be super fast.
 */
function wc_beans_instance() {
    return WC_Beans::instance();
}

function get_beans_account(){
    if(!$_SESSION['beans_account']) return false;

    return Beans::get('account/'.$_SESSION['beans_account']);
}

$GLOBALS['wc_beans'] = wc_beans_instance();

 // todo: Log out from Beans when necessary
