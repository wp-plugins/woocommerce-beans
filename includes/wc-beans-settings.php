<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
 
if ( ! class_exists( 'WC_Beans_Settings' ) ) :
 
 
require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

global $beans_db_version;
$beans_db_version = '1.0';
    
    
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
        $this->db_install();
        // Register options
        if(get_option(self::OPT_NAME)) return;
    
        $default_option = array(
            'card_name' => '',
            'public_key' => '',
            'private_key' => '',
            'modal_msg' => __('Would you like to get our Beans card for free?', 'wc-beans')
        );
        add_option(self::OPT_NAME, $default_option);
    }

    public function db_install()
    {
        global $wpdb;
        global $beans_db_version;
        /*
         * We'll set the default character set and collation for this table.
         * If we don't do this, some characters could end up being converted 
         * to just ?'s when saved in our table.
         */
        $charset_collate = '';
        
        if ( ! empty( $wpdb->charset ) ) {
          $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
        }
        
        if ( ! empty( $wpdb->collate ) ) {
          $charset_collate .= " COLLATE {$wpdb->collate}";
        }
        
        $table_name = $wpdb->prefix . "beans_accounts"; 
        
        $sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          wp_id mediumint(9) NOT NULL ,
          beans_id varchar(255) NOT NULL,
          PRIMARY KEY id (id),
          UNIQUE KEY wp_id (wp_id),
          UNIQUE KEY beans_id (beans_id)
        ) $charset_collate;";
        
        dbDelta( $sql );
        
        add_option( "beans_db_version",$beans_db_version);     
    }
    // TODO: Add direct link to Beans account from user wp accoubt
    public static function add_beans_account($wp_id,$beans_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "beans_accounts"; 
        
        try{
            $wpdb->insert( 
                $table_name, 
                array( 
                    'wp_id' => $wp_id, 
                    'beans_id' => $beans_id, 
                ) 
            );
        }catch( Exception $e ){
            return false;
        }
        
        return true;
    }
    
    public function get_beans_account($wp_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "beans_accounts"; 
        
        try{
            return $wpdb->get_var( $wpdb->prepare( 
                "
                    SELECT beans_id 
                    FROM $table_name 
                    WHERE wp_id = %d
                ", 
                $wp_id
            ) );
        }catch( Exception $e ){
            return null;
        }
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
                               ' and rules. Your loyalty program will be promoted on our website.', 'wc-beans'); ?>    
                    </span>
                    <p>
                      <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>/register/" target="_blank" class="button button-primary">
                        <?php _e('Join for free', 'wc-beans'); ?>
                      </a> 
                    </p>
                 </div>
            <?php else : ?>
                 <p>
                     <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>/login/" class="button" target="_blank">Connect to Beans</a> 
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
                    <!--
                    <tr valign="top">
                        <th scope="row">Max value of the cart that can be redeem in beans</th>
                        <td>
                            <label for="beans_cart_limit_25">25%</label>
                            <input type="radio" name="beans_cart_limit" id="beans_cart_limit_25" value="0.25" <?php if($opt['cart_limit']=='0.25') echo "checked='checked'"; ?> />
                            <label for="beans_cart_limit_50">50%</label>
                            <input type="radio" name="beans_cart_limit" id="beans_cart_limit_50" value="0.5" <?php if($opt['cart_limit']=='0.5') echo "checked='checked'"; ?> />
                            <label for="beans_cart_limit_100">100%</label>
                            <input type="radio" name="beans_cart_limit" id="beans_cart_limit_100" value="1" <?php if($opt['cart_limit']=='1') echo "checked='checked'"; ?> />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Modal message</th>
                        <td>
                            <textarea style="min-width: 25em;" rows="2" name="beans_modal_msg" ><?php echo $opt['modal_msg']; ?></textarea>
                            <p class="description">
                                <?php _e('This message will be displayed on the cart page before checkout.'. 
                                         ' Leave it empty to disable.'); ?>
                            </p>
                        </td>
                    </tr> -->
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
        
            
            try{
                Beans::init($secret);
                $business = Beans::get("business/$public",null,false);
            }catch(BeansException $e){
                $this->errors[] = $e->getMessage();
            }
            
            if (empty($this->errors))
            {
                Beans::init($secret);
                $card = Beans::get('card/'.$business['card__id']);
                $rules = Beans::get('rule');
                // TODO: Find a better way to get the right rule
                foreach ($rules as $rule) {
                    if($rule['type__id'] == 'rt_09uk')
                        $opt['rule_id'] = $rule['id'];
                }
                $opt['card_name'] = $card['name'];
                $opt['public_key'] = $public;
                $opt['secret_key'] = $secret;
            }
        }
        
        // TODO: Set max limit for beans redeem
        // TODO: Show a modal message to propose beans cards ???? or not 
        // $opt['cart_limit'] = $_POST['beans_cart_limit'];
        // $opt['modal_msg'] = trim($_POST['beans_modal_msg']);
        
        if(empty($this->errors))
            $this->updated = true;
        
        update_option(self::OPT_NAME, $opt);
    }
}
endif;

// Load Beans Settings
if( is_admin() )
    $GLOBALS['wc-beans-settings'] = new WC_Beans_Settings();
    