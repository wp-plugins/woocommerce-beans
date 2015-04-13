<?php

class WC_Beans_Order {

    const TABLE_NAME = 'beans_dr_cr';
    public $debit_id       = null;
    public $credit_id      = null;
    public $account_id     = null;
    public $wc_order_id    = null;
    public $followup_sent  = false;

    public $wc_order       = null;

    public function __construct($order_id, $insert=true) {
        $this->wc_order_id = $order_id;
        if ( ! $this->load() ) {
            if ( $insert ) {
                $this->insert();
            } else {
                $this->wc_order_id = null;
            }
        }
    }

    public function load(){
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        try{
            $res = $wpdb->get_row( $wpdb->prepare(
                    "
                        SELECT beans_id, credit, debit, followup_sent
                        FROM $table_name
                        WHERE order_id = %d
                    ",
                    $this->wc_order_id
                ),
                'ARRAY_A' );
        }catch( Exception $e ){
            return false;
        }

        if(!$res) return false;

        $this->account_id       = $res['beans_id'];
        $this->credit_id        = $res['credit'];
        $this->debit_id         = $res['debit'];
        $this->followup_sent = $res['followup_sent'];

        return true;
    }

    public function insert(){
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        try{
            $wpdb->insert(
                $table_name,
                array(
                    'order_id'      => $this->wc_order_id,
                    'beans_id'      => $this->account_id,
                    'debit'         => $this->debit_id,
                    'credit'        => $this->credit_id,
                    'followup_sent' => $this->followup_sent,
                )
            );
        }catch( Exception $e ){
            return false;
        }

        return true;
    }

    public function save(){
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        try{
            $wpdb->update(
                $table_name,
                array(
                    'beans_id'        => $this->account_id,
                    'debit'           => $this->debit_id,
                    'credit'          => $this->credit_id,
                    'followup_sent'   => $this->followup_sent,
                ) ,
                array('order_id' => $this->wc_order_id),
                array(
                    '%s',
                    '%s',
                    '%s',
                    '%d'
                ),
                '%d'
            );
        }catch( Exception $e ){
            return false;
        }

        return true;
    }

    public function get_wc_order(){
        if(!$this->wc_order)
            $this->wc_order = new WC_Order($this->wc_order_id);
        return $this->wc_order;
    }

    public function make_credit(){
        if($this->credit_id || !$this->account_id) return;

        $wc_order = $this->get_wc_order();

        beans_log_info("Processing beans credit", true);

        $total = $wc_order->get_total() - $wc_order->get_total_shipping();
        beans_log_info("Oder total => ".$wc_order->get_total()." Shipping total => ".$wc_order->get_total_shipping());

        if($total>0){

            $total_str = sprintf(get_woocommerce_price_format(),
                " ".strtoupper(get_woocommerce_currency())." ", $total);

            $data = array(
                'quantity'      => $total,
                'rule_type__id' => 'rt_09uk',
                'account__id'   => $this->account_id,
                'description'   => "Customer loyalty rewarded for a $total_str purchase",
                'uid' => $wc_order->id.'_'.$wc_order->order_key,
            );

            beans_log_info("data => ".print_r($data, true));

            try{
                $credit = Beans::post('credit', $data, false);
                if($credit['status'] == 'failed'){
                    beans_log_info("**************** ERROR *****************");
                    error_log($credit['failure_message'], 3, BEANS_ERROR_LOG);
                }
                $this->credit_id = $credit['id'];
            }catch(Exception $e) {
                beans_log_info( "**************** ERROR *****************" );
                error_log( $e, 3, BEANS_ERROR_LOG );
                error_log( print_r($data, true), 3, BEANS_ERROR_LOG );
            }
        }
        $this->save();
    }

    public function make_debit()
    {
        if(!$this->account_id) return;

        $wc_order = $this->get_wc_order();
        $err_msg = "";

        $coupon_codes = $wc_order->get_used_coupons();

        foreach ($coupon_codes as $code) {

            if ($code === BEANS_COUPON_UID) {
                beans_log_info("Processing beans debit", true);
                $coupon = new WC_Coupon($code);
                # fixme: find a better way to do this
                $amount = (double) (property_exists($coupon, 'coupon_amount') ? $coupon->coupon_amount : $coupon->amount);
                $amount_str = sprintf(get_woocommerce_price_format(),
                    " " . strtoupper(get_woocommerce_currency()) . " ",
                    $amount);
                $data = array(
                    'amount' => $amount,
                    'currency' => strtoupper(get_woocommerce_currency()),
                    'account__id' => $this->account_id,
                    'description' => "Debited for a $amount_str discount",
                    'uid' => $wc_order->id . '_' . $wc_order->order_key,
                );
                beans_log_info("data => " . print_r(json_encode($data), true));
                try {
                    $debit = Beans::post('debit', $data, false);
                    if ($debit['status'] == 'failed') {
                        beans_log_info("**************** ERROR *****************");
                        error_log($debit['failure_message'], 3, BEANS_ERROR_LOG);
                        $err_msg = "Beans error: " . $debit['failure_message'];
                    }
                    $this->debit_id = $debit['id'];
                } catch (Exception $e) {
                    beans_log_info("**************** ERROR *****************");
                    error_log($e, 3, BEANS_ERROR_LOG);
                    error_log(print_r($data, true), 3, BEANS_ERROR_LOG);
                    if(!$err_msg) $err_msg = "Beans error: Unable to debit your beans account.".$e;
                }

            }
        }

        $this->save();
        if($err_msg) throw new Exception($err_msg); // because there is no finally clause in PHP
    }

    public function send_followup_email(){
        if($this->followup_sent || $this->account_id) return;

        $opt = get_option(BEANS_OPT_NAME);
        $rule = Beans::get('rule/'.$opt['rule_currency_spent_id']);

        $wc_order = new WC_Order($this->wc_order_id);

        if ($opt['beans_invite_after_purchase']){
            $data = array(
                'email'        => $wc_order->billing_email,
                'model'        => 'order_followup',
                'substitutions' => array(
                    'beans_order' => $this->get_beans_to_credit($rule),
                    'redirect_link' => $wc_order->get_checkout_order_received_url(),
                )
            );
            Beans::post('notification', $data);
            $this->followup_sent = true;
            $this->save();
        }
    }

    public function  get_beans_to_credit($rule_currency_spent){
        $wc_order = $this->get_wc_order();
        $total = $wc_order->get_total() - $wc_order->get_total_shipping();
        return (int) ($total * $rule_currency_spent['beans']);
    }
    /*
    method cancel # todo later
        # cancel credit and debit
    */

    public static function db_install($charset_collate){
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME ;

        $sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          order_id mediumint(9) NOT NULL,
          beans_id varchar(255) NULL,
          credit varchar(255) NULL,
          debit varchar(255) NULL,
          followup_sent SMALLINT(1) NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY order_id (order_id)
        ) $charset_collate;";

        dbDelta($sql);
    }
} 