<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
 
if ( ! class_exists( 'WC_Beans_Settings' ) ) :
 
/**
* Beans Settings
*/
class WC_Beans_Settings
{
    const OPT_NAME = 'wc_beans_options';
    protected $updated = false;
    protected $errors = '';
    
    function __construct() 
    {
        $this->install();
        
        // Hooks
        add_action( 'admin_menu',                   array($this, 'admin_menu'),     100); // Add beans submenu in woocommerce menu
    }       
    
    public function install()
    {
        // Register options
        if(get_option(self::OPT_NAME)) return;
    
        $default_option = array(
            'card_name' => '',
            'public_key' => '',
            'private_key' => '',
            'modal_msg' => __('Would you like to get our loyalty card for free?', 'wc-beans'),
            'mode' => array('cart_page' => 'normal', 'product_page' => 'light', 'order_page' => 'light'),
        );
        add_option(self::OPT_NAME, $default_option);
    
        $default_style = ".beans-plugin-order_page{"  .PHP_EOL."  display: block;".PHP_EOL."}".PHP_EOL.
                         ".beans-plugin-product_page{".PHP_EOL."  display: block;".PHP_EOL."  margin: 10px 0;".PHP_EOL."}".PHP_EOL.
                         ".beans-plugin-cart_page{"   .PHP_EOL."  display: block;".PHP_EOL."  text-align: right;".PHP_EOL."}".PHP_EOL.
                         ".beans-plugin-cart_page iframe{"   .PHP_EOL."  margin: 0;".PHP_EOL."}";

        if(!file_exists(BEANS_CSS_FILE))
            file_put_contents(BEANS_CSS_FILE, $default_style);
    }
    
    public function admin_menu()
    {
        if ( current_user_can( 'manage_woocommerce' ) )
            add_submenu_page( 'woocommerce', __( 'Beans Settings', 'wc-beans' ), 'Beans' , 'manage_woocommerce', 'wc-beans', array( $this, 'render_settings_page' ) );  
    }
    
