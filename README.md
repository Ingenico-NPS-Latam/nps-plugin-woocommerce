# WooCommerce 3.x.x Plugin

*Read this in other languages: [English](README.md), [Español](README.es.md)*

## Introduction

NPS Ingenico Latam can be easily integrated to WooCommerce, giving you the posibility to setup the payment method in few simply steps. In just a matter of minutes your shopping cart will be ready to start operating online.

## Availability

Supported & Tested in the 3.7.0 version of WooCommerce.

## Integration Modes

To handle financial transactions to NPS Ingenico Latam server, this cart addon supports the following mechanisms:

* Simple Checkout  
* Advanced Checkout  
* Direct Payment  

The plugin allows to perform an inmediate settlement (_Sale_ methods) or a delayed settlement (_Authorization_ methods) to capture the funds manually, later, from the customers.

For further details, check <https://developers.nps.com.ar/GuideReference/reference>

Also, you will be able to refund to your customer directly from your Wordpress admin site.

## Additional features

Using this cart addon for WooCommerce, will allow your shopping cart to make use of the following features:

* Operate with previously stored cards  
* Operate with Masterpass wallet  

## Install

To make the following configuration it is necessary to have Wordpress and WooCommerce previously installed.

1. Get the latest plugin release from <https://github.com/Ingenico-NPS-Latam>

1. Extract the file NPS.Woocommerce.3.x.x.Connector.vx.xx.xxx.tar.gz in the wp-content/plugins directory of your WordPress server.

1. Enter your WordPress admin site.

1. Go to Plugins -> Installed Plugins.
  ![Inactive plugin](https://user-images.githubusercontent.com/24914148/50290010-2b793c00-0449-11e9-8290-5ea564c4d92c.png)

1. Click on "Activate" link below the plugin name ("WooCommerce NPS Payment Gateway") to enable the cart addon.

## Settings

1. Enter your WordPress admin site.

1. Go to Plugins -> Installed Plugins.
  ![Active plugin](https://user-images.githubusercontent.com/24914148/50290011-2c11d280-0449-11e9-8c05-51dd0626afcb.png)

1. Click on "Settings" link below the plugin name ("WooCommerce NPS Payment Gateway").

1. Fulfill the available setting options according to your business requirements.

### How to fulfill each item setting

\
![Plugin settings 1/2](https://user-images.githubusercontent.com/24914148/50290018-2f0cc300-0449-11e9-9702-78a9913f09df.png)

* **Enable/Disable checkbox** (non-mandatory)

  (By default) Enable NPS appears as DISABLE (Unchecked Enable NPS check box)
  
  You can Enable NPS by checking it.

* **Title input text** (non-mandatory)

  (By default) Credit Card (Nps) text
  
  Text entered in Title input text will appear in the Checkout as a payment option.

* **Description input text** (non-mandatory)

  (By default)  Pay with your credit card via Nps text

  Text entered in Description input text will appear in the Checkout after customer selected this payment method.

  ![Plugin title and description](https://user-images.githubusercontent.com/24914148/50291840-65007600-044e-11e9-8c1e-d67a994cedcf.png)

* **URL input text** (mandatory)

  (By default) <https://sandbox.nps.com.ar/ws.php?wsdl>  (Sandbox environment)

  Place the Payment gateway URL, take into account on which environment you wish to work with.

* **Merchant ID input text** (mandatory)

  Get your Merchant ID from NPS account.

  Pls. request it by contacting Merchant Implementation Support

* **Secret Key input text** (mandatory)

  Get your Secret key from your NPS account.

  Pls. request it by contacting Merchant Implementation Support

* **Soft Descriptor input text** (non-mandatory)

  Extra information about the merchant. This will appear on your customer card statement.

* **Capture checkbox** (non-mandatory)

  (By default) Capture appears as ENABLE (checked Capture check box)

  Whether or not to immediately capture the charge. When unchecked the charge issues non-authorization and will need to capture later.

* **Saved Cards checkbox** (non-mandatory)

  (By default) It appears as unchecked Saved Cards check box.

  If you select it, it will appear in Customer’s Checkout payment selection as an option to save cards after customers entering their Credit Card details. For next Payment, Credit Card details will be stored except CVC.

  ![Save to account option](https://user-images.githubusercontent.com/24914148/50291842-65007600-044e-11e9-8878-27eb2f518767.png)

* **Logging checkbox** (non-mandatory)

  (By default) It appears as unchecked Logging check box.

  If you select it, it will activate log tracking for developers use. Save debug  messages to the Woocommerce system status log.

* **Payment Flow drop-down list** (mandatory)

  (By default) It appears Simple checkout (3p) selected in Payment Flow drop-down list.
  
  It indicates Payment Flow selected for operate against NPS. Following options are available to be selected according to your business requirements:

  * Simple checkout

  * Advance Checkout

  * Direct Payment

  For more information  pls. visit  <https://developers.nps.com.ar/GuideReference/reference> in order to know what Payment Flow is your business.

![Plugin settings 2/2](https://user-images.githubusercontent.com/24914148/50290019-2f0cc300-0449-11e9-99b1-0dad76333aa9.png)

* Require Card Holder Name check box (opcional)

  This will ask your customer the name of the card holder to be used. Only applies to the "Advanced Checkout" and "Direct Payment" payment flows. For asking the card holder name in the "Simple Checkout" payment flow, please contact Merchant Implementation Support from Ingenico NPS Latam.
  
* Installments check box (non-mandatory)

  Enable the installments options included in the table below in the Checkout page.

  ![Installments options](https://user-images.githubusercontent.com/24914148/50291843-65007600-044e-11e9-81a0-205767d9e520.png)

* Installments Details table selection (mandatory)

  * Add installment by clicking on +Add installment button

  * Remove installment by clicking on Remove selected installment(s) button

  * Modify installment just selecting Card, Installments, Status, Country and Currency drop-down selection. Also by entering any number between 0-999 in Rate input text.

## Notes:
  
  Even when Installments checkbox it's not enabled, you must setup the Cards in 1 installment, for being able to show it to the customer in the Checkout page.

  The customer only will see this card as a payment option when the Country options in the following places matches each other.

  * WooCommerce -> Settings -> General -> Country / State

  * Country column in the Installments Details table

  * The customer Billing Details Country selection.
  
## Known Issues:

  An error is known in the jquery.payments plugin used by woocomerce when using the **Advance Checkout integration method (Payment Flow)**, which it does not recognize the 19-digit length of some cards correctly. Being a third-party library, we leave     below a workarround to solve it:

  >Locate the file **jquery.payments.js** inside the woocomerce plugin folder. It is usually found in:
  >
  >`wp-content\plugins\woocommerce\assets\js\jquery-payments\jquery.payments.js`
  >
  > Place in the code the line **upperLength = 16;**
  >```length = (value.replace(/\D/g, '') + digit).length;
  >   upperLength = 16;
  >   if (card) {
  >      upperLength = card.length[card.length.length - 1];
  >   }
  >```
  >Replace `upperLength = 16;` by `upperLength = 19;`
  >
  >Minify and update the **jquery.payment.min.js** file.

  **Note:** In case of updating woocommerce, it is necessary to perform these steps again.

### Language:

* Consider drop-down menu messages in NPS-installments.js and class-wc-gateway-nps.php files

* Consider the value of 'psp_FrmLanguage' in class-wc-gateway-nps.php file