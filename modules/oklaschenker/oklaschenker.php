<?php
/**
 * Module oklaschenker — intégration tarifaire Schenker pour OK-LA (Phase 1 : calcul local uniquement).
 *
 * Aucun appel SOAP, aucune réservation, aucune étiquette réelle en Phase 1
 * (cf. SPEC_MODULE_PRESTASHOP_SCHENKER.md fourni par le donneur d'ordre).
 *
 * IMPORTANT — choix d'architecture : la configuration se fait via getContent()
 * (formulaire affiché directement dans la liste des modules, bouton "Configurer"),
 * PAS via un contrôleur admin dédié (Tab séparé). Ce choix fait suite à une
 * série de tests réels sur le site OK-LA : trois tentatives successives de
 * contrôleur dédié (AdminOklaSchenkerController, un module de diagnostic minimal
 * sans dépendances, puis une copie sous un nom de fichier jamais vu du serveur)
 * ont toutes échoué avec "Le contrôleur ... est manquant ou non valable.",
 * alors qu'un module tiers utilisant getContent() (Smartsupp) fonctionne
 * normalement sur ce même site. Voir docs/RAPPORT_POINTS_BLOQUANTS.md.
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
        $this->version = '1.1.0';
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

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'uninstall', 'Module désinstallé — transporteur désactivé, données et tables conservées', ['id_carrier' => $idCarrier]);

        // NB: on n'appelle volontairement PAS sql/uninstall.php ici — voir ce fichier
        // et docs/ANALYSE_TECHNIQUE.md pour la justification (conservation de l'historique).
        return parent::uninstall();
    }

    /**
     * Supprime définitivement les tables et données du module.
     * Appelée UNIQUEMENT depuis getContent() après une confirmation explicite
     * et distincte de la désinstallation standard du module.
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
        $groupIds = array_map(function ($g) {
            return (int) $g['id_group'];
        }, $groups);
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
     * Densité minimale nationale en dessous de laquelle le contrat Schenker distingue
     * un traitement particulier (tableau de contraintes de service DB SCHENKER fourni
     * par le gestionnaire le 14/08/2026 : « Densité minimale (national) : 50 kg/m3 »,
     * identique sur les 5 services system/system premium/system home/pallet/pallet
     * premium). Ce document ne précise PAS le mode de facturation en dessous de ce
     * seuil (poids volumétrique recalculé ? refus ? autre ?) — en l'absence de cette
     * information, le module ne modifie JAMAIS le tarif calculé sur cette base. Il
     * affiche uniquement un avertissement de vérification manuelle en back-office.
     */
    private const MIN_DENSITY_KG_PER_M3 = 50.0;

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
            'okla_density_warning' => $this->computeOrderDensityWarning($order),
        ]);

        return $this->fetch('module:oklaschenker/views/templates/hook/admin_order_block.tpl');
    }

    /**
     * Calcule la densité (poids réel / volume) de la commande à partir des dimensions
     * renseignées en fiche produit (largeur/hauteur/profondeur, supposées en cm — unité
     * par défaut de PrestaShop) et compare au seuil contractuel Schenker. Ne renvoie
     * jamais d'estimation si une dimension est manquante ou nulle sur au moins un
     * produit de la commande : mieux vaut ne pas afficher d'alerte qu'en afficher une
     * fondée sur une donnée absente.
     */
    private function computeOrderDensityWarning(Order $order): ?array
    {
        $products = $order->getProducts();
        if (empty($products)) {
            return null;
        }

        $totalWeightKg = 0.0;
        $totalVolumeM3 = 0.0;

        foreach ($products as $line) {
            $quantity = (float) $line['product_quantity'];
            $product = new Product((int) $line['product_id'], false, (int) $this->context->language->id);
            if (!Validate::isLoadedObject($product)) {
                return null;
            }

            $width = (float) $product->width;
            $height = (float) $product->height;
            $depth = (float) $product->depth;
            $weight = (float) $product->weight;

            if ($width <= 0.0 || $height <= 0.0 || $depth <= 0.0 || $quantity <= 0.0) {
                return null;
            }

            $totalWeightKg += $weight * $quantity;
            $totalVolumeM3 += ($width / 100) * ($height / 100) * ($depth / 100) * $quantity;
        }

        if ($totalVolumeM3 <= 0.0) {
            return null;
        }

        $density = $totalWeightKg / $totalVolumeM3;

        return [
            'total_weight_kg' => $totalWeightKg,
            'total_volume_m3' => $totalVolumeM3,
            'density_kg_m3' => $density,
            'below_threshold' => $density < self::MIN_DENSITY_KG_PER_M3,
            'threshold' => self::MIN_DENSITY_KG_PER_M3,
        ];
    }

    // ------------------------------------------------------------------
    // Configuration — affichée via le bouton "Configurer" standard de la
    // liste des modules (PAS de contrôleur admin dédié, voir en-tête du fichier).
    // ------------------------------------------------------------------

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitOklaSchenkerModule')) {
            if (!$this->canWrite()) {
                $output .= $this->displayError($this->l('Vous n\'avez pas la permission d\'effectuer cette action.'));
            } elseif (Tools::isSubmit('oklaSchenkerSaveConfig')) {
                $output .= $this->processSaveConfig();
            } elseif (Tools::isSubmit('oklaSchenkerConfirmImport')) {
                $output .= $this->processConfirmImport();
            } elseif (Tools::isSubmit('oklaSchenkerActivateCarrier')) {
                $output .= $this->processActivateCarrier((bool) Tools::getValue('oklaSchenkerActivateCarrier'));
            } elseif (Tools::isSubmit('oklaSchenkerRefreshLegacyReport')) {
                $output .= $this->processRefreshLegacyReport();
            } elseif (Tools::isSubmit('oklaSchenkerDisableLegacyCarrier') && Tools::isSubmit('oklaSchenkerConfirmDisableLegacy')) {
                $output .= $this->processDisableLegacyCarrier((int) Tools::getValue('oklaSchenkerDisableLegacyCarrier'));
            } elseif (Tools::isSubmit('oklaSchenkerPurgeData') && Tools::getValue('oklaSchenkerPurgeConfirmText') === 'SUPPRIMER') {
                $output .= $this->processPurgeData();
            }
        }

        $importPreview = Tools::isSubmit('oklaSchenkerPreviewImport') ? $this->buildImportPreview() : null;
        $testResult = Tools::isSubmit('oklaSchenkerTestRate') ? $this->runTestRate() : null;

        $this->context->smarty->assign([
            // Formulaire posté sur la page courante elle-même (URL exacte affichée,
            // token CSRF inclus) — reconstruire manuellement le lien AdminModules
            // omettait le token et aurait fait échouer chaque soumission.
            'okla_config_form_action' => '',
            'okla_config' => $this->collectConfigViewData(),
            'okla_import_preview' => $importPreview,
            'okla_test_result' => $testResult,
            'okla_legacy_carriers' => $this->fetchLegacyCarrierReport(),
            'okla_recent_logs' => $this->fetchRecentLogs(),
            'okla_surcharge_codes' => OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES,
        ]);

        return $output . $this->fetch('module:oklaschenker/views/templates/admin/configure.tpl');
    }

    private function canWrite(): bool
    {
        if ($this->context->employee === null) {
            return false;
        }

        // Profile::hasPermission() n'existe pas dans l'API PrestaShop (erreur constatée
        // en production : "Attempted to call an undefined method named hasPermission").
        // Tab::checkTabRights() est la méthode réellement utilisée par le cœur PrestaShop
        // (AdminController::viewAccess()) pour vérifier les droits de l'employé connecté.
        return (bool) Tab::checkTabRights((int) Tab::getIdFromClassName('AdminModules'));
    }

    private function collectConfigViewData(): array
    {
        $taxRulesGroups = TaxRulesGroup::getTaxRulesGroups(true);

        return [
            'mode' => OklaSchenkerConfig::get(OklaSchenkerConfig::MODE),
            'wsdl_test_url' => OklaSchenkerConfig::get(OklaSchenkerConfig::WSDL_TEST_URL),
            'wsdl_prod_url' => OklaSchenkerConfig::get(OklaSchenkerConfig::WSDL_PROD_URL),
            'access_key_set' => OklaSchenkerConfig::get(OklaSchenkerConfig::ACCESS_KEY) !== '',
            'group_id' => OklaSchenkerConfig::get(OklaSchenkerConfig::GROUP_ID),
            'account_number' => OklaSchenkerConfig::get(OklaSchenkerConfig::ACCOUNT_NUMBER),
            'tax_rules_group_id' => (int) OklaSchenkerConfig::get(OklaSchenkerConfig::TAX_RULES_GROUP_ID),
            'tax_rules_groups' => $taxRulesGroups,
            'announced_delay' => OklaSchenkerConfig::get(OklaSchenkerConfig::ANNOUNCED_DELAY),
            'logging_enabled' => (bool) OklaSchenkerConfig::get(OklaSchenkerConfig::LOGGING_ENABLED),
            'carrier_id' => (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID),
            'carrier_active' => $this->isOwnCarrierActive(),
            'surcharges_enabled' => array_combine(
                OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES,
                array_map(
                    function ($code) {
                        return OklaSchenkerConfig::isSurchargeEnabled($code);
                    },
                    OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES
                )
            ),
            'tariff_row_counts' => $this->fetchTariffRowCounts(),
        ];
    }

    private function isOwnCarrierActive(): bool
    {
        $idCarrier = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier <= 0) {
            return false;
        }
        $carrier = new Carrier($idCarrier);

        return Validate::isLoadedObject($carrier) && (bool) $carrier->active;
    }

    private function fetchTariffRowCounts(): array
    {
        return [
            'less100' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'oklaschenker_rate_less100`'),
            'over100' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'oklaschenker_rate_over100`'),
            'urban' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'oklaschenker_urban_department`'),
        ];
    }

    private function processSaveConfig(): string
    {
        OklaSchenkerConfig::set(OklaSchenkerConfig::MODE, Tools::getValue('mode') === 'production' ? 'production' : 'test');
        OklaSchenkerConfig::set(OklaSchenkerConfig::WSDL_TEST_URL, Tools::getValue('wsdl_test_url', ''));
        OklaSchenkerConfig::set(OklaSchenkerConfig::WSDL_PROD_URL, Tools::getValue('wsdl_prod_url', ''));

        $accessKey = Tools::getValue('access_key', '');
        if ($accessKey !== '') {
            OklaSchenkerConfig::set(OklaSchenkerConfig::ACCESS_KEY, $accessKey);
        }
        OklaSchenkerConfig::set(OklaSchenkerConfig::GROUP_ID, Tools::getValue('group_id', ''));
        OklaSchenkerConfig::set(OklaSchenkerConfig::ACCOUNT_NUMBER, Tools::getValue('account_number', ''));
        OklaSchenkerConfig::set(OklaSchenkerConfig::TAX_RULES_GROUP_ID, (int) Tools::getValue('tax_rules_group_id', 0));
        OklaSchenkerConfig::set(OklaSchenkerConfig::ANNOUNCED_DELAY, Tools::getValue('announced_delay', ''));
        OklaSchenkerConfig::set(OklaSchenkerConfig::LOGGING_ENABLED, Tools::getValue('logging_enabled') ? '1' : '0');

        $idCarrier = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier > 0) {
            $carrier = new Carrier($idCarrier);
            if (Validate::isLoadedObject($carrier)) {
                $carrier->id_tax_rules_group = (int) Tools::getValue('tax_rules_group_id', 0);
                foreach (Language::getLanguages(false) as $lang) {
                    $carrier->delay[$lang['id_lang']] = Tools::getValue('announced_delay', '');
                }
                $carrier->save();
            }
        }

        foreach (OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES as $code) {
            OklaSchenkerConfig::setSurchargeEnabled($code, (bool) Tools::getValue('surcharge_' . $code));
        }

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'config', 'Configuration mise à jour', [
            'mode' => Tools::getValue('mode'),
            'tax_rules_group_id' => Tools::getValue('tax_rules_group_id'),
            'access_key' => $accessKey, // masqué automatiquement par OklaSchenkerLogger::sanitize()
        ]);

        return $this->displayConfirmation($this->l('Configuration enregistrée.'));
    }

    private function processActivateCarrier(bool $activate): string
    {
        $idCarrier = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier <= 0) {
            return $this->displayError($this->l('Aucun transporteur Schenker - OK-LA n\'a été créé.'));
        }
        $carrier = new Carrier($idCarrier);
        if (!Validate::isLoadedObject($carrier)) {
            return $this->displayError($this->l('Transporteur introuvable.'));
        }

        if ($activate && (int) OklaSchenkerConfig::get(OklaSchenkerConfig::TAX_RULES_GROUP_ID) === 0) {
            return $this->displayError($this->l('Configurez un groupe de règles de taxe avant d\'activer le transporteur.'));
        }

        $carrier->active = $activate;
        $carrier->save();

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'carrier', $activate ? 'Transporteur activé' : 'Transporteur désactivé', ['id_carrier' => $idCarrier]);

        return $this->displayConfirmation($activate ? $this->l('Transporteur activé.') : $this->l('Transporteur désactivé.'));
    }

    private function buildImportPreview(): array
    {
        $dataFile = _PS_MODULE_DIR_ . 'oklaschenker/data/schenker_tarifs_extraits.json';
        try {
            $repo = OklaSchenkerArrayTariffRepository::fromJsonFile($dataFile);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $counts = $repo->countBrackets();

        return [
            'ok' => true,
            'source_filename' => 'schenker_tarifs_extraits.json',
            'departments_count' => $repo->countDepartments(),
            'less100_count' => $counts['less_100kg'],
            'over100_count' => $counts['over_100kg'],
        ];
    }

    private function processConfirmImport(): string
    {
        $dataFile = _PS_MODULE_DIR_ . 'oklaschenker/data/schenker_tarifs_extraits.json';
        $raw = file_get_contents($dataFile);
        $json = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($json)) {
            return $this->displayError($this->l('Fichier tarifaire illisible, import annulé.'));
        }

        Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'oklaschenker_rate_less100`');
        Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'oklaschenker_rate_over100`');
        Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'oklaschenker_urban_department`');

        $less100Rows = [];
        foreach ($json['less_100kg_rates'] ?? [] as $row) {
            $less100Rows[] = [
                'department' => pSQL((string) $row['department']),
                'label' => pSQL((string) ($row['label'] ?? '')),
                'min_kg' => (float) $row['min_kg'],
                'max_kg' => (float) $row['max_kg'],
                'price_ht' => (float) $row['price_ht'],
            ];
        }
        Db::getInstance()->insert('oklaschenker_rate_less100', $less100Rows);

        $over100Rows = [];
        foreach ($json['over_100kg_rates'] ?? [] as $row) {
            $over100Rows[] = [
                'department' => pSQL((string) $row['department']),
                'label' => pSQL((string) ($row['label'] ?? '')),
                'min_kg' => (float) $row['min_kg'],
                'max_kg' => (float) $row['max_kg'],
                'price_per_100kg_ht' => (float) $row['price_per_100kg_ht'],
            ];
        }
        Db::getInstance()->insert('oklaschenker_rate_over100', $over100Rows);

        $urbanRows = [];
        foreach ($json['urban_departments'] ?? [] as $dept) {
            $urbanRows[] = ['department' => pSQL((string) $dept)];
        }
        if (!empty($urbanRows)) {
            Db::getInstance()->insert('oklaschenker_urban_department', $urbanRows, false, true, Db::INSERT_IGNORE);
        }

        Db::getInstance()->insert('oklaschenker_import_log', [
            'source_filename' => pSQL($json['source_file'] ?? 'schenker_tarifs_extraits.json'),
            'departments_count' => count(array_unique(array_column($json['less_100kg_rates'] ?? [], 'department'))),
            'less100_count' => count($less100Rows),
            'over100_count' => count($over100Rows),
            'urban_departments_count' => count($urbanRows),
            'status' => 'success',
            'id_employee' => (int) $this->context->employee->id,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'import', 'Grille tarifaire importée', [
            'less100' => count($less100Rows),
            'over100' => count($over100Rows),
            'urban' => count($urbanRows),
        ]);

        return $this->displayConfirmation($this->l('Grille tarifaire importée avec succès.'));
    }

    private function runTestRate(): array
    {
        $postcode = trim((string) Tools::getValue('test_postcode', ''));
        $weight = (float) Tools::getValue('test_weight', 0);

        $resolver = new OklaSchenkerAddressResolver();
        $resolved = $resolver->resolveDepartment(['postcode' => $postcode, 'country_iso' => 'FR']);

        if (!$resolved['ok']) {
            return ['ok' => false, 'reason' => $resolved['reason'], 'postcode' => $postcode, 'weight' => $weight];
        }

        $calculator = new OklaSchenkerRateCalculator(new OklaSchenkerDbTariffRepository());
        $trace = $calculator->calculate($resolved['department'], $weight, new DateTimeImmutable());
        $trace['postcode'] = $postcode;

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'test_rate', 'Test tarifaire manuel exécuté', $trace);

        return $trace;
    }

    private function fetchLegacyCarrierReport(): array
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('oklaschenker_legacy_carrier_report')
            ->orderBy('date_detected DESC');

        $rows = Db::getInstance()->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    private function processRefreshLegacyReport(): string
    {
        $detector = new OklaSchenkerLegacyCarrierDetector();
        $legacyCarriers = $detector->findLegacySchenkerCarriers();

        Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'oklaschenker_legacy_carrier_report`');
        foreach ($legacyCarriers as $legacy) {
            Db::getInstance()->insert('oklaschenker_legacy_carrier_report', [
                'id_carrier' => $legacy['id_carrier'],
                'name' => pSQL($legacy['name']),
                'active' => $legacy['active'] ? 1 : 0,
                'deleted' => $legacy['deleted'] ? 1 : 0,
                'date_detected' => date('Y-m-d H:i:s'),
            ]);
        }

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'legacy_detection', 'Rapport des anciens transporteurs Schenker rafraîchi', ['count' => count($legacyCarriers)]);

        return $this->displayConfirmation(sprintf($this->l('%d ancien(s) transporteur(s) évoquant Schenker détecté(s).'), count($legacyCarriers)));
    }

    /**
     * Désactive (ne supprime JAMAIS) un ancien transporteur AD SCHENKER, uniquement
     * après confirmation explicite cochée dans le formulaire.
     */
    private function processDisableLegacyCarrier(int $idCarrier): string
    {
        $ownCarrierId = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier <= 0 || $idCarrier === $ownCarrierId) {
            return $this->displayError($this->l('Transporteur invalide.'));
        }

        $carrier = new Carrier($idCarrier);
        if (!Validate::isLoadedObject($carrier)) {
            return $this->displayError($this->l('Transporteur introuvable.'));
        }

        $carrier->active = false;
        $carrier->save();

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_WARNING, 'legacy_carrier', 'Ancien transporteur Schenker désactivé manuellement (jamais supprimé)', [
            'id_carrier' => $idCarrier,
            'name' => $carrier->name,
        ]);

        return $this->displayConfirmation($this->l('Ancien transporteur désactivé (non supprimé).'));
    }

    private function fetchRecentLogs(): array
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('oklaschenker_log')
            ->orderBy('date_add DESC');
        $sql->limit(50);

        $rows = Db::getInstance()->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    private function processPurgeData(): string
    {
        if (!$this->canWrite()) {
            return $this->displayError($this->l('Permission de suppression requise.'));
        }

        $this->purgeAllData();

        return $this->displayConfirmation($this->l('Données du module supprimées définitivement.'));
    }
}