    public function render_settings_page() 
    {
        if( isset($_POST['action']) && $_POST['action']=='wc_beans_settings')
            $this->update_settings();
        
        $opt = get_option(self::OPT_NAME)
        ?>
        <div class="wrap">
            <h3>Beans</h3>
            
            <?php if (!$opt['secret_key']) : ?>
                <div style="background-color: white; padding: 10px">
                    <p class="main"><strong><?php _e('Get started with Beans', 'wc-beans'); ?></strong></p>
                    <span>
                      <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>" target="_blank">Beans</a> 
                      <?php _e(' is an advanced loyalty program that helps you engage you customers on a long term.'.
                               ' Once you have join Beans you will be able to create rewards'.
                               ' and rules and promote your shop loyalty program.', 'wc-beans'); ?>    
                    </span>
                    <p>
                      <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>/register" target="_blank" class="button button-primary">
                        <?php _e('Join for free', 'wc-beans'); ?>
                      </a> 
                    </p>
                 </div>
            <?php else : ?>
                 <p>
                     <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>" class="button" target="_blank">Connect to Beans</a> 
                 </p>
            <?php endif; ?>
            
            <?php if ($this->errors || $this->updated) : ?>
                <div id="setting-error-settings_updated" class="<?php if($this->errors): echo "error"; elseif ($this->updated): echo "updated"; endif;?> ">
                    <?php if ($this->errors) : ?>
                        <ul>
                            <?php  foreach($this->errors as $error) echo "<li>$error</li>"; ?>
                        </ul>
                    <?php else : ?>
                        <p><?php _e('Settings saved.') ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
    
            <form method="post" action="">
                <?php wp_nonce_field("wc_beans_settings"); ?>
                <input type="hidden" name="action" value="wc_beans_settings">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Public Key</th>
                        <td>
                            <input class="regular-text code" type="text" name="beans_public_key" required="" value="<?php echo $opt['public_key']; ?>" />
                            <p class="description"><?php _e('Connect to your Beans account to get your public key.'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Secret Key</th>
                        <td>
                            <input class="regular-text code" type="text" name="beans_secret_key" required="" value="<?php echo $opt['secret_key']; ?>" />
                            <p class="description"><?php _e('Connect to your Beans account to get your secret key.'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Modal message</th>
                        <td>
                            <textarea style="min-width: 25em;" rows="2" name="beans_modal_msg" ><?php echo $opt['modal_msg']; ?></textarea>
                            <p class="description">
                                <?php _e('This message will be display on the cart page before checkout.'. 
                                         ' Leave it empty to disable.'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Cart page plugin</th>
                        <td>
                            <label for="beans_mode_cart_page_normal">normal</label>
                            <input type="radio" name="beans_mode_cart_page" id="beans_mode_cart_page_normal" value="normal" <?php if($opt['mode']['cart_page']=='normal') echo "checked='checked'"; ?> />
                            
                            <label for="beans_mode_cart_page_light">light</label>
                            <input type="radio" name="beans_mode_cart_page" id="beans_mode_cart_page_light" value="light" <?php if($opt['mode']['cart_page']=='light') echo "checked='checked'"; ?> />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Product page plugin</th>
                        <td>
                            <label for="beans_mode_product_page_normal">normal</label>
                            <input type="radio" name="beans_mode_product_page" id="beans_mode_product_page_normal" value="normal" <?php if($opt['mode']['product_page']=='normal') echo "checked='checked'"; ?> />
                            
                            <label for="beans_mode_product_page_light">light</label>
                            <input type="radio" name="beans_mode_product_page" id="beans_mode_product_page_light" value="light" <?php if($opt['mode']['product_page']=='light') echo "checked='checked'"; ?> />
                            
                            <label for="beans_mode_product_page_none">none</label>
                            <input type="radio" name="beans_mode_product_page" id="beans_mode_product_page_none" value="" <?php if($opt['mode']['product_page']=='none') echo "checked='checked'"; ?> />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Order page plugin</th>
                        <td>
                            <label for="beans_mode_order_page_normal">normal</label>
                            <input type="radio" name="beans_mode_order_page" id="beans_mode_order_page_normal" value="normal" <?php if($opt['mode']['order_page']=='normal') echo "checked='checked'"; ?> />
                            
                            <label for="beans_mode_order_page_light">light</label>
                            <input type="radio" name="beans_mode_order_page" id="beans_mode_order_page_light" value="light" <?php if($opt['mode']['order_page']=='light') echo "checked='checked'"; ?> />
                            
                            <label for="beans_mode_order_page_none">none</label>
                            <input type="radio" name="beans_mode_order_page" id="beans_mode_order_page_none" value="" <?php if($opt['mode']['order_page']=='none') echo "checked='checked'"; ?> />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">CSS</th>
                        <td>
                            <textarea style="min-width: 25em;" rows="10" name="beans_css" ><?php echo file_get_contents(BEANS_CSS_FILE) ?></textarea>
                            <p class="description">
                                <?php _e('Modify the plugin position on your website.'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function update_settings()
    {

        $opt = get_option(self::OPT_NAME);
                  
        $this->errors = array();
        
        if (!$_POST['beans_public_key'] || !$_POST['beans_secret_key'])
            $this->errors[] = __('Beans Public and Secret keys are mandatory.');
        
        $public = trim ($_POST['beans_public_key']);
        $secret = trim ($_POST['beans_secret_key']);
        
        // Check public and secret keys only if they have been modified
        if($public!=$opt['public_key'] || $secret!=$opt['secret_key']){
        
            $rewards_public = '';
            try{
                $beans = new BeansAPI(array('secret'=>''));
                $rewards_public = $beans->call('reward/get_all/',array('public'=>$public));
            }catch(BeansException $e){
                $this->errors[] = $e->getMessage();
            }
            
            $rewards_secret = '';
            try{
                $beans = new BeansAPI(array('secret'=>$secret));
                $rewards_secret = $beans->call('reward/get_all/');
            }catch(BeansException $e){
                $this->errors[] = $e->getMessage();
            }
            
            if($rewards_public!=$rewards_secret)
                $this->errors[] = 'Public key does not match Secret key.';
            
            if (!count($this->errors))
            {
                $card = $beans->call('card/get/');
                $opt['card_name'] = $card['name'];
                $opt['public_key'] = $public;
                $opt['secret_key'] = $secret;
            }
     
        }
        
        $opt['modal_msg'] = trim($_POST['beans_modal_msg']);
        $opt['mode']['cart_page'] = $_POST['beans_mode_cart_page'];
        $opt['mode']['product_page'] = $_POST['beans_mode_product_page'];
        $opt['mode']['order_page'] = $_POST['beans_mode_order_page'];
        
        if(!count($this->errors))
            $this->updated = true;
        
        update_option(self::OPT_NAME, $opt);
        
        file_put_contents(BEANS_CSS_FILE, $_POST['beans_css']);
    }
}

endif;

// Load Beans Settings

if( is_admin() )
    $GLOBALS['wc-beans-settings'] = new WC_Beans_Settings();
    