<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * IMPORTANT : ce fichier N'EST PAS exécuté automatiquement par
 * oklaschenker::uninstall(). La désinstallation standard du module
 * (bouton « Désinstaller » du back-office) ne supprime QUE le transporteur
 * (désactivé, pas supprimé) et laisse les tables et données intactes, afin
 * de respecter la consigne « proposer de conserver l'historique Schenker »
 * et « demander confirmation avant suppression des tables ».
 *
 * Ces requêtes ne sont exécutées que via l'action explicite et confirmée
 * « Supprimer définitivement les données du module » de
 * AdminOklaschenkerv2Controller::processPurgeData(), qui exige une
 * confirmation dédiée distincte de la désinstallation du module.
 */

return [
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_rate_less100`;',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_rate_over100`;',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_urban_department`;',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_surcharge`;',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_calc_log`;',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_log`;',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_import_log`;',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'oklaschenker_legacy_carrier_report`;',
];
