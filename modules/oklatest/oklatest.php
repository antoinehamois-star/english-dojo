<?php
/**
 * Module de diagnostic temporaire — sert uniquement à vérifier que
 * l'hébergement peut charger un contrôleur admin de module tiers.
 * À désinstaller et supprimer une fois le diagnostic terminé.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Oklatest extends Module
{
    public function __construct()
    {
        $this->name = 'oklatest';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'OK-LA';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => '1.7.99.99'];

        parent::__construct();

        $this->displayName = 'OKLA Test Diagnostic';
        $this->description = 'Module temporaire de diagnostic - a supprimer apres usage.';
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->installAdminTab()) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $this->uninstallAdminTab();

        return parent::uninstall();
    }

    private function installAdminTab()
    {
        if (Tab::getIdFromClassName('AdminOklaTestController')) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminOklaTestController';
        $tab->module = $this->name;
        $tab->id_parent = 0;
        $tab->icon = 'bug_report';
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'OKLA Test Diagnostic';
        }

        return $tab->add();
    }

    private function uninstallAdminTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminOklaTestController');
        if ($idTab <= 0) {
            return true;
        }
        $tab = new Tab($idTab);

        return $tab->delete();
    }
}
