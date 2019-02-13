if( typeof NPS !== 'undefined' ) {



/* global wc_nps_params */
NPS.setClientSession(wc_nps_params.clientSession);
NPS.setMerchantId(wc_nps_params.merchantId);
NPS.setAmount(wc_nps_params.amount);
NPS.setCountry(wc_nps_params.country);
NPS.setCurrency(wc_nps_params.currency);
/* NPS.setUseDeviceFingerPrint(false); */

jQuery(document).ready(function(){
    if(jQuery('.payment_box.payment_method_nps').is(':visible') || jQuery('.payment_box.payment_method_nps-wallet:visible').is(':visible')) {
      processClientSession();
    }
    jQuery('body').on('click', 'ul.payment_methods li.payment_method_nps', function() {
      processClientSession();
    });
    jQuery('body').on('click', 'ul.payment_methods li.payment_method_nps-wallet', function() {
      processClientSession();
    });    
});

function processClientSession() {
    if(!NPS.getClientSession()) {
        jQuery.ajax({
            type:    'POST',
	    data: { },
            url: '?wc-ajax=get_client_session',
	    success: function( response ) {
                wc_nps_params.clientSession = response.clientSession;
                NPS.setClientSession(wc_nps_params.clientSession);
	    }
	});     
    }
}

var npsSuccessResponseHandler;
npsSuccessResponseHandler = function(paymentMethodToken) {
  var paymentMethodForm, npsPaymentMethodTokenId;
  paymentMethodForm = jQuery( 'form.woocommerce-checkout, form#add_payment_method, form#order_review' ).first();
  paymentMethodForm.append("<input type='hidden' class='npsPaymentMethodTokenId' name='npsPaymentMethodTokenId' id='npsPaymentMethodTokenId' value='" + paymentMethodToken.id + "' >" );
  
 
  if(paymentMethodToken.installmentsOptions) {
    // npsShowInstallmentsDetails(paymentMethodToken.installmentsOptions);
    
    // Only submit when installments is already confirmed
    return false;
  }

  // And submit
  paymentMethodForm.submit();
};

var npsErrorResponseHandler;
npsErrorResponseHandler = function(response) {
  var paymentMethodForm;
  // paymentMethodForm = document.getElementById("payment-method-form");
  // console.log(response);alert(response);
  // To retrieve errors on create token
  // console.log(response.message);
  // document.getElementById("payment-method-errors").innerHTML = response.message_to_purchaser;
  // document.getElementById("payment-method-form-submit").removeAttribute("disabled");
  
  jQuery( document ).trigger( 'npsError', response );
  
  // Only submit when token is already loaded
  return false;
};


var npsShowInstallmentsDetails;
npsShowInstallmentsDetails = function(installmentsOptions) {
  if(installmentsOptions && installmentsOptions[0]) {
    var num =(installmentsOptions[0].installmentAmount / 100).toLocaleString((null, {style:"currency", currency:null}));
    // document.getElementById("payment-method-installments-description").innerHTML = "make "+installmentsOptions[0].numPayments+" monthly payments of "+num;
    // e.g: make 12 monthly payments of 12312313132
    
    // document.getElementById("payment-method-form-submit").removeAttribute("disabled");
  }  
}

jQuery( function( jQuery ) {
	'use strict';

	/* Open and close for legacy class */
	jQuery( 'form.checkout, form#order_review' ).on( 'change', 'input[name="wc-nps-payment-token"]', function() {
		if ( 'new' === jQuery( '.nps-legacy-payment-fields input[name="wc-nps-payment-token"]:checked' ).val() ) {
			jQuery( '.nps-legacy-payment-fields #nps-payment-data' ).slideDown( 200 );
		} else {
			jQuery( '.nps-legacy-payment-fields #nps-payment-data' ).slideUp( 200 );
		}
	} );

	/**
	 * Object to handle Nps payment forms.
	 */
	var wc_nps_form = {

		/**
		 * Initialize event handlers and UI state.
		 */
		init: function() {
			// checkout page
			if ( jQuery( 'form.woocommerce-checkout' ).length ) {
				this.form = jQuery( 'form.woocommerce-checkout' );
			}

			jQuery( 'form.woocommerce-checkout' )
				.on(
					'checkout_place_order_nps',
					this.onSubmit
				);

			// pay order page
			if ( jQuery( 'form#order_review' ).length ) {
				this.form = jQuery( 'form#order_review' );
			}

			jQuery( 'form#order_review' )
				.on(
					'submit',
					this.onSubmit
				);

			// add payment method page
			if ( jQuery( 'form#add_payment_method' ).length ) {
				this.form = jQuery( 'form#add_payment_method' );
			}

			jQuery( 'form#add_payment_method' )
				.on(
					'submit',
					this.onSubmit
				);

			jQuery( document )
				.on(
					'change',
					'#wc-nps-cc-form :input',
					this.onCCFormChange
				)
				.on(
					'npsError',
					this.onError
				)
				.on(
					'checkout_error',
					this.clearToken
				);
		},

		isNpsChosen: function() {
			// return jQuery( '#payment_method_nps' ).is( ':checked' ) && ( ! jQuery( 'input[name="wc-nps-payment-token"]:checked' ).length || 'new' === jQuery( 'input[name="wc-nps-payment-token"]:checked' ).val() );
      return jQuery( '#payment_method_nps' ).is( ':checked' ) && ( ! jQuery( 'input[name="wc-nps-payment-token"]:checked' ).length || jQuery( 'input[name="wc-nps-payment-token"]' ).is( ':checked' ) )
		},
    
    getPaymentMethodId: function() {
      if(!jQuery( 'input[name="wc-nps-payment-token"]:checked' ).length) {
        return '';
      }
      if('new' === jQuery( 'input[name="wc-nps-payment-token"]:checked' ).val()) {
        return '';
      }
      return jQuery( 'input[name="wc-nps-payment-token"]:checked' ).val();
    },

		hasToken: function() {
			return 0 < jQuery( 'input.npsPaymentMethodTokenId' ).length;
		},

		block: function() {
			wc_nps_form.form.block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
		},

		unblock: function() {
			wc_nps_form.form.unblock();
		},

		onError: function( e, responseObject ) {
      // console.log(responseObject);alert(responseObject);
			var message = responseObject.message_to_purchaser;

			// Customers do not need to know the specifics of the below type of errors
			// therefore return a generic localizable error message.
			/* if ( 
				'invalid_request_error' === responseObject.response.error.type ||
				'api_connection_error'  === responseObject.response.error.type ||
				'api_error'             === responseObject.response.error.type ||
				'authentication_error'  === responseObject.response.error.type ||
				'rate_limit_error'      === responseObject.response.error.type
			) {
				message = wc_nps_params.invalid_request_error;
			} */

			/* if ( 'card_error' === responseObject.response.error.type && wc_nps_params.hasOwnProperty( responseObject.response.error.code ) ) {
				message = wc_nps_params[ responseObject.response.error.code ];
			} */

			jQuery( '.wc-nps-error, .npsPaymentMethodTokenId' ).remove();
			jQuery( '#nps-card-number' ).closest( 'fieldset' ).before( '<ul class="woocommerce_error woocommerce-error wc-nps-error"><li>' + message + '</li></ul>' );
			wc_nps_form.unblock();
		},

		onSubmit: function( e ) {
			if ( wc_nps_form.isNpsChosen() && ! wc_nps_form.hasToken() ) {
				e.preventDefault();
				wc_nps_form.block();
/*
				var card       = jQuery( '#nps-card-number' ).val(),
					cvc        = jQuery( '#nps-card-cvc' ).val(),
					expires    = jQuery( '#nps-card-expiry' ).payment( 'cardExpiryVal' ),
					first_name = jQuery( '#billing_first_name' ).length ? jQuery( '#billing_first_name' ).val() : wc_nps_params.billing_first_name,
					last_name  = jQuery( '#billing_last_name' ).length ? jQuery( '#billing_last_name' ).val() : wc_nps_params.billing_last_name,
					data       = {
						number   : card,
						cvc      : cvc,
						exp_month: parseInt( expires.month, 10 ) || 0,
						exp_year : parseInt( expires.year, 10 ) || 0
					};

				if ( first_name && last_name ) {
					data.name = first_name + ' ' + last_name;
				}

				if ( jQuery( '#billing_address_1' ).length > 0 ) {
					data.address_line1   = jQuery( '#billing_address_1' ).val();
					data.address_line2   = jQuery( '#billing_address_2' ).val();
					data.address_state   = jQuery( '#billing_state' ).val();
					data.address_city    = jQuery( '#billing_city' ).val();
					data.address_zip     = jQuery( '#billing_postcode' ).val();
					data.address_country = jQuery( '#billing_country' ).val();
				} else if ( wc_nps_params.billing_address_1 ) {
					data.address_line1   = wc_nps_params.billing_address_1;
					data.address_line2   = wc_nps_params.billing_address_2;
					data.address_state   = wc_nps_params.billing_state;
					data.address_city    = wc_nps_params.billing_city;
					data.address_zip     = wc_nps_params.billing_postcode;
					data.address_country = wc_nps_params.billing_country;
				}

				Nps.createToken( data, wc_nps_form.onNpsResponse );
*/


        // var paymentMethodForm;
        // paymentMethodForm = this;




        // Request a token from NPS:
        jQuery('#payment-method-select').remove();
        if(wc_nps_form.getPaymentMethodId()) {
          jQuery(wc_nps_form.form).append("<input type='hidden' name='payment-method-select' id='payment-method-select' data-nps='card[payment_method_id]' value='" + wc_nps_form.getPaymentMethodId() + "' >" );
        }
        
        NPS.paymentMethodToken.create(wc_nps_form.form, npsSuccessResponseHandler, npsErrorResponseHandler);

        // Only submit when token is already loaded
        return false;
      }
		},

		onCCFormChange: function() {
			jQuery( '.wc-nps-error, .npsPaymentMethodTokenId' ).remove();
		},

		onNpsResponse: function( status, response ) {
			if ( response.error ) {
				jQuery( document ).trigger( 'npsError', { response: response } );
			} else {
				// token contains id, last4, and card type
				var token = response.id;

				// insert the token into the form so it gets submitted to the server
				wc_nps_form.form.append( "<input type='hidden' class='npsPaymentMethodTokenId' name='npsPaymentMethodTokenId' value='" + token + "'/>" );
				wc_nps_form.form.submit();
			}
		},

		clearToken: function() {
			jQuery( '.npsPaymentMethodTokenId' ).remove();
		}
	};

	wc_nps_form.init();
} );


};