<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
 
if ( ! class_exists( 'WC_Beans_Settings' ) ) :


/**
* Beans Settings
*/
class WC_Beans_Settings
{
    const OPT_DB_VERSION_NAME = 'beans_db_version';
    const OPT_TABLE_ACCOUNT = 'beans_accounts';
    const OPT_TABLE_DR_CR = 'beans_dr_cr';
    const OPT_DB_VERSION = '1.2';
    
    protected $updated = false;
    protected $errors = null;
    protected $message = '';
    private $opt = null;

    function __construct() 
    {
        $this->install();
        
        // Hooks
        // Add beans sub-menu in woocommerce menu
        register_activation_hook( __FILE__,                                     array($this, 'install'));
        add_filter( 'plugin_action_links_'.BEANS_PLUGIN,                        array($this, 'action_links' ));
        add_action( 'plugin_loaded',                                            array($this, 'install'));
        add_action( 'admin_notices',                                            array($this, 'admin_notice'));
        add_action( 'admin_menu',                                               array($this, 'admin_menu'),     100);
        add_action( 'wp_loaded',                                                array($this, 'form_post_handler' ), 30 );
        add_action( 'add_meta_boxes',                                           array($this, 'add_meta_boxes' ), 30 );

        $this->opt = get_option(BEANS_OPT_NAME);

    }

    public function add_meta_boxes(){
//         $color = "<span style='color: #00aa00'>&#9632;</span>";
         add_meta_box('woocommerce-beans-order-info', "Beans Details", 'WC_Beans_Settings::render_order_meta', 'shop_order', 'normal', 'high' );
    }
    
    public function install()
    {
        // Register options
        if(empty($this->opt)){

            $this->opt = array(
                'card_id' => '',
                'secret_key' => '',
                'company_name' => '',
                'beans_address' => '',
                'range_min_redeem' => 0,
                'range_max_redeem' => 100,
                'test_mode' => true,
                'beans_popup' => false,
                'invitation_sent' => false,
                'translation_done' => false,
                'auto_enroll' => true,
                'default_button_style' => true,
                'disable_product_page' => false,
                'beans_on_product_page' => false,
                'beans_invite_after_purchase' => true,
            );
            add_option(BEANS_OPT_NAME, $this->opt);
        }

        // Install database
        if(get_option(self::OPT_DB_VERSION_NAME)!== self::OPT_DB_VERSION)
            $this->db_install();
        
        // Install CSS file
        if(!file_exists(BEANS_CSS_FILE) && file_exists(BEANS_CSS_MASTER) )
            copy(BEANS_CSS_MASTER, BEANS_CSS_FILE);
    }

    public static function check_opt(){
            
        $opt = get_option(BEANS_OPT_NAME);

        if(empty($opt['translation_done'])){
            $lang = substr( get_locale(), 0, 2 );
            $available_languages = array('en', 'it', ); //add here languages that have already been translated! Yeah! TODO: put this in const
            $opt['translation_done'] = in_array($lang, $available_languages);
            update_option(BEANS_OPT_NAME, $opt);
        }

        if(!$opt['secret_key']) return;
        
        Beans::init($opt['secret_key']);
        
        if(empty($opt['rule_currency_spent_id'])){
            $rules = Beans::get('rule', array('type__id'=> 'rt_09uk'));
            if (isset($rules[0]))
                $opt['rule_currency_spent_id'] = $rules[0]['id'];
        }
        
        if(empty($opt['beans_address']) && !empty($opt['public_key'])){
            $business = Beans::get("business/".$opt['public_key']);
            $card = Beans::get('card/'.$business['card__id']);
            $opt['beans_address'] = '$'.$card['uid'];
        }

        if(empty($opt['card_id'])  || empty($opt['company_name']) ){
            $card = Beans::get('card/'.$opt['beans_address']);
            $opt['card_id'] = $card['id'];
            $opt['company_name'] = $card['company_name'];
        }

        if(!isset($opt['range_min_redeem']))
            $opt['range_min_redeem'] = 0;

        if(!isset($opt['range_max_redeem']))
            $opt['range_max_redeem'] = 100;

        update_option(BEANS_OPT_NAME, $opt);
    }
    
