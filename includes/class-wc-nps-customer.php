<?php

if (!defined('ABSPATH')) {
    exit;
}

include_once('nps-sdk-php-master/init.php');


use NpsSDK\Configuration;
use NpsSDK\Constants;
use NpsSDK\Sdk;
use NpsSDK\ApiException;


/**
 * WC_Nps_Customer class.
 *
 * Represents a Nps Customer.
 */
class WC_Nps_Customer
{

    /**
     * Nps customer ID
     * @var string
     */
    private $id = '';

    /**
     * WP User ID
     * @var integer
     */
    private $user_id = 0;

    /**
     * Data from API
     * @var array
     */
    private $customer_data = array();

    /**
     * Constructor
     * @param  integer  $user_id
     */
    public function __construct($user_id = 0, $url, $secret_key, $merchant_id)
    {
        if ($user_id) {
            Configuration::environment(Constants::CUSTOM_ENV);
            Configuration::verifyPeer(false);
            Configuration::customUrl($url);
            Configuration::secretKey($secret_key);

            $this->merchant_id = $merchant_id;
            $this->set_user_id($user_id);
            $this->set_id(get_user_meta($user_id, '_nps_customer_id', true));
        }
    }

    protected static function get_nps_logging()
    {
        $options = get_option('woocommerce_nps_settings', array());
        return 'yes' === @$options['logging'];
    }

    /**
     * Logs
     *
     * @param  string  $message
     * @version 3.1.0
     *
     * @since 3.1.0
     */
    public function log($message, $logging = true)
    {
        // if ( $this->logging ) {
        if ($logging) {
            WC_Nps::log($message);
        }
        // }
    }

    /**
     * Get Nps customer ID.
     * @return string
     */
    public function get_id()
    {
        return $this->id;
    }

    /**
     * Set Nps customer ID.
     * @param [type] $id [description]
     */
    public function set_id($id)
    {
        $this->id = wc_clean($id);
    }

    /**
     * User ID in WordPress.
     * @return int
     */
    public function get_user_id()
    {
        return absint($this->user_id);
    }

    /**
     * Set User ID used by WordPress.
     * @param  int  $user_id
     */
    public function set_user_id($user_id)
    {
        $this->user_id = absint($user_id);
    }

    /**
     * Get user object.
     * @return WP_User
     */
    protected function get_user()
    {
        return $this->get_user_id() ? get_user_by('id', $this->get_user_id()) : false;
    }

    /**
     * Store data from the Nps API about this customer
     */
    public function set_customer_data($data)
    {
        if (!is_wp_error($data) && $data instanceof stdClass) {
            $this->customer_data = $data;
            if ($this->get_id()) {
                set_transient('nps_customer_'.$this->get_id(), $data, HOUR_IN_SECONDS * 48);
                if (is_array(@$data->psp_PaymentMethods) && count(@$data->psp_PaymentMethods)) {
                    $unrepited_cards = array();
                    foreach (@$data->psp_PaymentMethods as $paymentMethod) {
                        $unrepited_cards[$paymentMethod->FingerPrint] = $paymentMethod;
                    }
                    set_transient('nps_cards_'.$this->get_id(), $unrepited_cards, HOUR_IN_SECONDS * 48);
                }
            }
        }
    }

    /**
     * Get data from the Nps API about this customer
     */
    public function get_customer_data()
    {
        // $this->log("ENTRY get_customer_data ====> this->customer_data[" . var_export(@$this->customer_data,true) . "] this->get_id[" . var_export($this->get_id(),true) . "] get_transient[" . var_export(get_transient( 'nps_customer_' . $this->get_id()), true)  . "]");

        if (empty($this->customer_data) && $this->get_id() && false === ($this->customer_data = get_transient('nps_customer_'.$this->get_id()))) {
            $sdk = new Sdk();
            $request = array(
                'psp_Version' => '2.2',
                'psp_MerchantId' => $this->merchant_id,
                'psp_CustomerId' => $this->get_id(),
                'psp_PosDateTime' => date('Y-m-d H:i:s'),
            );
            $this->log('Processing retrieveCustomer request: '.print_r($request, true), WC_Nps_Customer::get_nps_logging());
            $response_retrieveCustomer = $sdk->retrieveCustomer($request);
            $this->log('Processing retrieveCustomer response: '.print_r($response_retrieveCustomer, true), WC_Nps_Customer::get_nps_logging());
            $this->set_customer_data($response_retrieveCustomer);
        } else {
            if ($this->customer_data && false === ($cards = get_transient('nps_cards_'.$this->get_id()))) {
                $this->set_customer_data($this->customer_data);
            }
        }
        return $this->customer_data;
    }

