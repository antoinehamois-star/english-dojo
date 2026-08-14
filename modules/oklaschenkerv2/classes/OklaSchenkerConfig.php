<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Accès centralisé à la configuration du module (table ps_configuration).
 * Aucun identifiant Schenker n'a de valeur par défaut : tout reste vide
 * tant que le gestionnaire ne l'a pas saisi manuellement en back-office.
 */
class OklaSchenkerConfig
{
    public const MODE = 'OKLASCHENKER_MODE'; // 'test' | 'production' — réservé Phase 2 (aucun appel SOAP en Phase 1)
    public const WSDL_TEST_URL = 'OKLASCHENKER_WSDL_TEST_URL'; // réservé Phase 2
    public const WSDL_PROD_URL = 'OKLASCHENKER_WSDL_PROD_URL'; // réservé Phase 2
    public const ACCESS_KEY = 'OKLASCHENKER_ACCESS_KEY'; // réservé Phase 2, jamais journalisé en clair
    public const GROUP_ID = 'OKLASCHENKER_GROUP_ID'; // réservé Phase 2
    public const ACCOUNT_NUMBER = 'OKLASCHENKER_ACCOUNT_NUMBER'; // réservé Phase 2
    public const CARRIER_ID = 'OKLASCHENKER_CARRIER_ID';
    public const TAX_RULES_GROUP_ID = 'OKLASCHENKER_TAX_RULES_GROUP_ID';
    public const ANNOUNCED_DELAY = 'OKLASCHENKER_ANNOUNCED_DELAY';
    public const LOGGING_ENABLED = 'OKLASCHENKER_LOGGING_ENABLED';
    public const SURCHARGE_ENABLED_PREFIX = 'OKLASCHENKER_SURCHARGE_ENABLED_';

    /** @var array<string,mixed> */
    private static array $defaults = [
        self::MODE => 'test',
        self::WSDL_TEST_URL => '',
        self::WSDL_PROD_URL => '',
        self::ACCESS_KEY => '',
        self::GROUP_ID => '',
        self::ACCOUNT_NUMBER => '',
        self::CARRIER_ID => 0,
        self::TAX_RULES_GROUP_ID => 0,
        self::ANNOUNCED_DELAY => '',
        self::LOGGING_ENABLED => true,
    ];

    public static function get(string $key)
    {
        $value = Configuration::get($key);
        if ($value === false || $value === null) {
            return self::$defaults[$key] ?? null;
        }

        return $value;
    }

    public static function set(string $key, $value): bool
    {
        return Configuration::updateValue($key, $value);
    }

    public static function isSurchargeEnabled(string $surchargeCode): bool
    {
        $value = Configuration::get(self::SURCHARGE_ENABLED_PREFIX . $surchargeCode);

        // Activé par défaut si jamais configuré (cf. docs/ANALYSE_TECHNIQUE.md §2.1),
        // sauf pour les suppléments hors liste "auto" qui ne doivent jamais s'activer seuls.
        if ($value === false || $value === null || $value === '') {
            return in_array($surchargeCode, OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES, true);
        }

        return (bool) $value;
    }

    public static function setSurchargeEnabled(string $surchargeCode, bool $enabled): bool
    {
        return Configuration::updateValue(self::SURCHARGE_ENABLED_PREFIX . $surchargeCode, $enabled ? '1' : '0');
    }

    /**
     * Supprime toutes les clés de configuration du module (utilisé par uninstall()).
     */
    public static function deleteAll(): void
    {
        foreach (array_keys(self::$defaults) as $key) {
            Configuration::deleteByName($key);
        }
        foreach (OklaSchenkerRateCalculator::AUTO_SURCHARGE_CODES as $code) {
            Configuration::deleteByName(self::SURCHARGE_ENABLED_PREFIX . $code);
        }
    }
}
