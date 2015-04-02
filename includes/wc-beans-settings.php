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
                'card_uid' => '',
                'public_key' => '',
                'secret_key' => '',
                'business_name' => '',
                'range_min_redeem' => 0,
                'range_max_redeem' => 100,
                'beans_popup' => false,
                'beans_rate_msg' => false,
                'invitation_sent' => false,
                'beans_on_shop_page' => false,
                'default_button_style' => true,
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
        
        if(!$opt['secret_key']) return;
        
        Beans::init($opt['secret_key']);
        
        if(empty($opt['rule_currency_spent_id'])){
            $rules = Beans::get('rule', array('type__id'=> 'rt_09uk'));
            if (isset($rules[0]))
                $opt['rule_currency_spent_id'] = $rules[0]['id'];
        }
        
        if(empty($opt['card_uid']) || empty($opt['card_id'])  || empty($opt['business_name']) ){
            $business = Beans::get("business/".$opt['public_key']);
            $card = Beans::get('card/'.$business['card__id']);
            $opt['business_name'] = $business['name'];
            $opt['card_id'] = $card['id'];
            $opt['card_uid'] = $card['uid'];
        }

        if(!isset($opt['range_min_redeem']))
            $opt['range_min_redeem'] = 0;

        if(!isset($opt['range_max_redeem']))
            $opt['range_max_redeem'] = 100;

        if(!isset($opt['default_button_style']))
            $opt['default_button_style'] = true;

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
        $links_to_add[] = '<a href="' . admin_url( 'admin.php?page=wc-beans' ) . '">' . __( 'Settings', 'woocommerce-beans-admin' ) . '</a>';
        // $links_to_add[] = '<a href="' . esc_url('http://docs.woothemes.com/documentation/plugins/woocommerce/') . '">' . __( 'Docs', 'woocommerce-beans-admin' ) . '</a>';
        // $links_to_add[] = '<a href="' . esc_url('http://support.woothemes.com/') . '">' . __( 'Premium Support', 'woocommerce-beans-admin' ) . '</a>';
        return array_merge($links_to_add, $links);
    }

    public function admin_menu()
    {
        if (current_user_can('manage_woocommerce'))
            add_submenu_page('woocommerce', __( 'Beans Settings', 'woocommerce-beans-admin' ),
                             'Beans' , 'manage_woocommerce',
                             'wc-beans', array( $this, 'render_settings_page' ) );
    }

    public function admin_notice() {

        if(!$this->opt['secret_key'] || !$this->opt['public_key'])
        {
            echo '<div class="error"><p>'.
                     __( 'Beans is not properly configured. ', 'woocommerce-beans-admin' ) .
                     '<a href="' . admin_url( 'admin.php?page=wc-beans' ) . '">' .
                     __( 'Set up', 'woocommerce-beans-admin' ) .
                     '</a>'.
                 '</p></div>';
        }

        elseif(!$this->opt['invitation_sent'])
        {
            echo '<div class="error"><p>'.
                     __( 'Beans: You have not invited your customers to join your reward program. ', 'woocommerce-beans-admin' ) .
                    '<form method="post" id="beans_send_invitations" action="' . admin_url( 'admin.php?page=wc-beans' ) . '" >'.
                        '<input type="hidden" name="send_invitations" value="1"/>'.
                         '<a href="#" onclick="document.getElementById(\'beans_send_invitations\').submit(); return false;">' .
                            __( 'Invite now', 'woocommerce-beans-admin' ) .
                         '</a>'.
                    '</form>'.
                 '</p></div>';
        }

    }

    public function form_post_handler() {

        if(isset($_POST['update_settings']))
            $this->update_settings();

        if(isset($_POST['send_invitations']))
            $this->send_invitations();

        if(isset($_POST['reset_settings']))
            delete_option(BEANS_OPT_NAME);

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
                    <h3><?php _e('Getting started with Beans', 'woocommerce-beans-admin'); ?></h3>
                    <p>
                      <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>" target="_blank">Beans</a>
                      <?php _e(' is an advanced incentive program that helps you improve'.
                               ' customers long term engagement and promote your business.'.
                               ' Beans wants to help your organization increase interactions with your customers.'.
                               ' Once you have joined Beans, you will be able to create rules, that define how'.
                               ' your customers will get their beans.', 'woocommerce-beans-admin'); ?>
                    </p>
                    <p>
                      <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>/register/" target="_blank" class="button button-primary">
                        <?php _e('Join for free', 'woocommerce-beans-admin'); ?>
                      </a>
                    </p>
                    <p>
                        <?php _e('Good luck and happy incentivizing!', 'woocommerce-beans-admin'); ?>
                    </p>
                 </div>
            <?php elseif(!$this->opt['invitation_sent']) : ?>
                <div style="margin-top: 40px">
                    <h3>Invitations</h3>
                    <p><?php _e('Send an email invitation to your customers to join your reward program.', 'woocommerce-beans-admin' ); ?></p>

                    <form method="post" action="">
                        <input class='button button-primary' type='submit' name='send_invitations'
                               value='<?php _e('Send invitations', 'woocommerce-beans-admin' ); ?>'/>
                    </form>
                </div>
            <?php else : ?>
                <div style="margin-bottom: 40px">
                   <h2>
                       Beans
                       <a href="<?php echo BEANS_BUSINESS_WEBSITE ?>/login/" class="add-new-h2" target="_blank"><?php _e('Connect to Beans', 'woocommerce-beans-admin' ); ?></a>
                   </h2>
                </div>
            <?php endif; ?>

             <div style="margin-top: 40px">
                <h3>Settings</h3>
                <form method="post" action="">
                    <?php wp_nonce_field("wc_beans_settings"); ?>
                    <input type="hidden" name="update_settings" value="1">
                    <table class="form-table">
                    <?php if (!$this->opt['secret_key']) : ?>
                        <tr valign="top">
                            <td>
                                <input class="regular-text code" type="text" name="beans_public_key" required="" value="<?php echo $this->opt['public_key']; ?>" />
                                <p class="description"><?php _e('Connect to your Beans account to get your public key.', 'woocommerce-beans-admin' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td>
                                <input class="regular-text code" type="text" name="beans_secret_key" required="" value="<?php echo $this->opt['secret_key']; ?>" />
                                <p class="description"><?php _e('Connect to your Beans account to get your secret key.', 'woocommerce-beans-admin' ); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_on_product_page_id" type='checkbox' <?php if ($this->opt['beans_on_product_page']) { echo 'checked="checked"'; }; ?> name="beans_on_product_page"/>
                                <label for="beans_on_product_page_id" class="description"><?php _e('Show beans price on product page', 'woocommerce-beans-admin' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_on_shop_page_id" type='checkbox' <?php if ($this->opt['beans_on_shop_page']) { echo 'checked="checked"'; }; ?> name="beans_on_shop_page"/>
                                <label for="beans_on_shop_page_id" class="description"><?php _e('Show beans price for each product on shop page', 'woocommerce-beans-admin' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_rate_msg_id" type='checkbox' <?php if ($this->opt['beans_rate_msg']) { echo 'checked="checked"'; }; ?> name="beans_rate_msg"/>
                                <label for="beans_rate_msg_id" class="description"><?php _e('Make the info tag (//) more explicit.' , 'woocommerce-beans-admin' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_popup_id" type='checkbox' <?php if ($this->opt['beans_popup']) { echo 'checked="checked"'; }; ?> name="beans_popup"/>
                                <label for="beans_popup_id" class="description"><?php _e('Automatically show Beans to first time visitors. This will only appear once.', 'woocommerce-beans-admin' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="default_button_style_id" type='checkbox' <?php if ($this->opt['default_button_style']) { echo 'checked="checked"'; }; ?> name="default_button_style"/>
                                <label for="default_button_style_id" class="description"><?php _e('Use my shop default style instead of Beans style.', 'woocommerce-beans-admin' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td colspan="2">
                                <input id="beans_invite_after_purchase_id" type='checkbox' <?php if ($this->opt['beans_invite_after_purchase']) { echo 'checked="checked"'; }; ?> name="beans_invite_after_purchase"/>
                                <label for="beans_invite_after_purchase_id" class="description"><?php _e('Allow customers to get their beans after completing an order. (Recommended)', 'woocommerce-beans-admin' ); ?></label>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Maximum beans redeem per order', 'woocommerce-beans-admin' ); ?></th>
                            <td>
                                <input id="r_max_reed" name="range_max_redeem" type="range" min="0" max="100" step="5" value="<?php if (isset($this->opt['range_max_redeem'])) { echo $this->opt['range_max_redeem']; }else{ echo 100; }; ?>" onchange="showValue1(this.value)" />
                                <div><span id="range_max_redeem"><?php if (isset($this->opt['range_max_redeem'])) { echo $this->opt['range_max_redeem']; }else{ echo 100; }; ?></span>%</div>
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
                            <th scope="row"><?php _e('Minimum beans redeem per order', 'woocommerce-beans-admin' ); ?></th>
                            <td>
                                <input id="r_min_reed" name="range_min_redeem" type="range" min="0" max="100" step="5"
                                       value="<?php if (isset($this->opt['range_min_redeem'])) { echo $this->opt['range_min_redeem']; }else{ echo 0; }; ?>" onchange="showValue2(this.value)" />
                                <div><span id="range_min_redeem"><?php if (isset($this->opt['range_min_redeem'])) { echo $this->opt['range_min_redeem']; }else{ echo 0; }; ?></span>%</div>
                                <script type="text/javascript">
                                function showValue2(newValue)
                                {
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
                    </table>
                    <?php submit_button(); ?>
                </form>
             </div>

            <form method="post" action="" id="beans_reset_settings">
                <p>
                    <a href="https://wordpress.org/support/plugin/woocommerce-beans" target="_blank">
                        <?php _e('Support and feedback to Beans', 'woocommerce-beans-admin' ); ?>
                    </a>
                    <span style="margin: auto 5px">|</span>
                    <a href="#" onclick="document.getElementById('beans_reset_settings').submit(); return false;">
                        <?php _e('Reset Settings', 'woocommerce-beans-admin' ); ?>
                    </a>
                    <input type='hidden' name='reset_settings' value='1'/>
                </p>
            </form>

        </div>
        <?php
    }

    public static  function render_order_meta($post){
        // todo: add translation support for this function

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
                <p>No information could be found on this order.</p>
            <?php elseif ($beans_order->account_id) : ?>
<!--                credit-->
                <?php if ($credit) : ?>
                    <p>
                        <strong>
                            Credit: <em class="beans-drcr-status-<?php echo $credit['status'] ?>">
                                <?php echo $credit['status'] ?></em>
                        </strong>
                        <?php if ($credit['status']=='committed'): ?>
                            The customer has been credited <?php echo print_beans($credit['beans']) ?> for this order.
                        <?php elseif($credit['status']=='failed') : ?>
                            Crediting the account failed with the message: <?php echo $credit['failure_message'] ?>.
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p>
                        <strong>Credit: <em class="beans-drcr-status-on_hold">On Hold</em></strong>
                        The customer will be credited when
                        the order status will be updated to <b>processing</b> or <b>completed</b>.
                    </p>
                <?php endif; ?>
<!--                debit-->
                <?php if ($debit) : ?>
                    <p>
                        <strong>
                            Debit: <em class="beans-drcr-status-<?php echo $debit['status'] ?>">
                                <?php echo $debit['status'] ?></em>
                        </strong>
                        <?php if ($debit['status']=='committed'): ?>
                            The customer has been debited <?php echo print_beans($debit['beans']) ?> for this order.
                        <?php elseif($debit['status']=='failed') : ?>
                            Debiting the account failed with the message: <?php echo $debit['failure_message'] ?>.
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p>
                        <strong>Debit: <em class="beans-drcr-status-none">None</em></strong>
                        There is no redeem with this order.
                    </p>
                <?php endif; ?>

                <div id="beans-view-account-div">
                    <a href="<?php echo BEANS_BUSINESS_WEBSITE.'/accounts/'.$beans_order->account_id; ?>" target="_blank">
                        View this customer account</a>
                </div>
<!--            no beans account-->
            <?php else : ?>

                <p>Hmmmm... This customer seems to not be using Beans.</p>

                <?php if (!$opt['beans_invite_after_purchase']) : ?>

                        <p>You have disable email notification, so there was nothing we could do to keep them. :(
                            <a href="<?php echo admin_url('admin.php?page=wc-beans'); ?>">Settings</a></p>


                <?php elseif($beans_order->followup_sent) : ?>

                        <p>Don't Panic! We have sent an email to correct this,
                            so they still have a chance to get their beans for this order.</p>


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
        $this->message = __('Invitations sent successfully.', 'woocommerce-beans-admin');

        update_option(BEANS_OPT_NAME, $this->opt);
    }

    public function update_settings()
    {

         $this->errors = array();

         if(isset($_POST['beans_public_key'] ) && isset($_POST['beans_public_key'] )){
             if (!$_POST['beans_public_key'] || !$_POST['beans_secret_key'])
                 $this->errors[] = __('Beans Public and Secret keys are mandatory.', 'woocommerce-beans-admin' );

             $public = trim ($_POST['beans_public_key']);
             $secret = trim ($_POST['beans_secret_key']);

             // Check public and secret keys

             try{
                 Beans::init($secret);
                 Beans::get("business/$public",null,false);
                 $this->opt['public_key'] = $public;
                 $this->opt['secret_key'] = $secret;
             }catch(BeansException $e){
                 $this->errors[] = $e->getMessage();
             }
         }

         $this->opt['beans_on_shop_page'] = isset($_POST['beans_on_shop_page']);
         $this->opt['beans_on_product_page'] = isset($_POST['beans_on_product_page']);
         $this->opt['beans_rate_msg'] = isset($_POST['beans_rate_msg']);
         $this->opt['beans_popup'] = isset($_POST['beans_popup']);
         $this->opt['default_button_style'] = isset($_POST['default_button_style']);
         $this->opt['beans_invite_after_purchase'] = isset($_POST['beans_invite_after_purchase']);

         if(isset($_POST['range_min_redeem']))
            $this->opt['range_min_redeem'] = $_POST['range_min_redeem'];

         if(isset($_POST['range_max_redeem']))
            $this->opt['range_max_redeem'] = $_POST['range_max_redeem'];

         if(empty($this->errors))
            $this->message = __('Settings saved successfully.', 'woocommerce-beans-admin');

        update_option(BEANS_OPT_NAME, $this->opt);
    }
}
endif;

// Load Beans Settings
if( is_admin() )
    $GLOBALS['wc-beans-settings'] = new WC_Beans_Settings();