    /**
     * Get default card/source
     * @return string
     */
    public function get_default_card()
    {
        $data = $this->get_customer_data();
        $source = '';

        if ($data) {
            $source = $data->default_source;
        }

        return $source;
    }

    /**
     * Create a customer via API.
     * @param  array  $args
     * @return WP_Error|int
     */
    public function create_customer($args = array())
    {
        if (is_user_logged_in() && ($user = $this->get_user())) {
            $billing_first_name = get_user_meta($user->ID, 'billing_first_name', true);
            $billing_last_name = get_user_meta($user->ID, 'billing_last_name', true);
            $billing_phone = get_user_meta($user->ID, 'billing_phone', true);
            $billing_email = get_user_meta($user->ID, 'billing_email', true);

            $billing_address_1 = get_user_meta($user->ID, 'billing_address_1', true);
            $billing_address_2 = get_user_meta($user->ID, 'billing_address_2', true);
            $billing_city = get_user_meta($user->ID, 'billing_city', true);
            $billing_postcode = get_user_meta($user->ID, 'billing_postcode', true);
            $billing_country = get_user_meta($user->ID, 'billing_country', true);
            $billing_state = get_user_meta($user->ID, 'billing_state', true);
            $billing_company = get_user_meta($user->ID, 'billing_company', true);


            $defaults = array(
                'email' => $user->user_email,
                'description' => $billing_first_name.' '.$billing_last_name,
            );
        } else {
            $defaults = array(
                'email' => '',
                'description' => '',
            );
        }

        $metadata = array();

        $defaults['metadata'] = apply_filters('wc_nps_customer_metadata', $metadata, $user);

        $args = wp_parse_args($args, $defaults);

        /* if ( $user->ID ) {
            $user_email = get_user_meta( $user->ID, 'billing_email', true );
            $user_email = $user_email ? $user_email : $user->user_email;
        } else {
            $user_email = '';
        } */


        $requestCreateCustomerParameters = array(
            'psp_Version' => '2.2',
            'psp_MerchantId' => $this->merchant_id,
            'psp_EmailAddress' => $user->user_email,
            'psp_AccountID' => $user->ID,
            'psp_AccountCreatedAt' => date("Y-m-d", strtotime($user->user_registered)),
            'psp_Person' => array(
                'FirstName' => $billing_first_name,
                'LastName' => $billing_last_name,
                // 'PhoneNumber1'=>$billing_phone,
                // 'Nationality'=>$billing_country,
            ),
            'psp_Address' => array(
                'Street' => mb_substr($billing_address_1, 0, 128),
                'HouseNumber' => mb_substr($billing_address_1, 0, 32),
                'AdditionalInfo' => mb_substr($billing_address_2, 0, 128),
                'City' => mb_substr($billing_city, 0, 40),
                'StateProvince' => mb_substr($billing_state, 0, 40),
                'Country' => $billing_country ? WC_Gateway_Nps::format_country($billing_country) : null,
                'ZipCode' => mb_substr($billing_postcode, 0, 10),
            ),
            'psp_PosDateTime' => date('Y-m-d H:i:s'),
        );

        $this->log("Info: Beginning createCustomer for user {$user->ID}", true);

        $sdk = new Sdk();
        $request = WC_Gateway_Nps::cleanArray($requestCreateCustomerParameters);
        $this->log('Processing createCustomer request: '.print_r($request, true), WC_Nps_Customer::get_nps_logging());
        $response_createCustomer = $sdk->createCustomer($request);
        $this->log('Processing createCustomer response: '.print_r($response_createCustomer, true), WC_Nps_Customer::get_nps_logging());

        if (is_wp_error($response_createCustomer)) {
            $this->log("Error: User {$user->ID} - Reason: ".@$response_createCustomer->psp_ResponseExtended, true);
            return $response_createCustomer;
        } elseif (empty($response_createCustomer->psp_CustomerId)) {
            return new WP_Error('nps_error', __('Could not create Nps customer.', 'woocommerce-gateway-nps'));
        }

        $this->log(
            "Success: Customer created - Customer ID: {$response_createCustomer->psp_CustomerId} - Reason: {$response_createCustomer->psp_ResponseMsg}",
            true
        );

        $this->set_id($response_createCustomer->psp_CustomerId);
        // $this->clear_cache();
        $this->set_customer_data($response_createCustomer);

        if ($this->get_user_id()) {
            update_user_meta($this->get_user_id(), '_nps_customer_id', $response_createCustomer->psp_CustomerId);
        }

        do_action('woocommerce_nps_add_customer', $args, $response_createCustomer);

        return $response_createCustomer->psp_CustomerId;
    }

