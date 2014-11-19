<?php
//TODO: Show beans balance for users on orders and users page menu 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
 
if ( ! class_exists( 'WC_Beans_Settings' ) ) :
 
 
require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
  
    
/**
* Beans Settings
*/
class WC_Beans_Settings
{
    const OPT_NAME = 'wc_beans_options';
    const I18N = 'wc_beans_back';
    const OPT_DB_VERSION_NAME = 'beans_db_version';
    const OPT_TABLE_ACCOUNT = 'beans_accounts';
    const OPT_TABLE_DR_CR = "beans_dr_cr";
    const OPT_DB_VERSION = '1.1';
    
    protected $updated = false;
    protected $errors = '';
    
    function __construct() 
    {
        $this->install();
        
        // Hooks
        add_action( 'admin_menu',                           array($this, 'admin_menu'),     100); // Add beans submenu in woocommerce menu
        add_action( 'plugin_loaded',                        array($this, 'install'));        
        register_activation_hook( __FILE__,                 array($this, 'install') );
        
        add_filter( 'plugin_action_links_'.BEANS_PLUGIN,    array( $this, 'action_links' ));
    }       
    
    public function install()
    {
        // Install database
        if(get_option(self::OPT_DB_VERSION_NAME)!= self::OPT_DB_VERSION){
            $this->db_install();
        }

        // Register options
        $opt = get_option(self::OPT_NAME);
        
        if(!file_exists(BEANS_CSS_FILE))
            copy(BEANS_CSS_MASTER, BEANS_CSS_FILE);
        
        if(!isset($opt)){
            
            $default_option = array(
                'card_name' => '',
                'public_key' => '',
                'private_key' => '',
                'modal_msg' => __('Would you like to get our Beans card for free?', 'wc-beans')
            );
            add_option(self::OPT_NAME, $default_option);
            
        }elseif(empty($opt['rule_id']) && $opt['secret_key']){
            
            Beans::init($opt['secret_key']);
            $rules = Beans::get('rule');
            // TODO: Find a better way to get the right rule
            foreach ((array)$rules as $rule) {
                if($rule['type__id'] == 'rt_09uk')
                    $opt['rule_id'] = $rule['id'];
            }
            update_option(self::OPT_NAME, $opt);       
        }
    }

    public function db_install()
    {
        global $wpdb;
        
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
        
        $table_name = $wpdb->prefix . self::OPT_TABLE_ACCOUNT; 
        
        $sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          wp_id mediumint(9) NOT NULL ,
          beans_id varchar(255) NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY wp_id (wp_id),
          UNIQUE KEY beans_id (beans_id)
        ) $charset_collate;";
        
        dbDelta( $sql );
        
        $table_name = $wpdb->prefix . self::OPT_TABLE_DR_CR ; 
        
