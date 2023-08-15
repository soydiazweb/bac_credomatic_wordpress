<?php
/* Authorize.net AIM Payment Gateway Class */
class Bac_Payment_Gateway extends WC_Payment_Gateway {
  // Setup our Gateway's id, description and other values
  function __construct() {
    // The global ID for this Payment method
    $this->id = "bac_payment";

    // The Title shown on the top of the Payment Gateways Page next to all the other Payment Gateways
    $this->method_title = __( "BAC PAYMENT GATEWAY", 'bac-payment' );

    // The description for this Payment Gateway, shown on the actual Payment options page on the backend
    $this->method_description = __( "BAC Payment Gateway Plug-in for WooCommerce", 'bac-payment' );

    // The title to be used for the vertical tabs that can be ordered top to bottom
    $this->title = __( "BAC Payment Gateway", 'bac-payment' );

    // If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
    $this->icon = null;

    // Bool. Can be set to true if you want payment fields to show on the checkout 
    // if doing a direct integration, which we are doing in this case
    $this->has_fields = true;

    // Supports the default credit card form
    $this->supports = array( 'default_credit_card_form' );

    // This basically defines your settings which are then loaded with init_settings()
    $this->init_form_fields();

    // After init_settings() is called, you can get the settings and load them into variables, e.g:
    // $this->title = $this->get_option( 'title' );
    $this->init_settings();
    
    // Turn these settings into variables we can use
    foreach ( $this->settings as $setting_key => $value ) {
      $this->$setting_key = $value;
    }
    
    // Lets check for SSL
    add_action( 'admin_notices', array( $this,  'do_ssl_check' ) );
    
    // Save settings
    if ( is_admin() ) {
      // Versions over 2.0
      // Save our administration options. Since we are not going to be doing anything special
      // we have not defined 'process_admin_options' in this class so the method in the parent
      // class will be used instead
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }   
  } // End __construct()


