<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return apply_filters( 'wc_nps_settings',
	array(
		'enabled' => array(
			'title'       => __( 'Enable/Disable', 'woocommerce-gateway-nps' ),
			'label'       => __( 'Enable Nps', 'woocommerce-gateway-nps' ),
			'type'        => 'checkbox',
			'description' => '',
			'default'     => 'no',
		),
		'title' => array(
			'title'       => __( 'Title', 'woocommerce-gateway-nps' ),
			'type'        => 'text',
			'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-nps' ),
			'default'     => __( 'Credit Card (Nps)', 'woocommerce-gateway-nps' ),
			'desc_tip'    => true,
		),
		'description' => array(
			'title'       => __( 'Description', 'woocommerce-gateway-nps' ),
			'type'        => 'text',
			'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-nps' ),
			'default'     => __( 'Pay with your credit card via Nps.', 'woocommerce-gateway-nps' ),
			'desc_tip'    => true,
		),
    'url' => array(
			'title'       => __( 'URL', 'woocommerce-gateway-nps' ),
			'type'        => 'text',
			'description' => __( 'Place the payment gateway url, take in account on which environment you wish to work with.', 'woocommerce-gateway-nps' ),
			'default'     => 'https://sandbox.nps.com.ar/ws.php?wsdl',
			'desc_tip'    => true,
		),      
		'merchant_id' => array(
			'title'       => __( 'Merchant ID', 'woocommerce-gateway-nps' ),
			'type'        => 'text',
			'description' => __( 'Get your Merchant ID from your nps account.', 'woocommerce-gateway-nps' ),
			'default'     => '',
			'desc_tip'    => true,
		),      
		'secret_key' => array(
			'title'       => __( 'Secret Key', 'woocommerce-gateway-nps' ),
			'type'        => 'text',
			'description' => __( 'Get your Secret Keys from your nps account.', 'woocommerce-gateway-nps' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'statement_descriptor' => array(
			'title'       => __( 'Soft Descriptor', 'woocommerce-gateway-nps' ),
			'type'        => 'text',
			'description' => __( 'Extra information about a charge. This will appear on your customer’s credit card statement.', 'woocommerce-gateway-nps' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'capture' => array(
			'title'       => __( 'Capture', 'woocommerce-gateway-nps' ),
			'label'       => __( 'Capture charge immediately', 'woocommerce-gateway-nps' ),
			'type'        => 'checkbox',
			'description' => __( 'Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later.', 'woocommerce-gateway-nps' ),
			'default'     => 'yes',
			'desc_tip'    => true,
		),
		'saved_cards' => array(
			'title'       => __( 'Saved Cards', 'woocommerce-gateway-nps' ),
			'label'       => __( 'Enable Payment via Saved Cards', 'woocommerce-gateway-nps' ),
			'type'        => 'checkbox',
			'description' => __( 'If enabled, users will be able to pay with a saved card during checkout. Card details are saved on Nps servers, not on your store.', 'woocommerce-gateway-nps' ),
			'default'     => 'no',
			'desc_tip'    => true,
		),
		'logging' => array(
			'title'       => __( 'Logging', 'woocommerce-gateway-nps' ),
			'label'       => __( 'Log debug messages', 'woocommerce-gateway-nps' ),
			'type'        => 'checkbox',
			'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-nps' ),
			'default'     => 'no',
			'desc_tip'    => true,
		),
		'nps_mode' => array(
			'title'       => __( 'Payment Flow', 'woocommerce-gateway-nps' ),
			'type'        => 'select',
			'class'       => 'wc-enhanced-select',
			// 'description' => __( 'Language to display in Nps Checkout modal. Specify Auto to display Checkout in the user\'s preferred language, if available. English will be used by default.', 'woocommerce-gateway-nps' ),
			'default'     => 'Simple Checkout',
			// 'desc_tip'    => true,
			'options'     => array(
				'simple_checkout' => __( 'Simple Checkout', 'woocommerce-gateway-nps' ),
				'advance_checkout'   => __( 'Advanced Checkout', 'woocommerce-gateway-nps' ),
				'direct_payment'   => __( 'Direct Payment', 'woocommerce-gateway-nps' ),
			),
		),      
		'require_card_holder_name' => array(
			'title'       => __( 'Require Card Holder Name', 'woocommerce-gateway-nps' ),
			'label'       => __( 'Require Card Holder Name', 'woocommerce-gateway-nps' ),
			'type'        => 'checkbox',
			'description' => __( 'Require card holder name in credit card form.', 'woocommerce-gateway-nps' ),
			'default'     => 'no',
			'desc_tip'    => true,
		),                              
		'installment_enable' => array(
			'title'       => __( 'Installments', 'woocommerce-gateway-nps' ),
			'label'       => __( 'Enable Installments', 'woocommerce-gateway-nps' ),
			'type'        => 'checkbox',
			'description' => __( 'Enable installment in credit card form.', 'woocommerce-gateway-nps' ),
			'default'     => 'no',
			'desc_tip'    => true,
		),      
                'installment_details' => array(
                  'type'        => 'installment_details',
                ),      
		'wallet_enable' => array(
			'title'       => __( 'Wallets', 'woocommerce-gateway-nps' ),
			'label'       => __( 'Enable Wallets', 'woocommerce-gateway-nps' ),
			'type'        => 'checkbox',
			'description' => __( 'Enable wallet in credit card form.', 'woocommerce-gateway-nps' ),
			'default'     => 'no',
			'desc_tip'    => true,
		),                              
                'wallets' => array(
                  'type'        => 'wallets',
                ),                  
	)
);
