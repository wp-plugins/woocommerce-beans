<?php 
/**
 * Plugin Name: WooCommerce Beans
 * Plugin URI: http://business.loyalbeans.com/
 * Description: Beans extension for woocommerce. Advanced loyalty program for woocommerce that helps you engage your customers.
 * Version: 0.9.8
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

define('BEANS_VERSION',                 '0.9.8');
define('BEANS_BUSINESS_WEBSITE',        'http://business.beans.cards');
define('BEANS_ERROR_LOG',               plugin_dir_path(__FILE__).'error.log');
define('BEANS_CSS_FILE',                plugin_dir_path(__FILE__).'assets/css/local.beans.css');

if( file_exists(BEANS_ERROR_LOG) && filesize(BEANS_ERROR_LOG)>10000)
    unlink(BEANS_ERROR_LOG);

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
        add_action( 'init',                                                    array( $this, 'initialize' ) );
        add_action( 'wp_logout',                                               array( $this, 'clear_session' ));
        add_action( 'wp_login',                                                array( $this, 'init_session_hook' ), 10, 2);
        add_filter('woocommerce_get_shop_coupon_data',                         array( $this, 'get_beans_coupon'), 10, 2);
        add_filter('woocommerce_checkout_order_processed',                     array( $this, 'process_beans_transaction'), 10, 1);
        add_filter('woocommerce_order_status_changed',                         array( $this, 'confirm_beans_transaction'), 10, 3);
        
        // Add hooks for display
        add_action( 'woocommerce_after_cart_table',                            array( $this, 'render_cart_page' ), 10 );
        add_action( 'woocommerce_single_product_summary',                      array( $this, 'render_product_page' ), 15);
        
        $this->opt = get_option(WC_Beans_Settings::OPT_NAME);
        
    }
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function initialize(){
        
         try{
            Beans::init($this->opt['secret_key']);
            
            if (!session_id()) {
                session_start();
            }
            
            if(!isset($_SESSION['beans_rule'])){
                $_SESSION['beans_rule'] = Beans::get('rule/'.$this->opt['rule_id']);
            }
                   
            if(!isset($_SESSION['beans_rate'])){
                $data = array('iso'=> strtoupper(get_woocommerce_currency()));
                $currency=Beans::get('currency/iso', $data);
                $_SESSION['beans_rate']= $currency['beans'];
            }

            if(!isset($_SESSION['beans_redeem'])){
                $_SESSION['beans_redeem'] = false;
            }

            if(isset($_SESSION['beans_account__id'])){
                $this->beans_account__id=$_SESSION['beans_account__id'];
            }else{
                $this->init_session(get_current_user_id());
            }
            
            
        }catch(BeansException $e){}
        
        if(isset($_POST['_beans_redeem_']) && !isset($_POST['update_cart']) && !isset($_POST['proceed'])){
            unset($_POST['_beans_redeem_']);
            $this->redeem_beans();
        }
        elseif(isset($_POST['_beans_cancel_redeem_']) && !isset($_POST['proceed']) ){
            $this->cancel_redeem_beans();
            unset($_POST['_beans_cancel_redeem_']);
        }
        
    }
    
    public function init_session_hook($user_login, $user){
        $this->init_session($user->ID);
    }
    public function init_session($user_id=null){
       
        if($user_id){
            $this->beans_account__id = WC_Beans_Settings::get_beans_account($user_id);
        }
        
        if(!$this->beans_account__id){
            if(isset($_SESSION['beans_account__id'])){
                $this->beans_account__id=$_SESSION['beans_account__id'];
            }
            elseif(!$this->beans_account__id){
                $response = Beans::get_token_from_cookie();
                if(isset($response['account__id'])){
                    $this->beans_account__id = $response['account__id'];
                }
            }
            
            if($user_id && $this->beans_account__id){
                WC_Beans_Settings::add_beans_account($user_id, $this->beans_account__id);
            }
        }
        if($this->beans_account__id){  
            $_SESSION['beans_account__id'] = $this->beans_account__id;
        }
    }
    public function clear_session(){
        unset($_SESSION['beans_account__id']);
        unset($_SESSION['beans_rule']);
        unset($_SESSION['beans_redeem']);
        unset($_SESSION['beans_rate']);
        unset($_SESSION['beans_coupon_data']);
        unset($_SESSION['beans_to_redeem']);
        setcookie("beans_user", "", time()-10, "/");
    }
    
    public function cancel_redeem_beans(){
        global $woocommerce;
        $coupons_code = $woocommerce->cart->get_applied_coupons();
        
        foreach((array)$coupons_code as $code){
            if( $code == self::uid){
                $woocommerce->cart->remove_coupon( $code );
            }
        }
        $_SESSION['beans_coupon_data']=null;
        $_SESSION['beans_redeem'] = false; 
    }
    
    public function redeem_beans(){
        
        if(!$this->beans_account__id) return;
        global $woocommerce;
        $woocommerce->cart->add_discount(self::uid);
        $_SESSION['beans_redeem'] = true; 
        //$woocommerce->session->set( 'refresh_totals', true ); Incompatible with wc<=2.0
    }
    
    public function get_beans_coupon($coupon, $coupon_code){
        
        if(isset($_SESSION['beans_coupon_data'])){
                return  $_SESSION['beans_coupon_data'];
        }
        
        if( $coupon_code !== self::uid)    return $coupon;
        if( !$this->beans_account__id )        return $coupon;
        $account=Beans::get('account/'.$this->beans_account__id);
        
        global $woocommerce;
        
        $cart_beans_limit_percentage = 1; // $this->opt['cart_limit'];
        $max_coupon = $cart_beans_limit_percentage * $woocommerce->cart->total;
        $coupon_value = min($account['beans']/$_SESSION['beans_rate'], $max_coupon);
        $coupon_value = (int) $coupon_value;
        $_SESSION['beans_to_redeem']=$coupon_value*$_SESSION['beans_rate'];
        
        $coupon_data = array();
        $coupon_data['id']                        = -1;
        $coupon_data['amount']                    = 0;
        $coupon_data['individual_use']            = 'yes';
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
        
        if($coupon_value>0){
            $_SESSION['beans_coupon_data']=$coupon_data;
        }
        
        return $coupon_data;   
    }
    
    public function process_beans_transaction($order_id){
        
        if( !$this->beans_account__id )        return;
        
        $order = new WC_Order($order_id);
                   
        # Use reward if necessary
        $coupon_codes = $order->get_used_coupons();

        foreach($coupon_codes as $code){
                
            if( $code === self::uid){
                $coupon = new WC_Coupon($coupon_code);
                $amount = $coupon->amount;
                $data = array(
                    'amount' => (int) $amount,
                    'currency' => strtoupper(get_woocommerce_currency()),
                    'account__id' => $this->beans_account__id,
                    "description" => "Debited for a ".get_woocommerce_currency_symbol()." $amount discount",
                    'uid' => $order->id.'_'.$order->order_key,
                );
                                         
                $debit=Beans::post('debit', $data);
                if($debit['status'] == 'failed'){
                    throw new Exception("Beans error: ".$debit['failure_message']);
                    error_log($debit['failure_message'], 3, BEANS_ERROR_LOG);
                }
                $_SESSION['beans_redeem']=false;
                $_SESSION['beans_coupon_data']=null;  
            }
                  
             
        }
        
        // Add beans to the user card
            $total = $order->get_total() - $order->get_shipping();
            
          if($total>0){                        
                $data = array(
                    'quantity'      => $total,
                    'rule_type__id' => 'rt_09uk',
                    'account__id'   => $this->beans_account__id,
                    'description'   => "Customer loyalty rewarded for a ".get_woocommerce_currency_symbol()."$total purchase",
                    'uid' => $order->id.'_'.$order->order_key,
                );
                print(get_woocommerce_currency_symbol()) ;       
                Beans::post('credit', $data);
                // TODO: handle error
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
        wp_enqueue_script('beans-wc-script', plugins_url( 'assets/js/local.beans.js' , __FILE__ ));
        global $woocommerce;
        $beans_to_earn  = (int) ($woocommerce->cart->total * $_SESSION['beans_rule']['beans']);
        
        if ($this->beans_account__id && !$_SESSION['beans_redeem']) : 
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
        <?php elseif ($this->beans_account__id && $_SESSION['beans_redeem']) : ?>
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
        <?php else : ?>
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
        
        $tooltip =  $_SESSION['beans_rate'].' beans // '. sprintf( get_woocommerce_price_format(), get_woocommerce_currency_symbol(), 1 ) ; 
        $beans_span = '<span class="beans-unit" title="'.$tooltip.'">'
        
        ?>
        <div  class="beans-div-product-page">
            Get this product for 
                <?php echo $beans_span; echo $beans_to_buy ?> beans</span>.<br/>
            Buy this product and earn 
                <?php echo $beans_span; echo $beans_to_earn ?> beans</span>.
        </div>
        <?php
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