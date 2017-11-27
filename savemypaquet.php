<?php
if (!defined('_PS_VERSION_')) exit;
class Savemypaquet extends CarrierModule {
  public $id_carrier;
  private $API_URL = 'https://api.savemypaquet.com/api';
  private $CARRIERS = [
    'SMP_OPTI_48H' => [
      'name' => 'SaveMyPaquet Optimum 48h',
      'delay' => 'Recevez votre colis en 48h',
      'fees' => [
        [0.01, 0.25, 5.99],
        [0.25, 0.50, 6.99],
        [0.50, 0.75, 7.99],
        [0.75, 1.00, 8.99],
        [1.00, 2.00, 9.99],
        [2.00, 5.00, 14.99],
        [5.00, 10.00, 19.99],
        [10.00, 30.00,  29.99]
      ]
    ],
    'SMP_PREM_48H' => [
      'name' => 'SaveMyPaquet Premium avec suivi et preuve de livraison en 48h',
      'delay' => 'Recevez votre colis en 48h',
      'fees' => [
        [0.01, 0.25, 6.99],
        [0.25, 0.50, 7.99],
        [0.50, 0.75, 8.99],
        [0.75, 1.00, 9.99],
        [1.00, 2.00, 10.99],
        [2.00, 5.00, 15.99],
        [5.00, 10.00, 20.99],
        [10.00, 30.00,  30.99]
      ]
    ],
    'SMP_PREM_24H' => [
      'name' => 'SaveMyPaquet Premium+ avec suivi et preuve de livraison en 24h',
      'delay' => 'Recevez votre colis en 24h',
      'fees' => [
        [0.01, 0.25, 10.99],
        [0.25, 0.50, 11.99],
        [0.50, 0.75, 12.99],
        [0.75, 1.00, 13.99],
        [1.00, 2.00, 14.99],
        [2.00, 5.00, 22.99],
        [5.00, 10.00, 29.99],
        [10.00, 30.00,  50.99]
      ]
    ]
  ];
  public function __construct() {

    $this->name = 'savemypaquet';
    $this->tab = 'shipping_logistics';
    $this->version = '1.0';
    $this->author = 'bel3atar';
    $this->need_instance = 0;
    $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_); 
    $this->bootstrap = true;
    parent::__construct();
    $this->displayName = $this->l('Save My Paquet');
    $this->description = $this->l('Ajouter SaveMyPaquet aux modes de livraison.');
    $this->confirmUninstall = $this->l('Êtes-vous sûrs de vouloir désinstaller ce module?');
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
    foreach ($this->CARRIERS as $key => $value) {
      $carrier = $this->addCarrier($key, $value);
      foreach (Zone::getZones(true) as $zone) Db::getInstance()->insert('carrier_zone', ['id_carrier' => (int)$carrier->id, 'id_zone' => (int)$zone['id_zone']]);
      $this->addRanges($carrier, $key, $value);
      $this->addGroups($carrier);
    }
    return true;
  }
  protected function addGroups($carrier)
  {
    $groups_ids = array();
    $groups = Group::getGroups(Context::getContext()->language->id);
    foreach ($groups as $group) $groups_ids[] = $group['id_group']; 
    $carrier->setGroups($groups_ids);
  }
  public function uninstall() {
    foreach ($this->CARRIERS as $key => $value) {
      $id = (int)Configuration::get($key);
      $carrier = new Carrier($id);
      $carrier->delete();
    }
    if (!parent::uninstall())
      return false;
    return true;
  }
  public function getOrderShippingCost($params, $shipping_cost) {
    $carrierNames = array_keys($this->CARRIERS);
    $carrierIds = array_combine(Configuration::getMultiple($carrierNames), $carrierNames);
    if (!in_array($this->id_carrier, array_keys($carrierIds))) return false;

		if ($carrierIds[$this->id_carrier] === 'SMP_PREM_24H' and date('h') >= 11) return false;

    $addr = new Address($params->id_address_delivery);
    $iso = (new Country($addr->id_country))->iso_code;
    $codes = array(75, 77, 78, 91, 92, 93, 94, 95);
    if ($iso !== 'FR' || !in_array(substr($addr->postcode, 0, 2), $codes)) return false;
    foreach($params->getProducts() as $p) if ($p['weight'] == 0) return false;

    $carrier = new Carrier($this->id_carrier);
    $total_weight = array_sum(array_map(function ($x) { return $x['weight']; }, $params->getProducts()));

    $sql = 'SELECT d.`price`
      FROM `'._DB_PREFIX_.'delivery` d
      LEFT JOIN `'._DB_PREFIX_.'range_weight` w ON (d.`id_range_weight` = w.`id_range_weight`)
      WHERE '.(float) $total_weight.' >= w.`delimiter1`
      AND '.(float) $total_weight.' < w.`delimiter2`
      AND d.`id_carrier` = '.$carrier->id.'
      AND id_range_price IS NOT NULL 
      AND (id_shop = '.(int)Context::getContext()->shop->id.' OR id_shop IS NULL)';
		dump($sql);
    $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
    return $result['price'];
  }
  protected function addCarrier($id, $config)
  {
    $carrier = new Carrier();
    $carrier->name = $config['name'];
    $carrier->shipping_handling = false;
    $carrier->id_tax_rules_group = 0;
    $carrier->range_behavior = true;

    $carrier->need_range = true;
    $carrier->is_module = true;
    $carrier->shipping_external = true; // cost calculated by module?
    $carrier->external_module_name = $this->name;
    foreach (Language::getLanguages() as $lang) $carrier->delay[$lang['id_lang']] = $config['delay'];
    if ($carrier->add() == true) {
      @copy(dirname(__FILE__).'/logo.png', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg');
      Configuration::updateValue($id, (int)$carrier->id);
      return $carrier;
    } else {
      return false;
    }
  }
  protected function addRanges($carrier, $id, $config) {
    $zones = Zone::getZones(true);
    foreach ($config['fees'] as $fees) {
      $range_weight = new RangeWeight();
      $range_weight->id_carrier = $carrier->id;
      $range_weight->delimiter1 = $fees[0];
      $range_weight->delimiter2 = $fees[1];
      $range_weight->add();
      foreach ($zones as $zone) {
        Db::getInstance()->insert('delivery', ['id_carrier' => $carrier->id, 'id_range_price' => NULL, 'id_range_weight' => (int)$range_weight->id, 'id_zone' => $zone['id_zone'], 'price' => $fees[2]]);
      }

    }
  }
  public function hookDisplayCarrierExtraContent($sutff) {
    $this->context->smarty->assign('smpid', Configuration::get('SMP_CARRIER_ID'));
    $phone = (new Address($this->context->cart->id_address_delivery))->phone;
    $this->context->smarty->assign('phone', $phone);
    return $this->display(__FILE__, 'displayCarrierExtraContent.tpl');
  }
  public function hookActionCarrierUpdate($params)
  {
    $id_carrier_old = (int)($params['id_carrier']);
    $id_carrier_new = (int)($params['carrier']->id);
    $carrierIds = array_keys($this->CARRIERS);
    $carrierIds = array_combine(Configuration::getMultiple($carrierIds), $carrierIds);
    if (array_key_exists($id_carrier_old, $carrierIds))
      Configuration::updateValue($carrierIds[$id_carrier_old], $id_carrier_new);
  }
  public function hookActionFrontControllerSetMedia($params) {
    if ('order' === $this->context->controller->php_self) {
      $this->context->controller->registerJavascript('smpjavascriptfile','modules/'.$this->name.'/script.js');
    }
  }
  public function hookActionValidateOrder($params) {
    $carrierIds = Configuration::getMultiple(array_keys($this->CARRIERS));

    $addr = new Address($params['cart']->id_address_delivery);
    $c = curl_init($this->API_URL . "/colis/new");
    curl_setopt_array($c, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => json_encode([
        "token"             => $this->authenticate()->token,
        "date_de_commande"  => date('Y/m/d'),
        "numero_colis"      => $params['order']->reference,
        "service"           => array_search($params['order']->id_carrier, $carrierIds),
        "nom_du_client"     => $addr->firstname . ' ' . $add->lastname,
        "email_du_client"   => $params['customer']->email,
        "tel_client"        => $addr->phone,
        "poids"             => array_sum(array_map(function ($x) { return $x['weight']; }, $params->getProducts())),
        "adresse1"          => $addr->address1,
        "adresse2"          => $addr->address2,
        "ville"             => $addr->city,
        "code_postal"       => $addr->postcode,
        "pays"              => "France",
        "batiment"          => 0,
        "etage"             => 0,
        "porte_position"    => 0,
        "porte_cote"        => 0
      ])
    ]);
    curl_exec($c);
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
	private function authenticate () {
		$c = curl_init($this->API_URL. '/auth/login');
		curl_setopt_array($c, [
			CURLOPT_POST => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
			CURLOPT_POSTFIELDS => json_encode(['email' =>  Configuration::get('SMP_LOGIN'), 'password' => Configuration::get('SMP_PASSWORD')])
		]);
		return json_decode(curl_exec($c));
	}
}