        $sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          order_id mediumint(9) NOT NULL,
          beans_id varchar(255) NOT NULL,
          credit varchar(255) NULL,
          debit varchar(255) NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY order_id (order_id)
        ) $charset_collate;";
        
        dbDelta($sql);
        if(get_option(self::OPT_DB_VERSION_NAME)){
            update_option( self::OPT_DB_VERSION_NAME, self::OPT_DB_VERSION);
        }else{
            add_option( self::OPT_DB_VERSION_NAME, self::OPT_DB_VERSION);  
        }
             
    }

    public static function db_uninstall()
    {
        global $wpdb;
        
        // Drop account table
        $table_name = $wpdb->prefix . self::OPT_TABLE_ACCOUNT; 
        $sql = "DROP TABLE $table_name;";
        $wpdb->query( $sql );
        
        // Drop CR_DR account
        $table_name = $wpdb->prefix . self::OPT_TABLE_DR_CR; 
        $sql = "DROP TABLE $table_name;";
        $wpdb->query( $sql ); 
    }
    
    public static function add_beans_account($wp_id,$beans_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::OPT_TABLE_ACCOUNT; 
        
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
    
    public static function get_beans_account($wp_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::OPT_TABLE_ACCOUNT; 
        
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
    
    public static function add_dr_cr($order_id, $beans_id){
        
        global $wpdb;
        $table_name = $wpdb->prefix . self::OPT_TABLE_DR_CR; 
        
        try{
            $wpdb->insert( 
                $table_name, 
                array( 
                    'order_id' => $order_id, 
                    'beans_id' => $beans_id, 
                ) 
            );
        }catch( Exception $e ){
            return false;
        }
        
        return true;
    }
    
    public static function get_dr_cr($order_id){
        global $wpdb;
        $table_name = $wpdb->prefix . self::OPT_TABLE_DR_CR; 
        
        try{
            return $wpdb->get_row( $wpdb->prepare( 
                "
                    SELECT beans_id, credit, debit  
                    FROM $table_name 
                    WHERE order_id = %d
                ", 
                $order_id
            ), 
            'ARRAY_A' );
        }catch( Exception $e ){
            return null;
        }
    }
    
    public static function update_dr_cr($order_id, $data){
        global $wpdb;
        $table_name = $wpdb->prefix . self::OPT_TABLE_DR_CR; 
        
        try{
            $wpdb->update( 
                $table_name, 
                $data, 
                array('order_id' => $order_id), 
                '%s',
                '%d'
            );
        }catch(Exception $e){
            return null;
        }
    }
    
    public function action_links( $links ) {
        $links_to_add = array();
        $links_to_add[] = '<a href="' . admin_url( 'admin.php?page=wc-beans' ) . '">' . __( 'Settings', 'wc-beans' ) . '</a>';
        // $links_to_add[] = '<a href="' . esc_url('http://docs.woothemes.com/documentation/plugins/woocommerce/') . '">' . __( 'Docs', 'wc-beans' ) . '</a>';
        // $links_to_add[] = '<a href="' . esc_url('http://support.woothemes.com/') . '">' . __( 'Premium Support', 'wc-beans' ) . '</a>';
        return array_merge($links_to_add, $links);
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
        
        if( isset($_POST['action']) && $_POST['action']=='invite_customers')
            $this->send_invitations();

        $opt = get_option(self::OPT_NAME)
        ?>
        
        <div class="wrap">
            <div style="margin-bottom: 40px">
                <h3>Beans</h3> 
                <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>/login/" class="button button-primary" target="_blank"><?php _e('Connect to Beans', 'wc-beans' ); ?></a> 
            </div>
            
            <?php if ($this->errors || $this->message) : ?>
                <div id="setting-error-settings_updated" class="<?php if($this->errors): echo "error"; elseif ($this->message): echo "updated"; endif;?> ">
                    <?php if ($this->errors) : ?>
                        <ul>
                            <?php  foreach($this->errors as $error) echo "<li>$error</li>"; ?>
                        </ul>
                    <?php else : ?>
                        <p><?php  echo $this->message ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$opt['secret_key']) : ?>
                <div style="background-color: white; padding: 10px">
                    <p class="main"><strong><?php _e('Get started with Beans', 'wc-beans'); ?></strong></p>
                    <span>
                      <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>" target="_blank">Beans</a> 
                      <?php _e(' is an advanced loyalty and reward program that helps you engage'.
                               ' your customers on a long term and promote your business.'.
                               ' Once you have joined Beans you will be able to set your rules.'.
                               ' Your reward program will be promoted on our website and indexed for SEO.', 'wc-beans'); ?>    
                    </span>
                    <p>
                      <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>/register/" target="_blank" class="button button-primary">
                        <?php _e('Join for free', 'wc-beans'); ?>
                      </a> 
                    </p>
                 </div>
            <?php else : ?>
                 <div style="margin-top: 40px">
                    <h3>Invitations</h3>
                    <p><?php _e('Send an email invitation to your customers to join your reward program.', 'wc-beans' ); ?></p>
 
                    <form method="post" action="">
                        <input type="hidden" name="action" value="invite_customers">
                        <input type="submit" id="invite_customers" class="button" value="<?php _e('Send invitations', 'wc-beans' ); ?>"/>
                    </form>
                 </div>
            <?php endif; ?> 
             
             <div style="margin-top: 40px">
                <h3>Settings</h3>
                <form method="post" action="">
                    <?php wp_nonce_field("wc_beans_settings"); ?>
                    <input type="hidden" name="action" value="wc_beans_settings">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Public Key', 'wc-beans' ); ?></th>
                            <td>
                                <input class="regular-text code" type="text" name="beans_public_key" required="" value="<?php echo $opt['public_key']; ?>" />
                                <p class="description"><?php _e('Connect to your Beans account to get your public key.', 'wc-beans' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Secret Key', 'wc-beans' ); ?></th>
                            <td>
                                <input class="regular-text code" type="text" name="beans_secret_key" required="" value="<?php echo $opt['secret_key']; ?>" />
                                <p class="description"><?php _e('Connect to your Beans account to get your secret key.', 'wc-beans' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
             </div>
             <p>
                 <a href="https://wordpress.org/support/plugin/woocommerce-beans" target="_blank"><?php _e('Support and feedback to Beans', 'wc-beans' ); ?></a> 
             </p>
        </div>
        <?php
    }
    
    public function send_invitations(){
        
        $args = array(
            'post_type'         => 'shop_order',
            'nopaging'          => true,
            'posts_per_page'    => -1,
            'post_status'       => 'any',
        );  
        
        $loop = new WP_Query( $args );
        
        while ( $loop->have_posts() ) {
            $loop->the_post();
            $order_id = $loop->post->ID;
            $order = new WC_Order($order_id);
            $emails [] = $order->billing_email;
            ;
        }
        
        $data = array(
            'emails'        => $emails,
        );
        
        $invitation = Beans::post('invitation/bulk_create', $data);
        $this->message = __('Invitations sent successfully.', 'wc-beans');
    }
    
    public function update_settings()
    {

        $opt = get_option(self::OPT_NAME);
                  
        $this->errors = array();
        
        if (!$_POST['beans_public_key'] || !$_POST['beans_secret_key'])
            $this->errors[] = __('Beans Public and Secret keys are mandatory.', 'wc-beans' );
        
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
            $opt['public_key'] = $public;
            $opt['secret_key'] = $secret;
            
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
        }
        
        // TODO: Set max limit for beans redeem
        // TODO: Show a modal message to propose beans cards ???? or not 
        // $opt['cart_limit'] = $_POST['beans_cart_limit'];
        // $opt['modal_msg'] = trim($_POST['beans_modal_msg']);
        
        if(empty($this->errors))
            $this->message = __('Settings saved successfully.', 'wc-beans');
        
        update_option(self::OPT_NAME, $opt);
    }
}
endif;

// Load Beans Settings
if( is_admin() )
    $GLOBALS['wc-beans-settings'] = new WC_Beans_Settings();
    