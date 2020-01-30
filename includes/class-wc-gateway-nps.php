<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

include_once('nps-sdk-php-master/init.php');


use NpsSDK\Configuration;
use NpsSDK\Constants;
use NpsSDK\Sdk;
use NpsSDK\ApiException;


define("NPS_ERR_BAD_ACCESS", "Bad Access");
define("NPS_ERR_BAD_SOURCE_IP", "Bad Source IP");
define("NPS_ERR_UNKNOWN", "Unknown Error");
define("NPS_ERR_AMOUNT_MISMATCH", "Amount Mismatch");
define("NPS_ERR_ORDER_ID_MISMATCH", "Order ID Mismatch");

define("NPS_ERR_WILDCARD", "An error occurred, please try again later.");
define("NPS_NOTICE_PAYMENT_APPROVED", "Payment Approved.");


/**
 * WC_Gateway_Nps class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Nps extends WC_Payment_Gateway_CC {

  const NPS_MODE_SIMPLE_CHECKOUT = "simple_checkout";
  const NPS_MODE_ADVANCE_CHECKOUT = "advance_checkout";
  const NPS_MODE_DIRECT_PAYMENT = "direct_payment";

  /**
   * Should we capture Credit cards
   *
   * @var bool
   */
  public $capture;

  /**
   * Alternate credit card statement name
   *
   * @var bool
   */
  public $statement_descriptor;

  /**
   * Should we store the users credit cards?
   *
   * @var bool
   */
  public $saved_cards;

  /**
   * API access secret key
   *
   * @var string
   */
  public $secret_key;

  /**
   * Logging enabled?
   *
   * @var bool
   */
  public $logging;

  /**
   * installments enabled?
   *
   * @var bool
   */
  public $installments;


  /**
   * Constructor
   */
  public function __construct() {
    if(!$this->hasCardAvailable() && is_checkout()) {
      return false;
    }

    $this->id                   = 'nps';
    $this->method_title         = __( 'Nps', 'woocommerce-gateway-nps' );
    $this->method_description   = __( 'NPS is a platform devoted to on-line payment processing, offering credit cards and alternative means of payment acceptance to e-commerce sites. Through a unique technical integration, a site could be connected to all means of payment available in Latin America.', 'woocommerce-gateway-nps' );
    $this->has_fields           = true;
    $this->view_transaction_url = $this->getViewTransactionUrl();
    $this->supports             = array(
      'products',
      'refunds',
      'pre-orders',
      'tokenization',
      'add_payment_method',
      'default_credit_card_form',
    );

    // Load the form fields.
    $this->init_form_fields();

    // Load the settings.
    $this->init_settings();

    if($this->checkWSDL() == false) {
      return false;
    }

    // Get setting values.
    $this->title                   = $this->get_option( 'title' );
    $this->description             = $this->get_option( 'description' );
    $this->enabled                 = $this->get_option( 'enabled' );
    $this->capture                 = 'yes' === $this->get_option( 'capture', 'yes' );
    $this->statement_descriptor    = $this->get_option( 'statement_descriptor' );
    $this->nps_mode                = $this->get_option( 'nps_mode' );
    $this->saved_cards             = 'yes' === $this->get_option( 'saved_cards' );
    $this->installments            = 'yes' === $this->get_option( 'installment_enable' );
    $this->wallet_enabled          = 'yes' === $this->get_option( 'wallet_enable' );
    $this->require_card_holder_name = 'yes' === $this->get_option( 'require_card_holder_name' );
    $this->secret_key              = $this->get_option( 'secret_key' );
    $this->url                     = $this->get_option( 'url' );
    $this->merchant_id             = $this->get_option( 'merchant_id' );
    $this->logging                 = 'yes' === $this->get_option( 'logging' );
    $this->response_url            = add_query_arg( 'wc-api', 'WC_Gateway_Nps', home_url( '/' ) );
    $this->installment_details     = get_option( 'woocommerce_nps_installments');
    $this->wallets                 = get_option( 'woocommerce_nps_wallets');

    if ( $this->is_simple_checkout() ) {
      $this->order_button_text = __( 'Continue to payment', 'woocommerce-gateway-nps' );
    }

    Configuration::environment(Constants::CUSTOM_ENV);
    Configuration::verifyPeer(false);
    Configuration::customUrl($this->url);
    Configuration::secretKey($this->secret_key);
    // Configuration::logger(WC_Nps::get_instance());

    // Hooks.
    add_action( 'woocommerce_api_wc_gateway_' . $this->id, array( $this, 'check_gateway_response' ) );
    add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
    add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
    add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_installment_details' ) );
    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_wallets' ) );
    add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
    add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_installment_fee' ) );
    add_filter( "woocommerce_endpoint_order-received_title", array($this,'filter_woocommerce_endpoint_order_received_title'), 10, 2 );
  }

  protected function hasCardAvailable() {
    $wc_currency  = wc_clean( strtoupper( get_woocommerce_currency() ) );
    $wc_country   = wc_clean( strtoupper( wc_get_base_location()['country'] ) );
    $installments = $this->searchInstallments($brand=NULL, $country=self::format_country($wc_country), $currency=self::format_currency($wc_currency));
    return (is_array($installments) && count($installments));
  }

  public function searchInstallments($brand=NULL, $country=NULL, $currency=NULL, $installment=NULL) {
    // var_dump("entry searchInstallments");
    // var_dump("brand[$brand] country[$country] currency[$currency] installment[$installment]");
    if(!$this->is_installment_enabled()) {
      $installment = 1;
    }
    $installments = array();
    $rs = get_option( 'woocommerce_nps_installments' );
    foreach($rs as $r) {
      if(($brand && $brand != $r['card'])
        || ($country && $country != $r['country'])
        || ($currency && $currency != $r['currency'])
        || ($installment && $installment != $r['installment'])
        || ($r['status'] != '1')
      ) {
        continue;
      }
      $installments[] = $r;
    }
    ksort($installments,SORT_NUMERIC);

    // var_dump("installments_before[".var_export($installments,true)."]");
    if(is_checkout() && !($installment > 1)) {
      // which products has installments=1?
      $enabled_products = array();
      foreach($installments as $key => $installment) {
        if($installment['installment']==1) {
          $enabled_products[$installment['card']] = $installment['card'];
        }
      }
      // var_dump("enabled_products[".var_export($enabled_products,true)."]");

      // remove the ones which hasnt
      foreach($installments as $key => $installment) {
        if(!in_array($installment['card'], $enabled_products)) {
          unset($installments[$key]); continue;
        }
      }

      // var_dump("installments_after[".var_export($installments,true)."]");
    }
    return $installments;
  }

  public function is_installment_enabled() {
    return $this->installments;
  }

  public static function format_country($country=NULL) {
    switch(strtoupper($country)) {
      case "AD": return "AND"; //Andorra
      case "AE": return "ARE"; //Emiratos Árabes Unidos
      case "AF": return "AFG"; //Afganistán
      case "AG": return "ATG"; //Antigua y Barbuda
      case "AI": return "AIA"; //Anguila
      case "AL": return "ALB"; //Albania
      case "AM": return "ARM"; //Armenia
      case "AN": return "ANT"; //Antillas Holandesas
      case "AO": return "AGO"; //Angola
      case "AQ": return "ATA"; //Antártida
      case "AR": return "ARG"; //Argentina
      case "AS": return "ASM"; //Samoa Americana
      case "AT": return "AUT"; //Austria
      case "AU": return "AUS"; //Australia
      case "AW": return "ABW"; //Aruba
      case "AX": return "ALA"; //Islas Aland
      case "AZ": return "AZE"; //Azerbaiyán
      case "BA": return "BIH"; //Bosnia y Herzegovina
      case "BB": return "BRB"; //Barbados
      case "BD": return "BGD"; //Bangladés
      case "BE": return "BEL"; //Bélgica
      case "BF": return "BFA"; //Burkina Faso
      case "BG": return "BGR"; //Bulgaria
      case "BH": return "BHR"; //Baréin
      case "BI": return "BDI"; //Burundi
      case "BJ": return "BEN"; //Benín
      case "BL": return "BLM"; //San Bartolomé
      case "BM": return "BMU"; //Bermudas
      case "BN": return "BRN"; //Brunéi
      case "BO": return "BOL"; //Bolivia
      case "BQ": return "BES"; //Caribe Neerlandés
      case "BR": return "BRA"; //Brasil
      case "BS": return "BHS"; //Bahamas
      case "BT": return "BTN"; //Bután
      case "BV": return "BVT"; //Isla Bouvet
      case "BW": return "BWA"; //Botsuana
      case "BY": return "BLR"; //Bielorrusia
      case "BZ": return "BLZ"; //Belice
      case "CA": return "CAN"; //Canadá
      case "CC": return "CCK"; //Islas Cocos
      case "CD": return "COD"; //República Democrática del Congo
      case "CF": return "CAF"; //República Centroafricana
      case "CG": return "COG"; //República del Congo
      case "CH": return "CHE"; //Suiza
      case "CI": return "CIV"; //Costa de Marfil
      case "CK": return "COK"; //Islas Cook
      case "CL": return "CHL"; //Chile
      case "CM": return "CMR"; //Camerún
      case "CN": return "CHN"; //China
      case "CO": return "COL"; //Colombia
      case "CR": return "CRI"; //Costa Rica
      case "CS": return "SCG"; //Serbia y Montenegro
      case "CU": return "CUB"; //Cuba
      case "CV": return "CPV"; //Cabo Verde
      case "CW": return "CUW"; //Curazao
      case "CX": return "CXR"; //Isla de Navidad
      case "CY": return "CYP"; //Chipre
      case "CZ": return "CZE"; //República Checa
      case "DE": return "DEU"; //Alemania
      case "DJ": return "DJI"; //Yibuti
      case "DK": return "DNK"; //Dinamarca
      case "DM": return "DMA"; //Dominica
      case "DO": return "DOM"; //República Dominicana
      case "DZ": return "DZA"; //Argelia
      case "EC": return "ECU"; //Ecuador
      case "EE": return "EST"; //Estonia
      case "EG": return "EGY"; //Egipto
      case "EH": return "ESH"; //Sahara Occidental
      case "ER": return "ERI"; //Eritrea
      case "ES": return "ESP"; //España
      case "ET": return "ETH"; //Etiopía
      case "FI": return "FIN"; //Finlandia
      case "FJ": return "FJI"; //Fiyi
      case "FK": return "FLK"; //Islas Malvinas
      case "FM": return "FSM"; //Micronesia
      case "FO": return "FRO"; //Islas Feroe
      case "FR": return "FRA"; //Francia
      case "GA": return "GAB"; //Gabón
      case "GB": return "GBR"; //Reino Unido
      case "GD": return "GRD"; //Granada
      case "GE": return "GEO"; //Georgia
      case "GF": return "GUF"; //Guayana Francesa
      case "GG": return "GGY"; //Guernsey
      case "GH": return "GHA"; //Ghana
      case "GI": return "GIB"; //Gibraltar
      case "GL": return "GRL"; //Groenlandia
      case "GM": return "GMB"; //Gambia
      case "GN": return "GIN"; //Guinea
      case "GP": return "GLP"; //Guadalupe
      case "GQ": return "GNQ"; //Guinea Ecuatorial
      case "GR": return "GRC"; //Grecia
      case "GS": return "SGS"; //Islas Georgias del Sur y Sandwich del Sur
      case "GT": return "GTM"; //Guatemala
      case "GU": return "GUM"; //Guam
      case "GW": return "GNB"; //Guinea-Bisáu
      case "GY": return "GUY"; //Guyana
      case "HK": return "HKG"; //Hong Kong
      case "HM": return "HMD"; //Islas Heard y McDonald
      case "HN": return "HND"; //Honduras
      case "HR": return "HRV"; //Croacia
      case "HT": return "HTI"; //Haití
      case "HU": return "HUN"; //Hungría
      case "ID": return "IDN"; //Indonesia
      case "IE": return "IRL"; //Irlanda
      case "IL": return "ISR"; //Israel
      case "IM": return "IMN"; //Isla de Man
      case "IN": return "IND"; //India
      case "IO": return "IOT"; //Territorio Británico del Océano Índico
      case "IQ": return "IRQ"; //Irak
      case "IR": return "IRN"; //Irán
      case "IS": return "ISL"; //Islandia
      case "IT": return "ITA"; //Italia
      case "JE": return "JEY"; //Jersey
      case "JM": return "JAM"; //Jamaica
      case "JO": return "JOR"; //Jordania
      case "JP": return "JPN"; //Japón
      case "KE": return "KEN"; //Kenia
      case "KG": return "KGZ"; //Kirguistán
      case "KH": return "KHM"; //Camboya
      case "KI": return "KIR"; //Kiribati
      case "KM": return "COM"; //Comoras
      case "KN": return "KNA"; //San Cristóbal y Nieves
      case "KP": return "PRK"; //Corea del Norte
      case "KR": return "KOR"; //Corea del Sur
      case "KW": return "KWT"; //Kuwait
      case "KY": return "CYM"; //Islas Caimán
      case "KZ": return "KAZ"; //Kazajistán
      case "LA": return "LAO"; //Laos
      case "LB": return "LBN"; //Líbano
      case "LC": return "LCA"; //Santa Lucía
      case "LI": return "LIE"; //Liechtenstein
      case "LK": return "LKA"; //Sri Lanka
      case "LR": return "LBR"; //Liberia
      case "LS": return "LSO"; //Lesoto
      case "LT": return "LTU"; //Lituania
      case "LU": return "LUX"; //Luxemburgo
      case "LV": return "LVA"; //Letonia
      case "LY": return "LBY"; //Libia
      case "MA": return "MAR"; //Marruecos
      case "MC": return "MCO"; //Mónaco
      case "MD": return "MDA"; //Moldavia
      case "ME": return "MNE"; //Montenegro
      case "MF": return "MAF"; //San Martín
      case "MG": return "MDG"; //Madagascar
      case "MH": return "MHL"; //Islas Marshall
      case "MK": return "MKD"; //República de Macedonia
      case "ML": return "MLI"; //Malí
      case "MM": return "MMR"; //Birmania
      case "MN": return "MNG"; //Mongolia
      case "MO": return "MAC"; //Macao
      case "MP": return "MNP"; //Islas Marianas del Norte
      case "MQ": return "MTQ"; //Martinica
      case "MR": return "MRT"; //Mauritania
      case "MS": return "MSR"; //Montserrat
      case "MT": return "MLT"; //Malta
      case "MU": return "MUS"; //Mauricio
      case "MV": return "MDV"; //Maldivas
      case "MW": return "MWI"; //Malaui
      case "MX": return "MEX"; //Mexico
      case "MY": return "MYS"; //Malasia
      case "MZ": return "MOZ"; //Mozambique
      case "NA": return "NAM"; //Namibia
      case "NC": return "NCL"; //Nueva Caledonia
      case "NE": return "NER"; //Níger
      case "NF": return "NFK"; //Norfolk
      case "NG": return "NGA"; //Nigeria
      case "NI": return "NIC"; //Nicaragua
      case "NL": return "NLD"; //Países Bajos
      case "NO": return "NOR"; //Noruega
      case "NP": return "NPL"; //Nepal
      case "NR": return "NRU"; //Nauru
      case "NU": return "NIU"; //Niue
      case "NZ": return "NZL"; //Nueva Zelanda
      case "OM": return "OMN"; //Omán
      case "PA": return "PAN"; //Panamá
      case "PE": return "PER"; //Perú
      case "PF": return "PYF"; //Polinesia Francesa
      case "PG": return "PNG"; //Papúa Nueva Guinea
      case "PH": return "PHL"; //Filipinas
      case "PK": return "PAK"; //Pakistán
      case "PL": return "POL"; //Polonia
      case "PM": return "SPM"; //San Pedro y Miquelón
      case "PN": return "PCN"; //Islas Pitcairn
      case "PR": return "PRI"; //Puerto Rico
      case "PS": return "PSE"; //Estado de Palestina
      case "PT": return "PRT"; //Portugal
      case "PW": return "PLW"; //Palaos
      case "PY": return "PRY"; //Paraguay
      case "QA": return "QAT"; //Catar
      case "RE": return "REU"; //Reunión
      case "RO": return "ROU"; //Rumania
      case "RS": return "SRB"; //Serbia
      case "RU": return "RUS"; //Rusia
      case "RW": return "RWA"; //Ruanda
      case "SA": return "SAU"; //Arabia Saudita
      case "SB": return "SLB"; //Islas Salomón
      case "SC": return "SYC"; //Seychelles
      case "SD": return "SDN"; //Sudán
      case "SE": return "SWE"; //Suecia
      case "SG": return "SGP"; //Singapur
      case "SH": return "SHN"; //Santa Helena
      case "SI": return "SVN"; //Eslovenia
      case "SJ": return "SJM"; //Svalbard y Jan Mayen
      case "SK": return "SVK"; //Eslovaquia
      case "SL": return "SLE"; //Sierra Leona
      case "SM": return "SMR"; //San Marino
      case "SN": return "SEN"; //Senegal
      case "SO": return "SOM"; //Somalia
      case "SR": return "SUR"; //Surinam
      case "SS": return "SSD"; //Sudán del Sur
      case "ST": return "STP"; //Santo Tomé y Príncipe
      case "SV": return "SLV"; //El Salvador
      case "SX": return "SXM"; //Sint Maarten
      case "SY": return "SYR"; //Siria
      case "SZ": return "SWZ"; //Suazilandia
      case "TC": return "TCA"; //Islas Turcas y Caicos
      case "TD": return "TCD"; //Chad
      case "TF": return "ATF"; //Territorios Australes Franceses
      case "TG": return "TGO"; //Togo
      case "TH": return "THA"; //Tailandia
      case "TJ": return "TJK"; //Tayikistán
      case "TK": return "TKL"; //Tokelau
      case "TL": return "TLS"; //Timor Oriental
      case "TM": return "TKM"; //Turkmenistán
      case "TN": return "TUN"; //Túnez
      case "TO": return "TON"; //Tonga
      case "TR": return "TUR"; //Turquía
      case "TT": return "TTO"; //Trinidad y Tobago
      case "TV": return "TUV"; //Tuvalu
      case "TW": return "TWN"; //Taiwán
      case "TZ": return "TZA"; //Tanzania
      case "UA": return "UKR"; //Ucrania
      case "UG": return "UGA"; //Uganda
      case "UM": return "UMI"; //Islas ultramarinas de Estados Unidos
      case "US": return "USA"; //Estados Unidos
      case "UY": return "URY"; //Uruguay
      case "UZ": return "UZB"; //Uzbekistán
      case "VA": return "VAT"; //Ciudad del Vaticano
      case "VC": return "VCT"; //San Vicente y las Granadinas
      case "VE": return "VEN"; //Venezuela
      case "VG": return "VGB"; //Islas Vírgenes Británicas
      case "VI": return "VIR"; //Islas Vírgenes de los Estados Unidos
      case "VN": return "VNM"; //Vietnam
      case "VU": return "VUT"; //Vanuatu
      case "WF": return "WLF"; //Wallis y Futuna
      case "WS": return "WSM"; //Samoa
      case "XK": return "XKX"; //Kosovo
      case "YE": return "YEM"; //Yemen
      case "YT": return "MYT"; //Mayotte
      case "ZA": return "ZAF"; //Sudáfrica
      case "ZM": return "ZMB"; //Zambia
      case "ZW": return "ZWE"; //Zimbabue
      // default: throw new Exception("Error while formatting country($country).");
      default: return 'ERROR';
    };
  }

  public static function format_currency($currency=NULL) {
    switch(strtoupper($currency)) {
      case 'ARS': return '032';
      // case 'AR': return '032'; // comentado el 2018-12-11, no parece usarse [AA]
      case 'USD': return '840';
      // case 'US': return '840'; // comentado el 2018-12-11, no parece usarse [AA]
      case 'CLP': return '152'; // pesos chilenos
      case 'COP': return '170'; //"Pesos Colombianos"
      // case '': return '350'; //??
      case 'MXN': return '484'; //"Pesos Mexicanos"
      case 'PYG': return '600'; //"Guaranies"
      case 'PEN': return '604'; //"Nuevos Soles Peruanos"
      case 'UYU': return '858'; //"Pesos Uruguayos"
      case 'VEB': return '862'; //"Bolivares Venezolanos"
      case 'VEF': return '937'; //"Bolivares Fuertes Venezolanos"
      case 'BRL': return '986'; //"Reales Brasileños"
      case 'CAD': return '124'; //"Dolares Canadienses"
      case 'EUR': return '978'; //"Euro"
      case 'CRC': return '188'; //"Colon Costarricense"
      case 'DOP': return '214'; //"Pesos Dominicanos"
      // case '': return '218'; //"Sucres Ecuatorianos"
      case 'SVC': return '222'; //"Colones Salvadoreños"
      case 'GTQ': return '320'; //"Quetzales Guatemaltecos"
      case 'HNL': return '340'; //"Lempiras Hondureños"
      case 'NIC': return '558'; //"Córdobas Oro Nicaragüenses"
      case 'PAB': return '590'; //"Balboas Panameñas"
      case 'GBP': return '826'; //"Libras Esterlinas"
      // default: throw new Exception('Error while formatting currency.');
      default: return 'ERROR';
    };
  }

  protected function getViewTransactionUrl() {
    switch(true) {
      case strpos($this->get_option( 'url' ), "localhost") !== FALSE:
        $host = 'psp-backend.localhost';
        break;
      case strpos($this->get_option( 'url' ), "sandbox") !== FALSE:
        $host = 'bosandbox.nps.com.ar';
        break;
      case strpos($this->get_option( 'url' ), "implementacion") !== FALSE:
        $host = 'boimplementacion.nps.com.ar';
        break;
      case strpos($this->get_option( 'url' ), "services") !== FALSE:
      default:
        $host = 'panel.nps.com.ar';
        break;
    }

    return "https://$host/index.php/es/transactions/%s/viewDetails";
  }

  /**
   * Initialise Gateway Settings Form Fields
   */
  public function init_form_fields() {
    $this->form_fields = include( 'settings-nps.php' );
  }

  protected function checkWSDL() {
    if ( is_cart() || is_checkout() || is_add_payment_method_page() ) {
      $url_info = parse_url($this->get_option('url'));
      $base_url = $url_info['scheme'] . '://' . $url_info['host'];
      $context = stream_context_create([
        'ssl' => [
          // set some SSL/TLS specific options
          'verify_peer' => false,
          'verify_peer_name' => false,
          'allow_self_signed' => true
        ]
      ]);

      if (!(($contents = @file_get_contents("$base_url/ws.php?wsdl", false, $context)) && stripos($contents, "targetNamespace") !== FALSE)) {
        $this->log("Error: WSDL file from NPS is unreachable. Payment method unavailable.");
        return false;
      }
    }
    return true;
  }

  /**
   * Logs
   *
   * @since 3.1.0
   * @version 3.1.0
   *
   * @param string $message
   */
  public function log( $message, $logging=true ) {
    // if ( $this->logging ) {
    if($logging) {
      WC_Nps::log( $message );
    }
    // }
  }

  public function is_simple_checkout() {
    return $this->nps_mode == self::NPS_MODE_SIMPLE_CHECKOUT;
  }

  public function filter_woocommerce_endpoint_order_received_title($title, $endpoint) {
    if( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
      $order = wc_get_order( wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) ) );
      if($order->needs_payment()) {
        $title = "Payment Rejected";
      }else {
        $title = "Payment Approved";
      }
    }
    return $title;
  }

  /**
   * Get_icon function.
   *
   * @access public
   * @return string
   */
  public function get_icon() {

  }

  /**
   * Check if SSL is enabled and notify the user
   */
  public function admin_notices() {
    if ( 'no' === $this->enabled ) {
      return;
    }
  }

  /**
   * Check if this gateway is enabled
   */
  public function is_available() {
    if ( 'yes' === $this->enabled ) {
      if ( ! $this->secret_key ) {
        return false;
      }
      return true;
    }
    return false;
  }

  /**
   * Payment form on checkout page
   */
  public function payment_fields() {
    /**
     * this is required for a known bug causing payment_fields to execute twice
     * first time as no-ajax and the next one as AJAX
     * https://github.com/woocommerce/woocommerce/issues/7226
     */
    if(!is_ajax() && !is_checkout_pay_page()) {
      return;
    }

    $user                 = wp_get_current_user();
    $display_tokenization = $this->supports( 'tokenization' ) && is_checkout() && $this->saved_cards && !$this->is_simple_checkout();
    $total                = WC()->cart->total;

    // If paying from order, we need to get total from order not cart.
    if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) {
      $order = wc_get_order( wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) ) );
      $total = $order->get_total();
    }

    if ( $user->ID ) {
      $user_email = get_user_meta( $user->ID, 'billing_email', true );
      $user_email = $user_email ? $user_email : $user->user_email;
    } else {
      $user_email = '';
    }

    if ( is_add_payment_method_page() ) {
      $pay_button_text = __( 'Add Card', 'woocommerce-gateway-nps' );
      $total        = '';
    } else {
      $pay_button_text = '';
    }

    echo '<div
			id="nps-payment-data"
			data-panel-label="' . esc_attr( $pay_button_text ) . '"
			data-description=""
			data-email="' . esc_attr( $user_email ) . '"
			data-amount="' . esc_attr( $this->get_nps_amount( $total ) ) . '"
			data-name="' . esc_attr( $this->statement_descriptor ) . '"
			data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '"
			data-allow-remember-me="' . esc_attr( $this->saved_cards ? 'true' : 'false' ) . '">';

    echo '<input type="hidden"  data-nps="exp_date_format" value="MM/YY"/>';

    if ( $this->description ) {
      echo apply_filters( 'wc_nps_description', wpautop( wp_kses_post( $this->description ) ) );
    }

    if ( $display_tokenization ) {
      $this->tokenization_script();
      if($this->id == 'nps') {
        $this->saved_payment_methods();
      }
    }

    $this->form();

    try {
      if(is_user_logged_in()) {
        $nps_customer = new WC_Nps_Customer(get_current_user_id(), $this->url, $this->secret_key, $this->merchant_id );
        if ( ! $nps_customer->get_id() ) {
          if ( ( $response = $nps_customer->create_customer() ) && is_wp_error( $response ) ) {
            // return $response;
            // no quiero penalizar la posible compra por el save card.
          }
        }


        if($this->saved_cards && !is_add_payment_method_page() && $nps_customer->get_id()) {
          $this->save_payment_method_checkbox();
        }
      }
    }catch(Exception $ex) {}

    echo '</div>';
  }

  /**
   * Get Nps amount to pay
   *
   * @param float  $total Amount due.
   * @param string $currency Accepted currency.
   *
   * @return float|int
   */
  public function get_nps_amount( $total, $currency = '' ) {
    if ( ! $currency ) {
      $currency = get_woocommerce_currency();
    }
    switch ( strtoupper( $currency ) ) {
      // Zero decimal currencies.
      case 'BIF' :
      case 'CLP' :
      case 'DJF' :
      case 'GNF' :
      case 'JPY' :
      case 'KMF' :
      case 'KRW' :
      case 'MGA' :
      case 'PYG' :
      case 'RWF' :
      case 'VND' :
      case 'VUV' :
      case 'XAF' :
      case 'XOF' :
      case 'XPF' :
        $total = round( $total, 0 ) * 100; // In cents. Zero decimal
        break;
      default :
        $total = round( $total, 2 ) * 100; // In cents. Two decimal
        break;
    }
    return $total;
  }

  /**
   * Credit card form.
   *
   * @param  array $args
   * @param  array $fields
   */
  public function form() {
    wp_enqueue_script( 'wc-credit-card-form' );

    $fields = array();

    $fields_have_names = $this->is_direct_payment() ? true : false;


    $total        = WC()->cart->total;
    $wc_currency  = wc_clean( strtoupper( get_woocommerce_currency() ) );
    $wc_country   = wc_clean( strtoupper( wc_get_base_location()['country'] ) );

    if($order = @$this->order) {
      $total = $order->get_total();
      $wc_currency = strtolower( version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->get_order_currency() : $order->get_currency() );
    }

    $attr_name_card_holder_name = $fields_have_names ? ( 'name="' . $this->id . '-card-holder-name' . '"' ) : NULL;
    $attr_name_card_number = $fields_have_names ? ( 'name="' . $this->id . '-card-number' . '"' ) : NULL;
    $attr_name_card_expiry = $fields_have_names ? ( 'name="' . $this->id . '-card-expiry' . '"' ) : NULL;
    $attr_name_card_cvc    = $fields_have_names ? ( 'name="' . $this->id . '-card-cvc' . '"' ) : NULL;

    $default_fields = array(
      'card-brand-field' => '<p class="form-row form-row-wide hide-if-saved-card">
				<label for="' . esc_attr( $this->id ) . '-card-brand">' . __( 'Card Brand', 'woocommerce' ) . ' <span class="required">*</span></label>
        <select id="' . esc_attr( $this->id ) . '-card-brand" class="wc-credit-card-form-card-brand" name="' . ( $this->id . '-card-brand' ) . '" >  
				' . $this->renderCardBrandChoices() . '</select>
			</p>',
      'card-number-field' => '<p class="form-row form-row-wide hide-if-saved-card">
				<label for="' . esc_attr( $this->id ) . '-card-number">' . __( 'Card Number', 'woocommerce' ) . ' <span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="•••• •••• •••• ••••"  '.$attr_name_card_number.' data-nps="card[number]" />
			</p>',
      'card-expiry-field' => '<p class="form-row form-row-first hide-if-saved-card">
				<label for="' . esc_attr( $this->id ) . '-card-expiry">' . __( 'Card Expiry (MM/YY)', 'woocommerce' ) . ' <span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="' . esc_attr__( 'MM / YY', 'woocommerce' ) . '" '.$attr_name_card_expiry.' data-nps="card[exp_date]" />
			</p>',
    );

    if($this->is_card_holder_name_required() && !$this->is_simple_checkout())  {

      $default_fields = array_slice($default_fields, 0, 1, true) +
        array("card-holder-name-field" => '<p class="form-row form-row-wide hide-if-saved-card">
				<label for="' . esc_attr( $this->id ) . '-card-holder-name">' . __( 'Card Holder Name', 'woocommerce' ) . ' <span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-holder-name" class="input-text wc-credit-card-form-card-holder-name" type="text" maxlength="20" autocomplete="off" placeholder="Name On Card"  '.$attr_name_card_holder_name.' data-nps="card[holder_name]" />
			</p>') +
        array_slice($default_fields, 1, count($default_fields) - 1, true) ;


    }

    if(is_checkout()) {
      $default_fields['card-cvc-field'] = '<p class="form-row form-row-last">
				<label for="' . esc_attr( $this->id ) . '-card-cvc">' . __( 'Card Code', 'woocommerce' ) . ' <span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . esc_attr__( 'CVC', 'woocommerce' ) . '" '.$attr_name_card_cvc.' data-nps="card[security_code]"  />
			</p>';
      $default_fields['card-numpayments-field'] = '<p class="form-row form-row-wide">
        <label for="' . esc_attr( $this->id ) . '-card-numpayments">' . __( 'Installments', 'woocommerce-nps' ) . ' <span class="required">*</span></label>
        <select id="' . esc_attr( $this->id ) . '-card-numpayments" class="wc-credit-card-form-card-numpayments" name="' . ( $this->id . '-card-numpayments' ) . '" >
        ' . $this->renderInstallmentChoices(@$_REQUEST['nps-card-brand']) . '</select>
        </p>';
    }

    if(is_checkout() && $this->is_simple_checkout()) {
      unset($default_fields['card-cvc-field'],$default_fields['card-number-field'],$default_fields['card-expiry-field']);
    }



    $fields = wp_parse_args( $fields, apply_filters( 'woocommerce_credit_card_form_fields', $default_fields, $this->id ) );
    ?>
      <fieldset id="<?php echo $this->id; ?>-cc-form">
        <?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>
        <?php
        foreach ( $fields as $field ) {
          echo $field;
        }
        ?>
        <?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
          <div class="clear"></div>
      </fieldset>
    <?php
  }

  public function is_direct_payment() {
    return $this->nps_mode == self::NPS_MODE_DIRECT_PAYMENT;
  }

  protected function renderCardBrandChoices() {
    $card_brand_choices[] = '<option value="">' . __('Choose an option', 'woocommerce-nps' ) . '</option>';
    $wc_currency  = wc_clean( strtoupper( get_woocommerce_currency() ) );
    $wc_country   = wc_clean( strtoupper( wc_get_base_location()['country'] ) );
    $installments = $this->searchInstallments($brand=NULL, $country=self::format_country($wc_country), $currency=self::format_currency($wc_currency));
    if(count($installments)) {
      foreach($installments as $installment) {
        $product_id = $installment['card'];
        $desc = self::format_card_brand($product_id);
        $card_brand_choices[$product_id] = '<option value="' . esc_attr( $product_id ) . '">' . esc_attr( $desc ) . '</option>';
      }
    }
    return join('',$card_brand_choices);
  }

  public static function format_card_brand($brand=NULL) {
    switch(strtoupper($brand)) {
      case 1: return 'American Express';
      case 2: return 'Diners';
      case 4: return 'JCB';
      case 5: return 'Mastercard';
      case 8: return 'Cabal';
      case 9: return 'Naranja';
      case 10: return 'Kadicard';
      case 14: return 'Visa';
      case 15: return 'Favacard';
      case 17: return 'Lider';
      case 20: return 'Credimas';
      case 21: return 'Nevada';
      case 29: return 'Visa Naranja';
      case 33: return 'Patagonia 365';
      case 34: return 'Sol';
      case 35: return 'CMR Falabella';
      case 38: return 'Nativa MC';
      case 42: return 'Tarjeta Shopping';
      case 43: return 'Italcred';
      case 45: return 'Club La Nacion';
      case 46: return 'Club Personal';
      case 47: return 'Club Arnet';
      case 48: return 'Mas(cencosud)';
      case 49: return 'Naranja MO';
      case 50: return 'Pyme Nacion';
      case 51: return 'Clarin 365';
      case 52: return 'Club Speedy';
      case 53: return 'Argenta';
      case 55: return 'Visa Debito';
      case 57: return 'MC Bancor';
      case 58: return 'Club La Voz';
      case 61: return 'Nexo';
      case 63: return 'NATIVA';
      case 65: return 'Argencard';
      case 66: return 'Maestro';
      case 69: return 'Cetelem';
      case 72: return 'Consumax';
      case 75: return 'Mira';
      case 91: return 'Credi Guia';
      case 93: return 'Sucredito';
      case 95: return 'Coopeplus';
      case 101: return 'Discover';
      case 102: return 'Elo';
      case 103: return 'Magna';
      case 104: return 'Aura';
      case 105: return 'Hipercard';
      case 106: return 'Credencial COL';
      case 107: return 'RedCompra';
      case 108: return 'SuperCard';
      case 110: return 'BBPS';
      case 112: return 'Ripley';
      case 113: return 'OH!';
      case 114: return 'Metro';
      case 115: return 'UnionPay';
      case 116: return 'Hiper';
      case 117: return 'Carrefour';
      case 118: return 'Grupar';
      case 119: return 'Tuya';
      case 120: return 'Club Dia';
      case 121: return 'CTC Group';
      case 122: return 'Qida';
      case 123: return 'Codensa';
      case 124: return 'Socios BBVA';
      case 125: return 'UATP';
      case 126: return 'Credz';
      case 127: return 'WebPay';
      case 128: return 'Comfama';
      case 129: return 'Colsubsidio';
      case 130: return 'Carnet';
      case 131: return 'Carnet Debit';
      case 132: return 'Ultra';
      case 133: return 'Elebar';
      case 134: return 'Carta Automatica';
      case 135: return 'Don Credito';

      // default: throw new Exception("Error while formatting card_brand($brand).");
      default: return 'ERROR';
    };
  }

  public function is_card_holder_name_required() {
    return $this->require_card_holder_name;
  }

  protected function renderInstallmentChoices($brand) {
    $wc_currency  = wc_clean( strtoupper( get_woocommerce_currency() ) );
    $wc_country   = wc_clean( strtoupper( wc_get_base_location()['country'] ) );
    $installments = $this->searchInstallments($brand, $country=self::format_country($wc_country), $currency=self::format_currency($wc_currency));
    $installment_choices = '';
    $total = '';
    if(!$brand) {
      $installment_choices = '<option value="">' . __('Choose a card first', 'woocommerce-nps' ) . '</option>';
    }else if ( is_array( $installments ) && count( $installments )) {
      $installment_choices = '<option value="">' . __('Choose an option', 'woocommerce-nps' ) . '</option>';
      foreach ( $installments as $installment ) {
        $desc = '(' . $wc_currency . ')' . $total . ' as ' . esc_attr( $installment['installment'] ) . ' installments';
        $installment_choices .= '<option value="' . esc_attr( $installment['installment'] ) . '">' . esc_attr( $desc ) . '</option>';
      }
    }else {
      $installment_choices = '<option value="">' . __('Not found', 'woocommerce-nps' ) . '</option>';
    }
    return $installment_choices;
  }

  /**
   * Load admin scripts.
   *
   * @since 3.1.0
   * @version 3.1.0
   */
  public function admin_scripts() {
    if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
      return;
    }

    $nps_admin_params = array(
      'localized_messages' => array(
        'missing_secret_key'     => __( 'Missing Secret Key. Please set the secret key field above and re-try.', 'woocommerce-gateway-nps' ),
      ),
      'ajaxurl'            => admin_url( 'admin-ajax.php' ),
      'nonce'              => array(

      ),
    );

    wp_localize_script( 'woocommerce_nps_admin', 'wc_nps_admin_params', apply_filters( 'wc_nps_admin_params', $nps_admin_params ) );
  }

  public function add_installment_fee($cart_data) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) )
      return;
  }

  public function cc_display_name($asd) {
    exit("pase por aca");
  }

  /**
   * payment_scripts function.
   *
   * Outputs scripts used for nps payment
   *
   * @access public
   */
  public function payment_scripts() {
    if ( ! is_cart() && ! is_checkout() && ! is_add_payment_method_page() ) {
      return;
    }

    $include_npsjs = $this->is_advance_checkout() || ($this->is_simple_checkout() && is_add_payment_method_page());


    if( $include_npsjs ) {

      $url_info = parse_url($this->url);
      $base_url = $url_info['scheme'] . '://' . $url_info['host'];
      $context = stream_context_create([
        'ssl' => [
          // set some SSL/TLS specific options
          'verify_peer' => false,
          'verify_peer_name' => false,
          'allow_self_signed' => true
        ]
      ]);

      if( !( ($contents = @file_get_contents("$base_url/sdk/v1/NPS.js", false, $context)) && stripos($contents, "NPS.") !== FALSE) ) {
        $this->log( "Error: External file NPS.js from NPS is unreachable. Advance Checkout may not work." );
      }else {
        wp_enqueue_script( 'nps_advance_checkout', "$base_url/sdk/v1/NPS.js");
      }
      wp_enqueue_script( 'woocommerce_nps', plugins_url( 'assets/js/NPS.js', WC_NPS_MAIN_FILE ), array('jquery','jquery-payment', 'nps_advance_checkout'), WC_NPS_VERSION, true );
    }
    if(!is_add_payment_method_page()) {
      wp_enqueue_script( 'nps_saved_cards', plugins_url( 'assets/js/NPS-saved-cards.js', WC_NPS_MAIN_FILE ), array('jquery','wc-credit-card-form'), WC_NPS_VERSION, true );
      wp_enqueue_script( 'nps_installments', plugins_url( 'assets/js/NPS-installments.js', WC_NPS_MAIN_FILE ), array('jquery','wc-credit-card-form'), WC_NPS_VERSION, true );
    }

    if( $include_npsjs
      && (is_add_payment_method_page() || is_checkout())
      && !(is_order_received_page()) ) {
      $total        = WC()->cart->total;
      $wc_currency  = wc_clean( strtoupper( get_woocommerce_currency() ) );
      $wc_country   = wc_clean( strtoupper( wc_get_base_location()['country'] ) );


      /*
       * https://jira.techno.ingenico.com/browse/NPS-1526
       *
       * $sdk = new Sdk();
      $response_createClientSession = $sdk->createClientSession(array(
        'psp_Version'          => '2.2',
        'psp_MerchantId'       => $this->merchant_id,
        'psp_PosDateTime'      => date('Y-m-d H:i:s'),
      ));
       *
       * $nps_params['clientSession'] = $response_createClientSession->psp_ClientSession;
       *
       */

      $nps_params['merchantId'] = $this->merchant_id;
      $nps_params['amount'] = $this->get_nps_amount($total, $wc_currency);
      $nps_params['country'] = self::format_country($wc_country);
      $nps_params['currency'] = self::format_currency($wc_currency);

      // merge localized messages to be use in JS
      $nps_params = array_merge( $nps_params, $this->get_localized_messages() );

      wp_localize_script( 'woocommerce_nps', 'wc_nps_params', apply_filters( 'wc_nps_params', $nps_params ) );
    }
  }

  public function is_advance_checkout() {
    return $this->nps_mode == self::NPS_MODE_ADVANCE_CHECKOUT;
  }

  /**
   * Localize Nps messages based on code
   *
   * @since 3.0.6
   * @version 3.0.6
   * @return array
   */
  public function get_localized_messages() {
    return apply_filters( 'wc_nps_localized_messages', array(

    ) );
  }

  /**
   * Process the payment
   *
   * @param int  $order_id Reference.
   * @param bool $retry Should we retry on fail.
   * @param bool $force_customer Force user creation.
   *
   * @throws Exception If payment will not be accepted.
   *
   * @return array|void
   *
   *
   * el boton place order de la pagina checkout me trae a este metodo
   *
   *
   */
  public function process_payment( $order_id, $retry = true, $force_customer = false ) {
    $order  = wc_get_order( $order_id );
    $source = $this->get_source( get_current_user_id(), $force_customer );
    $exceptionMissData = new Exception( __( 'Please enter your card details to make a payment.', 'woocommerce-gateway-nps' ) );

    if($this->is_advance_checkout() || @$_REQUEST['payment_method'] == 'nps-wallet') {
      if(!@$_REQUEST['npsPaymentMethodTokenId']) {
        $this->log( "Error: npsPaymentMethodToken is missing.");
        if(is_checkout_pay_page()) {
          wc_add_notice('<p>' . __( 'There was an error processing the payment. Please try again later.', 'woocommerce-gateway-nps' ) . '</p>' ,'error');
          return array(
            'result'   => 'fail',
            'redirect' => '',
          );
        }else {
          throw new Exception( __( NPS_ERR_WILDCARD, 'woocommerce-gateway-nps' ) );
        }
      }
    }else if($this->is_direct_payment()) {
      $is_saved_card = (isset($_REQUEST['wc-nps-payment-token']) && $_REQUEST['wc-nps-payment-token'] != 'new');

      if(!$is_saved_card && ($this->is_card_holder_name_required() && !@$_REQUEST['nps-card-holder-name'])) {
        throw $exceptionMissData;
      }
    }

    if(empty( $source->source )
      || ($this->is_installment_enabled() && !@$_REQUEST['nps-card-numpayments'])) {
      throw $exceptionMissData;
    }

    // Store source to order meta.
    $this->save_source( $order, $source );

    try {
      // Result from Nps API request.
      $response = null;

      // Handle payment.
      if ( $order->get_total() > 0 ) {
        $this->log( "Info: Begin processing payment for order $order_id for the amount of {$order->get_total()}" );


        if((@$_REQUEST['payment_method'] == 'nps' && ('new' === @$_REQUEST['wc-nps-payment-token'] && !@$_REQUEST['nps-card-brand']))
          || ($this->is_direct_payment() && (!(@$_REQUEST['npsPaymentMethodTokenId'] || @$_REQUEST['wc-nps-payment-token']) && ($this->is_card_holder_name_required() && !@$_REQUEST['nps-card-holder-name'])))
          || ($this->is_installment_enabled() && !@$_REQUEST['nps-card-numpayments'])) {
          throw $exceptionMissData;
        }

        switch(true) {
          case ($this->is_installment_enabled() && !@$_REQUEST['nps-card-numpayments']):
          case ($this->is_wallet_checkout() && !(@$_REQUEST['WalletType'] && @$_REQUEST['WalletKey'])):
          case (@$_REQUEST['payment_method']  == 'nps' && ('new' === @$_REQUEST['wc-nps-payment-token'] && !@$_REQUEST['nps-card-brand'])):
            throw new $exceptionMissData;
        }



        if($this->is_simple_checkout()) {

          // Return thank you page redirect.
          return array(
            'result' 	 => 'success',
            'redirect'	 => $order->get_checkout_payment_url( true ),
          );
        }

        // Make the request.
        try{
          $sdk = new Sdk();
          $method = $this->get_gateway_payment_method();
          $request = $this->generate_payment_request( $order, $source );
          $this->log( "Processing $method request: " . print_r( $request, true ), $this->logging );
          $response = $sdk->$method($this->generate_payment_request( $order, $source ));
          $this->log( "Processing $method response: " . print_r( $response, true ), $this->logging );
        }catch(ApiException $e){
          $response = false;
        }


        if ( ctype_digit(@$response->psp_ResponseCod) && @$response->psp_ResponseCod != "0" ) {
          $localized_messages = $this->get_localized_messages();

          $message = isset( $localized_messages[ $response->psp_ResponseCod ] ) ? $localized_messages[ $response->psp_ResponseCod ] : $response->psp_ResponseMsg;

          $order->add_order_note( $message );

          if($this->id == 'nps-wallet') {
            wc_add_notice(
              '<p>' . __( 'There was an error processing the payment. Please try again later.', 'woocommerce-gateway-nps' ) . '<br>' .
              // $response->psp_ResponseExtended .
              '</p>' .
              '<p><a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' .
              __( 'Click to try again', 'woocommerce-gateway-nps' ) .
              '</a></p>',
              'error'
            );
            return array(
              'result' => 'success',
              'redirect' => $order->get_checkout_payment_url( true )
            );
          }else {
            throw new Exception( $message );
          }

        }


        // $this->orderAddFeeInstallment($order, @$response->psp_Amount);

        // Process valid response.
        $this->process_response( $response, $order );

        // $order->calculate_totals(false);
      } else {
        $order->payment_complete();
      }

      // Remove cart.
      WC()->cart->empty_cart();

      do_action( 'wc_gateway_nps_process_payment', $response, $order );


      $this->add_payment_method_from_nps_payment_id($order, @$response->psp_TransactionId);


      // wc_add_notice( __(NPS_NOTICE_PAYMENT_APPROVED, 'woocommerce-gateway-nps') );

      // Return thank you page redirect.
      return array(
        'result'   => 'success',
        'redirect' => $this->get_return_url( $order ),
      );

    } catch ( Exception $e ) {
      if(@$response->psp_TransactionId) {
        wc_add_notice( __( @$response->psp_ResponseMsg, 'woocommerce-gateway-nps' ), 'error' );
      }else {
        wc_add_notice( __( NPS_ERR_WILDCARD, 'woocommerce-gateway-nps' ), 'error' );
      }
      $this->log( sprintf( __( 'Error: Transaction ID: %d - Reason: %s', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId, $e->getMessage() ) );

      if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
        $this->send_failed_order_email( $order_id );
      }

      do_action( 'wc_gateway_nps_process_payment_error', $e, $order );

      return array(
        'result'   => 'fail',
        'redirect' => '',
      );
    }
  }

  /**
   * Get payment source. This can be a new token or existing card.
   *
   * @param string $user_id
   * @param bool  $force_customer Should we force customer creation.
   *
   * @throws Exception When card was not added or for and invalid card.
   * @return object
   */
  protected function get_source( $user_id, $force_customer = false ) {
    $nps_customer = new WC_Nps_Customer( $user_id, $this->url, $this->secret_key, $this->merchant_id );
    $force_customer  = apply_filters( 'wc_nps_force_customer_creation', $force_customer, $nps_customer );
    $nps_source   = false;
    $token_id     = false;

    // New CC info was entered and we have a new token to process
    if ( isset( $_REQUEST['npsPaymentMethodTokenId'] ) ) {

      $token_id = $nps_token = wc_clean( $_REQUEST['npsPaymentMethodTokenId'] );
      $maybe_saved_card = isset( $_REQUEST['wc-nps-new-payment-method'] ) && ! empty( $_REQUEST['wc-nps-new-payment-method'] );

      // This is true if the user wants to store the card to their account.
      if ( ( $user_id && $this->saved_cards && $maybe_saved_card ) || $force_customer ) {
        $nps_source = $nps_customer->add_card( $nps_token );

        if ( is_wp_error( $nps_source ) ) {
          throw new Exception( $nps_source->get_error_message() );
        }
      } else {
        // Not saving token, so don't define customer either.
        $nps_source   = $nps_token;
        $nps_customer = false;
      }
    } elseif ( isset( $_REQUEST['wc-nps-payment-token'] ) && 'new' !== $_REQUEST['wc-nps-payment-token'] ) {

      // Use an existing token, and then process the payment
      $token_id = wc_clean( @$_POST['wc-nps-payment-token'] );
      $token = false;
      $tokens = $this->get_tokens();

      // var_dump($tokens, $_REQUEST['wc-nps-payment-token']);

      foreach($tokens as $t) {
        if($t->get_token() == wc_clean( $_REQUEST['wc-nps-payment-token'] )) {
          $token = $t;
          break;
        }
      }

      // var_dump($token);exit;

      if($this->is_wallet_checkout() && isset($_REQUEST['nps-wallet-pm-not-choosen'])) {
        $this->log( "Error: Choose your wallet before the payment method.");
        throw new Exception( __( "Choose your wallet before the payment method.", 'woocommerce-gateway-nps' ) );
      }

      if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
        WC()->session->set( 'refresh_totals', true );
        throw new Exception( __( 'Invalid payment method. Please input a new card number.', 'woocommerce-gateway-nps' ) );
      }

      $nps_source = $token->get_token();
    } elseif ( $this->is_direct_payment() && (!isset($_REQUEST['wc-nps-payment-token']) || (isset( $_REQUEST['wc-nps-payment-token'] ) && 'new' === $_REQUEST['wc-nps-payment-token']))) {
      $token_id = NULL;
      $nps_source = 'new';
    } else if ($this->is_simple_checkout() && !isset( $_REQUEST['wc-nps-payment-token'] )) {
      $token_id = NULL;
      $nps_source = 'new';
    }

    return (object) array(
      'token_id' => $token_id,
      'customer' => $nps_customer ? $nps_customer->get_id() : false,
      'source'   => $nps_source,
    );
  }

  public function is_wallet_checkout() {
    return $this->is_wallet_enabled() && @$_REQUEST['payment_method']  == 'nps-wallet';
  }

  public function is_wallet_enabled() {
    return $this->wallet_enabled;
  }

  /**
   * Save source to order.
   *
   * @param WC_Order $order For to which the source applies.
   * @param stdClass $source Source information.
   */
  protected function save_source( $order, $source ) {
    $order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();

    // Store source in the order.
    if ( $source->customer ) {
      $this->update_order_meta($order, '_nps_customer_id', $source->customer);
      // version_compare( WC_VERSION, '3.0.0', '<' ) ? update_post_meta( $order_id, '_nps_customer_id', $source->customer ) : $order->update_meta_data( '_nps_customer_id', $source->customer );
    }
    if ( $source->source ) {
      $this->update_order_meta($order, '_nps_card_id', $source->source);
      // version_compare( WC_VERSION, '3.0.0', '<' ) ? update_post_meta( $order_id, '_nps_card_id', $source->source ) : $order->update_meta_data( '_nps_card_id', $source->source );
    }

    if(@$_REQUEST['wc-nps-new-payment-method']) {
      $this->update_order_meta($order, '_maybe_saved_card', @$_REQUEST['wc-nps-new-payment-method']);
    }
    if(@$_REQUEST['nps-card-brand']) {
      $this->update_order_meta($order, '_nps_product_id', @$_REQUEST['nps-card-brand']);
    }
    if(@$_REQUEST['nps-wallet-card-brand']) {
      $this->update_order_meta($order, '_nps_product_id', @$_REQUEST['nps-wallet-card-brand']);
    }
    if(@$_REQUEST['nps-card-numpayments']) {
      $this->update_order_meta($order, '_nps_num_payments', @$_REQUEST['nps-card-numpayments']);
    }
  }

  protected function update_order_meta($order, $attr, $value) {
    $order = is_object($order) ? $order : wc_get_order($order);
    $order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();
    if(version_compare( WC_VERSION, '3.0.0', '<' )) {
      update_post_meta( $order_id, $attr, $value );
    }else {
      $order->update_meta_data( $attr, $value );
      $order->save_meta_data();
    }
  }

  protected function get_gateway_payment_method() {
    switch(true) {
      case $this->is_simple_checkout() && $this->capture == true:
        return 'payOnline3p';
      case $this->is_simple_checkout() && $this->capture == false:
        return 'authorize3p';

      case $this->is_direct_payment() && $this->capture == true:
      case $this->is_advance_checkout() && $this->capture == true:
        return 'payOnline2p';
      case $this->is_direct_payment() && $this->capture == false:
      case $this->is_advance_checkout() && $this->capture == false:
        return 'authorize2p';
    }
  }

  /**
   * Generate the request for the payment.
   * @param  WC_Order $order
   * @param  object $source
   * @return array()
   */
  protected function generate_payment_request( $order, $source ) {
    $order_id = self::get_order_prop( $order, 'id' );
    $post_data                = array();
    $post_data['currency']    = wc_clean( strtoupper( get_woocommerce_currency() ) );
    $post_data['amount']      = $order->get_total();
    $post_data['description'] = sprintf( __( '%1$s - Order %2$s', 'woocommerce-gateway-nps' ), $this->statement_descriptor, $order->get_order_number() );
    $nps_customer = new WC_Nps_Customer( get_current_user_id(), $this->url, $this->secret_key, $this->merchant_id );
    $country = wc_clean( strtoupper( wc_get_base_location()['country'] ) );


    $token = $this->get_token($source->token_id);
    $nps_card_brand_id = $this->get_order_meta($order, '_nps_product_id') ?: $token->get_card_type();

    $installments = $this->searchInstallments($nps_card_brand_id, self::format_country($country), self::format_currency($post_data['currency']), $this->get_order_meta($order, '_nps_num_payments'));
    if(!(is_array($installments) && count($installments))) {
      throw new Exception('Error while setting data for payment.');
    }else {
      $installment = end($installments);
    }

    $psp_post_data = array(
      'psp_Version'          => '2.2',
      'psp_MerchantId'       => $this->merchant_id,
      'psp_TxSource'         => 'WEB',
      // 'psp_MerchTxRef'       => $order_id.'t'.time(),
      'psp_MerchTxRef'         => strtoupper(uniqid($order_id.".", true)),
      'psp_MerchOrderId'     => $order->get_order_number(),
      'psp_Amount'           => strval($this->get_nps_amount( (float)$post_data['amount'] + self::calculateInstallmentCost($this->get_order_meta($order, '_nps_num_payments'), $installment['rate'], $post_data['amount']), $post_data['currency'] )),
      'psp_NumPayments'      => $this->get_order_meta($order, '_nps_num_payments'),
      'psp_Currency'         => self::format_currency($post_data['currency']),
      'psp_Country'          => self::format_country($country),
      'psp_Product'          => $nps_card_brand_id,
      // 'psp_CustomerMail'     => self::get_order_prop( $order, 'billing_email' ),
      'psp_SoftDescriptor'   => $this->statement_descriptor,
      'psp_PosDateTime'      => date('Y-m-d H:i:s'),
      // 'psp_CustomerId'       => get_current_user_id(),
      // 'psp_MerchantMail'     => get_option( 'admin_email' ),
      // 'psp_PurchaseDescription' => substr('ORDER-'.self::get_order_prop( $order, 'id' ),0,255),
    );

    if($this->is_wallet_checkout()) {
      if($source->source) {
        $psp_post_data["psp_VaultReference"]["PaymentMethodToken"] = $source->source;
      }else {
        throw new Exception('Error while setting token for payment.');
      }
    }else if($this->is_advance_checkout()) {
      if((@$_REQUEST['wc-nps-payment-token'] == 'new' || !@$_REQUEST['wc-nps-payment-token']) && $source->token_id) { // Use a new payment method
        $psp_post_data["psp_VaultReference"]["PaymentMethodToken"] = $source->token_id;
      }else if($source->source) { // is a PaymentMethodId so we need to recache
        $psp_post_data["psp_VaultReference"]["PaymentMethodToken"] = $source->source;
      }else {
        throw new Exception('Error while setting token for payment.');
      }
    }else if($this->is_direct_payment()) {
      if(@$_REQUEST['wc-nps-payment-token'] != 'new' && $source->token_id) {
        $psp_post_data["psp_VaultReference"]["PaymentMethodId"] = $source->token_id;
        if(isset($_REQUEST['nps-card-cvc'])) {
          $psp_post_data += array(
            'psp_CardSecurityCode' => $_REQUEST['nps-card-cvc'],
          );
        }
      }elseif ($_REQUEST['nps-card-number']) {
        $card_number = str_replace( ' ', '', $_REQUEST['nps-card-number'] );
        $card_cvc = $_REQUEST['nps-card-cvc'];
        $card_holder_name = @$_REQUEST['nps-card-holder-name'] ? trim( $_REQUEST['nps-card-holder-name'] ) : NULL;
        $exp_date_array = explode( "/", $_REQUEST['nps-card-expiry'] );
        $exp_month = trim( $exp_date_array[0] );
        $exp_year = trim( $exp_date_array[1] );
        $exp_date = substr( $exp_year, -2 ) . $exp_month;
        $psp_post_data += array(
          'psp_CardHolderName'  => $card_holder_name,
          'psp_CardNumber'       => $card_number,
          'psp_CardExpDate'      => $exp_date,
          'psp_CardSecurityCode' => $card_cvc,
        );
      }else {
        throw new Exception('Error while setting card data for payment.');
      }
    }else if($this->is_simple_checkout()) {
      $psp_post_data += array(
        'psp_ReturnURL' => $this->response_url,
        'psp_FrmLanguage' => "en_US",
        'psp_FrmBackButtonURL' => $order->get_checkout_payment_url(),
      );

      if($this->saved_cards && $nps_customer->get_id() && count($nps_customer->get_cards())) {
        $psp_post_data['psp_VaultReference']['CustomerId'] = $nps_customer->get_id();
      }
    }else {
      throw new Exception('Error while setting data for payment.');
    }


    if(self::get_order_prop( $order, 'billing_first_name' )) {
      $psp_post_data['psp_BillingDetails']['Person'] = array(
        'FirstName'=>self::get_order_prop( $order, 'billing_first_name' ),
        'LastName'=>self::get_order_prop( $order, 'billing_last_name' ),
        'PhoneNumber1'=>self::get_order_prop( $order, 'billing_phone')  ?: NULL,
      );
    }

    if(self::get_order_prop( $order, 'billing_address_1' )
      && self::get_order_prop( $order, 'billing_city' )
      && self::get_order_prop( $order, 'billing_country' )
    ){
      $state_name='';
      if(($billing_country = self::get_order_prop( $order, 'billing_country' )) && ($billing_state = self::get_order_prop( $order, 'billing_state' ))) {
        $countries_obj = new WC_Countries();
        $countries_array = $countries_obj->get_countries();
        $country_states_array = $countries_obj->get_states();

        // Get the state name:
        $state_name = @$country_states_array[$billing_country][$billing_state] ?: '';
      }
      $psp_post_data['psp_BillingDetails']['Address'] = array(
        'Street'=>self::get_order_prop( $order, 'billing_address_1' ),
        'HouseNumber'=>self::get_order_prop( $order, 'billing_address_1' ),
        'City'=>self::get_order_prop( $order, 'billing_city' ),
        'StateProvince'=> html_entity_decode($state_name),
        'Country'=>self::format_country(self::get_order_prop( $order, 'billing_country' )),
        'ZipCode'=>self::get_order_prop( $order, 'billing_postcode' ),
        'AdditionalInfo'=> self::get_order_prop( $order, 'billing_address_2' ),
      );
    }

    if(self::get_order_prop( $order, 'shipping_first_name' )) {
      $psp_post_data['psp_ShippingDetails']['PrimaryRecipient'] = array(
        'FirstName'=>self::get_order_prop( $order, 'shipping_first_name' ),
        'LastName'=>self::get_order_prop( $order, 'shipping_last_name' ),
      );
    }

    if(self::get_order_prop( $order, 'shipping_address_1' )
      && self::get_order_prop( $order, 'shipping_city' )
      && self::get_order_prop( $order, 'shipping_country' )
    ){
      $state_name='';
      if(($shipping_country = self::get_order_prop( $order, 'shipping_country' )) && ($shipping_state = self::get_order_prop( $order, 'shipping_state' ))) {
        $countries_obj = new WC_Countries();
        $countries_array = $countries_obj->get_countries();
        $country_states_array = $countries_obj->get_states();

        // Get the state name:
        $state_name = @$country_states_array[$shipping_country][$shipping_state] ?: '';
      }

      $psp_post_data['psp_ShippingDetails']['Address']=array(
        'Street'=>self::get_order_prop( $order, 'shipping_address_1' ),
        'HouseNumber'=> self::get_order_prop( $order, 'shipping_address_1' ),
        'City'=>self::get_order_prop( $order, 'shipping_city' ),
        'StateProvince'=> html_entity_decode($state_name),
        'Country'=>self::format_country(self::get_order_prop( $order, 'shipping_country' )),
        'ZipCode'=>self::get_order_prop( $order, 'shipping_postcode' ),
        'AdditionalInfo'=> self::get_order_prop( $order, 'shipping_address_2' ),
      );
    }

    if(isset($psp_post_data['psp_ShippingDetails'])) {
      $psp_post_data['psp_ShippingDetails']['Method'] = '99'; // method 'other(99)'
    }
    global $woocommerce;
    $psp_post_data['psp_MerchantAdditionalDetails'] = array(
      // 'Type'=>'A',
//      'SellerDetails' => array(
//        // 'ExternalReferenceId'=>null,
//        // 'IDNumber'=>'27087764-0',
//        // 'IDType'=>'205',
//        // 'Name'=>'',
//        'Invoice'=>$order->get_order_number(),
//        'PurchaseDescription' => substr('ORDER-'.self::get_order_prop( $order, 'id' ),0,255),
//        'Address' => array(
//          'Street'=>WC()->countries->get_base_address(),
//          'HouseNumber'=>WC()->countries->get_base_address(),
//          'City'=>WC()->countries->get_base_city(),
//          'StateProvince'=> WC()->countries->get_base_country() && WC()->countries->get_base_state() ? html_entity_decode(WC()->countries->states[WC()->countries->get_base_country()][WC()->countries->get_base_state()]) : NULL,
//          'Country'=>self::format_country(WC()->countries->get_base_country()),
//          'ZipCode'=>WC()->countries->get_base_postcode(),
//          'AdditionalInfo'=> WC()->countries->get_base_address_2(),
//        ),
//        // 'MCC'=>'LÁ678',
//        // 'ChannelCode'=>'211',
//        // 'GeoCode'=>'12345',
//        'EmailAddress'=>get_option( 'admin_email' ),
//        'PhoneNumber1'=>'',
//        'PhoneNumber2'=>'',
//      ),
      'ShoppingCartInfo' => $woocommerce->version,
      'ShoppingCartPluginInfo' => 'Woocommerce NPS Plugin '.WC_NPS_VERSION,
    );


    // var_dump($psp_post_data['psp_MerchantAdditionalDetails']);exit;

    // Get all customer orders
    $customer_orders = get_posts( array(
      'numberposts' => -1,
      'meta_key'    => '_customer_user',
      'meta_value'  => get_current_user_id(),
      'post_type'   => wc_get_order_types(),
      'post_status' => array_keys( wc_get_order_statuses() ),
    ) );


    $psp_post_data['psp_CustomerAdditionalDetails']=array(
      'AccountID'=>get_current_user_id(),
      'AccountHasCredentials'=>(is_user_logged_in()) ? 1 : 0,
      'HttpUserAgent'=>wc_get_user_agent(),
    );
    // if(wp_get_current_user()->ID != 0) {
    $order_ids = wc_get_orders( array(
      'customer' => get_current_user_id(),
      'limit'    => -1,
      'status'   => array_map( 'wc_get_order_status_name', wc_get_is_paid_statuses() ),
      'return'   => 'ids',
    ) );
    $psp_post_data['psp_CustomerAdditionalDetails']['AccountPreviousActivity'] = get_current_user_id() > 0 && !empty($order_ids) ? 1 : 0;

    if($email = wp_get_current_user()->user_email) {
      $psp_post_data['psp_CustomerAdditionalDetails']['EmailAddress'] = $email;
    }
    if($ip = $this->getIp()) {
      $psp_post_data['psp_CustomerAdditionalDetails']['IPAddress'] = $ip;
    }
    if($created_at = wp_get_current_user()->user_registered) {
      $psp_post_data['psp_CustomerAdditionalDetails']['AccountCreatedAt'] = date("Y-m-d", strtotime($created_at));
    }


    // }



    /**
     * Filter the return value of the WC_Payment_Gateway_CC::generate_payment_request.
     *
     * @since 3.1.0
     * @param array $post_data
     * @param WC_Order $order
     * @param object $source
     */
    return apply_filters( 'wc_nps_generate_payment_request', self::cleanArray($psp_post_data), $order, $source );
  }

  /**
   * Get order property with compatibility check on order getter introduced
   * in WC 3.0.
   *
   * @since 1.4.1
   *
   * @param WC_Order $order Order object.
   * @param string   $prop  Property name.
   *
   * @return mixed Property value
   */
  public static function get_order_prop( $order, $prop ) {
    switch ( $prop ) {
      case 'order_total': $getter = array( $order, 'get_total' ); break;
      case 'order_currency':  $getter = array( $order, 'get_currency' );  break;
      default:  $getter = array( $order, 'get_' . $prop );  break;
    }

    return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
  }

  protected function get_token($token_id) {
    $token = false;
    $tokens = $this->get_tokens();
    foreach($tokens as $t) {
      if($t->get_token() == wc_clean( @$_REQUEST['wc-nps-payment-token'] )) {
        $token = $t;
        break;
      }
    }
    return $token;
  }

  protected function get_order_meta($order, $attr) {
    $order = is_object($order) ? $order : wc_get_order($order);
    $order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();
    return version_compare( WC_VERSION, '3.0.0', '<' ) ? get_post_meta( $order_id, $attr, true ) : $order->get_meta( $attr, true);
  }

  public static function calculateInstallmentCost($installments, $rate, $total) {
    return floor(((float)$rate/100) * (float)$total);
  }

  public function getIp() {
    $ip = $_SERVER['REMOTE_ADDR'];
    if(!empty($_SERVER['HTTP_CLIENT_IP'])) {
      $ip = $_SERVER['HTTP_CLIENT_IP'];
    }elseif(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    return strlen($ip) >= 7 ? $ip : null;
  }

  public static function cleanArray( array $array, callable $callback = null ) {
    $array = is_callable( $callback ) ? array_filter( $array, $callback ) : array_filter($array, function($v) { return !($v === null || $v === 0 || $v === '' || (is_array($v) && !count($v)) ); });
    foreach ( $array as &$value ) {
      if ( is_array( $value ) ) {
        $value = call_user_func( array(__CLASS__, __FUNCTION__), $value, $callback );
      }
    }

    return array_filter($array, function($v) { return !($v === null || $v === 0 || $v === '' || (is_array($v) && !count($v))); });
  }

  /**
   * Store extra meta data for an order from a Nps Response.
   */
  public function process_response( $response, $order ) {
    $order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();

    $captured = $this->capture && @$response->psp_ResponseCod == '0';

    // Store charge data
    $this->update_order_meta($order, '_nps_charge_id', @$response->psp_TransactionId);
    $this->update_order_meta($order, '_nps_charge_captured', $captured ? 'yes' : 'no');
    $this->update_order_meta($order, '_transaction_id', @$response->psp_TransactionId);

    // if($this->is_simple_checkout()) { // if 3p update installment amount
    $this->orderAddFeeInstallment($order, @$response->psp_Amount);
    // }

    if ( $captured ) {
      $order->payment_complete( @$response->psp_TransactionId );

      $message = sprintf( __( 'Nps charge complete (Charge ID: %s)', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId );
      $order->add_order_note( $message );
      $this->log( 'Success: ' . $message );

    } else {
      if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
        version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->reduce_order_stock() : wc_reduce_stock_levels( $order_id );
      }

      $order->update_status( 'on-hold', sprintf( __( 'Nps charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId ) );
      $this->log( "Successful auth: ".@$response->psp_TransactionId );
    }

    $order->calculate_totals(false);

    do_action( 'wc_gateway_nps_process_response', $response, $order );

    return $response;
  }

  protected function orderAddFeeInstallment($order=NULL, $psp_Amount=NULL) {
    /* if(!is_object($order) || !(is_numeric($psp_Amount) && $psp_Amount > 0)) {
        return;
    } */

    $excost = ($psp_Amount - $this->get_nps_amount($order->get_total())) / 100;
    WC()->cart->add_fee('Installment Fee', $excost, $taxable = false,'');
    $cart_fees = WC()->cart->get_fees();
    $fees_keys = array_keys($cart_fees);
    $fee_key = end($fees_keys);
    $fee = $cart_fees[$fee_key];

    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
      $item_id = $order->add_fee( $fee );
    } else {
      $item = new WC_Order_Item_Fee();
      $item->set_props( array(
        'name'      => $fee->name,
        'tax_class' => $fee->taxable ? $fee->tax_class : 0,
        'total'     => $fee->amount,
        // 'total_tax' => $fee->tax,
        /* 'taxes'     => array(
          'total' => $fee->tax_data,
        ), */
        'order_id'  => $order->get_id(),
      ) );
      $item_id = $item->save();
      $order->add_item( $item );
    }

    if ( ! $item_id ) {
      throw new Exception( sprintf( __( 'Error %d: Unable to add order installment fee. Please try again.', 'woocommerce-gateway-nps' ), '' ) );
    }
    // $order->save();
    // $order->calculate_totals(false);

    // Allow plugins to add order item meta to fees
    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
      do_action( 'woocommerce_add_order_fee_meta', $order->get_id(), $item_id, $fee, $fee_key );
    } else {
      do_action( 'woocommerce_new_order_item', $item_id, $fee, $order->get_id() );
    }
  }

  /**
   * Save Card
   * @param type $order
   * @param type $nps_payment_id
   */
  protected function add_payment_method_from_nps_payment_id($order, $nps_payment_id) {
    if(!$order || !$nps_payment_id || $this->is_advance_checkout()) {
      return;
    }
    $order = is_object($order) ? $order : wc_get_order($order);
    if($maybe_saved_card = $this->get_order_meta($order, "_maybe_saved_card")) {
      try {
        $nps_customer = new WC_Nps_Customer(get_current_user_id(), $this->url, $this->secret_key, $this->merchant_id );

        $this->log( "Info: Beginning createPaymentMethodFromPayment for order {$order->get_id()} and payment {$nps_payment_id}", true );

        $sdk = new Sdk();
        $request = array(
          'psp_Version' => '1',
          'psp_MerchantId' =>$this->merchant_id,
          'psp_TransactionId' => $nps_payment_id,
          'psp_CustomerId' => $nps_customer->get_id(),
          // 'psp_SetAsCustomerDefault' => 1,
          'psp_PosDateTime'=> date('Y-m-d H:i:s'),
          'psp_PaymentMethodTag' => '#'.$order->get_id(),
        );
        $this->log( 'Processing createPaymentMethodFromPayment request: ' . print_r( $request, true ), $this->logging );
        $response_createPaymentMethod = $sdk->createPaymentMethodFromPayment($request);
        $this->log( 'Processing createPaymentMethodFromPayment response: ' . print_r( $response_createPaymentMethod, true ), $this->logging );

        // Add token to WooCommerce
        if ( class_exists( 'WC_Payment_Token_CC' ) && @$response_createPaymentMethod->psp_PaymentMethod->PaymentMethodId ) {
          $this->log("Success: Payment method created - PaymentMethod ID: {$response_createPaymentMethod->psp_PaymentMethod->PaymentMethodId} - Reason: {$response_createPaymentMethod->psp_ResponseMsg}", true);
          $token = new WC_Payment_Token_CC();
          $token->set_token( $response_createPaymentMethod->psp_PaymentMethod->PaymentMethodId );
          $token->set_gateway_id( $this->id );
          $token->set_card_type( strtolower( $response_createPaymentMethod->psp_PaymentMethod->Product ) );
          $token->set_last4( $response_createPaymentMethod->psp_PaymentMethod->CardOutputDetails->Last4 );
          $token->set_expiry_month( $response_createPaymentMethod->psp_PaymentMethod->CardOutputDetails->ExpirationMonth );
          $token->set_expiry_year( $response_createPaymentMethod->psp_PaymentMethod->CardOutputDetails->ExpirationYear );
          $token->set_user_id( get_current_user_id() );
          $token->save();

          $nps_customer->clear_cache();
        }

      }catch(ApiException $e) {
        $this->log("Error: On createPaymentMethodFromPayment {$nps_payment_id} - Reason: " . @$response_createPaymentMethod->psp_ResponseExtended, true);
      }
    }
  }

  /**
   * Sends the failed order email to admin
   *
   * @version 3.1.0
   * @since 3.1.0
   * @param int $order_id
   * @return null
   */
  public function send_failed_order_email( $order_id ) {
    $emails = WC()->mailer()->get_emails();
    if ( ! empty( $emails ) && ! empty( $order_id ) ) {
      $emails['WC_Email_Failed_Order']->trigger( $order_id );
    }
  }

  /**
   * Add payment method via account screen.
   * We don't store the token locally, but to the Nps API.
   * @since 3.0.0
   */
  public function add_payment_method() {
    if ( (!@$_REQUEST['npsPaymentMethodTokenId'] && !@$_REQUEST['nps-card-number']) || ! is_user_logged_in() ) {
      wc_add_notice( __( 'There was a problem adding the card.', 'woocommerce-gateway-nps' ), 'error' );
      return;
    }

    $nps_customer = new WC_Nps_Customer(get_current_user_id(), $this->url, $this->secret_key, $this->merchant_id );



    if(@$_REQUEST['npsPaymentMethodTokenId']) {
      $card = $nps_customer->add_card( wc_clean( $_REQUEST['npsPaymentMethodTokenId'] ) );

      if ( is_wp_error( $card ) ) {
        $localized_messages = $this->get_localized_messages();
        $error_msg = __( 'There was a problem adding the card.', 'woocommerce-gateway-nps' );

        // loop through the errors to find matching localized message
        foreach ( $card->errors as $error => $msg ) {
          if ( isset( $localized_messages[ $error ] ) ) {
            $error_msg = $localized_messages[ $error ];
          }
        }

        wc_add_notice( $error_msg, 'error' );
        return;
      }
    }else if(@$_REQUEST['nps-card-number']) {
      if ( ! $nps_customer->get_id() ) {
        if ( ( $response = $nps_customer->create_customer() ) && is_wp_error( $response ) ) {
          $error_msg = __( 'There was a problem adding the card.', 'woocommerce-gateway-nps' );
          wc_add_notice( $error_msg, 'error' );
          return;
        }
      }

      $sdk = new Sdk();
      $card_number = str_replace( ' ', '', $_REQUEST['nps-card-number'] );
      $card_cvc = $_REQUEST['nps-card-cvc'];
      $exp_date_array = explode( "/", $_REQUEST['nps-card-expiry'] );
      $exp_month = trim( $exp_date_array[0] );
      $exp_year = trim( $exp_date_array[1] );
      $exp_date = substr( $exp_year, -2 ) . $exp_month;
      $request = array(
        'psp_Version'          => '2.2',
        'psp_MerchantId'       => $this->merchant_id,
        'psp_PaymentMethod' => array(
          //'PaymentMethodTag' => 'PM.PaymentMethodTag - 009 DIGITOS - Suite 01 Case 25 Step 01',
          //'PaymentMethodToken' => 'w7R1uLzaqRPnSZumiET8sKVAt4HPuAUf',
          'CardInputDetails' => array(
            'Number'       => $card_number,
            'ExpirationDate' => $exp_date,
            // 'SecurityCode' => '377',
            // 'HolderName'   => 'John Doe'
          ),
          /* 'Person'=>array(
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
          ), */
          /* 'Address'=>array(
              'Street'=>'pepe st',
              'HouseNumber'=>'99',
              // 'AdditionalInfo'=>'Nº 735 Piso 5',
              'City'=>'capìtal federal',
              // 'StateProvince'=>'capìtal federal',
              'Country'=>'PER',
              //'ZipCode'=>'1414',
          ), */
        ),
        'psp_CustomerId' => $nps_customer->get_id(),
        'psp_SetAsCustomerDefault' => 0,
        'psp_PosDateTime' => date('Y-m-d H:i:s'),
      );
      $this->log( "Info: Beginning createPaymentMethod for merchant {$this->merchant_id}" );
      $this->log( 'Processing createPaymentMethod request: ' . print_r( $request, true ), $this->logging );
      $createPaymentMethodResponse = $sdk->createPaymentMethod($request);
      $this->log( 'Processing createPaymentMethod response: ' . print_r( $createPaymentMethodResponse, true ), $this->logging );

      if ( @$createPaymentMethodResponse->psp_PaymentMethod->PaymentMethodId ) {
        $this->log("Success: Payment method created - PaymentMethod ID: {$createPaymentMethodResponse->psp_PaymentMethod->PaymentMethodId} - Reason: {$createPaymentMethodResponse->psp_ResponseMsg}", true);
        $token = new WC_Payment_Token_CC();
        $token->set_token( $createPaymentMethodResponse->psp_PaymentMethod->PaymentMethodId );
        $token->set_gateway_id( $this->id );
        $token->set_card_type( $createPaymentMethodResponse->psp_PaymentMethod->Product );
        $token->set_last4( $createPaymentMethodResponse->psp_PaymentMethod->CardOutputDetails->Last4 );
        $token->set_expiry_month( $createPaymentMethodResponse->psp_PaymentMethod->CardOutputDetails->ExpirationMonth );
        $token->set_expiry_year( $createPaymentMethodResponse->psp_PaymentMethod->CardOutputDetails->ExpirationYear );
        $token->set_user_id( get_current_user_id() );
        $token->save();

        $nps_customer->clear_cache();
      } else {
        $this->log("Error: On createPaymentMethod for merchant {$this->merchant_id} - Reason: " . @$createPaymentMethodResponse->psp_ResponseExtended, true);
        if ( isset( $createPaymentMethodResponse->psp_ResponseMsg ) ) {
          $error_msg = __( 'Error adding card: ', 'woocommerce-nps' ) . $createPaymentMethodResponse->psp_ResponseMsg;
        } else {
          $error_msg = __( 'Error adding card: ', 'woocommerce-nps' );
        }

      }
    }


    if($error_msg) {
      wc_add_notice( $error_msg, 'error' );
      return;
    }else {
      return array(
        'result'   => 'success',
        'redirect' => wc_get_endpoint_url( 'payment-methods' ),
      );
    }
  }

  /**
   * Refund a charge
   * @param  int $order_id
   * @param  float $amount
   * @return bool
   */
  public function process_refund( $order_id, $amount = null, $reason = '' ) {
    $order = wc_get_order( $order_id );

    if ( ! $order || ! $order->get_transaction_id() ) {
      return false;
    }

    $body = array();

    if ( ! is_null( $amount ) ) {
      $body['amount']	= $this->get_nps_amount( $amount );
    }

    if ( $reason ) {
      $body['metadata'] = array(
        'reason'	=> $reason,
      );
    }

    $this->log( "Info: Beginning refund for order $order_id for the amount of {$amount}" );


    $sdk = new Sdk();
    $request = array(
      'psp_Version'            => '2.2',
      'psp_MerchantId'         => $this->merchant_id,
      'psp_TxSource'           => 'WEB',
      'psp_MerchTxRef'         => strtoupper(uniqid($order_id.".", true)),
      'psp_TransactionId_Orig' => $order->get_transaction_id(),
      'psp_AmountToRefund'     => $this->get_nps_amount( $amount ),
      'psp_PosDateTime'        => date('Y-m-d H:i:s'),
      'psp_UserId'             => get_current_user_id(),
    );
    $this->log( 'Processing Refund Request: ' . print_r( $request, true ), $this->logging );
    $response = $sdk->refund($request);
    $this->log( 'Processing Refund Response: ' . print_r( $response, true ), $this->logging );

    if ( @$response->psp_ResponseCod != "0" ) {
      $refund_message = sprintf( __( 'Unable to refund charge! - Refund ID: %1$s - psp_MerchTxRef: %2$s  - Reason: %3$s', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId, @$response->psp_MerchTxRef, @$response->psp_ResponseExtended);
      $order->add_order_note( $refund_message );
      $this->log( sprintf( __( 'Error: Transaction ID: %d - Reason: %s', 'woocommerce-gateway-nps' ), @$response->psp_TransactionId, @$response->psp_ResponseExtended) );
      return false;
    } elseif ( ! empty( $response->psp_TransactionId ) ) {
      $refund_message = sprintf( __( 'Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-gateway-nps' ), wc_price( @$response->psp_RefundedAmount / 100 ), @$response->psp_TransactionId, $reason );
      $order->add_order_note( $refund_message );
      $this->log( 'Success: ' . html_entity_decode( strip_tags( $refund_message ) ) );
      return true;
    }
  }

  /**
   * Check NPS gateway response.
   *
   * @since 1.0.0
   */
  public function check_gateway_response() {
    $this->handle_gateway_request( stripslashes_deep( $_REQUEST ) );
    exit(0);

    // Notify PayFast that information has been received
    header( 'HTTP/1.0 200 OK' );
    flush();
  }

  /**
   * Check NPS gateway request validity.
   *
   * @param array $data
   * @since 1.0.0
   */
  public function handle_gateway_request( $post_data ) {
    $this->log( "\n" . '----------' . "\n" . 'NPS gateway call received', $this->logging);
    $this->log( 'Get posted data', $this->logging);
    $this->log( 'NPS Data: ' . print_r( $post_data, true ), $this->logging);

    $nps_error  = false;
    $nps_done   = false;


    if ( false === $post_data ) {
      $nps_error  = true;
      $nps_error_message = NPS_ERR_BAD_ACCESS;
    }

    // Verify source IP (If not in debug mode)
    /* if ( ! $nps_error && ! $nps_done ) {
  $this->log( 'Verify source IP' );

  if ( ! $this->validate_ip( $_SERVER['REMOTE_ADDR'] ) ) {
    $nps_error  = true;
    $nps_error_message = NPS_ERR_BAD_SOURCE_IP;
  }
} */

    // Verify data received
    if ( ! $nps_error ) {
      $this->log( 'Verify data received', $this->logging);
      $validation_data = $post_data;
      $has_valid_response_data = $this->validate_response_data( $validation_data );

      if ( ! $has_valid_response_data ) {
        $nps_error = true;
        $nps_error_message = NPS_ERR_BAD_ACCESS;
      }
    }


    try {

      if(@$post_data['psp_TransactionId']) {

        $sdk = new Sdk();
        $request = array(
          'psp_Version'          => '2.2',
          'psp_MerchantId'       => $this->merchant_id,
          'psp_QueryCriteria'    => 'T',
          'psp_QueryCriteriaId'  => $post_data['psp_TransactionId'],
          'psp_PosDateTime'      => date('Y-m-d H:i:s')
        );
        $this->log( 'Processing simpleQueryTx request: ' . print_r( $request, true ), $this->logging );
        $response = $sdk->simpleQueryTx($request);
        $this->log( 'Processing simpleQueryTx response: ' . print_r( $response, true ), $this->logging );

        $data = $response->psp_Transaction;

        /* if($data->psp_ResponseCod != "0") {
          $nps_error = true;
          $nps_error_message = $data->psp_ResponseMsg;
        } */
      }
    }catch(ApiException $e){
      $nps_error = true;
      $nps_error_message = NPS_ERR_UNKNOWN;
    }

    $debug_email    = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
    // $session_id     = $post_data['custom_str1'];
    $vendor_name    = get_bloginfo( 'name' );
    $vendor_url     = home_url( '/' );
    $order_id       = absint( $data->psp_MerchOrderId );
    // $order_key      = wc_clean( $session_id );
    $order          = wc_get_order( $order_id );
    $original_order = $order;

    // Check data against internal order
    /* if ( ! $nps_error && ! $nps_done ) {
  $this->log( 'Check data against internal order', $this->logging );

  // Check order amount
  if ( ! $this->amounts_equal( $data->psp_Amount, self::get_order_prop( $order, 'order_total' ) )
     && ! $this->order_contains_pre_order( $order_id ) ) {
    $nps_error  = true;
    $nps_error_message = NPS_ERR_AMOUNT_MISMATCH;
  }
} */

    // alter order object to be the renewal order if
    // the gateway request comes as a result of a renewal submission request
    if ($data && $data->psp_MerchOrderId ) {
      $order = wc_get_order( $data->psp_MerchOrderId );
    }

    // Get internal order and verify it hasn't already been processed
    if ( ! $nps_error && ! $nps_done ) {
      $this->log( "Purchase:\n" . print_r( $order, true ), $this->logging);

      // Check if order has already been processed
      if ( 'completed' === self::get_order_prop( $order, 'status' ) ) {
        $this->log( 'Order has already been processed' );
        $nps_done = true;
      }
    }



    // If an error occurred
    if ( $nps_error ) {

      $this->log( 'Error occurred: ' . $nps_error_message );

      if ( $this->send_debug_email ) {
        $this->log( 'Sending email notification' );

        // Send an email
        $subject = 'NPS gateway response error: ' . $nps_error_message;
        $body =
          "Hi,\n\n" .
          "An invalid NPS transaction on your website requires attention\n" .
          "------------------------------------------------------------\n" .
          'Site: ' . $vendor_name . ' (' . $vendor_url . ")\n" .
          'Remote IP Address: ' . $_SERVER['REMOTE_ADDR'] . "\n" .
          'Remote host name: ' . gethostbyaddr( $_SERVER['REMOTE_ADDR'] ) . "\n" .
          'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
          'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n";
        if ( $data->psp_TransactionId ) {
          $body .= 'NPS Transaction ID: ' . esc_html( $data->psp_TransactionId ) . "\n";
        }
        if ( isset( $data['payment_status'] ) ) {
          $body .= 'NPS Payment Status: ' . esc_html( $data->psp_ResponseMsg ) . "\n";
        }

        $body .= "\nError: " . $nps_error_message . "\n";

        /* switch ( $nps_error_message ) {
  case NPS_ERR_AMOUNT_MISMATCH:
    $body .=
      'Value received : ' . esc_html( $data->psp_Amount ) . "\n"
      . 'Value should be: ' . self::get_order_prop( $order, 'order_total' );
    break;

  case NPS_ERR_ORDER_ID_MISMATCH:
    $body .=
      'Value received : ' . esc_html( $data->psp_MerchOrderId ) . "\n"
      . 'Value should be: ' . self::get_order_prop( $order, 'id' );
    break;

  // For all other errors there is no need to add additional information
  default:
    break;
} */

        wp_mail( $debug_email, $subject, $body );
      } // End if().
    } elseif ( ! $nps_done ) {
      $this->log( 'Check status and update order', $this->logging);

      /* if ( self::get_order_prop( $original_order, 'order_key' ) !== $order_key ) {
  $this->log( 'Order key does not match' );
  exit;
} */

      $status = $data->psp_ResponseCod != '0' ? 'failed' : 'complete';

      if ( 'complete' === $status ) {
        /**
         * comentado https://jira.techno.ingenico.com/browse/NPS-1573
         */
        // wc_add_notice( __( NPS_NOTICE_PAYMENT_APPROVED, 'woocommerce-gateway-nps' ) );

        $this->process_response( $data, $order );
      } else {
        $this->log( sprintf( __( 'Error: Transaction ID: %d - Reason: %s', 'woocommerce-gateway-nps' ), @$data->psp_TransactionId, @$data->psp_ResponseExtended) );
        if(@$data->psp_TransactionId) {
          wc_add_notice( __( @$data->psp_ResponseMsg, 'woocommerce-gateway-nps' ), 'error' );
        }else {
          wc_add_notice( __( NPS_ERR_WILDCARD, 'woocommerce-gateway-nps' ), 'error' );
        }

        $this->handle_gateway_payment_failed( $data, $order );

        wp_redirect( $order->get_checkout_payment_url() );
        exit;
      }
    } // End if().


    $this->add_payment_method_from_nps_payment_id($order, $data->psp_TransactionId);

    wp_redirect( $this->get_return_url( $order ) );
    exit;
  }

  /**
   * validate_response_data()
   *
   * @param array $post_data
   * @param string $proxy Address of proxy to use or NULL if no proxy.
   * @since 1.0.0
   * @return bool
   */
  public function validate_response_data( $post_data, $proxy = null ) {
    // $this->log( 'Host = ' . $this->validate_url );
    $this->log( 'Params = ' . print_r( $post_data, true ), $this->logging);


    if(!(is_array($post_data)
      && ((@$post_data['psp_TransactionId'] && @$post_data['psp_MerchTxRef'])
        || (@$post_data['oauth_verifier']))))
    {
      return false;
    }

    /*
        $response = wp_remote_post( $this->validate_url, array(
          'body'       => $post_data,
          'timeout'    => 70,
          'user-agent' => NPS_USER_AGENT,
        ));

        if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
          return false;
        }

        parse_str( $response['body'], $parsed_response );

        $response = $parsed_response;

        $this->log( "Response:\n" . print_r( $response, true ) );

        // Interpret Response
        if ( is_array( $response ) && in_array( 'VALID', array_keys( $response ) ) ) {
          return true;
        } else {
          return false;
        }*/

    return true;
  }

  /**
   * @param $data
   * @param $order
   */
  public function handle_gateway_payment_failed( $data, $order ) {
    if(!is_object($data)) {
      return;
    }

    /* translators: 1: payment status */
    $order->update_status( 'failed', sprintf( __( 'Payment %s via nps gateway.', 'woocommerce-gateway-nps' ), strtolower( sanitize_text_field( $data->psp_ResponseMsg ) ) ) );
    $debug_email   = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
    $vendor_name    = get_bloginfo( 'name' );
    $vendor_url     = home_url( '/' );

    if ( $this->send_debug_email ) {
      $subject = 'NPS gateway Transaction on your site';
      $body =
        "Hi,\n\n" .
        "A failed NPS transaction on your website requires attention\n" .
        "------------------------------------------------------------\n" .
        'Site: ' . $vendor_name . ' (' . $vendor_url . ")\n" .
        'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
        'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n" .
        'NPS Transaction ID: ' . esc_html( $data->psp_TransactionId ) . "\n" .
        'NPS Payment Status: ' . esc_html( $data->psp_ResponseMsg );
      wp_mail( $debug_email, $subject, $body );
    }

    do_action( 'wc_gateway_nps_process_payment_error', new Exception($data->psp_ResponseMsg, $data->psp_ResponseCod), $order );
  }

  /**
   * Reciept page.
   *
   * Display text and a button to direct the user to NPS.
   *
   * @since 1.0.0
   */
  public function receipt_page( $order ) {
    echo '<p>' . __( 'Thank you for your order, please click the button below to pay with NPS.', 'woocommerce-gateway-nps' ) . '</p>';
    echo $this->generate_nps_form( $order );
  }

  /**
   * Generate the NPS button link.
   *
   * @since 1.0.0
   */
  public function generate_nps_form( $order_id ) {
    $order  = wc_get_order( $order_id );
    $source = $this->get_source( get_current_user_id() );

    try {
      $sdk = new Sdk();
      $method = $this->get_gateway_payment_method();
      $request = $this->generate_payment_request( $order, $source );
      $this->log( "Processing $method request: " . print_r( $request, true ), $this->logging );
      $response = $sdk->$method($request);
      $this->log( "Processing $method response: " . print_r( $response, true ), $this->logging );

      if(@$response->psp_ResponseCod == '1') {

        return '<form action="' . esc_url( $response->psp_FrontPSP_URL ) . '" method="post" id="nps_payment_form">
            <input type="submit" class="button-alt" id="submit_nps_payment_form" value="' . __( 'Pay via NPS', 'woocommerce-gateway-nps' ) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Cancel order &amp; restore cart', 'woocommerce-gateway-nps' ) . '</a>
            <script type="text/javascript">
              jQuery(function(){
                jQuery("body").block(
                  {
                    message: "' . __( 'Thank you for your order. We are now redirecting you to NPS to make payment.', 'woocommerce-gateway-nps' ) . '",
                    overlayCSS:
                    {
                      background: "#fff",
                      opacity: 0.6
                    },
                    css: {
                      padding:        20,
                      textAlign:      "center",
                      color:          "#555",
                      border:         "3px solid #aaa",
                      backgroundColor:"#fff",
                      cursor:         "wait"
                    }
                  });
                jQuery( "#submit_nps_payment_form" ).click();
              });
            </script>
          </form>';
      }else {
        throw new Exception(@$response->psp_ResponseExtended ?: @$response->psp_ResponseMsg, @$response->psp_ResponseCod);
      }

    } catch ( Exception $e ) {
      // wc_add_notice( __( NPS_ERR_WILDCARD, 'woocommerce-gateway-nps' ), 'error' );
      $this->log( sprintf( __( 'Error: %s', 'woocommerce-gateway-nps' ), $e->getMessage() ) );

      if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
        $this->send_failed_order_email( $order_id );
      }

      do_action( 'wc_gateway_nps_process_payment_error', $e, $order );

      // ===== Reaching at this point means that the URL could not be build by some reason =====
      $html = '<p>' .
        __( NPS_ERR_WILDCARD, 'woocommerce-gateway-nps' ) .
        '</p>' .
        '<a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' .
        __( 'Click to try again', 'woocommerce-gateway-nps' ) .
        '</a>
			';
      return $html;

      // wc_clear_notices();
      // wp_redirect( wc_get_checkout_url() );
      // exit;
    }
  }

  /**
   * validate_ip()
   *
   * Validate the IP address to make sure it's coming from NPS.
   *
   * @param array $source_ip
   * @since 1.0.0
   * @return bool
   */
  public function validate_ip( $source_ip ) {
    // Variable initialization
    $valid_hosts = array(
      'psp.localhost',
      'dev.nps.com.ar',
      'sandbox.nps.com.ar',
      'services2.nps.com.ar',
      'implementacion.nps.com.ar',
    );

    $valid_ips = array();

    foreach ( $valid_hosts as $pf_hostname ) {
      $ips = gethostbynamel( $pf_hostname );

      if ( false !== $ips ) {
        $valid_ips = array_merge( $valid_ips, $ips );
      }
    }

    // Remove duplicates
    $valid_ips = array_unique( $valid_ips );

    $this->log( "Valid IPs:\n" . print_r( $valid_ips, true ) );

    return in_array( $source_ip, $valid_ips );
  }

  public function generate_wallets_html() {

    ob_start();

    $country 	= wc_clean( strtoupper( wc_get_base_location()['country'] ) );
    $locale		= $this->get_country_locale();

    // Get sortcode label in the $locale array and use appropriate one
    $sortcode = isset( $locale[ $country ]['sortcode']['label'] ) ? $locale[ $country ]['sortcode']['label'] : __( 'Sort code', 'woocommerce' );

    ?>
      <script>
          function refreshWalletRows() {

              jQuery("select.nps_wallet option").each(function() {
                  jQuery(this).show();
              });

              jQuery("select.nps_wallet option:selected").each(function() {
                  var el = jQuery(this).closest('select.nps_wallet');

                  if(jQuery(el).val() == 2) {
                      jQuery(el).closest('tr.wallet').find('input.nps_wallet_key').attr("placeholder", "checkoutId");
                      jQuery(el).closest('tr.wallet').find('input.nps_wallet_ephemeralpublickey').hide();

                      jQuery("select.nps_wallet").not(el).find("option[value='2']").each(function() {
                          jQuery(this).hide();
                      });

                  }else if(jQuery(el).val() == 1) {
                      jQuery(el).closest('tr.wallet').find('input.nps_wallet_key').attr("placeholder", "apikey");
                      jQuery(el).closest('tr.wallet').find('input.nps_wallet_ephemeralpublickey').show();

                      jQuery("select.nps_wallet").not(el).find("option[value='1']").each(function() {
                          jQuery(this).hide();
                      });

                  }else {
                      jQuery(el).closest('tr.wallet').find('input.nps_wallet_key').attr("placeholder", "");
                  }
              });

              /* jQuery("select.nps_wallet option:selected").each(function() {


                   if((jQuery(el) <> jQuery(this).closest('select.nps_wallet'))) && (jQuery(this).val())) {
                     jQuery(el).find('option[value='+jQuery(this).val()+']').remove();
                   }
               });                                      */

          }
      </script>
      <tr valign="top">
          <th scope="row" class="titledesc"><?php _e( 'Wallets Details', 'woocommerce' ); ?>:</th>
          <td class="forminp" id="nps_wallets">
              <table class="widefat wc_input_table sortable" cellspacing="0">
                  <thead>
                  <tr>
                      <th class="sort">&nbsp;</th>
                      <th><?php _e( 'Wallet', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Key', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Environment', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Status', 'woocommerce' ); ?></th>
                      <th><?php _e( 'EphemeralPublicKey', 'woocommerce' ); ?></th>
                  </tr>
                  </thead>
                  <tbody class="wallets">
                  <?php
                  $i = -1;
                  if ( $this->wallets ) {
                    foreach ( $this->wallets as $wallet ) {
                      $i++;

                      echo '<tr class="wallet">
									<td class="sort"></td>
                                                                        <td>'.$this->renderWalletOptions($wallet['wallet'], $i).'</td>
									<td><input type="text" class="nps_wallet_key" value="' . $wallet['key'] . '" name="nps_wallet_key[' . $i . ']" required="required" /></td>
                                                                        <td>'.$this->renderWalletEnvironmentOptions($wallet['status'], $i).'</td>    
									<td>'.$this->renderWalletStatusOptions($wallet['status'], $i).'</td>
									<td><input type="text" class="nps_wallet_ephemeralpublickey" value="' . $wallet['ephemeralpublickey'] . '" name="nps_wallet_ephemeralpublickey[' . $i . ']" /></td>
								</tr><script>refreshWalletRows();</script>';


                    }
                  }
                  ?>
                  </tbody>
                  <tfoot>
                  <tr>
                      <th colspan="7"><a href="#" class="add button"><?php _e( '+ Add Wallet', 'woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php _e( 'Remove selected wallet(s)', 'woocommerce' ); ?></a></th>
                  </tr>
                  </tfoot>
              </table>
              <script type="text/javascript">



                  jQuery(function() {

                      jQuery('#nps_wallets a.remove_rows').on( 'click', { }, function(){
                          refreshWalletRows();
                      });

                      jQuery('#nps_wallets').on( 'click', 'a.add', function(){

                          var size = jQuery('#nps_wallets').find('tbody .wallet').length;

                          jQuery('<tr class="wallet">\
									<td class="sort"></td>\
									<td><?php echo $this->renderWalletOptions(null, "' + size + '") ?></td>\
									<td><input type="text" class="nps_wallet_key" name="nps_wallet_key[' + size + ']" required="required" /></td>\
									<td><?php echo $this->renderWalletEnvironmentOptions(null, "' + size + '") ?></td>\
									<td><?php echo $this->renderWalletStatusOptions(null, "' + size + '") ?></td>\
									<td><input type="text" class="nps_wallet_ephemeralpublickey" name="nps_wallet_ephemeralpublickey[' + size + ']" /></td>\
								</tr>').appendTo('#nps_wallets table tbody');
                          refreshWalletRows();

                          jQuery('#nps_wallets').on( 'change', 'select.nps_wallet', function(){
                              refreshWalletRows();
                              return false;
                          });

                          return false;
                      });

                  });
              </script>
          </td>
      </tr>


    <?php
    return ob_get_clean();

  }

  /**
   * Get country locale if localized.
   *
   * @return array
   */
  public function get_country_locale() {

    if ( empty( $this->locale ) ) {

      // Locale information to be used - only those that are not 'Sort Code'
      $this->locale = apply_filters( 'woocommerce_get_nps_locale', array(
        'AU' => array(
          'sortcode'	=> array(
            'label'		=> __( 'BSB', 'woocommerce' ),
          ),
        ),
        'CA' => array(
          'sortcode'	=> array(
            'label'		=> __( 'Bank transit number', 'woocommerce' ),
          ),
        ),
        'IN' => array(
          'sortcode'	=> array(
            'label'		=> __( 'IFSC', 'woocommerce' ),
          ),
        ),
        'IT' => array(
          'sortcode'	=> array(
            'label'		=> __( 'Branch sort', 'woocommerce' ),
          ),
        ),
        'NZ' => array(
          'sortcode'	=> array(
            'label'		=> __( 'Bank code', 'woocommerce' ),
          ),
        ),
        'SE' => array(
          'sortcode'	=> array(
            'label'		=> __( 'Bank code', 'woocommerce' ),
          ),
        ),
        'US' => array(
          'sortcode'	=> array(
            'label'		=> __( 'Routing number', 'woocommerce' ),
          ),
        ),
        'ZA' => array(
          'sortcode'	=> array(
            'label'		=> __( 'Branch code', 'woocommerce' ),
          ),
        ),
      ) );

    }

    return $this->locale;

  }

  protected function renderWalletOptions($selected=NULL,$index=null) {
    $choices = array('1'=>'Visa Checkout','2'=>'Masterpass');
    $html = '<select name="nps_wallet['.$index.']" class="nps_wallet" required="required"><option value="">Choose an option</option>';
    foreach($choices as $k => $v) {
      $html .= '<option value="'.$k.'" '.($k == $selected ? 'selected=selected' : '').'>'.$v.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  protected function renderWalletEnvironmentOptions($selected,$index) {
    $choices = array('0'=>'production','1'=>'sandbox');
    $html = '<select name="nps_wallet_environment['.$index.']">';
    foreach($choices as $k => $v) {
      $html .= '<option value="'.$k.'" '.($k == $selected ? 'selected=selected' : '').'>'.$v.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  protected function renderWalletStatusOptions($selected,$index) {
    $choices = array('0'=>'disabled','1'=>'enabled');
    $html = '<select name="nps_wallet_status['.$index.']">';
    foreach($choices as $k => $v) {
      $html .= '<option value="'.$k.'" '.($k == $selected ? 'selected=selected' : '').'>'.$v.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  /**
   * Generate account details html.
   *
   * @return string
   */
  public function generate_installment_details_html() {

    ob_start();

    $country 	= wc_clean( strtoupper( wc_get_base_location()['country'] ) );
    $locale		= $this->get_country_locale();

    // Get sortcode label in the $locale array and use appropriate one
    $sortcode = isset( $locale[ $country ]['sortcode']['label'] ) ? $locale[ $country ]['sortcode']['label'] : __( 'Sort code', 'woocommerce' );

    ?>
      <tr valign="top">
          <th scope="row" class="titledesc"><?php _e( 'Installments Details', 'woocommerce' ); ?>:</th>
          <td class="forminp" id="nps_accounts">
              <table class="widefat wc_input_table sortable" cellspacing="0">
                  <thead>
                  <tr>
                      <th class="sort">&nbsp;</th>
                      <th><?php _e( 'Card', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Installments', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Rate', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Status', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Country', 'woocommerce' ); ?></th>
                      <th><?php _e( 'Currency', 'woocommerce' ); ?></th>
                  </tr>
                  </thead>
                  <tbody class="accounts">
                  <?php
                  $i = -1;
                  if ( $this->installment_details ) {
                    foreach ( $this->installment_details as $installment ) {
                      $i++;

                      /* echo '<tr class="account">
        <td class="sort"></td>
        <td><input type="text" value="' . esc_attr( wp_unslash( $installment['card'] ) ) . '" name="nps_card[' . $i . ']" /></td>
        <td><input type="text" value="' . esc_attr( $installment['installment'] ) . '" name="nps_installment[' . $i . ']" /></td>
        <td><input type="text" value="' . esc_attr( wp_unslash( $installment['rate'] ) ) . '" name="nps_rate[' . $i . ']" /></td>
        <td><input type="text" value="' . esc_attr( $installment['status'] ) . '" name="nps_status[' . $i . ']" /></td>
        <td><input type="text" value="' . esc_attr( $installment['country'] ) . '" name="nps_country[' . $i . ']" /></td>
        <td><input type="text" value="' . esc_attr( $installment['currency'] ) . '" name="nps_currency[' . $i . ']" /></td>
      </tr>'; */


                      echo '<tr class="account">
									<td class="sort"></td>
                  <td>'.$this->renderCardOptions($installment['card'], $i).'</td>
                  <td>'.$this->renderInstallmentOptions($installment['installment'], $i).'</td>
									<td><input type="text" value="' . $installment['rate'] . '" name="nps_rate[' . $i . ']" /></td>
									<td>'.$this->renderInstallmentStatusOptions($installment['status'], $i).'</td>
									<td>'.$this->renderCountryOptions($installment['country'], $i).'</td>
									<td>'.$this->renderCurrencyOptions($installment['currency'], $i).'</td>
								</tr>';


                    }
                  }
                  ?>
                  </tbody>
                  <tfoot>
                  <tr>
                      <th colspan="7"><a href="#" class="add button"><?php _e( '+ Add installment', 'woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php _e( 'Remove selected installment(s)', 'woocommerce' ); ?></a></th>
                  </tr>
                  </tfoot>
              </table>
              <script type="text/javascript">
                  jQuery(function() {
                      jQuery('#nps_accounts').on( 'click', 'a.add', function(){

                          var size = jQuery('#nps_accounts').find('tbody .account').length;

                          jQuery('<tr class="account">\
									<td class="sort"></td>\
									<td><?php echo $this->renderCardOptions(null, "' + size + '") ?></td>\
									<td><?php echo $this->renderInstallmentOptions(null, "' + size + '") ?></td>\
									<td><input type="text" name="nps_rate[' + size + ']" /></td>\
									<td><?php echo $this->renderInstallmentStatusOptions(null, "' + size + '") ?></td>\
									<td><?php echo $this->renderCountryOptions(null, "' + size + '") ?></td>\
									<td><?php echo $this->renderCurrencyOptions(null, "' + size + '") ?></td>\
								</tr>').appendTo('#nps_accounts table tbody');

                          return false;
                      });
                  });
              </script>
          </td>
      </tr>




    <?php
    return ob_get_clean();

  }

  protected function renderCardOptions($selected=NULL,$index=null) {
    $choices = array(
      '1'=>'American Express',
      '65'=>'Argencard',
      '53'=>'Argenta',
      '104'=>'Aura',
      '110'=>'BBPS',
      '8'=>'Cabal',
      '130'=>'Carnet',
      '131'=>'Carnet Debit',
      '117'=>'Carrefour',
      '134'=>'Carta Automatica',
      '69'=>'Cetelem',
      '51'=>'Clarin 365',
      '47'=>'Club Arnet',
      '120'=>'Club Dia',
      '45'=>'Club La Nacion',
      '58'=>'Club La Voz',
      '46'=>'Club Personal',
      '52'=>'Club Speedy',
      '35'=>'CMR Falabella',
      '123'=>'Codensa',
      '129'=>'Colsubsidio',
      '128'=>'Comfama',
      '72'=>'Consumax',
      '95'=>'Coopeplus',
      '91'=>'Credi Guia',
      '20'=>'Credimas',
      '126'=>'Credz',
      '121'=>'CTC Group',
      '2'=>'Diners',
      '101'=>'Discover',
      '135'=>'Don Credito',
      '133'=>'Elebar',
      '102'=>'Elo',
      '15'=>'Favacard',
      '118'=>'Grupar',
      '116'=>'Hiper',
      '105'=>'Hipercard',
      '43'=>'Italcred',
      '4'=>'JCB',
      '10'=>'Kadicard',
      '17'=>'Lider',
      '66'=>'Maestro',
      '103'=>'Magna',
      '48'=>'Mas(cencosud)',
      '5'=>'Mastercard',
      '57'=>'MC Bancor',
      '114'=>'Metro',
      '75'=>'Mira',
      '9'=>'Naranja',
      '49'=>'Naranja MO',
      '63'=>'NATIVA',
      '38'=>'Nativa MC',
      '21'=>'Nevada',
      '61'=>'Nexo',
      '113'=>'OH!',
      '33'=>'Patagonia 365',
      '50'=>'Pyme Nacion',
      '122'=>'Qida',
      '107'=>'RedCompra',
      '112'=>'Ripley',
      '124'=>'Socios BBVA',
      '34'=>'Sol',
      '93'=>'Sucredito',
      '108'=>'SuperCard',
      '42'=>'Tarjeta Shopping',
      '119'=>'Tuya',
      '125'=>'UATP',
      '132'=>'Ultra',
      '115'=>'UnionPay',
      '14'=>'Visa',
      '55'=>'Visa Debito',
      '29'=>'Visa Naranja',
      '127'=>'WebPay',
    );
    $html = '<select name="nps_card['.$index.']">';
    foreach($choices as $k => $v) {
      $html .= '<option value="'.$k.'" '.($k == $selected ? 'selected=selected' : '').'>'.$v.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  protected function renderInstallmentOptions($selected,$index) {
    $html = '<select name="nps_installment['.$index.']">';
    for($i=1;$i<=99;$i++) {
      $html .= '<option value="'.$i.'" '.($i == $selected ? 'selected=selected' : '').'>'.$i.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  protected function renderInstallmentStatusOptions($selected,$index) {
    $choices = array('0'=>'disabled','1'=>'enabled');
    $html = '<select name="nps_status['.$index.']">';
    foreach($choices as $k => $v) {
      $html .= '<option value="'.$k.'" '.($k == $selected ? 'selected=selected' : '').'>'.$v.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  protected function renderCountryOptions($selected,$index) {
    $choices = array('ARG'=>'Argentine','BRA'=>'Brazil','CHL'=>'Chile');
    $html = '<select name="nps_country['.$index.']">';
    foreach($choices as $k => $v) {
      $html .= '<option value="'.$k.'" '.($k == $selected ? 'selected=selected' : '').'>'.$v.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  protected function renderCurrencyOptions($selected,$index) {
    $choices = array('032'=>'Argentine Peso','152'=>'Chilean Peso','986'=>'Brazilian Real');
    $html = '<select name="nps_currency['.$index.']">';
    foreach($choices as $k => $v) {
      $html .= '<option value="'.$k.'" '.($k == $selected ? 'selected=selected' : '').'>'.$v.'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  /**
   * Save account details table.
   */
  public function save_wallets() {

    $save_wallets = array();


    if ( isset( $_REQUEST['nps_wallet'] ) ) {

      $wallets   = array_map( 'wc_clean', $_REQUEST['nps_wallet'] );
      $key = array_map( 'wc_clean', $_REQUEST['nps_wallet_key'] );
      $environment = array_map( 'wc_clean', $_REQUEST['nps_wallet_environment'] );
      $status      = array_map( 'wc_clean', $_REQUEST['nps_wallet_status'] );
      $ephemeralPublicKeys            = array_map( 'wc_clean', $_REQUEST['nps_wallet_ephemeralpublickey'] );

      foreach ( $wallets as $i => $wallet ) {
        if ( ! isset( $wallets[ $i ] ) ) {
          continue;
        }

        $save_wallets[] = array(
          'wallet'   => $wallets[ $i ],
          'key' => $key[ $i ],
          'environment' => $environment[ $i ],
          'status'      => $status[ $i ],
          'ephemeralpublickey'           => $ephemeralPublicKeys[ $i ],
        );
      }
    }


    update_option( 'woocommerce_nps_wallets', $save_wallets );

  }

  /**
   * Save account details table.
   */
  public function save_installment_details() {

    $save_installments = array();


    if ( isset( $_REQUEST['nps_card'] ) ) {

      $cards   = array_map( 'wc_clean', $_REQUEST['nps_card'] );
      $installments = array_map( 'wc_clean', $_REQUEST['nps_installment'] );
      $rate      = array_map( 'wc_clean', $_REQUEST['nps_rate'] );
      $status      = array_map( 'wc_clean', $_REQUEST['nps_status'] );
      $country           = array_map( 'wc_clean', $_REQUEST['nps_country'] );
      $currency            = array_map( 'wc_clean', $_REQUEST['nps_currency'] );

      foreach ( $cards as $i => $card ) {
        if ( ! isset( $cards[ $i ] ) ) {
          continue;
        }

        $save_installments[] = array(
          'card'   => $cards[ $i ],
          'installment' => $installments[ $i ],
          'rate'      => $rate[ $i ],
          'status'      => $status[ $i ],
          'country'           => $country[ $i ],
          'currency'            => $currency[ $i ],
        );
      }
    }


    update_option( 'woocommerce_nps_installments', $save_installments );

  }

  /**
   * amounts_equal()
   *
   * Checks to see whether the given amounts are equal using a proper floating
   * point comparison with an Epsilon which ensures that insignificant decimal
   * places are ignored in the comparison.
   *
   * eg. 100.00 is equal to 100.0001
   *
   * @author Jonathan Smit
   * @param $amount1 Float 1st amount for comparison
   * @param $amount2 Float 2nd amount for comparison
   * @since 1.0.0
   * @return bool
   */
  public function amounts_equal( $amount1, $amount2 ) {
    return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > 0.01 );
  }

  /**
   * @param string $order_id
   * @return bool
   */
  public function order_contains_pre_order( $order_id ) {
    if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
      return WC_Pre_Orders_Order::order_contains_pre_order( $order_id );
    }
    return false;
  }

  /**
   * Output field name HTML
   *
   * Gateways which support tokenization do not require names - we don't want the data to post to the server.
   *
   * @param  string $name
   * @return string
   */
  public function field_name( $name ) {
    if($this->is_advance_checkout()) {
      switch($name) {
        case 'card-number': $name = 'number'; break;
        case 'card-expiry': $name = 'exp_date'; break;
        case 'card-cvc': $name = 'security_code'; break;
      }
      return ' data-nps="card[' . esc_attr($name) . ']" ';
    }else {
      return ' name="' . esc_attr( $this->id . '-' . $name ) . '" ';
    }
  }

  public function searchWallets($brand=NULL) {
    if(!$this->is_wallet_enabled()) {
      return array();
    }

    $wallets = array();
    $rs = get_option( 'woocommerce_nps_wallets' );

    foreach($rs as $r) {
      if(($brand && $brand != $r['wallet'])
        || ($r['status'] != '1')
      ) {
        continue;
      }
      $wallets[$r['wallet']] = $r;
    }

    return $wallets;
  }

  /**
   * Gets saved payment method HTML from a token.
   * @since 2.6.0
   * @param  WC_Payment_Token $token Payment Token
   * @return string                  Generated payment method HTML
   */
  public function get_saved_payment_method_option_html( $token ) {
    if(@$token->get_card_type()) {
      $wc_currency  = wc_clean( strtoupper( get_woocommerce_currency() ) );
      $wc_country   = wc_clean( strtoupper( wc_get_base_location()['country'] ) );
      $installments = $this->searchInstallments($brand=$token->get_card_type(), $country=self::format_country($wc_country), $currency=self::format_currency($wc_currency));
      if(!count($installments)) {
        return;
      }
    }
    $html = sprintf(
      '<li class="woocommerce-SavedPaymentMethods-token">
				<input id="wc-%1$s-payment-token-%2$s" type="radio" name="wc-%1$s-payment-token" value="%2$s" style="width:auto;" class="woocommerce-SavedPaymentMethods-tokenInput" %4$s />
				<label for="wc-%1$s-payment-token-%2$s">%3$s</label>
			</li>',
      esc_attr( $this->id ),
      esc_attr( $token->get_token() ),
      esc_html( sprintf(
        __( '%1$s ending in %2$s (expires %3$s/%4$s)', 'woocommerce' ),
        "card",
        $token->get_last4(),
        $token->get_expiry_month(),
        substr( $token->get_expiry_year(), 2 )
      ) ),
      checked( $token->is_default(), true, false )
    );

    return apply_filters( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', $html, $token, $this );
  }

}
