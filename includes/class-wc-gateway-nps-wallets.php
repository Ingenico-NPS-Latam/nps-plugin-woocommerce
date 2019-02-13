<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



use NpsSDK\Configuration;
use NpsSDK\Constants;
use NpsSDK\Sdk;
use NpsSDK\ApiException;





/**
 * WC_Gateway_Nps class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Nps_Wallets extends WC_Gateway_Nps {
  
  const ENVIRONMENT_SANDBOX = 1;  
  const ENVIRONMENT_PRODUCTION = 0;
    
  public function __construct() {
      parent::__construct();
      
      if(is_checkout()) {
        $this->id                   = 'nps-wallet';
        $this->method_title         = __( 'Nps Wallet', 'woocommerce-gateway-nps' );
      
        $this->title = "Wallet (Nps)"; 
        $this->description = "Pay with your wallet via Nps.";
      }
  }
  
  public function thankyou_page( $order ) {
    return;
  }  
  
  public function payment_scripts() {
      // wp_enqueue_script( 'nps_wallets_masterpass', 'https://masterpass.com/integration/merchant.js', array('jquery','wc-credit-card-form'), WC_NPS_VERSION, true );
      if($masterpass = @$this->wallets[array_search("2", array_column($this->wallets, 'wallet'))]) {
        if($masterpass['environment'] == self::ENVIRONMENT_PRODUCTION) {
          $masterpassHost = "masterpass.com";
        }else {
          $masterpassHost = "sandbox.masterpass.com";  
        }
        wp_enqueue_script( 'nps_wallets_masterpass', "https://$masterpassHost/integration/merchant.js", array('jquery','wc-credit-card-form'), WC_NPS_VERSION, true );
      }
  }

  public function form() {
          $fields = array();
          $default_fields = array();
          $post_data = isset($_REQUEST['post_data']) ? $_REQUEST['post_data'] : "";
          parse_str($post_data, $post);
          parse_str(@$post['_wp_http_referer'], $get);
          $oauth_verifier = @$get['oauth_verifier'] ?: @$_REQUEST['oauth_verifier'];

      try {


          if (($WalletKey = $oauth_verifier) && ($WalletType = 2)) {
              $sdk = new Sdk();
              $request = array(
                  'psp_Version' => '2.2',
                  'psp_MerchantId' => $this->merchant_id,
                  'psp_PosDateTime' => date('Y-m-d H:i:s'),
              );
              $this->log("Info: Beginning createClientSession for merchant {$this->merchant_id}", true);
              $this->log('Processing createClientSession request: ' . print_r($request, true), $this->logging);
              $createClientSessionResponse = $sdk->createClientSession($request);
              $this->log('Processing createClientSession response: ' . print_r($createClientSessionResponse, true), $this->logging);

              if ($psp_ClientSession = @$createClientSessionResponse->psp_ClientSession) {
                  $this->log("Success: client session created - ClientSession ID: {$psp_ClientSession} - Reason: {$createClientSessionResponse->psp_ResponseMsg}", true);

                  $sdk = new Sdk();
                  $request = array(
                      'psp_Version' => '2.2',
                      'psp_MerchantId' => $this->merchant_id,
                      'psp_WalletInputDetails' => array(
                          'WalletTypeId' => '2',
                          'WalletKey' => $WalletKey,
                          'MerchOrderId' => key(WC()->cart->get_cart()),
                      ),
                      'psp_ClientSession' => $psp_ClientSession,
                  );
                  $this->log('Processing createPaymentMethodToken request: ' . print_r($request, true), $this->logging);
                  $createPaymentMethodTokenResponse = $sdk->createPaymentMethodToken($request);
                  $this->log('Processing createPaymentMethodToken response: ' . print_r($createPaymentMethodTokenResponse, true), $this->logging);

                  if ($psp_PaymentMethodToken = @$createPaymentMethodTokenResponse->psp_PaymentMethodToken) {
                      // $_REQUEST['wc-nps-payment-token'] = 'new';
                      // $_REQUEST['npsPaymentMethodTokenId'] = $psp_PaymentMethodToken;

                      $selectedPaymentMethodFields['nps-wallet-card-brand'] = '<input type="hidden" name="nps-wallet-card-brand" id="nps-wallet-card-brand" value="' . @$createPaymentMethodTokenResponse->psp_Product . '" />';
                      // $selectedPaymentMethodFields['wc-nps-payment-token'] = '<input type="hidden" name="wc-nps-payment-token" id="wc-nps-payment-token" value="'.$psp_PaymentMethodToken.'" />';
                      $selectedPaymentMethodFields['npsPaymentMethodTokenId'] = '<input type="hidden" name="npsPaymentMethodTokenId" id="npsPaymentMethodTokenId" value="' . $psp_PaymentMethodToken . '" />';
                      $selectedPaymentMethodFields['WalletType'] = '<input type="hidden" name="WalletType" id="WalletType" value="' . $WalletType . '" />';
                      $selectedPaymentMethodFields['WalletKey'] = '<input type="hidden" name="WalletKey" id="WalletKey" value="' . $WalletKey . '" />';

                      if ($card = @$createPaymentMethodTokenResponse->psp_WalletOutputDetails->CardOutputDetails) {
                          $selectedPaymentMethodFields['wallet-selected-card-number'] = '<p class="form-row form-row-wide"><label>Card Number</label><input type="text" value="' . $card->MaskedNumber . '" readonly></p>';
                          $selectedPaymentMethodFields['wallet-selected-card-expiry'] = '<p class="form-row form-row-wide"><label>Card Expiry (MM/YY)</label><input type="text" value="' . $card->ExpirationDate . '" readonly></p>';
                          $selectedPaymentMethodFields['wallet-selected-card-holder-name'] = '<p class="form-row form-row-wide"><label>Name On Card</label><input type="text" value="' . $card->HolderName . '" readonly></p>';
                      }

                      $selectedPaymentMethodFields['card-numpayments-field'] = '<p class="form-row form-row-wide">
                  <label for="nps-card-numpayments">' . __('Installments', 'woocommerce-nps') . ' <span class="required">*</span></label>
                  <select id="nps-card-numpayments" class="wc-credit-card-form-card-numpayments" name="' . ('nps-card-numpayments') . '" >
                  ' . $this->renderInstallmentChoices(@$createPaymentMethodTokenResponse->psp_Product) . '</select>
                  </p>';

                      // $cart = WC_Cart::get_cart();
                      // $order_id   = isset( WC()->session->order_awaiting_payment ) ? absint( WC()->session->order_awaiting_payment ) : 0;
                      // do_action( 'wc_gateway_nps_process_payment', $response, $order );

                      // return $this->process_payment($order_id, $retry=true, $force_customer=false);

                      // var_dump(WC()->checkout());exit;

                      // WC()->checkout()->process_checkout();

                      /**
                       * force selected_payment_method
                       */
                      echo "<script>
                      jQuery(document).ready(function(){ 
                        setTimeout(function(){ jQuery('.woocommerce-checkout input[name=payment_method]').removeAttr('checked');jQuery('#payment_method_nps-wallet').attr('checked','checked');jQuery( 'div.payment_box' ).filter( ':visible' ).slideUp( 0 );jQuery( '.woocommerce-checkout' ).find( 'input[name=payment_method]' ).filter( ':checked' ).eq(0).trigger( 'click' ); },1000);
                      });
                      </script>";


                  } else {
                      $this->log("Error: On createPaymentMethodToken for merchant {$this->merchant_id} - Reason: " . @$createPaymentMethodTokenResponse->psp_ResponseExtended, true);
                      wc_add_notice( __( NPS_ERR_WILDCARD, 'woocommerce-gateway-nps' ), 'error' );
                  }

              } else {
                  $this->log("Error: On createClientSession for merchant {$this->merchant_id} - Reason: " . @$createClientSessionResponse->psp_ResponseExtended, true);
              }
          }

      }catch (Exception $e) {
          $this->log("Error: On while creating paymentMethodToken for merchant {$this->merchant_id} - Reason: " . $e->getMessage(), true);
          wc_add_notice( __( NPS_ERR_WILDCARD, 'woocommerce-gateway-nps' ), 'error' );
      }

          if (($masterpass = @$this->wallets[array_search("2", array_column($this->wallets, 'wallet'))])
              && @$masterpass['status'] == '1'
              && strlen(@$masterpass['key']) > 0) {

              $amount = sprintf("%.2f", (WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax()));

              // var_dump($amount);

              // var_dump(key(WC()->cart->get_cart()), (WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax()));
              // "cartId": "1efed583-1824-436a-869f-286ebdb22ae9",                                   // Unique identifier for cart generated by merchant

              $default_fields['wallet-masterpass-field'] = '
<script>
function npsMasterpassCheckout() { 
  masterpass.checkout({
          "checkoutId": "' . $masterpass['key'] . '",                                   // Merchant checkout identifier received when merchant onboarded for masterpass
          "allowedCardTypes": ["master,amex,diners,discover,jcb,maestro,visa"],               // Card types accepted by merchant
          "amount": "' . $amount . '",                                                                 // Shopping cart subtotal
          "currency": "' . get_woocommerce_currency() . '",                                                                  // Currency code for cart
          // "shippingLocationProfile": "US,AU,BE",                                              // Shipping locations supported by merchant - configured in merchant portal
          // "suppress3Ds": false,                                                               // Set true when 3DS not mandatory for the spcecific country
          "suppressShippingAddress": true,                                                   // Set true when cart items has digital goods only
          "cartId": "' . key(WC()->cart->get_cart()) . '",                                   // Unique identifier for cart generated by merchant
          // "callbackUrl": "https://dev.nps.com.ar/"                                // The URL to which the browser must redirect when checkout is complete
  });
}
</script>
<div style="width:147px;height:34px;"><img src="https://static.masterpass.com/dyn/img/btn/global/mp_chk_btn_147x034px.svg" onclick="npsMasterpassCheckout()" style="width:147px;height:34px;min-width:147px;min-height:34px;max-width:147px;max-height:34px;" /></div>';

          }


          $fields = wp_parse_args($fields, apply_filters('woocommerce_credit_card_form_fields', $default_fields, $this->id));
          ?>
          <fieldset id="<?php echo $this->id; ?>-cc-form">
              <?php do_action('woocommerce_credit_card_form_start', $this->id); ?>
              <?php
              foreach ($fields as $field) {
                  echo $field;
              }
              ?>
              <?php do_action('woocommerce_credit_card_form_end', $this->id); ?>
              <div class="clear"></div>
          </fieldset>
          <p>Selected Payment Method:</p>
          <fieldset id="<?php echo $this->id; ?>-selected-payment-method-form">
              <?php do_action('woocommerce_credit_card_form_start', $this->id); ?>
              <?php
              if (isset($selectedPaymentMethodFields) && is_array($selectedPaymentMethodFields) && count($selectedPaymentMethodFields)) {
                  foreach ($selectedPaymentMethodFields as $field) {
                      echo $field;
                  }
              } else {
                  ?>
                  <input type="hidden" name="nps-wallet-pm-not-choosen" id="nps-wallet-pm-not-choosen" value="1"/>
                  <p>Choose your wallet before the payment method.</p>
                  <?php
              }
              ?>
              <?php do_action('woocommerce_credit_card_form_end', $this->id); ?>
              <div class="clear"></div>
          </fieldset>
          <?php




  }
  
}
