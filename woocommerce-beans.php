<?php 
/**
 * Plugin Name: WooCommerce Beans
 * Plugin URI: http://business.beans.cards/
 * Description: Beans extension for woocommerce. Advanced reward program for woocommerce that helps you engage your customers.
 * Version: 0.9.18
 * Author: Beans
 * Author URI: http://business.beans.cards/
 * Text Domain: wc-beans
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

define('BEANS_VERSION',                 '0.9.18');
define('BEANS_BUSINESS_WEBSITE',        'http://business.beans.cards');
define('BEANS_PLUGIN',                  plugin_basename(__FILE__));
define('BEANS_ERROR_LOG',               plugin_dir_path(__FILE__).'error.log');
define('BEANS_INFO_LOG',                plugin_dir_path(__FILE__).'info.log');
define('BEANS_CSS_FILE',                plugin_dir_path(__FILE__).'assets/css/local.beans.css');
define('BEANS_CSS_MASTER',              plugin_dir_path(__FILE__).'assets/css/master.beans.css');

load_plugin_textdomain('wc-beans', false, plugin_dir_path(__FILE__) . '/languages' );

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
        add_filter('woocommerce_get_shop_coupon_data',                        array( $this, 'get_beans_coupon'), 10, 2);
        add_filter('woocommerce_checkout_order_processed',                    array( $this, 'process_beans_transaction'), 10, 1);
        add_filter('woocommerce_order_status_changed',                        array( $this, 'confirm_beans_transaction'), 10, 3);
        
        // Add hooks for display
        add_action('woocommerce_after_cart_table',                            array( $this, 'render_cart_page' ), 10 );
        add_action('woocommerce_single_product_summary',                      array( $this, 'render_product_page' ), 15);
        add_action('woocommerce_before_my_account',                           array( $this, 'render_account_page' ), 15);

        // TODO: Allow User to redeem its beans on the checkout page
        $this->opt = get_option(WC_Beans_Settings::OPT_NAME);
    }
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function initialize(){
        
        Beans::init($this->opt['secret_key']);
        
        if (!session_id())
            session_start();
        
        if(!isset($_SESSION['beans_coupon_data']))
            $_SESSION['beans_coupon_data'] = false;            
        
        if(!isset($_SESSION['beans_account_in_db']))
            $_SESSION['beans_account_in_db'] = false;
        
        if(!isset($_SESSION['beans_rule']))
            $_SESSION['beans_rule'] = Beans::get('rule/'.$this->opt['rule_id']);
               
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
        elseif(isset($_POST['_beans_cancel_redeem_']) && !isset($_POST['proceed']) ){
            unset($_POST['_beans_cancel_redeem_']);
            $this->cancel_redeem_beans();
        }
        
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
        unset($_SESSION['beans_rule']);
        unset($_SESSION['beans_rate']);
        unset($_SESSION['beans_coupon_data']);
        unset($_SESSION['beans_to_redeem']);
        setcookie("beans_user", "", time()-10, "/");
    }
    
    public function cancel_redeem_beans(){
        // TODO: Track when user choose to remove coupon using wc  
        global $woocommerce;
        $woocommerce->cart->remove_coupon(self::UID);
        $_SESSION['beans_coupon_data'] = false;
    }
    
    public function redeem_beans(){
        $this->init_beans_account($user->ID);
        if(!$this->beans_account__id) return;
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
        self::log_info("OPT : ".print_r($this->opt));
        self::log_info("RULE ID: ".$this->opt['rule_id']);
        
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
                $_SESSION['beans_coupon_data'] = false; 
                // TODO: You have chosen to redeem your beans message appear after a repeat purchase  
            }
        }
        
            
    }
    
   public function confirm_beans_transaction($order_id, $order_status, $new_status){

        // TODO: If order cancelled , cancel debit and credit
        
        $dr_cr_data = WC_Beans_Settings::get_dr_cr($order_id);
        
        if( !$dr_cr_data)        return;
        
        $order = new WC_Order($order_id);
        
        if ( ($new_status=='processing' || $new_status=='completed') && !isset($dr_cr_data['credit']) ){
           
            self::log_info("Processing beans credit");
            // Add beans to the user account
            $total = $order->get_total() - $order->get_shipping();
            self::log_info("oder total => ".$order->get_total()." shipping total => ".$order->get_total_shipping());
            // TODO: handle the case where rt_09uk does not exist
            if($total>0){
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
                        throw new Exception("Beans error: ".$credit['failure_message']);
                    }else{
                        WC_Beans_Settings::update_dr_cr($order_id,array('credit'=>$credit['id']));
                    }
                    
                }catch(Exception $e){
                    self::log_info("**************** ERROR *****************");
                    error_log($e, 3, BEANS_ERROR_LOG);
                    error_log($data, 3, BEANS_ERROR_LOG);
                    throw new Exception("Beans error: Unable to credit your beans account.");
                }
            }                  
        }
    }
    
    public function render_cart_page($page){
        global $woocommerce;
        
        wp_enqueue_style( 'beans-wc-style', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
        $beans_to_earn  = (int) ($woocommerce->cart->subtotal * $_SESSION['beans_rule']['beans']);
        
        //TODO: handle when not enough beans
        $form_html = '';
        $info_html = '';
        
        if ($this->beans_account__id && $_SESSION['beans_coupon_data']) {
            $info_html = sprintf( 
                __('You have chosen to redeem <span class="beans-unit"> %s beans</span>.','wc-beans'),
                 $_SESSION['beans_to_redeem']
            );
            $button_text = __('Cancel', 'wc-beans');
            $form_html = "<input type='hidden' name='_beans_cancel_redeem_' value='1'>
                          <button class='button' type='submit'>$button_text</button>";
            
        }elseif ($this->beans_account__id){
            $account = Beans::get('account/'.$this->beans_account__id);
            $info_html = sprintf( 
                __('You have  %s beans.','wc-beans'),
                '<span class="beans-unit">' .$account['beans']
            );
            $info_html .= '</span>';
            $button_text = __('Redeem my beans', 'wc-beans');
            $form_html = "<input type='hidden' name='_beans_redeem_' value='1'>
                          <button class='button' type='submit'>$button_text</button>";
                          
        }else{
            wp_enqueue_script('beans-wc-script', plugins_url( 'assets/js/local.beans.js' , __FILE__ ));
            wp_localize_script('beans-wc-script', 'beans_data', array(
                'public_key'  =>   $this->opt['public_key'],
            ));
            $info_html = __('Connect with Beans to get rewarded when you make a purchase, 
                             like our Facebook page and more...', 'wc-beans');
            $button_text = __('Connect with Beans', 'wc-beans');
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
                        <div style="padding: 10px; text-align: left">
                            <?php
                                 echo $info_html;
                                 echo "<br/>";
                                 printf(
                                 __('Earn <span class="beans-unit"> %s beans</span> with this purchase.', 'wc-beans'),
                                 $beans_to_earn); 
                            ?>
                            <span class="beans-unit"> <?php echo $_SESSION['beans_rate'] ?> beans</span> 
                            // <?php echo wc_price(1); ?>
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
        
        wp_enqueue_style('beans-wc-style', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
        $account=Beans::get('account/'.$this->beans_account__id);
        $account_beans_to_ccy = $account['beans']/$_SESSION['beans_rate'];
        ?>
        <div class="beans-div-account-page">
            <div>
                <?php  
                    printf(
                     __('You have %s beans.', 'wc-beans'),
                     '<span class="beans-unit">'.$account['beans']); 
                ?>
                </span>
                (<?php echo wc_price($account_beans_to_ccy); ?>)
                <br/>            
                <span class="beans-unit"> <?php echo $_SESSION['beans_rate'] ?> beans</span> 
                // <?php echo wc_price(1); ?>
            </div>
            <a href="//<?php echo Beans::$domain."/$".$this->opt['card_name'] ?>" class="button" target="_blank"> 
                <?php _e('Beans account', 'wc-beans' ); ?>
            </a> 
        </div>
        <?php
    }

    public function render_product_page($page){
        
        // TODO: Show beans for each product on shop page
        wp_enqueue_style( 'beans-wc-style', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
                
        global $post;
        
        // TODO: Support product price variation 
        
        $product        = get_product( $post->ID );
        $regular_price  = $product->get_price();
        $beans_to_earn  = (int) ($regular_price * $_SESSION['beans_rule']['beans']);
        $beans_to_buy   = (int) ($regular_price * $_SESSION['beans_rate']);
        
        ?>
        <div  class="beans-div-product-page">
            <?php  
                 printf(
                 __('Get this product for  %s beans', 'wc-beans'),
                 '<span class="beans-unit"> '. $beans_to_buy); 
            ?>
            </span>.
            <br/>
            <?php  
                 printf(
                 __('Buy this product and earn %s beans', 'wc-beans'),
                 '<span class="beans-unit"> '.$beans_to_earn); 
            ?>
            </span>.
            <br/>            
            <span class="beans-unit"> <?php echo $_SESSION['beans_rate'] ?> beans</span> 
            // <?php echo wc_price(1); ?>

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
 // TODO: Support translation
 // TODO: Fix style bug for mobile device
 
?>