    public function db_install()
    {
        // load the dbDelta, this not loaded by default
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        global $wpdb;
        $charset_collate = '';

        if (!empty($wpdb->charset))
            $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";

        if (!empty($wpdb->collate))
            $charset_collate .= " COLLATE {$wpdb->collate}";

        WC_Beans_Order::db_install($charset_collate);

        self::migrate_to_user_meta();

        update_option( self::OPT_DB_VERSION_NAME, self::OPT_DB_VERSION);
    }

    public static function db_uninstall()
    {
        global $wpdb;
        try{
            // Drop account table
            $table_name = $wpdb->prefix . self::OPT_TABLE_ACCOUNT;
            $sql = "DROP TABLE $table_name;";
            $wpdb->query( $sql );
        }catch( Exception $e ){}

        try{
            // Drop CR_DR account
            $table_name = $wpdb->prefix . self::OPT_TABLE_DR_CR;
            $sql = "DROP TABLE $table_name;";
            $wpdb->query( $sql );
        }catch( Exception $e ){}
      delete_option(self::OPT_DB_VERSION_NAME);
    }

    public static function migrate_to_user_meta(){
        global $wpdb;

        $table_name = $wpdb->prefix . self::OPT_TABLE_ACCOUNT;
        try{
            $rows = $wpdb->get_results("SELECT wp_id, beans_id FROM $table_name ");
            foreach ( $rows as $row ){
                update_user_meta($row->wp_id, 'beans_account_id', $row->beans_id);
            }
            //$wpdb->query( "DROP TABLE $table_name;" );
        }catch( Exception $e ){}

    }

    public function action_links( $links ) {
        $links_to_add = array();
        $links_to_add[] = '<a href="' . admin_url( 'admin.php?page=wc-beans' ) . '">' . __( 'Settings', 'woocommerce-beans' ) . '</a>';
        // $links_to_add[] = '<a href="' . esc_url('http://docs.woothemes.com/documentation/plugins/woocommerce/') . '">' . __( 'Docs', 'woocommerce-beans' ) . '</a>';
        // $links_to_add[] = '<a href="' . esc_url('http://support.woothemes.com/') . '">' . __( 'Premium Support', 'woocommerce-beans' ) . '</a>';
        return array_merge($links_to_add, $links);
    }

    public function admin_menu()
    {
        if (current_user_can('manage_woocommerce'))
            add_submenu_page('woocommerce', 'Beans',
                             'Beans' , 'manage_woocommerce',
                             'wc-beans', array( $this, 'render_settings_page' ) );
    }

