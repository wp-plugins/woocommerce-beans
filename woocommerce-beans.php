<?php 
/**
 * Plugin Name: WooCommerce Beans
 * Plugin URI: http://business.loyalbeans.com/
 * Description: Beans extension for woocommerce. Advanced loyalty program for woocommerce that helps you engage your customers.
 * Version: 0.9.0
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
 
define('BEANS_VERSION',                 '0.9.0');
define('BEANS_BUSINESS_WEBSITE',        'http://business.loyalbeans.com');
define('BEANS_ERROR_LOG',               plugin_dir_path(__FILE__).'/error.log');
define('BEANS_CSS_FILE',                plugin_dir_path(__FILE__).'/assets/css/local.beans.css');

if( file_exists(BEANS_ERROR_LOG) && filesize(BEANS_ERROR_LOG)>10000) 
    unlink(BEANS_ERROR_LOG);
 
include_once(plugin_dir_path(__FILE__).'/includes/beans-api.php');

function wc_version() {
    if ( ! function_exists( 'get_plugins' ) )
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    $plugin_folder = get_plugins( '/woocommerce' );
    return $plugin_folder['woocommerce.php']['Version'];
}

BeansAPI::$SIGNATURE = '[CMS]Wordpress '.get_bloginfo('version').' WooCommerce '.wc_version().' Version '.BEANS_VERSION;

include_once(plugin_dir_path(__FILE__).'/includes/wc-beans-settings.php');
 

if ( ! class_exists( 'WC_Beans' ) ) :
   
class WC_Beans{
    
    protected static $_instance = null;
    
    const uid = 'beans_';
    protected $opt = null;
    protected $api = null;
    protected $reward = null;
    protected $card_check  = false;
    protected $is_ready = false;
    
    function __construct(){
        
        // Add hooks for display
        add_action( 'woocommerce_after_cart_table',                            array( $this, 'render_plugin_cart_page' ), 10 );
        add_action( 'woocommerce_single_product_summary',                      array( $this, 'render_plugin_product_page' ), 50);
        add_action( 'woocommerce_order_details_after_order_table',             array( $this, 'render_plugin_order_page' ) );
        
        // Add hooks for action
        add_action( 'init',                                                    array( $this, 'update_cart_by_post' ) );
        add_filter('woocommerce_get_shop_coupon_data',                         array( $this, 'get_beans_coupon'), 10, 2);
        add_filter('woocommerce_checkout_order_processed',                     array( $this, 'process_beans_transaction'), 10, 1);
        add_filter('woocommerce_order_status_changed',                         array( $this, 'confirm_beans_transaction'), 10, 3);
        
        $this->opt = get_option(WC_Beans_Settings::OPT_NAME);
        
        try{
            $this->api = new BeansAPI(array('secret' => $this->opt['secret_key']));
            $this->card_check = $this->api->call('card/check/');
            $this->is_ready = true;
        }catch(BeansException $e){}
    }
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function update_cart_by_post(){
        
        if(isset($_POST['_beans_updt_cart_']) ){
            unset($_POST['_beans_updt_cart_']);
            $this->update_cart();
        }
    }

    public function update_cart(){

        if(!$this->is_ready) return;

        global $woocommerce;
        
        // Get the reward the user want to use. return null if no reward
        if(!$this->reward)
            $this->reward = $this->api->call('reward/get/');
        
        //Check if reward can be used
        if ($this->reward && !$this->api->call('reward/check/')) 
            $this->reward = null;
        
        $reward = $this->reward;
            
        $reward_code_pattern = self::uid . ($reward? $reward['id'] : '0');
        
        $is_reward_in_cart = false;
        
        // Remove outdated coupon
        $coupons_code = $woocommerce->cart->applied_coupons;
        foreach($coupons_code as $code){
            
            if( strpos($code, self::uid) !== false)
                if(strpos($code, $reward_code_pattern) !== false)
                    $is_reward_in_cart = true;
                else
                    $woocommerce->cart->remove_coupon( $code );
        }
                
        // if reward exist and not in cart, add reward to cart
        if($reward && !$is_reward_in_cart)
            $woocommerce->cart->add_discount($reward_code_pattern);
         
        //$woocommerce->session->set( 'refresh_totals', true ); Incompatible with wc<=2.0
    }
    
    public function get_beans_coupon($coupon, $coupon_code){
        
        if( strpos($coupon_code, self::uid) === false)    return $coupon;
        if( !$this->is_ready )                            return $coupon;
        
        $tmp = explode ('_', $coupon_code);
        $reward_id = $tmp[1];
        $arg = array('reward' => $reward_id);
        
        if( (!$this->reward || $this->reward['id'] != $reward_id) )
            if($this->api->call('reward/check/', $arg))
                $this->reward = $this->api->call('reward/get/', $arg);
            else
                return $coupon;
                
        $reward = $this->reward; 
        
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
        
        switch ($reward['type']){
            
            case BeansAPI::REWARD_CART_COUPON :
                $coupon_data['type'] = 'fixed_cart';
                if (get_woocommerce_currency() == $reward['unit'])
                    $coupon_data['amount'] = $reward['value'];
                break;
                
            case BeansAPI::REWARD_CART_DISCOUNT:
                $coupon_data['type'] = 'percent';
                $coupon_data['amount'] = $reward['value'] * 100;
                break;
                
            default:
                break;
        }
        return $coupon_data;   
    }
    
    public function process_beans_transaction($order_id){
        
        if (!$this->is_ready || !$this->card_check) return;
        
        $order = new WC_Order($order_id);
                   
        # Use reward if necessary
        $coupon_codes = $order->get_used_coupons();

        foreach($coupon_codes as $code){

            if(strpos($code, self::uid) !== false) 
                try{
                    $tmp = explode ('_', $code);
                    
                    $arg ['reward']     = $tmp[1];
                    $arg ['unique_id']  = $code.'_'.$order->id.'_'.$order->order_key;
                                                            
                    $this->api->call('reward/prepare_use/', $arg);
                    $this->api->call('reward/execute/', $arg);
                    
                }catch(BeansException $e){
                    error_log($e, 3, BEANS_ERROR_LOG);
                }
        }
        
        # Add beans points to the user card
        try{
            $total = $order->get_total() - $order->get_shipping();
            
            $arg ['number']     = $total;
            $arg ['unit']       = get_woocommerce_currency();
            $arg ['unique_id']  = self::uid.$order->id.'_'.$order->order_key;
            
            if($total>0)            
                $this->api->call('beans/prepare_add/',$arg);
            
        }catch(BeansException $e){
            error_log($e, 3, BEANS_ERROR_LOG);
        }
    }
    
    public function confirm_beans_transaction($order_id, $order_status, $new_status){

        if (!$this->is_ready) return;
        
        if ( $new_status=='processing' || $new_status=='completed')
            try{
                
                $order = new WC_Order($order_id);
                $arg['unique_id'] = self::uid.$order->id.'_'.$order->order_key;
                
                $this->api->call('beans/execute/',$arg);
        
            }catch(BeansException $e){
                // error_log($e, 3, BEANS_ERROR_LOG);
            }
    }
    
    public function render_plugin_cart_page($page){

        echo $this->render_plugin('cart_page');
        
        $msg = $this->card_check ? '':$this->opt['modal_msg'];  
        $cardname = $this->opt['card_name'];
        
        ?>
        <script> 
            window.onload=function(){
                showBeansMsg("<?php echo $msg; ?>", "<?php echo $cardname; ?>");
             } 
        </script>
        <?php
    }
    
    public function render_plugin_product_page($page){
            
        echo $this->render_plugin('product_page');
    }
    
    public function render_plugin_order_page($page){
        
        echo $this->render_plugin('order_page');
    }

    public function render_plugin($page){
        
        if(!$this->is_ready) return;
          
        $mode = $this->opt['mode'][$page];
        
        if(!$mode) return;
       
        $beans_public = $this->opt['public_key'];

        $height = $mode == 'light' ? '25px': '60px';
       
        $website = BeansAPI::$WEBSITE;
        
        wp_enqueue_script('beans-plugin-script', $website.'/assets/static/js/beans.client.js');
        wp_enqueue_script('beans-wc-script', plugins_url( 'assets/js/local.beans.js' , __FILE__ ));
        wp_enqueue_style( 'beans-wc-style', plugins_url( 'assets/css/local.beans.css' , __FILE__ ));
        
        return 
        " <div class='beans-plugin-$page'>".
        "   <iframe name='beans-plugin-frame' scrolling='no' frameborder='0'".
        "   allowtransparency='true' style='border:none; overflow:hidden; width:155px; height:$height;'".
        "   src='$website/plugin/?public=$beans_public&style=$mode'></iframe>".
        " </div>";       
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
