<?php
/**
 * Plugin Name: WooCommerce Beans
 * Plugin URI: https://business.trybeans.com/
 * Description: Beans extension for woocommerce. Advanced reward program for woocommerce that helps you engage your customers.
 * Version: 0.10.2
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

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
    return;

define('BEANS_VERSION',                 '0.10.2');
define('BEANS_DECIMALS',                2);
define('BEANS_CACHE_TIME',              5); // api data cached time in minutes
define('BEANS_OPT_NAME',                'wc_beans_options');
define('BEANS_COUPON_UID',              'beans_redeem');
define('BEANS_WEBSITE',                 'www.trybeans.com');
define('BEANS_API_WEBSITE',             'api.trybeans.com');
define('BEANS_BUSINESS_WEBSITE',        'business.trybeans.com');
define('BEANS_PLUGIN',                  plugin_basename(__FILE__));
define('BEANS_INFO_LOG',                plugin_dir_path(__FILE__).'info.txt');
define('BEANS_ERROR_LOG',               plugin_dir_path(__FILE__).'error.txt');
define('BEANS_CSS_FILE',                plugin_dir_path(__FILE__).'local/beans.css');
define('BEANS_CSS_MASTER',              plugin_dir_path(__FILE__).'assets/beans.css');
define('BEANS_REWARD_PAGE',             plugin_dir_path(__FILE__).'includes/reward.php');


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
include_once(plugin_dir_path(__FILE__).'includes/order.php');
include_once(plugin_dir_path(__FILE__).'includes/admin.php');


if ( ! class_exists( 'WC_Beans' ) ) :

class WC_Beans{
    protected static $_instance = null;
    private $opt = null;
    private $filters = null;
    private $is_debug = false;
    private $is_deactivate = false;

    function __construct(){

        $this->opt = get_option(BEANS_OPT_NAME);

        $this->filters = array(
            // (filter_name, function_name, priority, accepted_args)

            array('init',                                           'initialize',                   10, 1),
            array('wp_logout',                                      'clear_session',                10, 1),
            array('wp_login',                                       'init_session_hook',            10, 2),
            array('wp_loaded',                                      'form_post_handler',            30, 1),
            array('user_register',                                  'register_new_user',            10, 1),
            array('profile_update',                                 'register_new_user',            10, 1),
            array('wp_enqueue_scripts',                             'enqueue_scripts',              10, 1),

            array('woocommerce_get_shop_coupon_data',               'get_coupon',                   10, 2),
            array('woocommerce_checkout_order_processed',           'create_order',                 10, 1),
            array('woocommerce_order_status_changed',               'update_order',                 10, 3),
            array('woocommerce_update_cart_action_cart_updated',    'cancel_redeem_beans',          10, 1),

            array('woocommerce_single_product_summary',             'render_product_page',          15, 1),
            array('woocommerce_after_cart_table',                   'render_cart_checkout_page',    10, 1),
            array('woocommerce_before_checkout_form',               'render_cart_checkout_page',    15, 1),
            array('woocommerce_order_details_after_order_table',    'render_order_page',            15, 1),
            array('the_content',                                    'render_reward_program_page',   10, 1),
        );

        foreach($this->filters as $filter){
            add_filter($filter[0], array($this, $filter[1]), $filter[2], $filter[3]);
        }

    }

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function initialize(){

        /*if(defined('BEANS_WEBSITE_BETA')){
            Beans::$endpoint = BEANS_API_BETA;
            define('BEANS_WEBSITE',                 BEANS_WEBSITE_BETA);
            define('BEANS_BUSINESS_WEBSITE',        BEANS_BUSINESS_WEBSITE);
            define('BEANS_API_WEBSITE',             BEANS_API_BETA);
        }*/

        if (!session_id())
            session_start();

        // activate debug mode if necessary
        $this->is_debug = isset($_GET['beans-debug']) || isset($_SESSION['beans_debug']);

        beans_set_locale();

        WC_Beans_Settings::check_opt();

        Beans::init($this->opt['secret_key']);

        if(empty($_SESSION['beans_card']) && $this->opt['beans_address']) {
            $_SESSION['beans_card']  = Beans::get('card/' . $this->opt['beans_address']);
            $_SESSION['beans_rate'] = $_SESSION['beans_card']['beans_rate'] ;
        }

        // Check if test mode and not admin and not debug mode
        // This the highest level we could check test mode because of user env vars
        $this->is_deactivate = empty($_SESSION['beans_card']['is_active']) && !current_user_can('manage_woocommerce') && !$this->is_debug;
        if($this->is_deactivate)
            return $this->deactivate();

        if(isset($_GET['beans-debug']))
            $_SESSION['beans_debug'] = $_GET['beans-debug'] ? true : null;

        if(!isset($_SESSION['beans_account']))
            $_SESSION['beans_account'] = false;

        if(!isset($_SESSION['beans_coupon_data']))
            $_SESSION['beans_coupon_data'] = false;

        // Flush beans_rule_currency_spent, beans_name && beans_rate if there are too old
        if(!empty($_SESSION['beans_last_update'])
                && (time() - $_SESSION['beans_last_update']) > 60 * BEANS_CACHE_TIME){
            unset($_SESSION['beans_card']);
            unset($_SESSION['beans_rate']);
            unset($_SESSION['beans_rule_currency_spent']);
            unset($_SESSION['beans_last_update']);
        }

        if(empty($_SESSION['beans_rule_currency_spent'])){
            $_SESSION['beans_rule_currency_spent'] = Beans::get('rule/beans:currency_spent');
        }

        if(empty($_SESSION['beans_last_update']))
            $_SESSION['beans_last_update'] = time();

        if(!empty($_SESSION['beans_card']))
            define('BEANS_NAME', $_SESSION['beans_card']['beans_name']);

        if(!empty($_SESSION['beans_card']))
            define('BEANS_SECONDARY_COLOR', $_SESSION['beans_card']['style']['secondary_color']);

        $this->init_beans_account();

        if(is_page(get_option(WC_Beans_Settings::REWARD_PROGRAM_PAGE ))){
            remove_filter('the_content', 'wpautop');
        }

        if($this->is_debug) {
            print('Beans Debug mode is active <br/>');
            $this->run_debug();
        }

        return true;
    }

    public function enqueue_scripts(){
        if(is_admin()) return;
        $login_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        if(strpos($login_url,'?') !== false)
            $login_url .= '&edit-account=1';
        else
            $login_url .= '?edit-account=1';
        if(empty($login_url))
            $login_url = wp_login_url();
        $data = array(
            'beans_address'     =>  $this->opt['beans_address'],
            'connect_mode'      =>  $this->opt['auto_enroll'] ? 2:1,
            'login_url'         =>  $login_url,
            'account'           =>  $_SESSION['beans_account'],
            'domain'            =>  BEANS_WEBSITE,
            'domain_api'        =>  BEANS_API_WEBSITE,
            'display'           =>  !$this->is_deactivate,
        );
        if(!empty($_SESSION['beans_auth'])){
            $data['authentication'] = $_SESSION['beans_auth']['authentication'];
//            $_SESSION['beans_auth'] = false;
        }
        wp_enqueue_script('beans-wc-script', plugins_url( 'assets/beans.js' , __FILE__ ));
        wp_localize_script('beans-wc-script', 'beans_data', $data);
        wp_enqueue_style( 'beans-wc-style2', plugins_url( 'assets/beans.css' , __FILE__ ));
        wp_enqueue_style( 'beans-wc-style1', plugins_url( 'local/beans.css' , __FILE__ ));
    }

    public function init_session_hook($user_login, $user){

         $user_id = $user->ID;
        // Look in the database
        if($user_id){
            $_SESSION['beans_account'] = get_user_meta($user_id, 'beans_account_id', true);
        }
    }

    public static function register_new_user($user_id){
        if(!beans_get_opt('auto_enroll')) return;
        WC_Beans_Settings::register_new_user($user_id);
    }

    public function init_beans_account(){

        $user_id = get_current_user_id();

        // 1. Look in the database
        if(!$_SESSION['beans_account'] && $user_id){
            $_SESSION['beans_account'] = get_user_meta($user_id, 'beans_account_id', true);
        }

        // 2. Make an API call
        if(!$_SESSION['beans_account'] && !empty($_COOKIE['beans_user'])){
            $response = Beans::get_token_from_cookie();
            if(!empty($response['account'])){
                $_SESSION['beans_account']= $response['account'];
                update_user_meta($user_id, 'beans_account_id', $_SESSION['beans_account']);
            }
        }

//         3. If account does exist create if auto enroll
//        if(!$_SESSION['beans_account'] && $user_id){
//            $this->register_new_user($user_id);
//            $_SESSION['beans_account'] = get_user_meta($user_id, 'beans_account_id', true);
//        }

        // Authenticate in Beans
        if($_SESSION['beans_account'] && !isset($_SESSION['beans_auth'])){
            $_SESSION['beans_auth'] = Beans::post('oauth', array('account'=> $_SESSION['beans_account']));
        }
    }

    public function clear_session(){
        unset($_SESSION['beans_card']);
        unset($_SESSION['beans_rate']);
        unset($_SESSION['beans_account']);
        unset($_SESSION['beans_to_redeem']);
        unset($_SESSION['beans_coupon_data']);
        unset($_SESSION['beans_auth']);
        unset($_SESSION['beans_debug']);
        unset($_SESSION['beans_rule_currency_spent']);
        setcookie('beans_user', '', time()-10, '/');
    }

    private static function get_current_cart(){
        global $woocommerce;

        if(!empty($woocommerce->cart) && empty($woocommerce->cart->cart_contents))
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
                    $_SESSION['beans_card']['beans_name'] )
                ,'error' );
            return;
        }

        $cart = self::get_current_cart();
        //TODO: Do this better
        $min_coupon = (int) ($cart->subtotal * $_SESSION['beans_rate'] );
        $min_coupon = floor($min_coupon * $this->opt['range_min_redeem'] / 10000)*100;
        if ($account['beans'] < $min_coupon ){
            wc_add_notice(
                sprintf(__( 'Not enough %1$s to redeem.', 'woocommerce-beans' ), $_SESSION['beans_card']['beans_name']).' '. // optimizing for translation
                sprintf(__( 'Minimal redeem is %2$s.', 'woocommerce-beans' ), print_beans($min_coupon)),
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

        $this->is_debug ? beans_log_info("get_beans_coupon: /$coupon_code/") : null;

        if( $coupon_code != BEANS_COUPON_UID)     return $coupon;
        if( !$_SESSION['beans_account'] )         return $coupon;

        if($_SESSION['beans_coupon_data'])
            return $_SESSION['beans_coupon_data'];

        $cart = self::get_current_cart();
        if(empty($cart))                          return $coupon;

        $account = Beans::get('account/'.$_SESSION['beans_account']);

        $max_coupon = $this->opt['range_max_redeem'] / 100 * $cart->subtotal;
        $coupon_value = (int) min($account['beans']/$_SESSION['beans_rate'], $max_coupon);
        $_SESSION['beans_to_redeem'] = $coupon_value * $_SESSION['beans_rate'];

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
        $coupon_data['discount_cart_tax']          = null;
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

        $this->is_debug ? beans_log_info(print_r($coupon_data, true)) : null;

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

        if($new_status=='cancelled'){
            $beans_order->cancel();
        }
    }

    public function is_order_validated($status){
        if($status=='processing' || $status=='completed')
            return true;
        else
            return false;
    }

    function render_reward_program_page($content, $vars=null)
    {
        if (strpos($content,'[beans_page]') !== false) {
            $beans_address = $this->opt['beans_address'];
            ob_start();
            include BEANS_REWARD_PAGE;
            $content_beans = ob_get_clean();
            $content = str_replace('[beans_page]', $content_beans, $content);
        }
        return $content;
    }

    public function render_product_page($page){

        if($this->opt['disable_product_page']) return;

        global $post;

        $product        = get_product( $post->ID );
        $regular_price  = $product->get_price();
        $min_price      = $product->min_variation_price;
        $max_price      = $product->max_variation_price;

        if (!empty($min_price) && !empty($max_price) && $min_price!=$max_price){

            $beans_to_earn_min  = (int) ($min_price * $_SESSION['beans_rule_currency_spent']['beans']);
            $beans_to_earn_max  = (int) ($max_price * $_SESSION['beans_rule_currency_spent']['beans']);

            $buy_product_msg = sprintf(__('Buy this product and earn %1$s - %2$s.', 'woocommerce-beans'),
                print_beans($beans_to_earn_min), print_beans($beans_to_earn_max));

        }else{

            $beans_to_earn  = (int) ($regular_price * $_SESSION['beans_rule_currency_spent']['beans']);

            $buy_product_msg = sprintf(__('Buy this product and earn %1$s.', 'woocommerce-beans'), print_beans($beans_to_earn));

        }

        echo '<div class="beans-product"><div class="beans-product-div">';
        echo $buy_product_msg.'<br/>';
        echo beans_info_tag($this->opt['beans_address'], $_SESSION['beans_rate']);
        echo '</div></div>';
    }

    public function render_cart_checkout_page($page){

        $beans_to_earn  = (int) (self::get_current_cart()->cart_contents_total * $_SESSION['beans_rule_currency_spent']['beans']);

        if ($_SESSION['beans_account'] && $_SESSION['beans_coupon_data'] && $_SESSION['beans_to_redeem'] > 0) {
            $info_html = sprintf(
                __('You have chosen to redeem %1$s.','woocommerce-beans'),
                print_beans($_SESSION['beans_to_redeem'])
            );
            $button_text = __('Cancel', 'woocommerce-beans');
            $form_html = "<button class='button' onclick='return beans_post(\"\", {beans_cancel_redeem: 1})' type='button'>$button_text</button>";

        }elseif ($_SESSION['beans_account']){
            $account = Beans::get('account/'.$_SESSION['beans_account']);
            $info_html = sprintf(
                __('You have  %1$s.','woocommerce-beans'),
                print_beans($account['beans'])
            );
            $button_text = __('Redeem', 'woocommerce-beans');
            $form_html = "<button class='button' onclick='return beans_post(\"\", {beans_redeem: 1})' type='button'>$button_text</button>";

        }else{
            if($beans_to_earn) {
                $info_html = sprintf(
                    __( 'Join our reward program and earn %1$s with this purchase.', 'woocommerce-beans' ),
                    print_beans( $beans_to_earn )
                );
                $beans_to_earn = null;
            }
            else{
                $info_html = __( 'Join our reward program.', 'woocommerce-beans' );
            }
            $form_html = beans_join_button();
        }
        ?>

        <div class="beans-cart">
            <div class="beans-cart-div">
                <div class="beans-cart-info-div" >
                    <div class="beans-info-contain">
                        <?php
                            if(false && $page != 'account' && $_SESSION['beans_account']){
                                $this->render_beans_description();
                            }else {
                                echo $info_html;
                                echo "<br/>";
                                if ($page != 'account' && $beans_to_earn) {
                                    printf(__('Earn %1$s with this purchase.', 'woocommerce-beans'), print_beans($beans_to_earn));
                                    echo "<br/>";
                                }
                            }
                            echo beans_info_tag($this->opt['beans_address'], $_SESSION['beans_rate']);
                        ?>
                    </div>
                </div>
                <div class="beans-cart-action-div">
                    <?php echo $form_html; ?>
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

        $beans_account = $_SESSION['beans_account'];
        if(!$beans_account){
            $beans_account = get_user_meta($wc_order->customer_user, 'beans_account_id', true);
        }

        if(!$beans_order->account_id && $beans_account){
            // Are we sure the session beans_account is the good one?
            $beans_order->account_id = $beans_account;
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
        echo $msg.'<br/>' ;
    }

    public function render_beans_description(){

        $beans_to_buy = self::get_current_cart()->subtotal * $_SESSION['beans_rate'];

            $acct = get_beans_account();
            $beans_amount = $acct['beans'];
            $min_beans_redeem = $beans_to_buy * $this->opt['range_min_redeem'] / 100;
            $max_beans_redeem = $beans_to_buy * $this->opt['range_max_redeem'] / 100;
            $percent_complete= round( min(floor($beans_amount/100)*100,floor($max_beans_redeem/100)*100)/ $beans_to_buy * 100,2);

            $beans_to_earn  = (int) (self::get_current_cart()->cart_contents_total * $_SESSION['beans_rule_currency_spent']['beans']);
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
                }else{
                    $this->render_beans_description_msg('green',' '.$info_html.' Redeem and get '.$percent_complete.'% discount!!');
                }
            }

            $this->render_beans_description_msg('blue',' '.$msg_b_to_earn);

    }

    public function deactivate(){
        foreach($this->filters as $filter){
            remove_filter($filter[0], array($this, $filter[1]), $filter[2], $filter[3]);
        }
        return True;
    }

    public function run_debug(){

        // ----------- Coupon Debugging --------------

        $code = BEANS_COUPON_UID;
        beans_log_info("Start debugging coupon: /$code/", true);
        $code  = apply_filters( 'woocommerce_coupon_code', $code );
        beans_log_info("Applying woocommerce_coupon_code coupon: /$code/");

        $coupon_enabled = apply_filters( 'woocommerce_coupons_enabled', get_option( 'woocommerce_enable_coupons' ) == 'yes' );
        beans_log_info("Checking if admin has enabled the use of coupon: /$coupon_enabled/");

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
        beans_log_info("Start debugging localization: '$locale'", true);

        $locale_path = WP_LANG_DIR . "/woocommerce-beans/$locale.mo";
        beans_log_info("Localization path 1: '$locale_path' exists=".file_exists($locale_path));

        $locale_path = WP_LANG_DIR . "/woocommerce-beans/woocommerce-beans-$locale.mo";
        beans_log_info("Localization path 2: '$locale_path' exists=".file_exists($locale_path));

        $locale_path = WP_LANG_DIR . "/plugins/woocommerce-beans-$locale.mo";
        beans_log_info("Localization path 3: '$locale_path' exists=".file_exists($locale_path));

        $locale_path = plugin_dir_path(__FILE__) . "languages/woocommerce-beans-$locale.mo";
        beans_log_info("Localization path 4: '$locale_path' exists=".file_exists($locale_path));

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

$GLOBALS['wc_beans'] = wc_beans_instance();

 // todo: Log out from Beans when necessary
