<?php 
/**
 * Plugin Name: WooCommerce Beans
 * Plugin URI: http://business.loyalbeans.com/
 * Description: Beans extension for woocommerce. Advanced loyalty program for woocommerce that helps you engage your customers.
 * Version: 0.9.9
 * Author: Beans
 * Author URI: http://business.loyalbeans.com
 * Tested up to: 3.9
 *
 *
 * @author Beans
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) 
    exit; 

//Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) 
    return;

define('BEANS_VERSION',                 '0.9.9');
define('BEANS_BUSINESS_WEBSITE',        'http://business.beans.cards');
define('BEANS_ERROR_LOG',               plugin_dir_path(__FILE__).'error.log');
define('BEANS_INFO_LOG',               plugin_dir_path(__FILE__).'info.log');
define('BEANS_CSS_FILE',                plugin_dir_path(__FILE__).'assets/css/local.beans.css');

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

Beans::$signature = '[CMS]Wordpress '.get_bloginfo('version').' WooCommerce '.wc_version().' Version '.BEANS_VERSION;
Beans::$fail_silently = true;
Beans::set_error_log(3, BEANS_ERROR_LOG);

include_once(plugin_dir_path(__FILE__).'includes/wc-beans-settings.php');


if ( ! class_exists( 'WC_Beans' ) ) :
   
class WC_Beans{
    protected static $_instance = null;
    const uid = 'beans_redeem';
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
        // TODO: Display link to Beans Account in user my account page
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
        $woocommerce->cart->remove_coupon(self::uid);
        $_SESSION['beans_coupon_data'] = false;
    }
    
    public function redeem_beans(){
        $this->init_beans_account($user->ID);
        if(!$this->beans_account__id) return;
        global $woocommerce;
        $woocommerce->cart->add_discount(self::uid);
    }
    
    public function get_beans_coupon($coupon, $coupon_code){

        if( $coupon_code !== self::uid)    return $coupon;
        if( !$this->beans_account__id )        return $coupon;
                
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
        
        $_SESSION['beans_coupon_data'] = $coupon_data;
        
        return $coupon_data;   
    }
    
    public function process_beans_transaction($order_id){
        self::log_info(PHP_EOL.PHP_EOL."============= Start processing beans transaction ==============");
        self::log_info("order_id = $order_id; user_id = ".get_current_user_id()."; beans_account__id = ".$this->beans_account__id);
        
        if( !$this->beans_account__id )        return;
        
        $order = new WC_Order($order_id);
                   
        # Use reward if necessary
        $coupon_codes = $order->get_used_coupons();
        
        foreach($coupon_codes as $code){
                
            if( $code === self::uid){
                self::log_info("Processing beans debit");
                $coupon = new WC_Coupon($code);
                $amount = $coupon->amount;
                // TODO: Handle currency printing.
                $amount_str = sprintf(get_woocommerce_price_format(), 
                                      html_entity_decode (get_woocommerce_currency_symbol(), ENT_QUOTES|ENT_HTML5), 
                                      $amount);
                $data = array(
                    'amount' => (int) $amount,
                    'currency' => strtoupper(get_woocommerce_currency()),
                    'account__id' => $this->beans_account__id,
                    'description' => "Debited for a $amount_str discount",
                    'uid' => $order->id.'_'.$order->order_key,
                );
                self::log_info("data => ".print_r($data, true));
                try{                         
                    $debit=Beans::post('debit', $data, false);
                    if($debit['status'] == 'failed'){
                        self::log_info("**************** ERROR *****************");
                        error_log($debit['failure_message'], 3, BEANS_ERROR_LOG);
                        throw new Exception("Beans error: ".$debit['failure_message']);
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
        
        self::log_info("Processing beans credit");
        // Add beans to the user account
        $total = $order->get_total() - $order->get_shipping();
        self::log_info("oder total => ".$order->get_total()." shipping total => ".$order->get_shipping());
        // TODO: get_shipping is deprecated in favor of get_total_shipping as of 2.1
        // TODO: handle the case where rt_09uk does not exist
        if($total>0){
            $total_str = sprintf(get_woocommerce_price_format(), 
                                 html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES|ENT_HTML5), 
                                 $total);                        
            $data = array(
                'quantity'      => $total,
                'rule_type__id' => 'rt_09uk',
                'account__id'   => $this->beans_account__id,
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
                }
            }catch(Exception $e){
                self::log_info("**************** ERROR *****************");
                error_log($e, 3, BEANS_ERROR_LOG);
                error_log($data, 3, BEANS_ERROR_LOG);
                throw new Exception("Beans error: Unable to credit your beans account.");
            }
        }                      
    }
    
/*  TODO: Handle Beans 2 parts transactions  
   public function confirm_beans_transaction($order_id, $order_status, $new_status){

        if( !$this->beans_account__id )        return;
        
        if ( $new_status=='processing' || $new_status=='completed')
            try{
                die("HERE");
                Beans::post("debit/".$this->beans_account__id."/commit");
            }catch(BeansException $e){
                // error_log($e, 3, BEANS_ERROR_LOG);
            }
    }*/
    
    public function render_cart_page($page){
        wp_enqueue_style( 'beans-wc-style', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
        global $woocommerce;
        $beans_to_earn  = (int) ($woocommerce->cart->subtotal * $_SESSION['beans_rule']['beans']);
        
        if ($this->beans_account__id && $_SESSION['beans_coupon_data']) : 
            
        ?>
            <div class="beans-div-cart-page">
                <table>
                    <tr>
                        <td>
                            <div style="padding: 10px; text-align: left">
                                You have chosen to redeem  <span class="beans-unit"><?php echo $_SESSION['beans_to_redeem']; ?> beans</span>.<br/>
                                Earn <span class="beans-unit"><?php echo  $beans_to_earn ?> beans</span> with this purchase.
                                <span class="beans-unit"> <?php echo $_SESSION['beans_rate'] ?> beans</span> // <?php echo wc_price(1); ?>
                            </div>
                        </td>
                        <td>
                            <div style="text-align: right">
                                <form id="beans_cancel_form" action="" method="post">
                                    <input type="hidden" name="_beans_cancel_redeem_" value="1">
                                    <button class='beans-button' type="submit">Cancel</button> 
                                </form>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        <?php 
            elseif ($this->beans_account__id) :
                $account=Beans::get('account/'.$this->beans_account__id);
         ?>
            
            <div class="beans-div-cart-page">
                <table>
                    <tr>
                        <td>
                            <div style="padding: 10px; text-align: left">
                                You have  <span class="beans-unit"><?php echo $account['beans'];  ?> beans</span>.<br/>
                                Earn <span class="beans-unit"><?php echo  $beans_to_earn ?> beans</span> with this purchase.
                                <span class="beans-unit"> <?php echo $_SESSION['beans_rate'] ?> beans</span> // <?php echo wc_price(1); ?>
                            </div>
                        </td>
                        <td>
                            <div style="text-align: right">
                                <form id="beans_redeem_form" action="" method="post">
                                    <input type="hidden" name="_beans_redeem_" value="1">
                                    <button class='beans-button' type="submit">Redeem my beans</button> 
                                </form>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        <?php else : 
                wp_enqueue_script('beans-wc-script', plugins_url( 'assets/js/local.beans.js' , __FILE__ ));
        ?>
            <script type='text/javascript'>
            window.beansAsyncInit = function() {
                Beans.init({
                     id : '<?php echo $this->opt['public_key']; ?>',
                });
                Beans.onSuccess = function(){
                    window.location = window.location.href
                }
            };
            </script>
            <div class="beans-div-cart-page">
                <table>
                    <tr>
                        <td>
                            <div style="padding: 10px; text-align: left">
                                Connect with Beans to get rewarded when you make a purchase, 
                                like our Facebook page and more...<br/>
                                Earn <span class="beans-unit"><?php echo  $beans_to_earn ?> beans</span> with this purchase.
                                <span class="beans-unit"> <?php echo $_SESSION['beans_rate'] ?> beans</span> // <?php echo wc_price(1); ?>
                            </div>
                        </td>
                        <td>
                            <div style="text-align: right">
                                <button class='beans-button beans-connect' onclick='Beans.connect()' type="button"
                                style="background-image: url('<?php echo plugins_url( 'assets/img/beans-100.png' , __FILE__ ); ?>')">
                                    Connect with Beans
                                </button>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
            
        <?php endif; 
    }
    
    public function render_product_page($page){
        
        // TODO: Show beans for each product on shop page
        wp_enqueue_style( 'beans-wc-style', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
                
        global $post;
        // global $woocommerce;
        
        $product        = get_product( $post->ID );
        $regular_price  = $product->get_sale_price();
        $beans_to_earn  = (int) ($regular_price * $_SESSION['beans_rule']['beans']);
        $beans_to_buy   = (int) ($regular_price * $_SESSION['beans_rate']);
        
        ?>
        <div  class="beans-div-product-page">
            Get this product for <span class="beans-unit" > 
                <?php echo $beans_to_buy ?> beans</span>.<br/>
            Buy this product and earn <span class="beans-unit" >
                <?php echo $beans_to_earn ?> beans</span>.<br/>
            <span class="beans-unit"> <?php echo $_SESSION['beans_rate'] ?> beans</span> // <?php echo wc_price(1); ?>

        </div>
        <?php
    }

    public static function log_info($info){
        $log = date('Y-m-d H:i:s.uP') ." => ".$info.PHP_EOL;
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


?>