    public function admin_notice() {

        if(!$this->opt['secret_key'] || !$this->opt['beans_address'])
        {
            echo '<div class="error" style="margin-left: auto"><div style="margin: 10px auto;"> Beans: '.
                     __( 'Beans is not properly configured.', 'woocommerce-beans' ) .
                     ' <a href="' . admin_url( 'admin.php?page=wc-beans' ) . '">' .
                     __( 'Set up', 'woocommerce-beans' ) .
                     '</a>'.
                 '</div></div>';
        }
        elseif($this->opt['test_mode']){
            echo '<div class="error" style="margin-left: auto"><div style="margin: 10px auto;"> Beans: '.
                __( 'Beans is currently in Test Mode. Only the shop manager is able to see and use Beans.', 'woocommerce-beans' ) .
                 '<form method="post" id="beans_activate_testing" style="display: inline;" action="' . admin_url( 'admin.php?page=wc-beans' ) . '" >'.
                     '<input type="hidden" name="test_mode" value="1"/>'.
                     ' <a href="#" onclick="document.getElementById(\'beans_activate_testing\').submit(); return false;">' .
                        __( 'Deactivate test mode', 'woocommerce-beans' ) .
                     '</a>'.
                 '</form>'.
                '</div></div>';
        }
        elseif(!isset($this->opt['auto_enroll']))
        {
            echo '<div class="error" style="margin-left: auto"><div style="margin: 10px auto;"> Beans: '.
                 __( 'You need to specify how you like your customers to join your reward program.', 'woocommerce-beans' ) .
                 ' <a href="' . admin_url( 'admin.php?page=wc-beans' ) . '">' .
                 __( 'Show me', 'woocommerce-beans' ) .
                 '</a>'.
                 '</div></div>';
        }
        elseif(!$this->opt['invitation_sent'])
        {
            echo '<div class="error" style="margin-left: auto"><div style="margin: 10px auto;"> Beans: '.
                 __( 'You have not invited your customers to join your reward program.', 'woocommerce-beans' ) .
                 '<form method="post" id="beans_send_invitations" style="display: inline;" action="' . admin_url( 'admin.php?page=wc-beans' ) . '" >'.
                     '<input type="hidden" name="send_invitations" value="1"/>'.
                     ' <a href="#" onclick="document.getElementById(\'beans_send_invitations\').submit(); return false;">' .
                        __( 'Invite now', 'woocommerce-beans' ) .
                     '</a>'.
                 '</form>'.
                 '</div></div>';
        }
        if(empty($this->opt['translation_done']))
        {
            // It's ironic this text need not to be translated

            if ( ! function_exists( 'format_code_lang' ) )
                require_once( ABSPATH . 'wp-admin/includes/ms.php' );

            echo '<div class="error" style="margin-left: auto"><div style="margin: 10px auto;"> Beans: '.
                '<a href="https://wordpress.org/plugins/woocommerce-beans/faq/" target="_blank" >'.
                    'Help us translate Beans in '.format_code_lang(get_locale()).'.'.
                '<a/> '.
                '<form method="post" id="beans_translation_done" action="" >'.
                    '<input type="hidden" name="translation_done" value="1"/>'.
                    ' <a href="#" onclick="document.getElementById(\'beans_translation_done\').submit(); return false;">' .
                        'No thanks, I prefer English.'  .
                    '</a>'.
                '</form>'.
                '</div></div>';
        }

    }

    public function form_post_handler() {

        if(isset($_POST['translation_done']))
            $this->update_setting('translation_done',true);

        if(isset($_POST['update_settings']))
            $this->update_settings();

        if(isset($_POST['send_invitations']))
            $this->send_invitations();

        if(isset($_POST['reset_settings']))
            delete_option(BEANS_OPT_NAME);

        if(isset($_POST['test_mode'])){
            $this->opt['test_mode'] = !$this->opt['test_mode'];
            update_option(BEANS_OPT_NAME, $this->opt);
            $this->create_beans_accounts();
        }

    }