    /**
     * Add a card for this nps customer.
     * @param  string  $token
     * @param  bool  $retry
     * @return WP_Error|int
     */
    public function add_card($token, $retry = true)
    {
        if (!$token) {
            return new WP_Error('error', __('There was a problem adding the card.', 'woocommerce-gateway-stripe'));
        }

        if (!$this->get_id()) {
            if (($response_createCustomer = $this->create_customer()) && is_wp_error($response_createCustomer)) {
                return $response_createCustomer;
            }
        }

        $request = array(
            'psp_Version' => '2.2',
            'psp_MerchantId' => $this->merchant_id,
            /*'Person'=>array(
                'FirstName'=>'Fernando',
                'LastName'=>'Bonifacio',
                // 'MiddleName'=>'',
                'PhoneNumber1'=>'3',
                'PhoneNumber2'=>'3',
                'Gender'=>'M',
                'DateOfBirth'=> '1987-01-01',
                'Nationality'=>'ARG',
                'IDNumber'=>'32123123',
                'IDType'=>'100',
            ),*/
            /*'Address'=>array(
                'Street'=>'pepe st',
                'HouseNumber'=>'99',
                // 'AdditionalInfo'=>'Nº 735 Piso 5',
                'City'=>'capìtal federal',
                // 'StateProvince'=>'capìtal federal',
                'Country'=>'PER',
                //'ZipCode'=>'1414',
            ),*/
            'psp_CustomerId' => $this->get_id(),
            'psp_SetAsCustomerDefault' => 0,
            'psp_PosDateTime' => date('Y-m-d H:i:s'),
            'psp_PaymentMethod' => array('PaymentMethodToken' => $token),
        );
        $this->log("Info: Beginning createPaymentMethod for merchant {$this->merchant_id}", true);
        $this->log('Processing createPaymentMethod request: '.print_r($request, true), WC_Nps_Customer::get_nps_logging());
        $sdk = new Sdk();
        $response_createPaymentMethod = $sdk->createPaymentMethod($request);
        $this->log('Processing createPaymentMethod response: '.print_r($response_createPaymentMethod, true), WC_Nps_Customer::get_nps_logging());

        if (is_wp_error($response_createPaymentMethod)) {
            $this->log(
                "Error: On createPaymentMethod for merchant {$this->merchant_id} - Reason: ".@$response_createPaymentMethod->psp_ResponseExtended,
                true
            );
            // It is possible the WC user once was linked to a customer on Nps
            // but no longer exists. Instead of failing, lets try to create a
            // new customer.
            /* if ( preg_match( '/No such customer:/', $response->get_error_message() ) ) {
                delete_user_meta( $this->get_user_id(), '_nps_customer_id' );
                $this->create_customer();
                return $this->add_card( $token, false );
            } elseif ( 'customer' === $response->get_error_code() && $retry ) {
                $this->create_customer();
                return $this->add_card( $token, false );
            } else {
                return $response;
            } */
            if ($retry) {
                // return new WP_Error( 'error', __( 'me esta pidiendo retry', 'woocommerce-gateway-nps' ) );

                // $this->create_customer();
                // return $this->add_card( $token, false );
            } else {
                return $response_createCustomer;
            }
        } elseif (empty($response_createPaymentMethod->psp_PaymentMethod->PaymentMethodId)) {
            $this->log(
                "Error: On createPaymentMethod for merchant {$this->merchant_id} - Reason: ".@$response_createPaymentMethod->psp_ResponseExtended,
                true
            );
            return new WP_Error('error', __('Unable to add card', 'woocommerce-gateway-nps'));
        }

        // Add token to WooCommerce
        if ($this->get_user_id() && class_exists('WC_Payment_Token_CC')) {
            $this->log(
                "Success: Payment method created - PaymentMethod ID: {$response_createPaymentMethod->psp_PaymentMethod->PaymentMethodId} - Reason: {$response_createPaymentMethod->psp_ResponseMsg}",
                true
            );
            $token = new WC_Payment_Token_CC();
            $token->set_token($response_createPaymentMethod->psp_PaymentMethod->PaymentMethodId);
            $token->set_gateway_id('nps');
            $token->set_card_type(strtolower($response_createPaymentMethod->psp_PaymentMethod->Product));
            $token->set_last4($response_createPaymentMethod->psp_PaymentMethod->CardOutputDetails->Last4);
            $token->set_expiry_month($response_createPaymentMethod->psp_PaymentMethod->CardOutputDetails->ExpirationMonth);
            $token->set_expiry_year($response_createPaymentMethod->psp_PaymentMethod->CardOutputDetails->ExpirationYear);
            $token->set_user_id($this->get_user_id());
            $token->save();

            $this->clear_cache();
        }

        do_action('woocommerce_nps_add_card', $this->get_id(), $token, $response_createPaymentMethod);

        return $response_createPaymentMethod->psp_PaymentMethod->PaymentMethodId;
    }

