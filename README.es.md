# WooCommerce 3.x.x Plugin

*Read this in other languages: [English](README.md), [Español](README.es.md)*

## Introducción

Puede integrar NPS Ingenico Latam en WooCommerce fácilmente, configurando los medios de pago en pocos pasos simples. En cuestión de solo unos minutos, su carrito de compra estará listo para operar en línea.

## Disponibilidad

Soportado y probado en la versión 3.7.0 de WooCommerce.

## Métodos de integración

Para transaccionar financieramente contra NPS Ingenico Latam, este carrito soporta los siguientes métodos de integración:

* Simple Checkout  
* Advanced Checkout  
* Direct Payment  

Este plugin permite realizar transacciones con captura de fondos inmediata (métodos _Sale_) o sin captura de fondos (métodos _Authorization_) que permite capturar los fondos posteriormente al requerimiento, de manera manual.

Para más detalles, verificar: <https://developers.nps.com.ar/GuideReference/reference>

Usted también podrá realizar anulaciones o devoluciones directamente desde su sitio de administración de WordPress.

## Características adicionales

Usar este plugin para WooCommerce, permitirá que su carrito haga uso de las siguientes funcionalidades:

* Operar con tarjetas previamente almacenadas
* Operar con la billetera Masterpass

## Instalación

Para poder completar la siguiente configuración, es necesario tener previamente instalado WordPress y el plugin WooCommerce.

1. Obtenga la última versión del plugin desde <https://github.com/Ingenico-NPS-Latam>

1. Descomprima el contenido del archivo NPS.Woocommerce.3.x.x.Connector.vx.xx.xxx.tar.gz dentro del directorio wp-content/plugins de su servidor WordPress.

1. Ingrese al sitio de administración de su instalación de WordPress.