    public function render_settings_page()
    {
        ?>

        <div class="wrap">

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

            <?php if (!$this->opt['secret_key']) : ?>
                <div style="background-color: white; padding: 10px">
                    <h3><?php _e('Getting started with Beans', 'woocommerce-beans'); ?></h3>
                    <p>
                      <?php

                      _e('Beans is an advanced incentive program that helps you improve customers long term engagement and promote your business.', 'woocommerce-beans');
                      echo ' ';
                      _e('Beans wants to help your organization increase interactions with your customers.', 'woocommerce-beans');
                      echo ' ';
                      _e('Once you have joined Beans, you will be able to create rules, that define how your customers will get their beans.', 'woocommerce-beans'); ?>
                        <a href="//<?php echo BEANS_BUSINESS_WEBSITE ?>" target="_blank">About</a>
                    </p>
                    <p>
                      <a href="//<?php echo BEANS_BUSINESS_WEBSITE ?>/register/" target="_blank" class="button button-primary">
                        <?php _e('Join for free', 'woocommerce-beans'); ?>
                      </a>
                    </p>
                    <p>
                        <?php _e('Good luck and happy incentivizing!', 'woocommerce-beans'); ?>
                    </p>
                 </div>
            <?php else : ?>
                <div style="margin-bottom: 25px">
                   <h2>
                       Beans
                       <a href="//<?php echo BEANS_BUSINESS_WEBSITE ?>/login/" class="add-new-h2" target="_blank"><?php _e('Connect to Beans', 'woocommerce-beans' ); ?></a>
                   </h2>
                </div>
            <?php endif; ?>

            <?php if($this->opt['test_mode']) : ?>
                <div style="margin-top: 25px">
                    <h3><?php _e('Test Mode', 'woocommerce-beans') ?></h3>
                    <div style="background-color: #FCEFA1; padding: 10px; border: 1px solid #DDDDDD; margin: 20px auto">
                        <?php
                            _e('Beans is currently in Test Mode. Only the shop manager is able to see and use Beans.', 'woocommerce-beans');
                            // two parts translation to avoid repeat at the first text has already been translated
                            echo ' ';
                            _e('The plugin is disabled for your customers and visitors.', 'woocommerce-beans');
                            echo ' ';
                            _e('When you are ready, just deactivate the test mode to allow your customers to see and join your reward program.', 'woocommerce-beans' );
                        ?>
                    </div>

                    <form method="post" action="">
                        <input class='button' type='submit' name='test_mode'
                               value='<?php _e('Deactivate test mode', 'woocommerce-beans' ); ?>'/>
                    </form>
                </div>

            <?php elseif(!$this->opt['invitation_sent']) : ?>

                <div style="margin-top: 25px">
                    <h3><?php _e('Invitations', 'woocommerce-beans'); ?></h3>
                    <p><?php _e('Send an email invitation to your customers to join your reward program.', 'woocommerce-beans' ); ?></p>

                    <form method="post" action="">
                        <input class='button' type='submit' name='send_invitations'
                               value='<?php _e('Send invitations', 'woocommerce-beans' ); ?>'/>
                    </form>
                </div>
            <?php endif; ?>

             <div style="margin-top: 25px">
                <h3><?php _e('Settings', 'woocommerce-beans' ); ?></h3>
                <form method="post" action="">
                    <?php wp_nonce_field("wc_beans_settings"); ?>
                    <input type="hidden" name="update_settings" value="1">
                    <table class="form-table">
                    <?php if (!$this->opt['secret_key']) : ?>
                        <tr valign="top">
                            <td>
                                <input class="regular-text code" type="text" name="beans_address"  placeholder="$address" required="" value="<?php echo $this->opt['beans_address']; ?>" />
                                <p class="description"><?php _e('Enter your Beans address.', 'woocommerce-beans' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td>
                                <input class="regular-text code" type="text" name="beans_secret_key" placeholder="sk_xxxxxxxxxxxxxxxxxxxxxxxx" required="" value="<?php echo $this->opt['secret_key']; ?>" />
                                <p class="description"><?php _e('Enter your Beans secret key.', 'woocommerce-beans' ); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
<!--                        <tr valign="top">-->
<!--                            <td colspan="2">-->
<!--                                <input id="beans_description_on_cart_page_id" type='checkbox' --><?php //if ($this->opt['beans_description_on_cart_page']) { echo 'checked="checked"'; }; ?><!-- name="beans_description_on_cart_page"/>-->
<!--                                <label for="beans_description_on_cart_page_id" class="description">--><?php //_e('Show new beans description on cart page', 'woocommerce-beans' ); ?><!--</label>-->
<!--                            </td>-->
<!--                        </tr>-->
                        <tr valign="top">
                            <td colspan="2">
                                <table class="radio-table">
                                    <tbody>
                                    <tr>
                                        <td><input class="setting-input-inline"  id="auto_enroll_0" name="auto_enroll" required="required" value="True" <?php if (!empty($this->opt['auto_enroll'])) { echo 'checked="checked"'; }; ?> type="radio"></td>
                                        <td><label for="auto_enroll_0" style="display: inline-block"><?php _e('Auto enroll users with shop account in my reward program.', 'woocommerce-beans' ); ?>
                                                <br><span class="small-text"><?php _e('Existing shop members will automatically be enrolled. New customers will be prompted to create a store account.', 'woocommerce-beans' ); ?> </span></label></td>
                                    </tr>
                                    <tr>
                                        <td><input class="setting-input-inline" id="auto_enroll_1" name="auto_enroll" required="required" value="False" <?php if (isset($this->opt['auto_enroll']) && $this->opt['auto_enroll']===false) { echo 'checked="checked"'; }; ?> type="radio"></td>
                                        <td><label for="auto_enroll_1" style="display: inline-block"><?php _e('Users should enroll separately in my reward program.', 'woocommerce-beans' ); ?>
                                                <br><span class="small-text"><?php _e('New customers will be prompted to connect with their Facebook or Beans account.', 'woocommerce-beans' ); ?> </span></label></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_on_product_page_id" type='checkbox' <?php if ($this->opt['beans_on_product_page']) { echo 'checked="checked"'; }; ?> name="beans_on_product_page"/>
                                <label for="beans_on_product_page_id" class="description"><?php _e('Show beans price on product page', 'woocommerce-beans' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="display_product_page_id" type='checkbox' <?php if (!$this->opt['disable_product_page']) { echo 'checked="checked"'; }; ?> name="display_product_page"/>
                                <label for="display_product_page_id" class="description"><?php _e('Display Beans on product page', 'woocommerce-beans' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_popup_id" type='checkbox' <?php if ($this->opt['beans_popup']) { echo 'checked="checked"'; }; ?> name="beans_popup"/>
                                <label for="beans_popup_id" class="description"><?php _e('Automatically show Beans to first time visitors. This will only appear once.', 'woocommerce-beans' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="default_button_style_id" type='checkbox' <?php if ($this->opt['default_button_style']) { echo 'checked="checked"'; }; ?> name="default_button_style"/>
                                <label for="default_button_style_id" class="description"><?php _e('Use my shop default style instead of Beans style.', 'woocommerce-beans' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_invite_after_purchase_id" type='checkbox' <?php if ($this->opt['beans_invite_after_purchase']) { echo 'checked="checked"'; }; ?> name="beans_invite_after_purchase"/>
                                <label for="beans_invite_after_purchase_id" class="description"><?php _e('Allow customers to get their beans after completing an order. (Recommended)', 'woocommerce-beans' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Maximum beans redeem per order', 'woocommerce-beans' ); ?></th>
                            <td>
                                <input id="r_max_reed" name="range_max_redeem" type="range" min="0" max="100" step="5"  onchange="showValue1(this.value)"
                                       value="<?php echo $this->opt['range_max_redeem']; ?>"/>
                                <div><span id="range_max_redeem"><?php echo $this->opt['range_max_redeem']; ?></span>%</div>
                                <script type="text/javascript">
                                    function showValue1(new_value)
                                    {
                                        var max_reed = parseInt(new_value);
                                        var min_reed = parseInt(document.getElementById("range_min_redeem").innerHTML);
                                        if(max_reed<min_reed){
                                            document.getElementById("range_min_redeem").innerHTML = new_value;
                                            document.getElementById("r_min_reed").value = max_reed;
                                        }
                                        document.getElementById("range_max_redeem").innerHTML = new_value;
                                    }
                                </script>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Minimum beans redeem per order', 'woocommerce-beans' ); ?></th>
                            <td>
                                <input id="r_min_reed" name="range_min_redeem" type="range" min="0" max="100" step="5" onchange="showValue2(this.value)"
                                       value="<?php echo $this->opt['range_min_redeem']; ?>"/>
                                <div><span id="range_min_redeem"><?php echo $this->opt['range_min_redeem']; ?></span>%</div>
                                <script type="text/javascript">
                                    function showValue2(newValue){

                                        var maxreed = parseInt(document.getElementById("range_max_redeem").innerHTML);
                                        var minreed = parseInt(newValue);
                                        if(maxreed<minreed){
                                            document.getElementById("range_max_redeem").innerHTML = newValue;
                                            document.getElementById("r_max_reed").value = minreed;
                                        }
                                        document.getElementById("range_min_redeem").innerHTML=newValue;

                                    }
                                </script>
                            </td>
                        </tr>
<!--                        <tr valign="top">-->
<!--                            <th scope="row">--><?php //_e('Information tag', 'woocommerce-beans' ); ?><!--</th>-->
<!--                            <td colspan="2">-->
<!--                                <input type="text" size="60" name="beans_rate_msg_text" value="--><?php //echo $this->opt['beans_rate_msg_text']; ?><!--" />-->
<!--                            </td>-->
<!--                        </tr>-->
                    </table>
                    <?php submit_button(); ?>
                </form>
             </div>


             <div style="margin: 1em auto">
                 <form method="post" action="" id="beans_reset_settings" style="display: inline;">
                     <a href="#" onclick="document.getElementById('beans_reset_settings').submit(); return false;">
                         <?php _e('Reset settings', 'woocommerce-beans' ); ?>
                     </a>
                    <input type='hidden' name='reset_settings' value='1'/>
                 </form>
                 <span style="margin: auto 5px">|</span>
                 <form method="post" action="" id="beans_activate_testing" style="display: inline;">
                     <a href="#" onclick="document.getElementById('beans_activate_testing').submit(); return false;">
                         <?php
                             if($this->opt['test_mode'])
                                 _e('Deactivate test mode', 'woocommerce-beans' );
                             else
                                 _e('Activate test mode', 'woocommerce-beans' );
                         ?>
                     </a>
                    <input type='hidden' name='test_mode' value='1'/>
                 </form>
                 <span style="margin: auto 5px">|</span>
                 <a href='https://wordpress.org/support/view/plugin-reviews/woocommerce-beans#postform' target="_blank">
                     <?php _e('Make a review', 'woocommerce-beans' ); ?>
                 </a>
                 <span style="margin: auto 5px">|</span>
                 <a href="https://wordpress.org/support/plugin/woocommerce-beans" target="_blank">
                     <?php _e('Help', 'woocommerce-beans' ); ?>
                 </a>
                 <span style="margin: auto 5px">|</span>
                 <a href="https://www.facebook.com/BeansHQ" target="_blank">
                     Facebook
                 </a>
                 <span style="margin: auto 5px">|</span>
                 <a href="https://twitter.com/BeansHQ" target="_blank">
                     Twitter
                 </a>
             </div>

        </div>
        <?php
    }

    public static  function render_order_meta($post){

        $opt = get_option(BEANS_OPT_NAME);
        Beans::init($opt['secret_key']);
        $beans_order = new WC_Beans_Order($post->ID, false);
        $debit = $beans_order->debit_id ? Beans::get('debit/'.$beans_order->debit_id) : null;
        $credit = $beans_order->credit_id ? Beans::get('credit/'.$beans_order->credit_id) : null;

        ?>
        <style type="text/css" scoped="scoped">
            #beans-order-meta-wrapper{
                margin: 1em auto;
            }
            #beans-order-meta-wrapper:after{
                content: " ";
                display: block;
                clear: both;
                float: none;
            }
            #beans-order-meta-wrapper strong{
                display: block;
            }
            #beans-order-quote{
                color: #B7B7B7;
                float: right;
            }
            #beans-view-account-div{
                float: left;
            }
            #beans-order-quote a, #beans-order-quote a:visited{
                color: #808080;
            }
            em.beans-drcr-status-failed{
                color: red;
                text-decoration: underline;
            }
            em.beans-drcr-status-on_hold, em.beans-drcr-status-pending{
                color: #999;
            }
            em{
                text-transform: capitalize;
            }
        </style>
        <div id="beans-order-meta-wrapper">

            <?php if (!$beans_order->wc_order_id) : ?>
                <p><?php _e('No information could be found on this order.', 'woocommerce-beans') ?></p>
            <?php elseif ($beans_order->account_id) : ?>
<!--                credit-->
                <?php if ($credit) : ?>
                    <p>
                        <strong>
                            <?php _e('Credit', 'woocommerce-beans') ?>: <em class="beans-drcr-status-<?php echo $credit['status'] ?>">
                                <?php echo $credit['status'] ?></em>
                        </strong>
                        <?php
                            if ($credit['status']=='committed'):
                                printf(__('The customer has been credited %1$s for this order.', 'woocommerce-beans'), print_beans($credit['beans']));
                            elseif($credit['status']=='failed') :
                                _e('Crediting the account failed with the message:', 'woocommerce-beans');
                                echo $credit['failure_message'];
                            endif;
                        ?>
                    </p>
                <?php else : ?>
                    <p>
                        <strong><?php _e('Credit', 'woocommerce-beans') ?>: <em class="beans-drcr-status-on_hold">On Hold</em></strong>
                        <?php _e('The customer will be credited when the order status will be updated to <b>processing</b> or <b>completed</b>.', 'woocommerce-beans') ?>
                    </p>
                <?php endif; ?>
<!--                debit-->
                <?php if ($debit) : ?>
                    <p>
                        <strong>
                            <?php _e('Debit', 'woocommerce-beans') ?>: <em class="beans-drcr-status-<?php echo $debit['status'] ?>">
                                <?php echo $debit['status'] ?></em>
                        </strong>
                        <?php
                            if ($debit['status']=='committed'):
                                printf(__('The customer has been debited %1$s for this order.', 'woocommerce-beans'), print_beans($debit['beans']));
                            elseif($debit['status']=='failed') :
                                _e('Debiting the account failed with the message:', 'woocommerce-beans');
                                echo $debit['failure_message'];
                            endif;
                        ?>
                    </p>
                <?php else : ?>
                    <p>
                        <strong><?php _e('Debit', 'woocommerce-beans') ?>: <em class="beans-drcr-status-none">None</em></strong>
                        <?php _e('There is no redeem with this order.', 'woocommerce-beans') ?>
                    </p>
                <?php endif; ?>

                <div id="beans-view-account-div">
                    <a href="//<?php echo BEANS_BUSINESS_WEBSITE.'/accounts/'.$beans_order->account_id; ?>" target="_blank">
                    <?php _e('View this customer account', 'woocommerce-beans') ?></a>
                </div>
<!--            no beans account-->
            <?php else : ?>

                <p><?php _e('Hmmmm... This customer seems to not be using Beans.', 'woocommerce-beans') ?></p>

                <?php if (!$opt['beans_invite_after_purchase']) : ?>

                    <p>
                        <?php _e('You have disable email notification, so there was nothing we could do to keep them. :(', 'woocommerce-beans') ?>
                        <a href="<?php echo admin_url('admin.php?page=wc-beans'); ?>"><?php _e('Settings', 'woocommerce-beans') ?></a>
                    </p>

                <?php elseif($beans_order->followup_sent) : ?>

                    <p><?php _e("Don't Panic! We have sent an email to correct this, so they still have a chance to get their beans for this order.", 'woocommerce-beans'); ?></p>

                <?php endif; ?>

            <?php endif; ?>
            <div id="beans-order-quote">
                <?php echo self::get_quotes(); ?>
            </div>
        </div>
        <?php
    }

