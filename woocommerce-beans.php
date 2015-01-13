<?php 
/**
 * Plugin Name: WooCommerce Beans
 * Plugin URI: http://business.beans.cards/
 * Description: Beans extension for woocommerce. Advanced reward program for woocommerce that helps you engage your customers.
 * Version: 0.9.33
 * Author: Beans
 * Author URI: http://business.beans.cards/
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

define('BEANS_VERSION',                 '0.9.33');
define('BEANS_BUSINESS_WEBSITE',        'http://business.beans.cards');
define('BEANS_PLUGIN',                  plugin_basename(__FILE__));
define('BEANS_ERROR_LOG',               plugin_dir_path(__FILE__).'error.log');
define('BEANS_INFO_LOG',                plugin_dir_path(__FILE__).'info.log');
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

include_once(plugin_dir_path(__FILE__).'includes/wc-beans-settings.php');

$a = load_plugin_textdomain('woocommerce-beans',plugin_dir_path(__FILE__) . 'languages/','woocommerce-beans/languages/' );        
        
if ( ! class_exists( 'WC_Beans' ) ) :
   
class WC_Beans{
    protected static $_instance = null;
    const UID = 'beans_redeem';
    protected $opt = null;
    protected $api = null;
    protected $beans_account__id = null;
    protected $coupon_data = null;

    function __construct(){
        
        // Add hooks for action
        add_action('init',                                                    array( $this, 'initialize' ) );
        add_action('wp_logout',                                               array( $this, 'clear_session' ));
        add_action('wp_login',                                                array( $this, 'init_session_hook' ), 10, 2);
        add_action('wp_enqueue_scripts',                                      array( $this, 'enqueue_scripts' ));
        add_filter('woocommerce_get_shop_coupon_data',                        array( $this, 'get_beans_coupon'), 10, 2);
        add_filter('woocommerce_checkout_order_processed',                    array( $this, 'process_beans_transaction'), 10, 1);
        add_filter('woocommerce_order_status_changed',                        array( $this, 'confirm_beans_transaction'), 10, 3);

        // Add hooks for display
        add_action('woocommerce_single_product_summary',                      array( $this, 'render_product_page' ), 15);
        add_action('woocommerce_before_my_account',                           array( $this, 'render_account_page' ), 15);
        add_action('woocommerce_after_cart_table',                            array( $this, 'render_cart_checkout_page' ), 10);
        add_action('woocommerce_before_checkout_form',                        array( $this, 'render_cart_checkout_page' ), 15);
        add_action('woocommerce_after_shop_loop_item_title',                  array( $this, 'render_shop_page' ), 15);
        
        $this->opt = get_option(WC_Beans_Settings::OPT_NAME);
    }
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function initialize(){
        
        WC_Beans_Settings::check_opt();
        
        Beans::init($this->opt['secret_key']);
        
        if (!session_id())
            session_start();
        
        if(!isset($_SESSION['beans_coupon_data']))
            $_SESSION['beans_coupon_data'] = false;            
        
        if(!isset($_SESSION['beans_account_in_db']))
            $_SESSION['beans_account_in_db'] = false;
        
        if(!isset($_SESSION['beans_rule_currency_spent']))
            $_SESSION['beans_rule_currency_spent'] = Beans::get('rule/'.$this->opt['rule_currency_spent_id']);
               
        if(!isset($_SESSION['beans_rate'])){
            $data = array('iso'=> strtoupper(get_woocommerce_currency()));
            $currency = Beans::get('currency/iso', $data);
            $_SESSION['beans_rate'] = $currency['beans'];
        }
        
        $this->init_beans_account(get_current_user_id());
        
        if(isset($_POST['_beans_redeem_']) && !isset($_POST['update_cart']) && !isset($_POST['proceed'])){
            unset($_POST['_beans_redeem_']);
            $this->redeem_beans();
        }
        elseif((isset($_POST['_beans_cancel_redeem_']) && !isset($_POST['proceed'])) || isset($_GET['remove_coupon'])){
            unset($_POST['_beans_cancel_redeem_']);
            $this->cancel_redeem_beans();
        }
    }

    public function enqueue_scripts(){
        if(is_admin()) return;

        wp_enqueue_script('beans-wc-script', plugins_url( 'assets/js/local.beans.js' , __FILE__ ));
        wp_localize_script('beans-wc-script', 'beans_data', array(
            'public_key'  =>  $this->opt['public_key'],
            'beans_popup' =>  $this->opt['beans_popup'],
        ));
        wp_enqueue_style( 'beans-wc-style2', plugins_url( 'assets/css/master.beans.css' , __FILE__ ));
        wp_enqueue_style( 'beans-wc-style1', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
    }

    public function init_session_hook($user_login, $user){
        $user_id = $user->ID;        
        // Look in the database
        if($user_id){
            $account = WC_Beans_Settings::get_beans_account($user_id);
            if($account){
                $this->beans_account__id = $account;
                $_SESSION['beans_account__id'] = $this->beans_account__id;
                $_SESSION['beans_account_in_db'] = True;
                return;
            }
        }
    }
    
    public function init_beans_account($user_id=null){
       
       // Look in the session
        if(isset($_SESSION['beans_account__id'])){
            $this->beans_account__id = $_SESSION['beans_account__id'];
            if($this->beans_account__id && $user_id && !$_SESSION['beans_account_in_db']){
                WC_Beans_Settings::add_beans_account($user_id, $this->beans_account__id);
                $_SESSION['beans_account_in_db'] = True;
                return;
            }
            elseif($this->beans_account__id){
                return;
            }
        }
        
        // Make an API call
        if(isset($_COOKIE['beans_user']) && !$this->beans_account__id){
            $response = Beans::get_token_from_cookie();
            if(isset($response['account__id']))
                $this->beans_account__id = $response['account__id'];
            
            if($user_id && $this->beans_account__id){
                WC_Beans_Settings::add_beans_account($user_id, $this->beans_account__id);
                $_SESSION['beans_account__id'] = $this->beans_account__id;
                $_SESSION['beans_account_in_db'] = True;
                return;
            }
            elseif ($this->beans_account__id) {
                $_SESSION['beans_account__id'] = $this->beans_account__id;
                return;
            }
        }
    }
    
    public function clear_session(){
        unset($_SESSION['beans_account__id']);
        unset($_SESSION['beans_account_in_db']);
        unset($_SESSION['beans_rule_currency_spent']);
        unset($_SESSION['beans_rate']);
        unset($_SESSION['beans_coupon_data']);
        unset($_SESSION['beans_to_redeem']);
        setcookie("beans_user", "", time()-10, "/");
    }
    
    public function cancel_redeem_beans(){
        global $woocommerce;
        $woocommerce->cart->remove_coupon(self::UID);
        $_SESSION['beans_coupon_data'] = false;
    }
    
    public function redeem_beans(){
        if(!$this->beans_account__id) return;
        
        $account = Beans::get('account/'.$this->beans_account__id);
        
        if($account['beans']/$_SESSION['beans_rate'] < 1) {
            wc_add_notice( sprintf( __( 'Not enough Beans to redeem', 'woocommerce-beans' ) ) ,'error' );
            return;
        }
        
        global $woocommerce;
        $woocommerce->cart->add_discount(self::UID);
    }
    
    public function get_beans_coupon($coupon, $coupon_code){
        
        if( $coupon_code != self::UID)          return $coupon;
        if( !$this->beans_account__id )         return $coupon;
                
        if($_SESSION['beans_coupon_data']){
            return $_SESSION['beans_coupon_data'];
        }
        
        $account = Beans::get('account/'.$this->beans_account__id);
        
        global $woocommerce;
        
        $cart_beans_limit_percentage = 1; // $this->opt['cart_limit'];
        $max_coupon = $cart_beans_limit_percentage * $woocommerce->cart->subtotal;
        $coupon_value = min($account['beans']/$_SESSION['beans_rate'], $max_coupon);
        $coupon_value = (int) $coupon_value;
        $_SESSION['beans_to_redeem'] = $coupon_value*$_SESSION['beans_rate'];
        
        $coupon_data = array();
        $coupon_data['id']                        = -1;
        $coupon_data['individual_use']            = null;
        $coupon_data['product_ids']               = null;
        $coupon_data['exclude_product_ids']       = null;
        $coupon_data['usage_limit']               = null;
        $coupon_data['usage_limit_per_user']      = null;
        $coupon_data['limit_usage_to_x_items']    = null;
        $coupon_data['usage_count']               = null;
        $coupon_data['expiry_date']               = strtotime('+1 day', time());
        $coupon_data['apply_before_tax']          = 'no';
        $coupon_data['free_shipping']             = 'no';
        $coupon_data['product_categories']        = null;
        $coupon_data['exclude_product_categories']= null;
        $coupon_data['exclude_sale_items']        = null;
        $coupon_data['minimum_amount']            = null;
        $coupon_data['customer_email']            = null;
        $coupon_data['type']                      = 'fixed_cart';
        $coupon_data['amount']                    = $coupon_value;
        
        self::log_info(print_r($coupon_data, true));
        
        $_SESSION['beans_coupon_data'] = $coupon_data;
        
        return $coupon_data;   
    }
    
    public function process_beans_transaction($order_id){
        self::log_info("============= Start processing beans transaction ==============", true);
        self::log_info("order_id = $order_id; user_id = ".get_current_user_id()."; beans_account__id = ".$this->beans_account__id);
        
        if( !$this->beans_account__id )        return;
        
        $order = new WC_Order($order_id);
        WC_Beans_Settings::add_dr_cr($order_id, $this->beans_account__id);           
        
        # Use reward if necessary
        $coupon_codes = $order->get_used_coupons();
        
        foreach($coupon_codes as $code){
                
            if( $code === self::UID){
                self::log_info("Processing beans debit");
                $coupon = new WC_Coupon($code);
                $amount = $coupon->amount;

                $amount_str = sprintf(get_woocommerce_price_format(), 
                                      " ".strtoupper(get_woocommerce_currency())." ", 
                                      $amount);
                $data = array(
                    'amount'        => (int) $amount,
                    'currency'      => strtoupper(get_woocommerce_currency()),
                    'account__id'   => $this->beans_account__id,
                    'description'   => "Debited for a $amount_str discount",
                    'uid' => $order->id.'_'.$order->order_key,
                );
                self::log_info("data => ".print_r($data, true));
                try{                         
                    $debit=Beans::post('debit', $data, false);
                    if($debit['status'] == 'failed'){
                        self::log_info("**************** ERROR *****************");
                        error_log($debit['failure_message'], 3, BEANS_ERROR_LOG);
                        throw new Exception("Beans error: ".$debit['failure_message']);
                    }else{
                        WC_Beans_Settings::update_dr_cr($order_id,array('debit'=>$debit['id']));
                    }
                }catch(Exception $e){
                    self::log_info("**************** ERROR *****************");
                    error_log($e, 3, BEANS_ERROR_LOG);
                    error_log($data, 3, BEANS_ERROR_LOG);
                    throw new Exception("Beans error: Unable to debit your beans account.");
                }
                unset($_SESSION['beans_rule_currency_spent']);
                unset($_SESSION['beans_rate']);
                unset($_SESSION['beans_coupon_data']);
                unset($_SESSION['beans_to_redeem']); 
            }
        }
    }
    
    public function confirm_beans_transaction($order_id, $order_status, $new_status){

        // TODO: If order cancelled , cancel debit and credit
        
        $dr_cr_data = WC_Beans_Settings::get_dr_cr($order_id);
        
        if( !$dr_cr_data)        return;
       self::log_info("============= ".print_r($dr_cr_data,true), true);
        $order = new WC_Order($order_id);
        
        if ( ($new_status=='processing' || $new_status=='completed') && !isset($dr_cr_data['credit']) ){
           
            self::log_info("Processing beans credit");
            // Add beans to the user account
            $total = $order->get_total() - $order->get_shipping();
            self::log_info("oder total => ".$order->get_total()." shipping total => ".$order->get_total_shipping());
            
            if($total>0 && isset($_SESSION['beans_rule_currency_spent'])){
                $total_str = sprintf(get_woocommerce_price_format(), 
                                     " ".strtoupper(get_woocommerce_currency())." ", 
                                     $total);                       
                $data = array(
                    'quantity'      => $total,
                    'rule_type__id' => 'rt_09uk',
                    'account__id'   => $dr_cr_data['beans_id'],
                    'description'   => "Customer loyalty rewarded for a $total_str purchase",
                    'uid' => $order->id.'_'.$order->order_key,
                );
                self::log_info("data => ".print_r($data, true));             
                try{                         
                    $credit=Beans::post('credit', $data, false);
                    if($credit['status'] == 'failed'){
                        self::log_info("**************** ERROR *****************");
                        error_log($credit['failure_message'], 3, BEANS_ERROR_LOG);
                        //throw new Exception("Beans error: ".$credit['failure_message']);
                    }else{
                        WC_Beans_Settings::update_dr_cr($order_id,array('credit'=>$credit['id']));
                    }
                    
                }catch(Exception $e){
                    self::log_info("**************** ERROR *****************");
                    error_log($e, 3, BEANS_ERROR_LOG);
                    error_log(print_r($data), 3, BEANS_ERROR_LOG);
                   // throw new Exception("Beans error: Unable to credit your beans account.");
                }
            }                  
        }
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
            
            <?php
                printf(
                    __('<span class="beans-unit"> %1$s beans </span>', 'woocommerce-beans'),
                    $beans_to_buy);
            ?>
            
        </span>
        <?php
        }
        
    }
    
    public function render_cart_checkout_page($page){
        global $woocommerce;

        $beans_to_earn  = (int) ($woocommerce->cart->subtotal * $_SESSION['beans_rule_currency_spent']['beans']);
        
        $form_html = '';
        $info_html = '';
        
        if ($this->beans_account__id && $_SESSION['beans_coupon_data'] && $_SESSION['beans_to_redeem'] > 0) {
            $info_html = sprintf( 
                __('You have chosen to redeem <span class="beans-unit"> %s beans</span>.','woocommerce-beans'),
                 $_SESSION['beans_to_redeem']
            );
            $button_text = __('Cancel', 'woocommerce-beans');
            $form_html = "<input type='hidden' name='_beans_cancel_redeem_' value='1'>
                          <button class='button' type='submit'>$button_text</button>";
            
        }elseif ($this->beans_account__id){
            $account = Beans::get('account/'.$this->beans_account__id);
            $info_html = sprintf( 
                __('You have  %s beans.','woocommerce-beans'),
                '<span class="beans-unit">' .$account['beans']
            );
            $info_html .= '</span>';
            $button_text = __('Redeem my beans', 'woocommerce-beans');
            $form_html = "<input type='hidden' name='_beans_redeem_' value='1'>
                          <button class='button' type='submit'>$button_text</button>";
                          
        }else{
            $info_html = __('Connect with Beans to get rewarded when you make a purchase, like our Facebook page and more...', 'woocommerce-beans');
            $button_text = __('Connect with Beans', 'woocommerce-beans');
            $img = plugins_url( 'assets/img/beans-100.png' , __FILE__ );
            $form_html = "<button class='beans-button beans-connect' onclick='Beans.connect()' type='button'
                            style=\"background-image: url('$img')\">
                                $button_text
                          </button>";               
        }
        ?>
        <div class="beans-div-cart-page">
            <table>
                <tr>
                    <td>
                        <div style="padding: 10px; text-align: left; cursor: pointer" onclick="Beans.show();">
                            <?php
                                 echo $info_html;
                                 echo "<br/>";
                                 printf(
                                 __('Earn <span class="beans-unit"> %s beans</span> with this purchase.', 'woocommerce-beans'),
                                 $beans_to_earn); 
                                 
                                 printf(
                                 __('<span class="beans-unit"> %1$s beans </span> // %2$s', 'woocommerce-beans'),
                                 $_SESSION['beans_rate'], wc_price(1));
                            ?>
                            
                            <a class="beans-info-tag" href="//www.beans.cards/$<?php echo $this->opt['card_name']; ?>/"  target="_blank" onclick="Beans.show();return false;">i</a>
                            
                            
                        </div>
                    </td>
                    <td>
                        <div style="text-align: right">
                            <form action="" method="post">
                                <?php echo $form_html; ?>
                            </form>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        <?php 
    }
        
    public function render_account_page($page){
        
        if( !$this->beans_account__id )        return;

        $account=Beans::get('account/'.$this->beans_account__id);
        $account_beans_to_ccy = $account['beans']/$_SESSION['beans_rate'];
        ?>
        <div class="beans-div-account-page">
            <div style="cursor: pointer" onclick="Beans.show();">
                <?php  
                    printf(
                     __('You have %s beans.', 'woocommerce-beans'),
                     '<span class="beans-unit">'.$account['beans']); 
                ?>
                </span>
                (<?php echo wc_price($account_beans_to_ccy); ?>)
                <br/>

                <?php
                printf(
                    __('<span class="beans-unit"> %1$s beans </span> // %2$s', 'woocommerce-beans'),
                    $_SESSION['beans_rate'], wc_price(1));
                ?>
                
                <a class="beans-info-tag" href="//www.beans.cards/$<?php echo $this->opt['card_name']; ?>/"  target="_blank" onclick="Beans.show();return false;">i</a>
                            
                
            </div>
            <a onclick="Beans.show();" class="button" href="#"> 
                <?php _e('Beans account', 'woocommerce-beans' ); ?>
            </a> 
        </div>
        <?php
    }

    public function render_product_page($page){
                 
        global $post;
        
        $product        = get_product( $post->ID );
        $regular_price  = $product->get_price();
        $min_price      = $product->min_variation_price;
        $max_price      = $product->max_variation_price;
        
        if (!empty($min_price) && !empty($max_price)){
            
            $beans_to_earn_min  = (int) ($min_price * $_SESSION['beans_rule_currency_spent']['beans']);
            $beans_to_earn_max  = (int) ($max_price * $_SESSION['beans_rule_currency_spent']['beans']); 
            
            $beans_to_buy_min   = (int) ($min_price * $_SESSION['beans_rate']);
            $beans_to_buy_max   = (int) ($max_price * $_SESSION['beans_rate']);   
            
            $get_product_msg = sprintf(__('Get this product for  %1$s - %2$s beans', 'woocommerce-beans'),'<span class="beans-unit">'.$beans_to_buy_min, $beans_to_buy_max); 
            $buy_product_msg = sprintf(__('Buy this product and earn %1$s - %2$s beans', 'woocommerce-beans'),'<span class="beans-unit">'.$beans_to_earn_min,$beans_to_earn_max); 
            
        }else{
            $beans_to_earn  = (int) ($regular_price * $_SESSION['beans_rule_currency_spent']['beans']);
            $beans_to_buy   = (int) ($regular_price * $_SESSION['beans_rate']);
            
            $get_product_msg = sprintf(__('Get this product for  %s beans', 'woocommerce-beans'),'<span class="beans-unit">'. $beans_to_buy); 
            $buy_product_msg = sprintf(__('Buy this product and earn %s beans', 'woocommerce-beans'),'<span class="beans-unit">'.$beans_to_earn); 
            
        }
        
        ?>
        <div class="beans-block-product-page" style="cursor: pointer" onclick="Beans.show();">
            
            <?php if ($this->opt['beans_on_product_page']) echo $get_product_msg."."."<br/>";?></span>
            
            <?php echo $buy_product_msg; ?></span>.
            <br/>
            
            <?php
                printf(
                    __('<span class="beans-unit"> %1$s beans </span> // %2$s', 'woocommerce-beans'),
                    $_SESSION['beans_rate'], wc_price(1));
            ?>
            
            <a class="beans-info-tag" href="//www.beans.cards/$<?php echo $this->opt['card_name']; ?>/"  target="_blank" onclick="Beans.show(); return false;">i</a>
                            
            
        </div>
        <?php
    }

    public static function log_info($info, $first_line=false){
        $log = date('Y-m-d H:i:s.uP') ." => ".$info.PHP_EOL;
        if ($first_line)
            $log = PHP_EOL.PHP_EOL.$log;
        file_put_contents(BEANS_INFO_LOG, $log, FILE_APPEND);
    }
}


endif;


/**
 * Use instance to avoid mutiple api call so Beans can be super fast.
 */
function wc_beans_instance() {
    return WC_Beans::instance();
}

$GLOBALS['wc_beans'] = wc_beans_instance();

 // TODO: Log out from Beans when necessary
 // TODO: Show  debit & credit beans for each order on order admin page
 // TODO: Fix style bug for mobile device
 
?>