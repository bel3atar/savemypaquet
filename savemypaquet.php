<?php
function asdf($x) { file_put_contents('php://stdout', PHP_EOL.$x.PHP_EOL);}
if (!defined('_PS_VERSION_'))
  exit;
class Savemypaquet extends CarrierModule {
  // configuration constants
  private $CARRIERS = [
    'SMP_OPTI_48H' => [
      'name' => 'SaveMyPaquet Optimum 48h',
      'fees' => [
        0.25 => 5.99,
        0.5 => 6.99,
        0.75 => 7.99,
        1 => 8.99,
        2 => 9.99,
        5 => 14.99,
        10 => 19.99,
        30 => 29.99
      ]
    ],
    'SMP_PREM_48H' => [
      'name' => 'SaveMyPaquet Premium avec suivi et preuve de livraison en 48h',
      'fees' => [
        0.25 => 6.99,
        0.5 => 7.99,
        0.75 => 8.99,
        1 => 9.99,
        2 => 10.99,
        5 => 15.99,
        10 => 20.99,
        30 => 30.99
      ]
    ],
    'SMP_PREM_24H' => [
      'name' => 'SaveMyPaquet Premium+ avec suivi et preuve de livraison en 24h',
      'fees' => [
        0.25 => 10.99,
        0.5 => 11.99,
        0.75 => 12.99,
        1 => 13.99,
        2 => 14.99,
        5 => 22.99,
        10 => 29.99,
        30 => 50.99
      ]
    ]
  ];
  public function __construct() {
    $this->name = 'savemypaquet';
    $this->version = '1.0';
    $this->author = 'bel3atar';
    $this->need_instance = 0;
    $this->ps_versions_compliancy = array('min' => '1.7.1.2', 'max' => _PS_VERSION_); 
    $this->tab = 'shipping_logistics';
    $this->bootstrap = true;
    parent::__construct();
    $this->displayName = $this->l('Save My Paquet');
    $this->description = $this->l('Ajouter SaveMyPaquet aux modes de livraison.');
    $this->confirmUninstall = $this->l('Êtes-vous sûrs de vouloir désinstaller ce module?');
    asdf($this->context->controller->php_self);
  }
  public function getOrderShippingCost($params, $shipping_cost) {
    $addr = new Address($params->id_address_delivery);
    $iso = (new Country($addr->id_country))->iso_code;
    $codes = array(75, 77, 78, 91, 92, 93, 94, 95);
    // TODO reenable weight check
    // foreach($params->getProducts() as $p) if ($p['weight'] == 0) return false;
    if ($iso !== 'FR' || !in_array(substr($addr->postcode, 0, 2), $codes)) return false;
    $sum = array_sum(array_map(function ($x) { return $x['weight']; }, $params->getProducts()));
    foreach ($this->CARRIERS['SMP_OPTI_48H']['fees'] as $w => $c) if ($sum <= $w) return $c;
  }
  public function getOrderShippingCostExternal($params) {
    return $this->getOrderShippingCost($params, 0);
  }
  public function install() {
    if (extension_loaded('curl') == false) {
      $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
      return false;
    }
    if (!parent::install())
      return false;
    if (!$this->registerHook('actionCarrierUpdate')
      || !$this->registerHook('actionValidateOrder')
      || !$this->registerHook('actionFrontControllerSetMedia')
      || !$this->registerHook('displayCarrierExtraContent'))
      return false;
    $carrier = $this->addCarrier();
    foreach (Zone::getZones() as $zone) $carrier->addZone($zone['id_zone']);
    $this->addRanges($carrier);
    $this->addGroups($carrier);
    return true;
  }
  protected function addRanges($carrier) {
    $range_price = new RangePrice();
    $range_price->id_carrier = $carrier->id;
    $range_price->delimiter1 = '0';
    $range_price->delimiter2 = '10000';
    $range_price->add();

    $range_weight = new RangeWeight();
    $range_weight->id_carrier = $carrier->id;
    $range_weight->delimiter1 = '0';
    $range_weight->delimiter2 = '10000';
    $range_weight->add();
  }
  protected function addGroups($carrier)
  {
    $groups_ids = array();
    $groups = Group::getGroups(Context::getContext()->language->id);
    foreach ($groups as $group) {
      $groups_ids[] = $group['id_group'];
    }

    $carrier->setGroups($groups_ids);
  }
  public function uninstall() {
    $id = (int)Configuration::get('SMP_CARRIER_ID');
    $carrier = new Carrier($id);
    $carrier->delete();

    if (!parent::uninstall())
      return false;
    return true;
  }