    public static function get_quotes(){
        $quotes = array(
            "Beans Likes you.",
            "You look nice today.",
            "We're all in this together!",
            "Please enjoy Beans, responsibly.",
            "Persistence wears down resistance.",
            "Alright World, time to take you on!",
            "You're here, and the day just got better.",
            "Remember to get up and stretch once in a while.",
            "What a day! What cannot be accomplished on such a splendid day?",
            "Like Alexander the Great and Caesar, you are out to conquer the world.",
            "The mystery of life isn't a problem to solve, but a reality to experience.",
            "Find us on <a href='https://www.facebook.com/BeansHQ' target='_blank'>Facebook</a>.",
            "We will announce new features to come on <a href='https://twitter.com/BeansHQ' target='_blank'>Twitter</a>.",
            "Please take 2 min to <a href='https://wordpress.org/support/view/plugin-reviews/woocommerce-beans#postform' target='_blank'>make a review</a>.",
        );

        $i = mt_rand(0, count($quotes) - 1);
        $quote = $quotes[$i];
        return $quote;
    }

    public function send_invitations(){

        $this->create_beans_accounts();

        $args = array(
            'post_type'         => 'shop_order',
            'nopaging'          => true,
            'posts_per_page'    => -1,
            'post_status'       => 'any',
        );

        $loop = new WP_Query($args);
        $emails = array();

        while ($loop->have_posts()){
            $loop->the_post();
            $order_id = $loop->post->ID;
            $order = new WC_Order($order_id);
            $emails [] = $order->billing_email;
        }

        $data = array(
            'emails'        => $emails,
            'model'         => 'invitation',
        );

        $this->opt['invitation_sent'] = Beans::post('notification/bulk_create', $data);
        $this->message = __('Invitations sent successfully.', 'woocommerce-beans');

        update_option(BEANS_OPT_NAME, $this->opt);
    }