  // Build the administration fields for this specific Gateway
  public function init_form_fields() {
    $this->form_fields = array(
      'enabled' => array(
        'title'   => __( 'Activar / Desactivar', 'bac-payment' ),
        'label'   => __( 'Activar este metodo de pago', 'bac-payment' ),
        'type'    => 'checkbox',
        'default' => 'no',
      ),
      'title' => array(
        'title'   => __( 'Título', 'bac-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'Título de pago que el cliente verá durante el proceso de pago.', 'bac-payment' ),
        'default' => __( 'Tarjeta de crédito', 'bac-payment' ),
      ),
      'description' => array(
        'title'   => __( 'Descripción', 'bac-payment' ),
        'type'    => 'textarea',
        'desc_tip'  => __( 'Descripción de pago que el cliente verá durante el proceso de pago.', 'bac-payment' ),
        'default' => __( 'Pague con seguridad usando su tarjeta de crédito.', 'bac-payment' ),
        'css'   => 'max-width:350px;'
      ),
      'key_id' => array(
        'title'   => __( 'Key id', 'bac-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'ID de clave de seguridad del panel de control del comerciante.', 'bac-payment' ),
        'default' => '',
      ),
      'api_key' => array(
        'title'   => __( 'Api key', 'bac-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'ID de clave de api del panel de control del comerciante.', 'bac-payment' ),
        'default' => '',
      ),
    );    
  }

  // Submit payment and handle response
  public function process_payment( $order_id ) {
    global $woocommerce;
    
    // Get this Order's information so that we know
    // who to charge and how much
    $customer_order = new WC_Order( $order_id );

    $environment_url = 'https://credomatic.compassmerchantsolutions.com/api/transact.php';
    
    $time = time();

    $key_id = $this->key_id;


    $orderid = str_replace( "#", "", $customer_order->get_order_number() );

    //$hash = md5(""."|".$customer_order->order_total."|".$time."|".$this->api_key);
    $hash = md5($orderid."|".$customer_order->order_total."|".$time."|".$this->api_key);

    // This is where the fun stuff begins
    $payload = array(
      "key_id"  => $key_id,
      "hash" => $hash,
      "time" => $time,
      "amount" => $customer_order->order_total,
      "ccnumber" => str_replace( array(' ', '-' ), '', $_POST['bac_payment-card-number'] ),
      "ccexp" => str_replace( array( '/', ' '), '', $_POST['bac_payment-card-expiry'] ),
      "orderid" => $orderid,
      "cvv" => ( isset( $_POST['bac_payment-card-cvc'] ) ) ? $_POST['bac_payment-card-cvc'] : '',
      "type" => "sale", //auth,sale
      "redirect" => $this->get_return_url( $customer_order ),
     );

    // Send this payload to Authorize.net for processing
    $response = wp_remote_post( $environment_url, array(
      'method'    => 'POST',
      'body'      => http_build_query( $payload ),
      'timeout'   => 90,
      'sslverify' => false,
    ) );


    if ( is_wp_error( $response ) ) 
      throw new Exception( __( 'We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.'.$payload, 'bac-payment' ) );

    if ( empty( $response['body'] ) )
      throw new Exception( __( 'BAC\'s Response was empty.', 'bac-payment' ) );
      
    // Retrieve the body's response if no errors found
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    // Procesar la respuesta HTML aquí
    if ( $response_code === 200 ) {

      // Parse the HTML to extract the form action attribute
      $dom = new DOMDocument();
      @$dom->loadHTML($response_body);  // Suppress errors, as HTML might not be well-formed

      // Find the form element(s) and read the 'action' attribute
      $forms = $dom->getElementsByTagName('form');
      foreach ($forms as $form) {
        $action = $form->getAttribute('action');
      }

      if($action != ''){

        return [
         'result' => 'success',
         'redirect' => $action
        ];

      } else {
        // Transaction was not succesful
        // Add notice to the cart
        wc_add_notice( $resp['responsetext'], 'error' );
        // Add note to the order for your reference
        $customer_order->add_order_note( 'Lo sentimos vuelva a intentarlo nuevamente. Código: '. $resp['responsetext'] );
      }

    } else {
      // Transaction was not succesful
      // Add notice to the cart
      wc_add_notice( $resp['responsetext'], 'error' );
      // Add note to the order for your reference
      $customer_order->add_order_note( 'Lo sentimos vuelva a intentarlo nuevamente. Código: '. $resp['responsetext'] );
    }

    // Validate fields
   

  }//end process payment

  public function validate_fields() {
    return true;
  }

  // Check if we are forcing SSL on checkout pages
  // Custom function not required by the Gateway
  public function do_ssl_check() {
    if( $this->enabled == "yes" ) {
      if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
        echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";  
      }
    }   
  }

}

/**
 * Display field value on the order edit page
 */
add_action( 'woocommerce_admin_order_data_after_billing_address', 'show_bac_info', 10, 1 );
function show_bac_info( $order ){
  $order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;
  echo '<p><strong>'.__('BAC Auth Code').':</strong> ' . get_post_meta( $order_id, '_wc_order_bac_authcode', true ) . '</p>';
  echo '<p><strong>'.__('BAC Transaction Id').':</strong> ' . get_post_meta( $order_id, '_wc_order_bac_transactionid', true ) . '</p>';
}


add_action( 'init', 'bac_woocommerce_complete_order' );
function bac_woocommerce_complete_order( $order ) { 
  global $woocommerce;

  if ( !isset($_GET["orderid"]) ) {
    return;
  }

  $customer_order = new WC_Order( $_GET["orderid"] );

  if($_GET["response"] == '1'){

    // Payment has been successful
    $customer_order->add_order_note( __( 'BAC payment completed.', 'bac-payment' ) );
    
    // Saving the bac info
    $order_id = method_exists( $customer_order, 'get_id' ) ? $customer_order->get_id() : $customer_order->ID;
    update_post_meta($order_id , '_wc_order_bac_authcode', $_GET['authcode'] );
    update_post_meta($order_id , '_wc_order_bac_transactionid', $_GET['transactionid'] );
                       
    // Mark order as Paid
    $customer_order->payment_complete();

    // Empty the cart (Very important step)
    $woocommerce->cart->empty_cart();

    wp_redirect('/orden-recibida');

  }else{

    wc_add_notice( 'Lo sentimos no hemos podido procesar tu pedido, por favor intenta nuevamente.', 'error' );
    wc_add_notice( 'El procesador de cobro ha devuelto los siguientes mensajes: ', 'error' );
    wc_add_notice( $_GET['responsetext'], 'error' );
    // Add note to the order for your reference
    $customer_order->add_order_note( 'Lo sentimos vuelva a intentarlo nuevamente. Código: '. $_GET['responsetext'] );

    wp_redirect('/pagina-carrito');
  }

}



?>