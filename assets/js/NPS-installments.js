var isNpsInstallmentsFirstLoad = true;
jQuery(document).bind('ready ajaxComplete', function(){


    function loadInstallments(npsCardBrandId, wcNpsPaymentToken) {
        if ( ! jQuery('#nps-card-numpayments').length ) {
            return;
        }        
	jQuery.ajax({
            type:    'POST',
            data: { 'nps-card-brand': npsCardBrandId, 'wc-nps-payment-token': wcNpsPaymentToken },
            url: '?wc-ajax=get_card_installments',
	    success: function( response ) {
                if(response && Object.keys(response).length > 0) {
                    jQuery("#nps-card-numpayments").empty().append(jQuery("<option value=''></option>").text("Choose an option"));
                }else {
                    jQuery("#nps-card-numpayments").empty().append(jQuery("<option value=''></option>").text("Choose a card first"));
                }
                jQuery.each(response, function(index, installment){
                    /* jQuery("#nps-card-numpayments").append(jQuery("<option value=''></option>")
                    .attr("value", installment.installment).text('(' + jQuery("#nps-payment-data").data('currency').toUpperCase() + ')' + jQuery("#nps-payment-data").data('amount').toFixed(2)/100 + ' as ' + installment.installment + ' installments')); */
                    jQuery("#nps-card-numpayments").append(jQuery("<option value=''></option>")
                        .attr("value", installment.installment).text('+%' + installment.rate + ' as ' +  installment.installment + ' installments'));              
                });
          
          
					
	    }
	});
    }    
    
    jQuery('input[name="wc-nps-payment-token"]').unbind('click');
    jQuery( 'input[name="wc-nps-payment-token"]').click(function(){
		var wcNpsPaymentToken = jQuery(this).val();
                loadInstallments(null, wcNpsPaymentToken);        
    });
    
    
    jQuery('#nps-card-brand').unbind('change');
    jQuery('#nps-card-brand').change(function () {
            var npsCardBrandId = jQuery('#nps-card-brand').val();
            loadInstallments(npsCardBrandId, null);
    });
    
    if(isNpsInstallmentsFirstLoad) {
        isNpsInstallmentsFirstLoad = false;
        setTimeout(function(){ 
            if(jQuery('input[name="wc-nps-payment-token"]:checked').val()) {
              loadInstallments(null, jQuery( 'input[name="wc-nps-payment-token"]:checked').val()); 
            }else if(jQuery('#nps-card-brand').val()) {
              loadInstallments(jQuery('#nps-card-brand').val(), null);
            }else if(jQuery('input[name="wc-nps-payment-token"]:first').val()) {
                jQuery('input[name="wc-nps-payment-token"]:first').prop( 'checked', true );
              loadInstallments(null, jQuery( 'input[name="wc-nps-payment-token"]:first').val());   
            };
        },1000);
    }
    
});