1. Entre a Plugins -> Installed Plugins.
  ![Inactive plugin](https://user-images.githubusercontent.com/24914148/50290010-2b793c00-0449-11e9-8290-5ea564c4d92c.png)

1. Haga Click en el link "Activate" debajo del nombre del plugin ("WooCommerce NPS Payment Gateway") para habilitar el uso del carrito.

## Configuración

1. Ingrese al sitio de administración de su instalación de WordPress.

1. Entre a Plugins -> Installed Plugins.
  ![Active plugin](https://user-images.githubusercontent.com/24914148/50290011-2c11d280-0449-11e9-8c05-51dd0626afcb.png)

1. Haga Click en el link "Settings" debajo del nombre del plugin ("WooCommerce NPS Payment Gateway").

1. Complete las opciones disponibles en la configuración de acuerdo a su necesidad.

### Como completar cada una de las opciones disponibles

\
![Plugin settings 1/2](https://user-images.githubusercontent.com/24914148/50290018-2f0cc300-0449-11e9-9702-78a9913f09df.png)

* **Enable/Disable checkbox** (opcional)

  (Por defecto) "Enable NPS" aparece no habilitado.
  
  Puede habilitar o no el carrito haciendo desde esta opción.

* **Title input text** (opcional)

  (Por defecto) Credit Card (Nps) text
  
  El texto ingresado en este campo aparecerá como opción de pago en la página de Checkout del usuario.
  
* **Description input text** (opcional)

  (Por defecto) Pay with your credit card via Nps text

  El texto ingresado en "Description input" aparecerá como una descripción del método de pago una vez seleccionado el medio de pago.

  ![Plugin title and description](https://user-images.githubusercontent.com/24914148/50291840-65007600-044e-11e9-8c1e-d67a994cedcf.png)
  
* **URL input text** (obligatorio)

  (Por defecto) <https://sandbox.nps.com.ar/ws.php?wsdl>  (Entorno Sandbox)

  Complete con la URL del gateway de NPS. Tome en cuenta contra cual ambiente desea transaccionar.
  
* **Merchant ID input text** (obligatorio)

  Ingrese el Merchant ID de su cuenta de NPS.

  Tenga a bien solicitar el mismo contactando con Merchant Implementation Support de Ingenico NPS Latam.

* **Secret Key input text** (obligatorio)

  Ingrese la Secret Key de su cuenta de NPS.

  Tenga a bien solicitar la misma contactando con Merchant Implementation Support de Ingenico NPS Latam.

* **Soft Descriptor input text** (opcional)

  Información adicional acerca del comercio. Este valor aparecerá en el resumen de la tarjeta del comprador.

* **Capture checkbox** (opcional)

  (Por defecto) "Capture" aparece como habilitado

  Seleccionar si se desea o no capturar inmediatamente el cargo. Cuando no está habilitado, la transacción será autorizada; posteriormente se deberá capturar el monto de la misma de manera manual.
  
* **Saved Cards checkbox** (opcional)

  (Por defecto) "Saved Cards" aparece como deshabilitado.

  Si es seleccionado, en el Checkout de los compradores registrados en el sitio aparecerá la opción de guardar los datos de la tarjeta que están por utilizar. En el siguiente pago, el comprador podrá volver a utilizar la misma tarjeta ingresando únicamente el código de seguridad.

  ![Save to account option](https://user-images.githubusercontent.com/24914148/50291842-65007600-044e-11e9-8878-27eb2f518767.png)
  
* **Logging checkbox** (opcional)

  (Por defecto) "Logging" aparece como deshabilitado.

  Si es seleccionado, activará el registro de debug para uso de desarrolladores. Los mensajes de Debug serán almacenados en el "system status log" de WooCommerce.
  
* **Payment Flow drop-down list** (obligatorio)

  (Por defecto) Aparece "Simple checkout" (3p) seleccionado en el Payment Flow dropdown.
  
  Indica el "Payment Flow" seleccionado para transaccionar contra NPS. Las siguientes opciones están disponibles para que sean seleccionadas de acuerdo a lo que el comercio necesite:
  
  * Simple checkout

  * Advance Checkout

  * Direct Payment

  Para más información, por favor visite <https://developers.nps.com.ar/GuideReference/reference> para conocer cuál es el "Payment Flow" correcto para su comercio.

![Plugin settings 2/2](https://user-images.githubusercontent.com/24914148/50290019-2f0cc300-0449-11e9-99b1-0dad76333aa9.png)

* Require Card Holder Name check box (opcional)

  Habilitar la solicitud del nombre del tarjeta habiente en el caso de los flows "Advanced Checkout" y "Direct Payment". Para solicita el ingreso del nombre del tarjeta habiente en el flujo "Simple Checkout", pongase en contacto con Merchant Implementation Support de Ingenico NPS Latam.

* Installments check box (opcional)

  Habilitar el pago con las cuotas configuradas más abajo, en la página de Checkout.

  ![Installments options](https://user-images.githubusercontent.com/24914148/50291843-65007600-044e-11e9-81a0-205767d9e520.png)

* Installments Details table selection (obligatorio)

  * Agregar selección de cuotas haciendo click en el botón "+ Add installment"

  * Eliminar una selección de cuotas haciendo click en el botón "Remove selected installment(s)".

  * Modificar la selección de cuotas seleccionando "Card" (marca de la tarjeta), "Installments" (cantidad de cuotas), "Status" (para habilitar o no esa selección de cuotas), "Country" (país de la transacción) y "Currency" (moneda de la transacción). También debe incluir el interés ("Rate") que desee agregarle a la transacción por el uso de cada selección de cuotas.

## Notas:
  
  Incluso cuando no se selecciona el checkbox "Installments" deberá configurar la selección de cuotas para 1 cuota para cada marca de tarjeta que desea habilitar en el Checkout.

  El comprador sólo será capaz de seleccionar este carrito como medio de pago si coinciden los países en:

  * WooCommerce -> Settings -> General -> Country / State

  * El país en la tabla de selección de cuotas

  * El país que eligirá el comprador dentro del Billing Details.
  
### Lenguaje:

* Considerar los mensajes del menu desplegable en los archivos NPS-installments.js y class-wc-gateway-nps.php

* Considerar el valor de 'psp_FrmLanguage' en el archivo class-wc-gateway-nps.php