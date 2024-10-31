<?php

/*
  Plugin Name: Payment Asia Gateway for WooCommerce
  Plugin URI: https://www.paymentasia.com/
  Description: Payment Asia Generic Payment Gateway
  Version: 1.0.4
  Author: Payment Asia
  Author URI: https://www.paymentasia.com/en/about-us/
 */
add_action('plugins_loaded', 'woocommerce_paymentasia_generic_init', 0);

function woocommerce_paymentasia_generic_init() {
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_PaymentasiaGeneric extends WC_Payment_Gateway {

        public function __construct() {
            
            $this->id = 'paymentasia_generic';
            $this->has_fields = false;
            $this->method_title = 'Payment Asia Gateway';

            $this->init_form_fields();
            $this->init_settings();
            
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->gateway_url = $this->settings['gateway_url'];
            $this->merchant_token = $this->settings['merchant_token'];
            $this->merchant_secret = $this->settings['merchant_secret'];
            $this->currency = get_woocommerce_currency();
            $this->shop_url = $this->settings['shop_url'];

            $this->msg['message'] = "";
            $this->msg['class'] = "";
            
            $this->lang = get_bloginfo("language");

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_paymentasia_generic', array(&$this, 'receipt_page'));

            /* callback/datafeed */
            add_action('woocommerce_api_wc_paymentasia', array($this, 'gateway_response'));

            /* Success Redirect URL */
            add_action('woocommerce_api_wc_pa_success_page', array($this, 'pa_success_redirect_url'));
            
        }

        function sign($fields, $secret) {
            return hash('SHA512', http_build_query($fields) . $secret);
        }

        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable:'),
                    'type' => 'checkbox',
                    'label' => __('Enable Payment Asia Generic Module.'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:'),
                    'type' => 'text',
                    'required' => true,
                    'description' => __('The title to be shown in checkout options.'),
                    'default' => __('Payment Asia Gateway')),
                'description' => array(
                    'title' => __('Description:'),
                    'type' => 'textarea',
                    'required' => true,
                    'description' => __('The description to be shown in checkout.'),
                    'default' => __('Pay securely through Payment Asia Generic services.')),
                'gateway_url' => array(
                    'title' => __('Gateway URL:'),
                    'type' => 'text',
                    'required' => true,
                    'description' => __('Gateway URL to send payment request.')),
                'shop_url' => array(
                    'title' => __('Shop URL:'),
                    'type' => 'text',
                    'required' => true,
                    'description' => __('Your online shop link.')),
                'merchant_token' => array(
                    'title' => __('Merchant token:'),
                    'type' => 'text',
                    'required' => true,
                    'description' => __('Your merchant token.')),
                'merchant_secret' => array(
                    'title' => __('Secret key:'),
                    'type' => 'text',
                    'required' => true,
                    'description' => __('Your secret key.'))
            );
        }

        public function admin_options() {
            
            $allow_tag = array(
                "table" => array("class"=>array()),
            );
            
            $str = '<h3>Payment Asia Generic Gateway</h3>
                    <p>
                        <strong>Gateway URL:</strong> <br/>
                        - Production: https://payment.pa-sys.com/app/page/generic/ <br/>
                        - Sandbox Test: https://payment-sandbox.pa-sys.com/app/page/generic/ <br/>
                        <br/>
                    </p>
                    <hr/>
                    <table class="form-table">';
            echo wp_kses_post($str);
            
            // Generate setting form.
            $this->generate_settings_html();
            
            echo wp_kses('</table>', $allow_tag);
        }

        function payment_fields() {
            if ($this->description)
                echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        /**
         * Receipt Page
         * */
        function receipt_page($order) {
//            include 'redirect.js';
            
            $allow_tag = array(
                "form" => array("id" => array(), "method"=> array(), "action" => array()),
                "input" => array(
                    'type'      => array(),
                    'name'      => array(),
                    'value'     => array(),
                ),
            );
            $thanksyoustr = '<p>Thank you for your order. You will be redirected to the Payment Gateway to proceed with the payment.</p>';
            $post_js = '<script type="text/javascript">
						jQuery(function(){
							setTimeout("pay();", 100);
	    				});
						function pay(){
							jQuery("#payment_form").submit();
						}
	    			</script>';
            
            $fields = $this->generate_paymentasia_form($order);
            $payment_array = array();
            
            $str = '<form action="' . $this->gateway_url . $this->merchant_token . '" method="post" id="payment_form">';
            foreach ($fields as $key => $value) {
                $str .= "<input type='hidden' name='$key' value='$value'/>";
            }

            $str .= '</form>';
            
            
            echo wp_kses_post($thanksyoustr);
            echo wp_kses($str, $allow_tag);
            wp_register_script( 'redirect', plugins_url( 'redirect.js', __FILE__ ));
            
            wp_enqueue_script('redirect', plugins_url( 'redirect.js', __FILE__ ));
            wp_localize_script('redirect', ' ', array(
                "alert" => "aaa"
            ));
//            echo $post_js;
        }

        /**
         * Generate Payment link
         * */
        public function generate_paymentasia_form($order_id) {
            global $woocommerce, $_SERVER;

            $logger = wc_get_logger();

            $order = new WC_Order($order_id);
            
            $return_url = $this->shop_url."/index.php?wc-api=wc_pa_success_page";
            $notify_url = $this->shop_url."/index.php?wc-api=wc_paymentasia";
            
            $remarks = '';
            $language = "zh-en";
            if(isset($this->lang)){
                if($this->lang == "zh-HK" || $this->lang == "zh-TW"){
                    $language = "zh-tw";
                }
                if($this->lang == "zh-CN"){
                    $language = "zh-cn";
                }
            }

            $fields = array(
                'merchant_reference'    => $order_id,
                'currency'              => $order->get_currency(),
                'amount'                => $order->get_total(),
                'return_url'            => $return_url,
                'notify_url'            => $notify_url,
                'customer_country'      => $order->get_billing_country(),
                'customer_state'        => $order->get_billing_country(),
                'customer_address'      => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
                'customer_postal_code'  => '000000',
                'customer_ip'           => $order->get_customer_ip_address(),
                'customer_first_name'   => $order->get_billing_first_name(),
                'customer_last_name'    => $order->get_billing_last_name(),
                'customer_phone'        => $order->get_billing_phone(),
                'customer_email'        => $order->get_billing_email(),
                'lang'                  => $language,
                'network'               => 'UserDefine'
            );
            if ($fields['customer_country'] == 'US') {
                $fields['customer_state'] = $order->get_billing_state();
            }

            ksort($fields);
            $fields['sign'] = $this->sign($fields, $this->merchant_secret);

            $logger->debug(json_encode($fields));

            return $fields;
        }

        /**
         * Process the payment and return the result
         * */
        function process_payment($order_id) {
            
            global $woocommerce;
            $order = new WC_Order($order_id);
            $woocommerce->cart->empty_cart();
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        /**
         * Datafeed
         * */
        function gateway_response() {
            
            global $woocommerce;
            $logger = wc_get_logger();

            $fields = array(
                "amount",
                "currency",
                "merchant_reference",
                "request_reference",
                "status",
                "sign",
            );
            $data = array();
            foreach ($fields as $field) {
                $data[$field] = filter_input(INPUT_POST, $field, FILTER_SANITIZE_STRING);
            }

            $sign = $data['sign'];
            unset($data['sign']);
            ksort($data);
            $signMsg="";
            if ($sign != $this->sign($data, $this->merchant_secret)) {
                foreach ($data as $k => $v) {
                    $signMsg .= $k . "=" . $v . "&";
                }
                
                $logger->debug($sign);
                $logger->debug($signMsg);
                $logger->debug("wrong 1");
                
                echo esc_attr('wrong sign');
                echo esc_attr($signMsg);
                exit();
            }

            $order = new WC_Order($data['merchant_reference']);
            if ($order->get_currency() != $data['currency'] || $order->get_total() != $data['amount']) {
                
                $logger->debug($data['merchant_reference']."/".$data['currency']."/".$data['amount']);
                $logger->debug($order->get_currency()."/".$order->get_total());
                $logger->debug("wrong 2");
                echo esc_attr('wrong currency or amount');
                exit();
            }

            echo esc_attr("OK");
            if ($order->status != 'completed') {
                switch ($data['status']) {
                    case 0: //pending
                        break;
                    case 1: //accepted
                        if ($order->status == 'processing') {//do nothing
                        } else {
                            $this->msg['message'] = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon. Payment reference no: ' . $data['request_reference'];
                            $this->msg['class'] = 'woocommerce_message';

                            $order->update_status('processing');
                            $order->add_order_note('Payment successful! Payment reference no: ' . $data['request_reference']);
                            $woocommerce->cart->empty_cart();
                        }
                        break;
                    case 2: //rejected
                         if($order -> status == 'processing'){//do nothing
                         }else{
                         	$this -> msg['message'] = 'Thank you for shopping with us. However, the transaction has been declined. Payment reference no: '. $data['request_reference'];
                         	$this -> msg['class'] = 'woocommerce_error';
                         	$order -> update_status('failed');
                         	$order -> add_order_note('Payment unsuccessful! Payment reference no: '.$data['request_reference']);
                         }
                        break;
                }
                add_action('the_content', array(&$this, 'showMessage'));
            }
            exit();
        }

        // redirect after payment to avoid lost cookies
        public function pa_success_redirect_url() {
//            include 'success_redirect.js';
            global $woocommerce;
            $logger = wc_get_logger();
            
            
            $fields = array(
                "amount",
                "currency",
                "merchant_reference",
                "request_reference",
                "status",
                "sign",
            );
            $data = array();
            foreach ($fields as $field) {
                $data[$field] = filter_input(INPUT_POST, $field, FILTER_SANITIZE_STRING);
            }
            
            $sign = $data['sign'];
            unset($data['sign']);
            ksort($data);
            if ($sign != $this->sign($data, $this->merchant_secret)) {
                foreach ($data as $k => $v) {
                    $signMsg .= $k . "=" . $v . "&";
                }
                $logger->debug($sign);
                $logger->debug($signMsg);
                $logger->debug("wrong 3");
                echo esc_attr('wrong sign(2)');
                exit();
            }
            
            $order = new WC_Order($data['merchant_reference']);
            
            $successURL = $order->get_checkout_order_received_url();
            if(strval($data["status"]) !== "1"){
                $successURL = $order->get_view_order_url();
            }
            
            wp_redirect($successURL);exit;
            
//            $allow_tag = array(
//                "form" => array("id" => array(), "name" => array(), "method"=> array(), "action" => array()),
//                "input" => array("type" => array(), "name" => array(), "value" => array()),
//                "head" => array(),
//                "body" => array(),
//                "html" => array(),
//                
//            );
//            
//            $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body><form id='form-success' name='form-success' method='get' action='{$successURL}'>";
//
//            foreach ($data as $_key => $_val) {
//                $html .= "<input type='hidden' name='{$_key}' value='".$_val."' />";
//            }
//
//            $html .= "</form>";
//
//            echo wp_kses($html, $allow_tag);
//            echo "<script type='text/javascript'>document.getElementById('form-success').submit();</script>";
//            echo wp_kses("</body></html>", $allow_tag);
////            wp_register_script( 'success_redirect', '/wp-content/plugins/PaymentAsia-Generic/success_redirect.js' );
////            
////            wp_enqueue_script('success_redirect', '/wp-content/plugins/PaymentAsia-Generic/success_redirect.js');
////            wp_localize_script('success_redirect', ' ', array(
////                "alert" => "bbb"
////            ));
//            exit;
        }

        function showMessage($content) {
            return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
        }

        // get all pages
        function get_pages($title = false, $indent = true) {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title)
                $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }

    }

    /**
     * Add the Gateway to WooCommerce
     * */
    function woocommerce_add_paymentasia_generic_gateway($methods) {
        $methods[] = 'WC_PaymentasiaGeneric';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_paymentasia_generic_gateway');
}
