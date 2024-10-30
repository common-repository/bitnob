<?php

/*

* Plugin Name: Bitnob - Accept Bitcoin Payments (On-chain & Lightning).

* Description: Accept bitcoin payments with bitnob

* Version: 1.1.4

* Author: Bitnob

* Author URI: https://bitnob.com

* License: GNU General Public License v3.0

* License URI: http://www.gnu.org/licenses/gpl-3.0.html

* Requires at least: 6.0

* Tested up to: 6.4.1

* WC requires at least: 3.2.0

* WC tested up to: 8.2

* Domain Path: /languages

* Text Domain: woocommerce-gateway-bitnob-payments

*/





add_filter('woocommerce_payment_gateways', 'bitnob_add_gateway_class');

function bitnob_add_gateway_class($gateways)

{

    $gateways[] = 'WC_Gateway_BitNob';

    return $gateways;

}



add_action('plugins_loaded', 'bitnob_add_gateway');

function bitnob_add_gateway()

{

    if (!class_exists('WC_Payment_Gateway')) return;



    class WC_Gateway_BitNob extends WC_Payment_Gateway

    {

        public function __construct()

        {

            $this->id = 'bitnob'; // payment gateway plugin ID



            $this->has_fields = true;



            $this->method_title = __('Bitnob');



            $this->method_description = __('Bitcoin Payments, Powered by Bitnob');



            $this->supports = array(

                'products'

            );



            /*Method with all the options fields*/

            $this->init_form_fields();



            $this->admin_email = get_option('admin_email');



            $this->title = $this->get_option('title');



            $this->description = $this->get_option('description');



            $this->enabled = $this->get_option('enabled');



            $this->testmode = $this->get_option( 'testmode' );



            $this->apikey = $this->get_option('apikey');



            $this->success_page_id = $this->settings['success_page_id'];



            add_action('woocommerce_api_wc_gateway_bitnob', array($this, 'webhookname'));



            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));



            add_action('woocommerce_receipt_bitnob', array($this, 'bitnob_checkout_receipt_page'));



            add_action('wp_head', array($this, 'woocommerce_enqueue_scripts'));

            //add_action( 'wp_footer' , [$this,'field_readonly'] );


        }
        
        public function woocommerce_enqueue_scripts()

        {



            if($this->enabled=='yes'){

                wp_enqueue_style( "bitnob-css", plugins_url('/assets/css/bitnob.css', __FILE__));

                wp_enqueue_script( "inline", plugins_url('/assets/js/inline.js', __FILE__));

                wp_enqueue_script( "bitnob", plugins_url('/assets/js/bitnob-js.js', __FILE__));

            }



        }

        public function process_payment($order_id)

        {



            global $woocommerce;



            $order     = new WC_Order($order_id);



            return array(

                'result' => 'success',

                'redirect' => $order->get_checkout_payment_url(true)

            );

            //Success URL 

        }

        public function bitnob_checkout_receipt_page($order_id)

        {

            global $woocommerce;



            $order = wc_get_order($order_id);



            $items = $order->get_items();



            $items = array_values(($items));



            if ($this->success_page_id == "" || $this->success_page_id == 0) {



                $successUrl = $order->get_checkout_order_received_url();



            } else {



                $successUrl = get_permalink($this->success_page_id);



            }



            $publicKey = $this->apikey;



            $amount = $order->total;



            //$callbackUrl = site_url() . '?wc-api=WC_Gateway_Bitnob&invoiceid=' . $order_id;

            $callbackUrl = site_url() . '/wc-api/wc_gateway_bitnob?invoiceid='.$order_id;



            $email = $order->get_billing_email();



            $currency = $order->currency;



            $environment = $this->testmode === 'yes' ? "sandbox" : "production";



            echo '<form method="post" action="" onsubmit="getFormData(event)">';



                echo '<input type="hidden" value="bitnob" name="bitnob">';



                echo '<input type="hidden" value="'.$publicKey.'" id="publicKey">';



                echo '<input type="hidden" value="'.$amount.'" id="amount">';



                echo '<input type="hidden" value="'.$email.'" id="email">';



                echo '<input type="hidden" value="'.$currency.'" id="currency">';



                echo '<input type="hidden" value="'.$callbackUrl.'" id="callbackUrl">';



                echo '<input type="hidden" value="'.$successUrl.'" id="successUrl">';



                echo '<input type="hidden" value="'.$this->admin_email.'" id="admin_email">';



                echo '<input type="hidden" value="Bitcoin Payment for Order No. ('.$order_id.') . Powered by Bitnob" id="description">';



                echo '<input type="hidden" value="'.$environment.'" id="environment">';



            echo '</form>';

        }

        public function webhookname()
        {

            $data = file_get_contents('php://input');



            $response = json_decode($data);



            $id = $response->data->id;



            $reference = $response->data->reference;



            $invoiceId = $response->data->invoiceId;



            $orderid = $_GET['invoiceid'];



            if ($response->event == 'checkout.received.paid') 
            {

                $response_id = $response->data->id;
                $reference = $response->data->reference;
                $invoiceId = $response->data->invoiceId;

                $order = wc_get_order($orderid);

                $order->payment_complete();
                // update status to "completed"
                $order->update_status( 'completed' );
                $order->reduce_order_stock();

                update_option('webhook_debug', $data);
                $order->add_order_note(sanitize_text_field('Bitcoin payment successful') . ("<br>") . ('ID') . (':') . ($response_id . ("<br>") . ('Payment Ref:') . ($reference) . ("<br>") . ('InvoiceId:') . ($invoiceId)));

                exit;

                if($this->testmode=="yes"){
                    $url = "https://sandboxapi.bitnob.co/api/v1/transactions/" . $response->data->transactions[0]->id;
                }
                else{
                    $url = "https://api.bitnob.co/api/v1/transactions/" . $response->data->transactions[0]->id; 
                }

                $apikey = $this->get_option('apikey');

                $resp = $this->sendDataCallback($url, $apikey); 

                $objdata = json_decode($resp);

                if ($objdata->status) {
                    $id = $objdata->data->id;

                    $reference = $objdata->data->reference;

                    $invoiceId = $objdata->data->invoiceId;

                    if ($objdata->data->status == 'success') {

                        $order = wc_get_order($orderid);

                        $order->payment_complete();

                        $order->add_order_note(sanitize_text_field('Bitcoin payment successful') . ("<br>") . ('ID') . (':') . ($id . ("<br>") . ('Payment Ref:') . ($reference) . ("<br>") . ('InvoiceId:') . ($invoiceId)));

                        $order->reduce_order_stock();

                        update_option('webhook_debug', $data);

                        $order->add_order_note($data);
                    } 
                    else{

                        $order = wc_get_order($orderid);

                        $order->update_status('pending');

                        $order->add_order_note(sanitize_text_field('Bitcoin payment failed') . ("<br>") . ('ID') . (':') . ($id . ("<br>") . ('Payment Type :') . ("<br>") . ('Payment Ref:') . ($reference) . ("<br>") . ('InvoiceId:') . ($invoiceId)));

                        update_option('webhook_debug', $data);

                        $order->add_order_note($data);

                    }

                }
            }
            else {

                $order = wc_get_order($orderid);

                $order->update_status('pending');

                $order->add_order_note(sanitize_text_field('Bitcoin payment failed') . ("<br>") . ('ID') . (':') . ($id . ("<br>") . ('Payment Type :') . ("<br>") . ('Payment Ref:') . ($reference) . ("<br>") . ('InvoiceId:') . ($invoiceId)));

                update_option('webhook_debug', $_GET);

                $order->add_order_note($data);

            }
            //wp_die();

        }

        public function sendDataCallback($url, $apikey)

        { 

            $request = wp_remote_get( 

                $url, 

                [

                    'headers' => [

                        'authorization' => 'Bearer ' . $apikey,

                        "accept" => "application/json",

                        "content-type" => "application/json"

                    ],

                ]

            );

            if ( ! is_wp_error( $request ) ) {

                $body = wp_remote_retrieve_body( $request );

                return $body;

            }

        }

        public function init_form_fields()

        {

            $this->form_fields = array(

                'enabled' => array(

                    'title'       => __('Enable/Disable'),

                    'label'       => __('Enable Bitnob Gateway'),

                    'type'        => 'checkbox',

                    'description' => '',

                    'default'     => 'no'

                ),

                'title' => array(

                    'title'       => __('Title'),

                    'type'        => 'text',

                    'description' => __('This controls the title which the user sees during checkout.'),

                    'default'     => 'Bitcoin',

                    'desc_tip'    => true,

                ),

                'description' => array(

                    'title'       => __('Description'),

                    'type'        => 'textarea',

                    'description' => __('This controls the description which the user sees during checkout.'),

                    'default'     => __('Bitcoin Payments. Powered by Bitnob. '),

                ),

                'apikey' => array(

                    'title'       => __('API Key'),

                    'type'        => 'text',

                ),

                'testmode' => array(

                    'title'       => __('Sandbox/Production'),

                    'label'       => __('Sandbox Environment'),

                    'type'        => 'checkbox',

                    'description' => '',

                    'default'     => 'yes'

                ),

                'success_page_id' => array(

                    'title'         => __('Return to Success Page'),

                    'type'             => 'select',

                    'options'         => $this->bitnob_get_pages('Select Page'),

                    'description'     => __('URL of success page', 'kdc'),

                    'desc_tip'         => true

                ),
                'webhook_url' => array(

                    'title'       => __('Webhook URL'),
                    'type'        => 'hidden',

                    'description' => site_url('/wc-api/wc_gateway_bitnob'),

                    'default'     => site_url('/wc-api/wc_gateway_bitnob')
                    
                    
                ),

            );



        }

        public function bitnob_get_pages()

        {



            $wp_pages = get_pages('sort_column=menu_order');



            $page_list = array();



            if (isset($title)) $page_list[] = $title;



            foreach ($wp_pages as $page) {



                $prefix = '';



                /*show indented child pages?*/

                if (isset($indent)) {



                    $has_parent = $page->post_parent;



                    while ($has_parent) {



                        $prefix .=  ' - ';



                        $next_page = get_post($has_parent);



                        $has_parent = $next_page->post_parent;



                    }



                }



                /*add to page list array array*/

                $page_list[$page->ID] = $prefix . $page->post_title;



            }



            return $page_list;



        }

    }

}

add_filter( 'woocommerce_gateway_icon', 'bitnob_payment_gateway_icon', 10, 2 );

function bitnob_payment_gateway_icon( $icon, $gateway_id ){

    

    /*Setting (or not) a custom icon to the payment IDs*/



    if($gateway_id == 'bitnob')



        $icon = '<img src="' . plugins_url('assets/img/logo.png', __FILE__) . '"  class="bitnob_icon"/>';



    return $icon;



}

/**

 * Adds an action to declare compatibility with High Performance Order Storage (HPOS)

 * before WooCommerce initialization.

 */

add_action(

	'before_woocommerce_init',

	function() {

		// Check if the FeaturesUtil class exists in the \Automattic\WooCommerce\Utilities namespace.

		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {

			// Declare compatibility with custom order tables using the FeaturesUtil class.

			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );

		}

	}

);