    /**
     * Get a customers saved cards using their Nps ID. Cached.
     *
     * @param  string  $customer_id
     * @return array
     */
    public function get_cards()
    {
        $cards = array();
        $this->get_customer_data();
        if ($this->get_id() && ($cache = get_transient('nps_cards_'.$this->get_id()))) {
            $unrepited_cards = array();
            foreach ($cache as $paymentMethod) {
                $unrepited_cards[$paymentMethod->FingerPrint] = $paymentMethod;
            }
            set_transient('nps_cards_'.$this->get_id(), $unrepited_cards, HOUR_IN_SECONDS * 48);
            $cards = $unrepited_cards;
        }
        return $cards;
    }

    /**
     * Delete a card from nps.
     * @param  string  $card_id
     */
    public function delete_card($card_id)
    {
        $sdk = new Sdk();
        $request = array(
            'psp_Version' => '2.2',
            'psp_MerchantId' => $this->merchant_id,
            'psp_PaymentMethodId' => $card_id,
            'psp_PosDateTime' => date('Y-m-d H:i:s'),
        );
        $this->log('Processing deletePaymentMethod request: '.print_r($request, true), WC_Nps_Customer::get_nps_logging());
        $response_deletePaymentMethod = $sdk->deletePaymentMethod($request);
        $this->log('Processing deletePaymentMethod response: '.print_r($response_deletePaymentMethod, true), WC_Nps_Customer::get_nps_logging());

        $this->clear_cache();

        if (!is_wp_error($response_deletePaymentMethod)) {
            do_action('wc_nps_delete_card', $this->get_id(), $response);

            return true;
        }

        return false;
    }

    /**
     * Set default card in Nps
     * @param  string  $card_id
     */
    public function set_default_card($card_id)
    {
        $this->log("Info: Beginning updateCustomer for customer {$this->get_id()}", true);

        $sdk = new Sdk();
        $request = array(
            'psp_Version' => '2.2',
            'psp_MerchantId' => $this->merchant_id,
            'psp_CustomerId' => $this->get_id(),
            'psp_DefaultPaymentMethodId' => $card_id,
            'psp_PosDateTime' => date('Y-m-d H:i:s'),
        );
        $this->log('Processing updateCustomer request: '.print_r($request, true), WC_Nps_Customer::get_nps_logging());
        $response = $sdk->updateCustomer($request);
        $this->log('Processing updateCustomer response: '.print_r($response, true), WC_Nps_Customer::get_nps_logging());

        $this->clear_cache();

        if (!is_wp_error($response)) {
            $this->log("Success: Customer updated - Customer ID: {$response->psp_CustomerId} - Reason: {$response->psp_ResponseMsg}", true);

            do_action('wc_nps_set_default_card', $this->get_id(), $response);

            return true;
        }

        $this->log("Error: On updateCustomer - Customer ID: ".$this->get_id()." - Reason: ".@$response->psp_ResponseExtended, true);

        return false;
    }

    /**
     * Deletes caches for this users cards.
     */
    public function clear_cache()
    {
        delete_transient('nps_cards_'.$this->get_id());
        delete_transient('nps_customer_'.$this->get_id());
        $this->customer_data = array();
    }
}
