<?php
/*
MIT License

Copyright (c) 2016 Ingenico NPS Latam Platform (http://www.nps.com.ar)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

/**
 * @package Ingenico_NPS
 * @version 1.1
 */
/*
Plugin Name: WooCommerce NPS Payment Gateway
Plugin URI: https://github.com/Ingenico-NPS-Latam
Description: NPS is a platform devoted to on-line payment processing, offering credit cards and alternative means of payment acceptance to e-commerce sites. Through a unique technical integration, a site could be connected to all means of payment available in Latin America.
Author: Ingenico NPS
Version: 1.1
Author URI: https://github.com/Ingenico-NPS-Latam
WC tested up to: 3.7.0
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required minimums and constants
 */
define( 'WC_NPS_VERSION', '0.1.1' );
define( 'WC_NPS_MIN_PHP_VER', '5.6.0' );
define( 'WC_NPS_MIN_WC_VER', '2.5.0' );
define( 'WC_NPS_MAIN_FILE', __FILE__ );
define( 'WC_NPS_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_NPS_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

include_once('includes/nps-sdk-php-master/init.php');


use NpsSDK\Configuration;
use NpsSDK\Constants;
use NpsSDK\Sdk;
use NpsSDK\ApiException;

if ( ! class_exists( 'WC_Nps' ) ) :

	class WC_Nps {

		/**
		 * @var Singleton The reference the *Singleton* instance of this class
		 */
		private static $instance;

		/**
		 * @var Reference to logging class.
		 */
		private static $log;

		/**
		 * Returns the *Singleton* instance of this class.
		 *
		 * @return Singleton The *Singleton* instance.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private clone method to prevent cloning of the instance of the
		 * *Singleton* instance.
		 *
		 * @return void
		 */
		private function __clone() {}

		/**
		 * Private unserialize method to prevent unserializing of the *Singleton*
		 * instance.
		 *
		 * @return void
		 */
		public function __wakeup() {}

		/**
		 * Flag to indicate whether or not we need to load code for / support subscriptions.
		 *
		 * @var bool
		 */
		private $subscription_support_enabled = false;

		/**
		 * Flag to indicate whether or not we need to load support for pre-orders.
		 *
		 * @since 3.0.3
		 *
		 * @var bool
		 */
		private $pre_order_enabled = false;

		/**
		 * Notices (array)
		 * @var array
		 */
		public $notices = array();

		/**
		 * Protected constructor to prevent creating a new instance of the
		 * *Singleton* via the `new` operator from outside of this class.
		 */
		protected function __construct() {
			add_action( 'admin_init', array( $this, 'check_environment' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
			add_action( 'plugins_loaded', array( $this, 'init' ) );
		}

		/**
		 * Init the plugin after plugins_loaded so environment variables are set.
		 */
		public function init() {
			// Don't hook anything else in the plugin if we're in an incompatible environment
			if ( self::get_environment_warning() ) {
				return;
			}

			include_once( dirname( __FILE__ ) . '/includes/class-wc-nps-customer.php' );

			// Init the gateway itself
			$this->init_gateways();

			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
			add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment' ) );
			add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment' ) );
			add_action( 'woocommerce_order_status_on-hold_to_cancelled', array( $this, 'cancel_payment' ) );
			add_action( 'woocommerce_order_status_on-hold_to_refunded', array( $this, 'cancel_payment' ) );
			add_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'woocommerce_get_customer_payment_tokens' ), 10, 3 );
			add_action( 'woocommerce_payment_token_deleted', array( $this, 'woocommerce_payment_token_deleted' ), 10, 2 );
			add_action( 'woocommerce_payment_token_set_default', array( $this, 'woocommerce_payment_token_set_default' ) );
			add_action( 'wp_ajax_nps_dismiss_request_api_notice', array( $this, 'dismiss_request_api_notice' ) );
      
      add_action( 'wp_ajax_woocommerce_' . 'get_card_installments', array( __CLASS__, 'get_card_installments' ) );
      add_action( 'wp_ajax_nopriv_woocommerce_' . 'get_card_installments', array( __CLASS__, 'get_card_installments' ) );
      add_action( 'wc_ajax_' . 'get_card_installments', array( __CLASS__, 'get_card_installments' ) );            
      add_action( 'wp_ajax_woocommerce_' . 'get_client_session', array( __CLASS__, 'get_client_session' ) );
      add_action( 'wp_ajax_nopriv_woocommerce_' . 'get_client_session', array( __CLASS__, 'get_client_session' ) );
      add_action( 'wc_ajax_' . 'get_client_session', array( __CLASS__, 'get_client_session' ) );                  
		}

    public static function get_client_session() {
      $payment_gateways = WC()->payment_gateways->payment_gateways();
      $gateway = $payment_gateways['nps'];
      
      if ( is_null( $gateway ) ) {
        return new WP_Error( 'woocommerce_rest_payment_gateway_invalid', __( 'Resource does not exist.', 'woocommerce' ), array( 'status' => 404 ) );
      }
      
      $sdk = new Sdk();
      $request = array(
        'psp_Version'          => '2.2',
        'psp_MerchantId'       => self::get_merchant_id(),
        'psp_PosDateTime'      => date('Y-m-d H:i:s'),           
      );
      WC_Nps::log( "Info: Beginning createClientSession for merchant " . self::get_merchant_id(), true );
      WC_Nps::log( 'Processing createClientSession request: ' . print_r( $request, true ), WC_Nps::get_nps_logging() );
      $response_createClientSession = $sdk->createClientSession($request);
      WC_Nps::log( 'Processing createClientSession response: ' . print_r( $response_createClientSession, true ), WC_Nps::get_nps_logging() );

      if(@$response_createClientSession->psp_ClientSession) {
        WC_Nps::log("Success: client session created - ClientSession ID: {$response_createClientSession->psp_ClientSession} - Reason: {$response_createClientSession->psp_ResponseMsg}", true); 
      }else {
        WC_Nps::log("Error: On createClientSession for merchant " . self::get_merchant_id() . " - Reason: " . @$response_createClientSession->psp_ResponseExtended, true);             
      }
      
      return wp_send_json( array("clientSession"=>$response_createClientSession->psp_ClientSession) );
    }
    
    public static function get_card_installments() {
      $installments = array();
      $payment_gateways = WC()->payment_gateways->payment_gateways();
      $gateway = $payment_gateways['nps'];
      
      if ( is_null( $gateway ) ) {
        return new WP_Error( 'woocommerce_rest_payment_gateway_invalid', __( 'Resource does not exist.', 'woocommerce' ), array( 'status' => 404 ) );
      }
      
      if(@$_POST['wc-nps-payment-token'] && $_POST['wc-nps-payment-token'] != 'new') {
	// Use an existing token, and then process the payment
        $token_id = wc_clean( @$_POST['wc-nps-payment-token'] );
        $token = false;
        $tokens = $gateway->get_tokens();
        // $tokens = $this->woocommerce_get_customer_payment_tokens( $tokens, $customer_id=get_current_user_id(), $gatyeway_id=$gateway->id );

        foreach($tokens as $t) {
          if($t->get_token() == wc_clean( @$_REQUEST['wc-nps-payment-token'] )) {
            $token = $t;
            break;
          }
        }      
        
        $brand=$token->get_card_type();
      }else if(@$_REQUEST['nps-card-brand']){
          $brand = @$_REQUEST['nps-card-brand'];
      }else {
          $brand=NULL;
      }
      
      if($brand) { 
        $wc_currency  = wc_clean( strtoupper( get_woocommerce_currency() ) );
        $wc_country   = wc_clean( strtoupper( wc_get_base_location()['country'] ) );
        $installments = $gateway->searchInstallments($brand, $country=$gateway::format_country($wc_country), $currency=$gateway::format_currency($wc_currency));
      }
      
      return wp_send_json( $installments );
    }
    
		/**
		 * Allow this class and other classes to add slug keyed notices (to avoid duplication)
		 */
		public function add_admin_notice( $slug, $class, $message ) {
			$this->notices[ $slug ] = array(
				'class'   => $class,
				'message' => $message,
			);
		}

    protected function get_nps_mode() {
      $options = get_option( 'woocommerce_nps_settings', array() );
      return @$options['nps_mode'];        
    }                            
    
    protected static function get_nps_logging() {
      $options = get_option( 'woocommerce_nps_settings', array() );
      return 'yes' === @$options['logging'];           
    }
                
    protected function get_wallet_enable() {
      $options = get_option( 'woocommerce_nps_settings', array() );
      return @$options['wallet_enable'];        
    }            
                
    protected static function get_merchant_id() {
      $options = get_option( 'woocommerce_nps_settings', array() );
      return @$options['merchant_id'];
    }    
    
    protected function get_url() {
      $options = get_option( 'woocommerce_nps_settings', array() );
      return @$options['url'];
    }        
    
    protected function get_secret_key() {
      $options = get_option( 'woocommerce_nps_settings', array() );
      return @$options['secret_key'];
    }            

		/**
		 * The backup sanity check, in case the plugin is activated in a weird way,
		 * or the environment changes after activation. Also handles upgrade routines.
		 */
		public function check_environment() {
			if ( ! defined( 'IFRAME_REQUEST' ) && ( WC_NPS_VERSION !== get_option( 'wc_nps_version' ) ) ) {
				$this->install();

				do_action( 'woocommerce_nps_updated' );
			}

			$environment_warning = self::get_environment_warning();

			if ( $environment_warning && is_plugin_active( plugin_basename( __FILE__ ) ) ) {
				$this->add_admin_notice( 'bad_environment', 'error', $environment_warning );
			}

			// Check if secret key present. Otherwise prompt, via notice, to go to
			// setting.
			$secret = $this->get_secret_key();

			if ( empty( $secret ) && ! ( isset( $_GET['page'], $_GET['section'] ) && 'wc-settings' === $_GET['page'] && 'nps' === $_GET['section'] ) ) {
				$setting_link = $this->get_setting_link();
				$this->add_admin_notice( 'prompt_connect', 'notice notice-warning', sprintf( __( 'Nps is almost ready. To get started, <a href="%s">set your Nps account keys</a>.', 'woocommerce-gateway-nps' ), $setting_link ) );
			}
		}

		/**
		 * Updates the plugin version in db
		 *
		 * @since 3.1.0
		 * @version 3.1.0
		 * @return bool
		 */
		private static function _update_plugin_version() {
			delete_option( 'wc_nps_version' );
			update_option( 'wc_nps_version', WC_NPS_VERSION );

			return true;
		}

		/**
		 * Dismiss the Google Payment Request API Feature notice.
		 *
		 * @since 3.1.0
		 * @version 3.1.0
		 */
		public function dismiss_request_api_notice() {
			update_option( 'wc_nps_show_request_api_notice', 'no' );
		}


		/**
		 * Handles upgrade routines.
		 *
		 * @since 3.1.0
		 * @version 3.1.0
		 */
		public function install() {
			if ( ! defined( 'WC_NPS_INSTALLING' ) ) {
				define( 'WC_NPS_INSTALLING', true );
			}

			$this->_update_plugin_version();
		}

		/**
		 * Checks the environment for compatibility problems.  Returns a string with the first incompatibility
		 * found or false if the environment has no problems.
		 */
		static function get_environment_warning() {
			if ( version_compare( phpversion(), WC_NPS_MIN_PHP_VER, '<' ) ) {
				$message = __( 'WooCommerce Nps - The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'woocommerce-gateway-nps' );

				return sprintf( $message, WC_NPS_MIN_PHP_VER, phpversion() );
			}

			if ( ! defined( 'WC_VERSION' ) ) {
				return __( 'WooCommerce Nps requires WooCommerce to be activated to work.', 'woocommerce-gateway-nps' );
			}

			if ( version_compare( WC_VERSION, WC_NPS_MIN_WC_VER, '<' ) ) {
				$message = __( 'WooCommerce Nps - The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.', 'woocommerce-gateway-nps' );

				return sprintf( $message, WC_NPS_MIN_WC_VER, WC_VERSION );
			}

			if ( ! function_exists( 'curl_init' ) ) {
				return __( 'WooCommerce Nps - cURL is not installed.', 'woocommerce-gateway-nps' );
			}

			return false;
		}

		/**
		 * Adds plugin action links
		 *
		 * @since 1.0.0
		 */
		public function plugin_action_links( $links ) {
			$setting_link = $this->get_setting_link();

			$plugin_links = array(
				'<a href="' . $setting_link . '">' . __( 'Settings', 'woocommerce-gateway-nps' ) . '</a>',
			);
			return array_merge( $plugin_links, $links );
		}

		/**
		 * Get setting link.
		 *
		 * @since 1.0.0
		 *
		 * @return string Setting link
		 */
		public function get_setting_link() {
			$use_id_as_section = function_exists( 'WC' ) ? version_compare( WC()->version, '2.6', '>=' ) : false;

			$section_slug = $use_id_as_section ? 'nps' : strtolower( 'WC_Gateway_Nps' );

			return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $section_slug );
		}

		/**
		 * Display any notices we've collected thus far (e.g. for connection, disconnection)
		 */
		public function admin_notices() {
			$show_request_api_notice = get_option( 'wc_nps_show_request_api_notice' );

			if ( empty( $show_request_api_notice ) ) {
				// @TODO remove this notice in the future.
				?>
				

				<?php
			}
			
			foreach ( (array) $this->notices as $notice_key => $notice ) {
				echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
				echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
				echo '</p></div>';
			}
		}

		/**
		 * Initialize the gateway. Called very early - in the context of the plugins_loaded action
		 *
		 * @since 1.0.0
		 */
		public function init_gateways() {
			if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
				$this->subscription_support_enabled = true;
			}

			if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
				$this->pre_order_enabled = true;
			}

			if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
				return;
			}

			if ( class_exists( 'WC_Payment_Gateway_CC' ) ) {
				include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-nps.php' );
                                include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-nps-wallets.php' );
			} else {
				include_once( dirname( __FILE__ ) . '/includes/legacy/class-wc-gateway-nps.php' );
				include_once( dirname( __FILE__ ) . '/includes/legacy/class-wc-gateway-nps-saved-cards.php' );
                                include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-nps-wallets.php' );
			}

			load_plugin_textdomain( 'woocommerce-gateway-nps', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
		}

		/**
		 * Add the gateways to WooCommerce
		 *
		 * @since 1.0.0
		 */
		public function add_gateways( $methods ) {
  		    $methods[] = 'WC_Gateway_Nps';
                    if($this->get_wallet_enable() == 'yes' && $this->get_nps_mode() != 'simple_checkout' && !is_add_payment_method_page()) {
                        $methods[] = 'WC_Gateway_Nps_Wallets';
                    }
		    return $methods;
		}

		/**
		 * List of currencies supported by Nps that has no decimals.
		 *
		 * @return array $currencies
		 */
		public static function no_decimal_currencies() {
			return array(
				'bif', // Burundian Franc
				'djf', // Djiboutian Franc
				'jpy', // Japanese Yen
				'krw', // South Korean Won
				'pyg', // Paraguayan Guaraní
				'vnd', // Vietnamese Đồng
				'xaf', // Central African Cfa Franc
				'xpf', // Cfp Franc
				'clp', // Chilean Peso
				'gnf', // Guinean Franc
				'kmf', // Comorian Franc
				'mga', // Malagasy Ariary
				'rwf', // Rwandan Franc
				'vuv', // Vanuatu Vatu
				'xof', // West African Cfa Franc
			);
		}

		/**
		 * Nps uses smallest denomination in currencies such as cents.
		 * We need to format the returned currency from Nps into human readable form.
		 *
		 * @param object $balance_transaction
		 * @param string $type Type of number to format
		 */
		public static function format_number( $balance_transaction, $type = 'fee' ) {
			if ( ! is_object( $balance_transaction ) ) {
				return;
			}

			if ( in_array( strtolower( $balance_transaction->currency ), self::no_decimal_currencies() ) ) {
				if ( 'fee' === $type ) {
					return $balance_transaction->fee;
				}

				return $balance_transaction->net;
			}

			if ( 'fee' === $type ) {
				return number_format( $balance_transaction->fee / 100, 2, '.', '' );
			}

			return number_format( $balance_transaction->net / 100, 2, '.', '' ); 
		}

		/**
		 * Capture payment when the order is changed from on-hold to complete or processing
		 *
		 * @param  int $order_id
		 */
		public function capture_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( 'nps' === ( version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->payment_method : $order->get_payment_method() ) ) {
				$charge   = get_post_meta( $order_id, '_nps_charge_id', true );
				$captured = get_post_meta( $order_id, '_nps_charge_captured', true );

				if ( $charge && 'no' === $captured ) {
          
          Configuration::environment(Constants::CUSTOM_ENV);
          Configuration::verifyPeer(false);
          Configuration::customUrl($this->get_url());
          Configuration::secretKey($this->get_secret_key());        
          // Configuration::logger(WC_Nps::get_instance());

          WC_Nps::log( "Info: Beginning capture for order $order_id for the amount of {$order->get_total()}" );
          
          $sdk = new Sdk();          
          $request = array(
            'psp_Version'            => '2.2',
            'psp_MerchantId'         => self::get_merchant_id(),
            'psp_TxSource'           => 'WEB',
            'psp_MerchTxRef'         => strtoupper(uniqid($order_id.".", true)),
            'psp_TransactionId_Orig' => $charge,
            'psp_AmountToCapture'    => $order->get_total() * 100,  
            'psp_PosDateTime'        => date('Y-m-d H:i:s'),
            'psp_UserId'             => get_current_user_id(),              
          );
          WC_Nps::log( 'Processing capture request: ' . print_r( $request, true ), WC_Nps::get_nps_logging() );
          $response = $sdk->capture($request);
          WC_Nps::log( 'Processing capture response: ' . print_r( $response, true ), WC_Nps::get_nps_logging() );
          
					if ( @$response->psp_ResponseCod != "0" ) {
                                            $capture_message = sprintf( __( 'Unable to capture charge! - Capture ID: %1$s - psp_MerchTxRef: %2$s  - Reason: %3$s', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId, @$response->psp_MerchTxRef, @$response->psp_ResponseExtended);
                                            $order->add_order_note( $capture_message );      
                                            WC_Nps::log( sprintf( __( 'Error: Transaction ID: %d - Reason: %s', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId, @$response->psp_ResponseExtended) );
					} else {
            $capture_message = sprintf( __( 'Captured %1$s - Capture ID: %2$s - Reason: %3$s', 'woocommerce-gateway-nps' ), wc_price( @$response->psp_CapturedAmount / 100 ), @$response->psp_TransactionId, @$response->psp_ResponseExtended );
            WC_Nps::log( 'Success: ' . html_entity_decode( strip_tags( $capture_message ) ) );
						$order->add_order_note( sprintf( __( 'Nps charge complete (Charge ID: %s)', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId ) );
						update_post_meta( $order_id, '_nps_charge_captured', 'yes' );
            update_post_meta( $order_id, '_transaction_id', @$response->psp_TransactionId );
            
						// Store other data such as fees
						update_post_meta( $order_id, 'Nps Payment ID', @$response->psp_TransactionId );

						/* if ( isset( $result->balance_transaction ) && isset( $result->balance_transaction->fee ) ) {
							// Fees and Net needs to both come from Nps to be accurate as the returned
							// values are in the local currency of the Nps account, not from WC.
							$fee = ! empty( $result->balance_transaction->fee ) ? self::format_number( $result->balance_transaction, 'fee' ) : 0;
							$net = ! empty( $result->balance_transaction->net ) ? self::format_number( $result->balance_transaction, 'net' ) : 0;
							update_post_meta( $order_id, 'Nps Fee', $fee );
							update_post_meta( $order_id, 'Net Revenue From Nps', $net );
						} */
					}
				}
			}
		}

		/**
		 * Cancel pre-auth on refund/cancellation
		 *
		 * @param  int $order_id
		 */
		public function cancel_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( 'nps' === ( version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->payment_method : $order->get_payment_method() ) ) {
				$charge   = get_post_meta( $order_id, '_nps_charge_id', true );

				if ( $charge ) {
          $amount = $order->get_total() * 100;
          
          Configuration::environment(Constants::CUSTOM_ENV);
          Configuration::verifyPeer(false);
          Configuration::customUrl($this->get_url());
          Configuration::secretKey($this->get_secret_key());          
          // Configuration::logger(WC_Nps::get_instance());
          
          WC_Nps::log( "Info: Beginning refund for order $order_id for the amount of {$amount}");
          
          $sdk = new Sdk();          
          $request = array(
            'psp_Version'            => '2.2',
            'psp_MerchantId'         => self::get_merchant_id(),
            'psp_TxSource'           => 'WEB',
            'psp_MerchTxRef'         => strtoupper(uniqid($order_id.".", true)),
            'psp_TransactionId_Orig' => $charge,
            'psp_AmountToRefund'    => $amount,  
            'psp_PosDateTime'        => date('Y-m-d H:i:s'),
            'psp_UserId'             => get_current_user_id(),              
          );
          WC_Nps::log( 'Processing refund request: ' . print_r( $request, true ), WC_Nps::get_nps_logging());
          $response = $sdk->refund($request);          
          WC_Nps::log( 'Processing refund response: ' . print_r( $response, true ), WC_Nps::get_nps_logging());

					if ( @$response->psp_ResponseCod != '0' ) {
                                                $refund_message = sprintf( __( 'Unable to refund charge! - Refund ID: %1$s - psp_MerchTxRef: %2$s  - Reason: %3$s', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId, @$response->psp_MerchTxRef, @$response->psp_ResponseExtended);
                                                $order->add_order_note( $refund_message );                                                
                                                WC_Nps::log( sprintf( __( 'Error: Transaction ID: %d - Reason: %s', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId, @$response->psp_ResponseExtended) );
					} elseif ( ! empty( $response->psp_TransactionId ) ) {
            $refund_message = sprintf( __( 'Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-gateway-nps' ), wc_price( @$response->psp_RefundedAmount / 100 ), @$response->psp_TransactionId, @$response->psp_ResponseExtended );
						$order->add_order_note( $refund_message );
            WC_Nps::log( 'Success: ' . html_entity_decode( strip_tags( $refund_message ) ) );
						delete_post_meta( $order_id, '_nps_charge_captured' );
						delete_post_meta( $order_id, '_nps_charge_id' );
					}
				}
			}
		}

		/**
		 * Gets saved tokens from API if they don't already exist in WooCommerce.
		 * @param array $tokens
		 * @return array
		 */
		public function woocommerce_get_customer_payment_tokens( $tokens, $customer_id, $gateway_id ) {
			if ( is_user_logged_in() && 'nps' === $gateway_id && class_exists( 'WC_Payment_Token_CC' ) ) {
				$nps_customer = new WC_Nps_Customer( $customer_id, $this->get_url(), $this->get_secret_key(), self::get_merchant_id() );
				$nps_cards    = $nps_customer->get_cards();
				$stored_tokens   = array();
        $tokens = array();

				/* foreach ( $tokens as $token ) {
					$stored_tokens[] = $token->get_token();
				} */

				foreach ( $nps_cards as $card ) {
					// if ( ! in_array( $card->PaymentMethodId, $stored_tokens ) ) {
						$token = new WC_Payment_Token_CC();
						$token->set_token( $card->PaymentMethodId );
						$token->set_gateway_id( 'nps' );
						$token->set_card_type( strtolower( $card->Product ) );
						$token->set_last4( $card->CardOutputDetails->Last4 );
						$token->set_expiry_month( $card->CardOutputDetails->ExpirationMonth );
						$token->set_expiry_year( $card->CardOutputDetails->ExpirationYear );
						$token->set_user_id( $customer_id );
						$token->save();
						$tokens[ $token->get_id() ] = $token;
					// }
				}
			}
			return $tokens;
		}

		/**
		 * Delete token from Nps
		 */
		public function woocommerce_payment_token_deleted( $token_id, $token ) {
			if ( 'nps' === $token->get_gateway_id() ) {
				$nps_customer = new WC_Nps_Customer( get_current_user_id(), $this->get_url(), $this->get_secret_key(), self::get_merchant_id() );
				$nps_customer->delete_card( $token->get_token() );
			}
		}

		/**
		 * Set as default in Nps
		 */
		public function woocommerce_payment_token_set_default( $token_id ) {
			$token = WC_Payment_Tokens::get( $token_id );
			if ( 'nps' === $token->get_gateway_id() ) {
				$nps_customer = new WC_Nps_Customer( get_current_user_id(), $this->get_url(), $this->get_secret_key(), self::get_merchant_id() );
				$nps_customer->set_default_card( $token->get_token() );
			}
		}

		/**
		 * Checks Nps minimum order value authorized per currency
		 */
		public static function get_minimum_amount() {
			// Check order amount
			switch ( get_woocommerce_currency() ) {
				case 'USD':
				case 'CAD':
				case 'EUR':
				case 'CHF':
				case 'AUD':
				case 'SGD':
					$minimum_amount = 50;
					break;
				case 'GBP':
					$minimum_amount = 30;
					break;
				case 'DKK':
					$minimum_amount = 250;
					break;
				case 'NOK':
				case 'SEK':
					$minimum_amount = 300;
					break;
				case 'JPY':
					$minimum_amount = 5000;
					break;
				case 'MXN':
					$minimum_amount = 1000;
					break;
				case 'HKD':
					$minimum_amount = 400;
					break;
				default:
					$minimum_amount = 50;
					break;
			}

			return $minimum_amount;
		}

		/**
		 * What rolls down stairs
		 * alone or in pairs,
		 * and over your neighbor's dog?
		 * What's great for a snack,
		 * And fits on your back?
		 * It's log, log, log
		 */
		public static function log( $message, $logging=true ) {
      $message = self::obfuscateSenseData( $message );
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
      if($logging) {
			  self::$log->add( 'woocommerce-gateway-nps', $message );
      }
		}
                
    public function info($message=NULL) {
      self::log($message);
    }
            
    public function debug($message=NULL) {
      self::log($message);
    }


                public static function obfuscateSenseData($string)
                {
                  $string = preg_replace_callback('/\[psp_CardNumber] =>(.*?)\n/', 'self::logger_mask_card_number', $string);
                  $string = preg_replace_callback('/\[Number] =>(.*?)\n/', 'self::logger_mask_card_number', $string);
                  $string = preg_replace_callback('/\[psp_CardExpDate] =>(.*?)\n/', 'self::logger_mask_exp_date', $string);
                  $string = preg_replace_callback('/\[ExpirationDate] =>(.*?)\n/', 'self::logger_mask_exp_date', $string);
                  $string = preg_replace_callback('/\[ExpirationYear] =>(.*?)\n/', 'self::logger_mask_exp_date', $string);
                  $string = preg_replace_callback('/\[ExpirationMonth] =>(.*?)\n/', 'self::logger_mask_exp_date', $string);
                  $string = preg_replace_callback('/\[psp_CardSecurityCode] =>(.*?)\n/', 'self::logger_mask_security_code', $string);
                  $string = preg_replace_callback('/\[SecurityCode] =>(.*?)\n/', 'self::logger_mask_security_code', $string);
                  return $string;
                }
                
                public static function logger_mask_exp_date($matches)
                {
                  $card_data = explode('=>', $matches[0]);
                  $card_exp_date = trim(@$card_data[1]);
                  return $card_data[0].'=>'.self::psp_mask_exp_date($card_exp_date)."\n";
                }                
                
                public static function logger_mask_security_code($matches)
                {
                  $card_data = explode('=>', $matches[0]);
                  $card_exp_date = trim(@$card_data[1]);
                  return $card_data[0].'=>'.self::psp_mask_exp_date($card_exp_date)."\n";
                }                                

                public static function logger_mask_card_number($matches)
                {
                  $card_data = explode('=>', $matches[0]);
                  $card_number = trim(@$card_data[1]);
                  return $card_data[0].'=>'.self::psp_mask_card_number($card_number)."\n";
                }

                public static function psp_mask_exp_date($exp_date)
                {
                  return str_repeat('x',strlen($exp_date));
                }                
                
                public static function psp_mask_security_code($security_code)
                {
                  return str_repeat('x',strlen($security_code));
                }                

                public static function psp_is_valid_card_length($card_number)
                {
                  return strlen($card_number) > 10 ? true : false;
                }

                public static function psp_get_card_bin($card_number)
                {
                  $bin = null;
                  // check if is valid card
                  if (self::psp_is_valid_card_length($card_number))
                  {
                    $bin = substr($card_number, 0, 6);
                  }

                  return $bin;
                }

                public static function psp_get_card_lfd($card_number)
                {
                  $lfd = null;
                  // check if is valid card
                  if (self::psp_is_valid_card_length($card_number))
                  {
                    $lfd = substr($card_number, -4);
                  }

                  return $lfd;
                }

                public static function psp_mask_card_number($card_number, $maskWithChar='x')
                {
                  // check if is necesary to mask
                  if (self::psp_is_valid_card_length($card_number))
                  {
                    $bin = self::psp_get_card_bin($card_number);
                    $lfd = self::psp_get_card_lfd($card_number);
                    $pan = $bin . str_repeat($maskWithChar,strlen($card_number)-10) . $lfd;
                  }
                  else
                  {
                    $pan = $card_number;
                  }

                  return substr($pan, 0, 32);
                }                

                /* public static function _mask_token_cvc($data){
                  $cvc_key = "</SecurityCode>";
                  $cvcs = self::_find_token_cvc($data);
                  foreach ($cvcs as $cvc){
                    $repeat = strlen($cvc) - strlen($cvc_key);
                    $data = str_replace($cvcs, str_repeat("*", $repeat). $cvc_key, $data);
                  }
                  return $data;
                }

              public static function _find_token_cvc($data){
                $var = '';
                $cvcs = preg_match_all('/\d{3,4}<\/SecurityCode>/', $data, $var);
                return $var[0];
              }

                public static function _mask_cvc($data){
                    $cvc_key = "[psp_CardSecurityCode]";
                    $cvcs = self::_find_cvc($data);
                    foreach ($cvcs as $cvc){
                        $repeat = strlen($cvc) - strlen($cvc_key);
                        $data = str_replace($cvcs, str_repeat("*", $repeat). $cvc_key, $data);
                    }
                    return $data;
                }

                public static function _find_cvc($data){
                    $var = '';
                    $exp_dates = preg_match("/\[psp_CardSecurityCode] =>(.*?)\n/", $data, $var);
                    return $var[0];
                }


              public static function _mask_token_exp_date($data){
                $exp_date_key = "</ExpirationDate>";
                $exp_dates = self::_find_token_exp_date($data);
                foreach ($exp_dates as $exp_date){
                  $data = str_replace($exp_date, "****" . $exp_date_key, $data);
                }
                return $data;
              }


              public static function _find_token_exp_date($data){
                $var = '';
                $exp_dates = preg_match_all('/\d{4}<\/ExpirationDate>/', $data, $var);
                return $var[0];
              }

                public static function _mask_exp_date($data){
                    $exp_date_key = "[psp_CardExpDate]";
                    $exp_dates = self::_find_exp_date($data);
                    foreach ($exp_dates as $exp_date){
                        $data = str_replace($exp_date, "****" . $exp_date_key, $data);
                    }
                    return $data;
                }


                public static function _find_exp_date($data){
                    $var = '';
                    $exp_dates = preg_match("/\[psp_CardExpDate] =>(.*?)\n/", $data, $var);
                    return $var[0];
                }

                public static function _mask_c_number($data){
                    $c_number_key = "[psp_CardNumber]";

                    $c_numbers = self::_find_c_numbers($data);
                    foreach ($c_numbers as $c_number){
                        $c_number_len = strlen(substr($c_number,0, strlen($c_number) - strlen($c_number_key)));
                        $masked_chars = $c_number_len - 10;
                        $replacer = substr($c_number, 0, 6) . str_repeat("*", $masked_chars) . substr($c_number, strlen($c_number) - 4 - strlen($c_number_key), strlen($c_number));
                        $data = str_replace($c_number, $replacer, $data);
                    }
                    return $data;
                }

                public static function _find_c_numbers($data){
                    $var = '';
                    $c_numbers = preg_match("/\[psp_CardNumber] =>(.*?)\n/", $data, $var);
                    return $var[0];
                }

              public static function _mask_token_c_number($data){
                $c_number_key = "</Number>";

                $c_numbers = self::_find_token_c_numbers($data);
                foreach ($c_numbers as $c_number){
                  $c_number_len = strlen(substr($c_number,0, strlen($c_number) - strlen($c_number_key)));
                  $masked_chars = $c_number_len - 10;
                  $replacer = substr($c_number, 0, 6) . str_repeat("*", $masked_chars) . substr($c_number, strlen($c_number) - 4 - strlen($c_number_key), strlen($c_number));
                  $data = str_replace($c_number, $replacer, $data);
                }
                return $data;
              }

              public static function _find_token_c_numbers($data){
                $var = '';
                $c_numbers = preg_match_all('/\d{13,19}<\/Number>/', $data, $var);
                return $var[0];
              }                 */
	}

	$GLOBALS['wc_nps'] = WC_Nps::get_instance();

endif;
