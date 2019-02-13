 jQuery(document).bind('ready ajaxComplete', function(){
    if(jQuery( 'input[name="wc-nps-payment-token"]').length == 0) 
      return;
  
    var initToken = ( 'new' !== jQuery( 'input[name="wc-nps-payment-token"]:checked' ).val() );
    if (initToken) {
      jQuery('.hide-if-saved-card').hide();
      jQuery('#nps-card-cvc').parent('p').removeClass('form-row-last');
    }else {
      jQuery('.hide-if-saved-card').show();
      jQuery('#nps-card-cvc').parent('p').addClass('form-row-last');
    }
  
  jQuery( 'input[name="wc-nps-payment-token"]' ).click(function () {
    var initToken = ( 'new' !== jQuery( 'input[name="wc-nps-payment-token"]:checked' ).val() );
    if (initToken) {
      jQuery('.hide-if-saved-card').hide();
      jQuery('#nps-card-cvc').parent('p').removeClass('form-row-last');
    } else {
      jQuery('.hide-if-saved-card').show();
      jQuery('#nps-card-cvc').parent('p').addClass('form-row-last');
    }
  });
});