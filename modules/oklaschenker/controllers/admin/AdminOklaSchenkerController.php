<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Contrôleur back-office unique du module (Phase 1) :
 *  - configuration (transporteur, TVA, délai, journalisation, suppléments) ;
 *  - import contrôlé de la grille tarifaire ;
 *  - outil « Tester un tarif » avec trace complète ;
 *  - rapport des anciens transporteurs évoquant Schenker (lecture seule) ;
 *  - consultation des journaux techniques ;
 *  - suppression définitive des données (à double confirmation).
 *
 * N'a jamais pu être chargé dans un vrai back-office PrestaShop pendant ce
 * développement (aucune instance disponible) — voir docs/ANALYSE_TECHNIQUE.md §8.
 * Toute action d'écriture passe par OklaSchenkerLogger::log().
 */
class AdminOklaSchenkerController extends AdminController
{
    /** @var Oklaschenker */
    public $module;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        if (!$this->module instanceof Oklaschenker) {
            $this->module = Module::getInstanceByName('oklaschenker');
        }
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign([
            'okla_config_form_action' => $this->context->link->getAdminLink('AdminOklaSchenkerController'),
            'okla_token' => $this->token,
            'okla_config' => $this->collectConfigViewData(),
            'okla_import_preview' => Tools::isSubmit('oklaSchenkerPreviewImport') ? $this->buildImportPreview() : null,
            'okla_test_result' => Tools::isSubmit('oklaSchenkerTestRate') ? $this->runTestRate() : null,
            'okla_legacy_carriers' => $this->fetchLegacyCarrierReport(),
            'okla_recent_logs' => $this->fetchRecentLogs(),
            'okla_surcharge_codes' => OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES,
        ]);

        $this->context->smarty->assign('content', $this->context->smarty->fetch($this->getTemplatePath('configure.tpl')));
    }

    public function getTemplatePath($template)
    {
        return _PS_MODULE_DIR_ . 'oklaschenker/views/templates/admin/' . $template;
    }

    public function postProcess()
    {
        if (!$this->canWrite()) {
            $this->errors[] = $this->l('Vous n\'avez pas la permission d\'effectuer cette action.');

            return parent::postProcess();
        }

        if (Tools::isSubmit('oklaSchenkerSaveConfig')) {
            $this->processSaveConfig();
        } elseif (Tools::isSubmit('oklaSchenkerConfirmImport')) {
            $this->processConfirmImport();
        } elseif (Tools::isSubmit('oklaSchenkerActivateCarrier')) {
            $this->processActivateCarrier((bool) Tools::getValue('oklaSchenkerActivateCarrier'));
        } elseif (Tools::isSubmit('oklaSchenkerRefreshLegacyReport')) {
            $this->processRefreshLegacyReport();
        } elseif (Tools::isSubmit('oklaSchenkerDisableLegacyCarrier') && Tools::isSubmit('oklaSchenkerConfirmDisableLegacy')) {
            $this->processDisableLegacyCarrier((int) Tools::getValue('oklaSchenkerDisableLegacyCarrier'));
        } elseif (Tools::isSubmit('oklaSchenkerPurgeData') && Tools::getValue('oklaSchenkerPurgeConfirmText') === 'SUPPRIMER') {
            $this->processPurgeData();
        }

        parent::postProcess();
    }

    private function canWrite(): bool
    {
        return (bool) Profile::hasPermission((int) $this->context->employee->id_profile, $this->id, 'edit');
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

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
                    static fn ($code) => OklaSchenkerConfig::isSurchargeEnabled($code),
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

    private function processSaveConfig(): void
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

        $this->confirmations[] = $this->l('Configuration enregistrée.');
    }

    private function processActivateCarrier(bool $activate): void
    {
        $idCarrier = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier <= 0) {
            $this->errors[] = $this->l('Aucun transporteur Schenker - OK-LA n\'a été créé.');

            return;
        }
        $carrier = new Carrier($idCarrier);
        if (!Validate::isLoadedObject($carrier)) {
            $this->errors[] = $this->l('Transporteur introuvable.');

            return;
        }

        if ($activate && (int) OklaSchenkerConfig::get(OklaSchenkerConfig::TAX_RULES_GROUP_ID) === 0) {
            $this->errors[] = $this->l('Configurez un groupe de règles de taxe avant d\'activer le transporteur.');

            return;
        }

        $carrier->active = $activate;
        $carrier->save();

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_INFO, 'carrier', $activate ? 'Transporteur activé' : 'Transporteur désactivé', ['id_carrier' => $idCarrier]);
        $this->confirmations[] = $activate ? $this->l('Transporteur activé.') : $this->l('Transporteur désactivé.');
    }

    // ------------------------------------------------------------------
    // Import de la grille tarifaire
    // ------------------------------------------------------------------

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

    private function processConfirmImport(): void
    {
        $dataFile = _PS_MODULE_DIR_ . 'oklaschenker/data/schenker_tarifs_extraits.json';
        $raw = file_get_contents($dataFile);
        $json = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($json)) {
            $this->errors[] = $this->l('Fichier tarifaire illisible, import annulé.');

            return;
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

        $this->confirmations[] = $this->l('Grille tarifaire importée avec succès.');
    }

    // ------------------------------------------------------------------
    // Test tarifaire
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Transporteurs legacy AD SCHENKER
    // ------------------------------------------------------------------

    private function fetchLegacyCarrierReport(): array
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('oklaschenker_legacy_carrier_report')
            ->orderBy('date_detected DESC');

        $rows = Db::getInstance()->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    private function processRefreshLegacyReport(): void
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
        $this->confirmations[] = sprintf($this->l('%d ancien(s) transporteur(s) évoquant Schenker détecté(s).'), count($legacyCarriers));
    }

    /**
     * Désactive (ne supprime JAMAIS) un ancien transporteur AD SCHENKER, uniquement
     * après confirmation explicite cochée dans le formulaire.
     */
    private function processDisableLegacyCarrier(int $idCarrier): void
    {
        $ownCarrierId = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);
        if ($idCarrier <= 0 || $idCarrier === $ownCarrierId) {
            $this->errors[] = $this->l('Transporteur invalide.');

            return;
        }

        $carrier = new Carrier($idCarrier);
        if (!Validate::isLoadedObject($carrier)) {
            $this->errors[] = $this->l('Transporteur introuvable.');

            return;
        }

        $carrier->active = false;
        $carrier->save();

        OklaSchenkerLogger::log(OklaSchenkerLogger::LEVEL_WARNING, 'legacy_carrier', 'Ancien transporteur Schenker désactivé manuellement (jamais supprimé)', [
            'id_carrier' => $idCarrier,
            'name' => $carrier->name,
        ]);
        $this->confirmations[] = $this->l('Ancien transporteur désactivé (non supprimé).');
    }

    // ------------------------------------------------------------------
    // Journaux
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Purge définitive (double confirmation)
    // ------------------------------------------------------------------

    private function processPurgeData(): void
    {
        if (!(bool) Profile::hasPermission((int) $this->context->employee->id_profile, $this->id, 'delete')) {
            $this->errors[] = $this->l('Permission de suppression requise.');

            return;
        }

        $this->module->purgeAllData();
        $this->confirmations[] = $this->l('Données du module supprimées définitivement.');
    }
}
