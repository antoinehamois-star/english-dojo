<?php
/**
 * Module oklaschenker — intégration tarifaire Schenker pour OK-LA (Phase 1 : calcul local uniquement).
 *
 * Aucun appel SOAP, aucune réservation, aucune étiquette réelle en Phase 1
 * (cf. SPEC_MODULE_PRESTASHOP_SCHENKER.md fourni par le donneur d'ordre).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/OklaSchenkerTariffRepositoryInterface.php';
require_once __DIR__ . '/classes/OklaSchenkerRateCalculator.php';
require_once __DIR__ . '/classes/OklaSchenkerArrayTariffRepository.php';
require_once __DIR__ . '/classes/OklaSchenkerAddressResolver.php';
require_once __DIR__ . '/classes/OklaSchenkerConfig.php';
require_once __DIR__ . '/classes/OklaSchenkerLogger.php';
require_once __DIR__ . '/classes/OklaSchenkerLegacyCarrierDetector.php';
require_once __DIR__ . '/classes/Repository/OklaSchenkerDbTariffRepository.php';

class Oklaschenker extends CarrierModule
{
    /** @var string Nom exact du transporteur créé par ce module (jamais réutilisé pour un transporteur existant). */
    public const CARRIER_NAME = 'Schenker - OK-LA';

    public function __construct()
    {
        $this->name = 'oklaschenker';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'OK-LA';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => '1.7.99.99'];

        parent::__construct();

        $this->displayName = $this->l('Schenker - OK-LA (calcul tarifaire)');
        $this->description = $this->l('Calcule automatiquement le tarif de livraison Schenker à partir de la grille tarifaire contractuelle OK-LA. Phase 1 : calcul local uniquement, sans réservation ni étiquette.');
        $this->confirmUninstall = $this->l('La désinstallation désactive uniquement le transporteur Schenker - OK-LA. Aucune commande, aucun ancien transporteur et aucune donnée tarifaire/journal ne sera supprimé. La suppression définitive des tables se fait séparément, depuis la page de configuration du module, après confirmation explicite.');
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->createModuleTables()) {
            $this->_errors[] = $this->l('Échec de la création des tables du module.');

            return false;
        }

        if (!$this->registerHook('displayCarrierExtraContent')
            || !$this->registerHook('displayAdminOrder')
        ) {
            return false;
        }

        if (!$this->installAdminTab()) {
            $this->_errors[] = $this->l('Échec de la création de l\'onglet back-office.');

            return false;
        }

        $idCarrier = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier <= 0 || !Validate::isLoadedObject(new Carrier($idCarrier))) {
            $idCarrier = $this->createOwnCarrier();
            if ($idCarrier === null) {
                $this->_errors[] = $this->l('Échec de la création du transporteur Schenker - OK-LA.');

                return false;
            }
            OklaSchenkerConfig::set(OklaSchenkerConfig::CARRIER_ID, $idCarrier);
        }

        $this->seedSurchargeCatalog();
        $this->snapshotLegacyCarrierReport();

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'install', 'Module installé', ['id_carrier' => $idCarrier]);

        return true;
    }

    public function uninstall(): bool
    {
        $idCarrier = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier > 0) {
            $carrier = new Carrier($idCarrier);
            if (Validate::isLoadedObject($carrier)) {
                // Désactivation uniquement : jamais de suppression physique du transporteur.
                $carrier->active = false;
                $carrier->save();
            }
        }

        $this->uninstallAdminTab();

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'uninstall', 'Module désinstallé — transporteur désactivé, données et tables conservées', ['id_carrier' => $idCarrier]);

        // NB: on n'appelle volontairement PAS sql/uninstall.php ici — voir ce fichier
        // et docs/ANALYSE_TECHNIQUE.md pour la justification (conservation de l'historique).
        return parent::uninstall();
    }

    /**
     * Supprime définitivement les tables et données du module.
     * Appelée UNIQUEMENT depuis AdminOklaSchenkerController après une confirmation
     * explicite et distincte de la désinstallation standard du module.
     */
    public function purgeAllData(): bool
    {
        $queries = require __DIR__ . '/sql/uninstall.php';
        $success = true;
        foreach ($queries as $query) {
            $success = Db::getInstance()->execute($query) && $success;
        }
        OklaSchenkerConfig::deleteAll();

        return $success;
    }

    private function createModuleTables(): bool
    {
        $queries = require __DIR__ . '/sql/install.php';
        $success = true;
        foreach ($queries as $query) {
            $success = Db::getInstance()->execute($query) && $success;
        }

        return $success;
    }

    private function installAdminTab(): bool
    {
        if (Tab::getIdFromClassName('AdminOklaSchenkerController')) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminOklaSchenkerController';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentShipping');
        $tab->icon = 'local_shipping';
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Schenker - OK-LA';
        }

        return $tab->add();
    }

    private function uninstallAdminTab(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminOklaSchenkerController');
        if ($idTab <= 0) {
            return true;
        }
        $tab = new Tab($idTab);

        return $tab->delete();
    }

    /**
     * Crée le transporteur "Schenker - OK-LA". Ne touche à AUCUN transporteur existant,
     * y compris les anciens "AD SCHENKER" (cf. OklaSchenkerLegacyCarrierDetector).
     */
    private function createOwnCarrier(): ?int
    {
        $carrier = new Carrier();
        $carrier->name = self::CARRIER_NAME;
        $carrier->is_module = true;
        $carrier->active = false; // activation manuelle explicite requise en back-office
        $carrier->deleted = false;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = false;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_PRICE;
        $carrier->url = '';
        $carrier->id_tax_rules_group = 0; // à configurer explicitement en back-office
        // Champ obligatoire côté PrestaShop (Carrier::$definition) : ne peut pas être vide,
        // sous peine d'échec de la validation à l'enregistrement ("La propriété Carrier->delay
        // est vide."). Valeur neutre, remplacée par le gestionnaire via l'écran de configuration
        // du module (champ "Délai annoncé au client") avant activation du transporteur.
        foreach (Language::getLanguages(false) as $lang) {
            $carrier->delay[$lang['id_lang']] = $this->l('Délai à configurer');
        }

        if (!$carrier->add()) {
            return null;
        }

        // Zones et groupes : association large par défaut (comme tout nouveau transporteur
        // module), à restreindre ensuite par le gestionnaire depuis le back-office standard
        // Transporteurs de PrestaShop — le module ne restreint aucun périmètre inventé.
        $groups = Group::getGroups((int) Context::getContext()->language->id);
        $groupIds = array_map(static fn ($g) => (int) $g['id_group'], $groups);
        if (!empty($groupIds)) {
            $carrier->setGroups($groupIds);
        }

        $zones = Zone::getZones(true);
        foreach ($zones as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
        }

        // Une seule tranche de prix "fictive" 0-1000000 requise par PrestaShop même en
        // shipping_external = true (le prix réel est renvoyé par getOrderShippingCost*).
        $rangePrice = new RangePrice();
        $rangePrice->id_carrier = (int) $carrier->id;
        $rangePrice->delimiter1 = 0;
        $rangePrice->delimiter2 = 10000;
        $rangePrice->add();

        $rangeWeight = new RangeWeight();
        $rangeWeight->id_carrier = (int) $carrier->id;
        $rangeWeight->delimiter1 = 0;
        $rangeWeight->delimiter2 = 10000;
        $rangeWeight->add();

        return (int) $carrier->id;
    }

    private function seedSurchargeCatalog(): void
    {
        $dataFile = __DIR__ . '/data/schenker_tarifs_extraits.json';
        if (!is_file($dataFile)) {
            return;
        }
        $json = json_decode(file_get_contents($dataFile), true);
        if (!is_array($json)) {
            return;
        }

        $labelMap = [
            OklaSchenkerRateCalculator::SURCHARGE_URBAN_ZONE => 'Livraison pour les expéditions à destination des zones urbaines',
            OklaSchenkerRateCalculator::SURCHARGE_SEASONAL => 'Ajustement saisonnier',
            OklaSchenkerRateCalculator::SURCHARGE_SAFETY_QUALITY => 'Contribution sûreté et qualité',
            OklaSchenkerRateCalculator::SURCHARGE_ENERGY_CONTRIBUTION => 'Contribution Transition Energétique',
        ];

        foreach ($json['surcharges_raw'] ?? [] as $row) {
            $label = (string) $row['label'];
            $code = null;
            foreach ($labelMap as $mapCode => $needle) {
                if (stripos($label, $needle) !== false) {
                    $code = $mapCode;
                    break;
                }
            }
            // Suppléments non auto-applicables : code technique dérivé du libellé, pour
            // conservation en base uniquement (jamais appliqués sans configuration manuelle).
            if ($code === null) {
                $code = 'MANUAL_' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), 0, 40));
            }

            Db::getInstance()->insert('oklaschenker_surcharge', [
                'code' => pSQL($code),
                'label' => pSQL($label),
                'application' => pSQL((string) ($row['application'] ?? '')),
                'amount' => (float) ($row['amount'] ?? 0),
                'extra_1' => $row['extra_1'] !== null ? pSQL((string) $row['extra_1']) : null,
                'extra_2' => $row['extra_2'] !== null ? (float) $row['extra_2'] : null,
                'is_auto_applicable' => in_array($code, OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES, true) ? 1 : 0,
            ], false, true, Db::INSERT_IGNORE);
        }
    }

    private function snapshotLegacyCarrierReport(): void
    {
        $detector = new OklaSchenkerLegacyCarrierDetector();
        $legacyCarriers = $detector->findLegacySchenkerCarriers();

        foreach ($legacyCarriers as $legacy) {
            Db::getInstance()->insert('oklaschenker_legacy_carrier_report', [
                'id_carrier' => $legacy['id_carrier'],
                'name' => pSQL($legacy['name']),
                'active' => $legacy['active'] ? 1 : 0,
                'deleted' => $legacy['deleted'] ? 1 : 0,
                'date_detected' => date('Y-m-d H:i:s'),
            ]);
        }

        OklaSchenkerLogger::log(
            OklaSchenkerLogger::LEVEL_INFO,
            'install',
            sprintf('%d ancien(s) transporteur(s) évoquant Schenker détecté(s) (lecture seule)', count($legacyCarriers)),
            ['legacy_carriers' => $legacyCarriers]
        );
    }

    /**
     * Hook standard PrestaShop pour un transporteur module (shipping_external = true).
     * $params est le Cart en cours de tunnel de commande.
     *
     * @param array|Cart $params
     */
    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    /**
     * @param array|Cart $params
     * @param float $shipping_cost
     *
     * @return float|bool false si Schenker ne doit pas être proposé (cf. conditions de blocage).
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        $idCarrier = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        $carrier = new Carrier($idCarrier);
        if (!Validate::isLoadedObject($carrier) || !$carrier->active) {
            return false;
        }

        $cart = $params instanceof Cart ? $params : Context::getContext()->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->id_address_delivery) {
            return false;
        }

        $address = new Address((int) $cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            return false;
        }

        $countryIso = '';
        if ($address->id_country) {
            $country = new Country((int) $address->id_country);
            if (Validate::isLoadedObject($country)) {
                $countryIso = $country->iso_code;
            }
        }

        $resolver = new OklaSchenkerAddressResolver();
        $resolved = $resolver->resolveDepartment([
            'postcode' => $address->postcode,
            'country_iso' => $countryIso,
        ]);

        if (!$resolved['ok']) {
            $this->logCalculation($cart, null, null, false, $resolved['reason']);

            return false;
        }

        $weightKg = (float) $cart->getTotalWeight();
        if ($weightKg <= 0) {
            $this->logCalculation($cart, $resolved['department'], $weightKg, false, OklaSchenkerRateCalculator::REASON_INVALID_WEIGHT);

            return false;
        }

        $calculator = new OklaSchenkerRateCalculator(new OklaSchenkerDbTariffRepository());
        $trace = $calculator->calculate($resolved['department'], $weightKg, new DateTimeImmutable());

        $this->logCalculation($cart, $resolved['department'], $weightKg, $trace['ok'], $trace['reason'] ?? null, $trace);

        if (!$trace['ok']) {
            return false;
        }

        return (float) $trace['total_price_ht'];
    }

    private function logCalculation(Cart $cart, ?string $department, ?float $weightKg, bool $ok, ?string $reason, array $trace = []): void
    {
        try {
            Db::getInstance()->insert('oklaschenker_calc_log', [
                'id_cart' => (int) $cart->id,
                'id_order' => null,
                'department' => $department !== null ? pSQL($department) : null,
                'weight_kg' => $weightKg,
                'ok' => $ok ? 1 : 0,
                'reason' => $reason !== null ? pSQL($reason) : null,
                'base_price_ht' => $trace['base_price_ht'] ?? null,
                'total_price_ht' => $trace['total_price_ht'] ?? null,
                'trace_json' => pSQL(json_encode($trace, JSON_UNESCAPED_UNICODE)),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('OklaSchenker: échec écriture calc_log — ' . $e->getMessage(), 3);
        }
    }

    /**
     * Affiche le délai annoncé dans la liste des transporteurs du tunnel de commande,
     * uniquement pour le transporteur créé par ce module.
     */
    public function hookDisplayCarrierExtraContent($params)
    {
        $idCarrier = isset($params['carrier']['id']) ? (int) $params['carrier']['id'] : 0;
        $ownCarrierId = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier !== $ownCarrierId || $ownCarrierId === 0) {
            return '';
        }

        $delay = (string) OklaSchenkerConfig::get(OklaSchenkerConfig::ANNOUNCED_DELAY);
        if ($delay === '') {
            return '';
        }

        $this->context->smarty->assign(['okla_delay' => $delay]);

        return $this->fetch('module:oklaschenker/views/templates/hook/carrier_extra_content.tpl');
    }

    /**
     * Bloc d'information Schenker dans la page commande du back-office (lecture seule,
     * Phase 1 : pas d'action de réservation/étiquette, seulement le détail du calcul
     * tarifaire au moment de la commande).
     */
    public function hookDisplayAdminOrder($params)
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $ownCarrierId = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($ownCarrierId === 0 || (int) $order->id_carrier !== $ownCarrierId) {
            return '';
        }

        $sql = new DbQuery();
        $sql->select('*')
            ->from('oklaschenker_calc_log')
            ->where('id_cart = ' . (int) $order->id_cart)
            ->orderBy('id_oklaschenker_calc_log DESC');
        $lastCalc = Db::getInstance()->getRow($sql);

        $this->context->smarty->assign([
            'okla_order' => $order,
            'okla_last_calc' => $lastCalc ?: null,
        ]);

        return $this->fetch('module:oklaschenker/views/templates/hook/admin_order_block.tpl');
    }
}