    public static function register_new_user($user_id){
        $user_data = get_userdata($user_id);
        $user_meta = get_user_meta($user_id);
        $first_name = $user_meta['first_name'][0];
        $last_name = $user_meta['last_name'][0];
        $email = $user_data->user_email;

        if($first_name && $last_name && $email){
            $user_data = array(
                'email' => $email,
                'first_name' => $first_name,
                'last_name' =>  $last_name,
            );
            $account = Beans::post('account', array('user'=> $user_data));

            if(isset($account['id']))
                update_user_meta($user_id, 'beans_account_id', $account['id']);
        }
    }

    public function create_beans_accounts(){

        if(empty($this->opt['auto_enroll']) || $this->opt['test_mode']) return;

        $query_args = array(
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'first_name',
                    'compare' => '!=',
                    'value' => ''
                ),
                array(
                    'key' => 'last_name',
                    'compare' => '!=',
                    'value' => ''
                ),
                array(
                    'key' => 'beans_account_id',
                    'compare' => 'NOT EXISTS',
                    'value' => 'bug #23268',
                ),
            )
        );

        $user_query = new WP_User_Query($query_args);
        foreach ( $user_query->results as $user ) {
            self::register_new_user($user->ID);
        }

//        $this->opt['invitation_sent'] = Beans::post('notification/bulk_create', $data);
//        $this->message = __('Invitations sent successfully.', 'woocommerce-beans');

