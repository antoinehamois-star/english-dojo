<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

$engine = _MYSQL_ENGINE_ ?? 'InnoDB';

return [
    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_rate_less100` (
        `id_oklaschenker_rate_less100` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `department` VARCHAR(4) NOT NULL,
        `label` VARCHAR(64) NOT NULL DEFAULT \'\',
        `min_kg` DECIMAL(10,2) NOT NULL,
        `max_kg` DECIMAL(10,2) NOT NULL,
        `price_ht` DECIMAL(10,2) NOT NULL,
        PRIMARY KEY (`id_oklaschenker_rate_less100`),
        KEY `idx_department_weight` (`department`, `min_kg`, `max_kg`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_rate_over100` (
        `id_oklaschenker_rate_over100` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `department` VARCHAR(4) NOT NULL,
        `label` VARCHAR(64) NOT NULL DEFAULT \'\',
        `min_kg` DECIMAL(10,2) NOT NULL,
        `max_kg` DECIMAL(10,2) NOT NULL,
        `price_per_100kg_ht` DECIMAL(10,2) NOT NULL,
        PRIMARY KEY (`id_oklaschenker_rate_over100`),
        KEY `idx_department_weight` (`department`, `min_kg`, `max_kg`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_urban_department` (
        `department` VARCHAR(4) NOT NULL,
        PRIMARY KEY (`department`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_surcharge` (
        `code` VARCHAR(64) NOT NULL,
        `label` VARCHAR(255) NOT NULL,
        `application` VARCHAR(255) NOT NULL DEFAULT \'\',
        `amount` DECIMAL(10,3) NOT NULL,
        `extra_1` VARCHAR(255) DEFAULT NULL,
        `extra_2` DECIMAL(10,3) DEFAULT NULL,
        `is_auto_applicable` TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`code`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_calc_log` (
        `id_oklaschenker_calc_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_cart` INT UNSIGNED DEFAULT NULL,
        `id_order` INT UNSIGNED DEFAULT NULL,
        `department` VARCHAR(4) DEFAULT NULL,
        `weight_kg` DECIMAL(10,2) DEFAULT NULL,
        `ok` TINYINT(1) NOT NULL DEFAULT 0,
        `reason` VARCHAR(64) DEFAULT NULL,
        `base_price_ht` DECIMAL(10,2) DEFAULT NULL,
        `total_price_ht` DECIMAL(10,2) DEFAULT NULL,
        `trace_json` TEXT,
        `date_add` DATETIME NOT NULL,
        PRIMARY KEY (`id_oklaschenker_calc_log`),
        KEY `idx_date_add` (`date_add`),
        KEY `idx_id_cart` (`id_cart`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_log` (
        `id_oklaschenker_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `level` VARCHAR(16) NOT NULL,
        `context` VARCHAR(64) NOT NULL,
        `message` VARCHAR(500) NOT NULL,
        `data` TEXT,
        `id_employee` INT UNSIGNED DEFAULT NULL,
        `date_add` DATETIME NOT NULL,
        PRIMARY KEY (`id_oklaschenker_log`),
        KEY `idx_date_add` (`date_add`),
        KEY `idx_level` (`level`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_import_log` (
        `id_oklaschenker_import_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `source_filename` VARCHAR(255) NOT NULL,
        `departments_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `less100_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `over100_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `urban_departments_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `status` VARCHAR(16) NOT NULL,
        `id_employee` INT UNSIGNED DEFAULT NULL,
        `date_add` DATETIME NOT NULL,
        PRIMARY KEY (`id_oklaschenker_import_log`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'oklaschenker_legacy_carrier_report` (
        `id_oklaschenker_legacy_carrier_report` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_carrier` INT UNSIGNED NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `active` TINYINT(1) NOT NULL,
        `deleted` TINYINT(1) NOT NULL,
        `date_detected` DATETIME NOT NULL,
        PRIMARY KEY (`id_oklaschenker_legacy_carrier_report`),
        KEY `idx_id_carrier` (`id_carrier`)
    ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',
];