  protected function addCarrier()
  {
    $carrier = new Carrier();
    $carrier->name = 'Save my paquet';
    $carrier->shipping_handling = false;
    $carrier->id_tax_rules_group = 0;
    $carrier->need_range = true;
    $carrier->is_module = true;
    $carrier->shipping_external = true;
    $carrier->external_module_name = $this->name;
    foreach (Language::getLanguages() as $lang) {
      if ($lang['iso_code'] == 'fr')
        $carrier->delay[$lang['id_lang']] = 'Réception à domicile en votre absence en 48h, sécurisé contre le vol avec photo comme preuve de livraison. www.savemypaquet.com';
      else $carrier->delay[$lang['id_lang']] = 'Shipping within 48 hours.';
    }
    if ($carrier->add() == true) {
      @copy(dirname(__FILE__).'/logo.png', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg');
      Configuration::updateValue('SMP_CARRIER_ID', (int)$carrier->id);
      return $carrier;
    } else {
      return false;
    }
  }
  public function hookActionValidateOrder($params) 
  {
    print_r($params) and die();
    if ($params['order']->id_carrier !== (int)Configuration::get('SMP_CARRIER_ID')) return;
    $auth = $this->authenticate();
    if (property_exists($auth, 'error')) die("Erreur d'authentification Save My Paquet, veuillez contacter l'administrateur du site.");
    $uid = $auth->localId;

    $addr = new Address($params['cart']->id_address_delivery);
    $c = curl_init($this->SMP_API_URL . "/orders.json?auth={$auth->idToken}");
    curl_setopt_array($c, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => json_encode([
        "seller"        => $auth->email,
        "reference"     => $params['order']->reference,
        "firstname"     => $addr->firstname,
        "lastname"      => $addr->lastname,
        "email"         => $params['customer']->email,
        "addr1"         => $addr->address1,
        "addr2"         => $addr->address2,
        "postcode"      => $addr->postcode,
        "city"          => $addr->city,
        "phone"         => $addr->phone,
        "phone_mobile"  => $addr->phone_mobile
      ])
    ]);
    curl_exec($c);
  }
  public function hookDisplayCarrierExtraContent($sutff) {
    $this->context->controller->addJquery();
    $this->context->smarty->assign('smpid', Configuration::get('SMP_CARRIER_ID'));
    $phone = (new Address($this->context->cart->id_address_delivery))->phone;
    $this->context->smarty->assign('phone', $phone);
    return $this->display(__FILE__, 'displayCarrierExtraContent.tpl');
  }
  public function hookActionCarrierUpdate($params)
  {
    $id_carrier_old = (int)($params['id_carrier']);
    $id_carrier_new = (int)($params['carrier']->id);
    if ($id_carrier_old == (int)(Configuration::get('SMP_CARRIER_ID')))
      Configuration::updateValue('SMP_CARRIER_ID', $id_carrier_new);
  }
  public function getContent() {
    if (Tools::isSubmit('savemypaquet_form_submit')) {
      Configuration::updateValue('SMP_LOGIN', Tools::getValue('SMP_LOGIN'));
      Configuration::updateValue('SMP_PASSWORD', Tools::getValue('SMP_PASSWORD'));
      $this->context->smarty->assign('confirmation', 'ok');
    }
    $this->context->smarty->assign('SMP_LOGIN', Configuration::get('SMP_LOGIN'));
    $this->context->smarty->assign('SMP_PASSWORD', Configuration::get('SMP_PASSWORD'));
    return $this->display(__FILE__, 'getContent.tpl');
  }
  public function hookActionFrontControllerSetMedia($params) {
    if ('order' === $this->context->controller->php_self) {
			$this->context->controller->registerJavascript('smpjavascriptfile','modules/'.$this->name.'/script.js');
		}
	}
}