//        update_option(BEANS_OPT_NAME, $this->opt);
    }

    public function update_settings()
    {

         $this->errors = array();

         if(isset($_POST['beans_address'] ) && isset($_POST['beans_address'] )){
             if (!$_POST['beans_address'] || !$_POST['beans_secret_key'])
                 $this->errors[] = __('Beans Public and Secret keys are mandatory.', 'woocommerce-beans' );

             $address = strtolower (trim ($_POST['beans_address']));
             $secret = trim ($_POST['beans_secret_key']);

             // $address should be like '$foo'.
             preg_match('/\$[\w\.]*/', $address, $matches);
             if(empty($matches))
                 $address = '$'.$address;  // Handle the case the user enter 'foo'
             else
                 $address = $matches[0];  // Handle the case the user enter 'www.trybeans.com/$foo/'

             // Check public and secret keys
             try{
                 Beans::init($secret);
                 Beans::get("card/$address",null,false);
                 $this->opt['beans_address'] = $address;
                 $this->opt['secret_key'] = $secret;
             }catch(BeansException $e){
                 $this->errors[] = $e->getMessage();
             }
         }

         $this->opt['beans_popup'] = isset($_POST['beans_popup']);
         $this->opt['default_button_style'] = isset($_POST['default_button_style']);
         $this->opt['disable_product_page'] = !isset($_POST['display_product_page']);
         $this->opt['beans_on_product_page'] = isset($_POST['beans_on_product_page']);
         $this->opt['beans_invite_after_purchase'] = isset($_POST['beans_invite_after_purchase']);

         if($_POST['auto_enroll'] == 'True')
            $this->opt['auto_enroll'] = true;

         if($_POST['auto_enroll'] == 'False')
            $this->opt['auto_enroll'] = false;

         if(isset($_POST['range_min_redeem']))
            $this->opt['range_min_redeem'] = $_POST['range_min_redeem'];

         if(isset($_POST['range_max_redeem']))
            $this->opt['range_max_redeem'] = $_POST['range_max_redeem'];

        update_option(BEANS_OPT_NAME, $this->opt);

         if(empty($this->errors)){
             $this->message = __('Settings saved successfully.', 'woocommerce-beans');
             $this->create_beans_accounts();
         }
    }

    public function update_setting($param,$value)
    {
        $this->opt[$param] = $value;
        update_option(BEANS_OPT_NAME, $this->opt);
    }
}
endif;

// Load Beans Settings
if( is_admin() )
    $GLOBALS['wc-beans-settings'] = new WC_Beans_Settings();