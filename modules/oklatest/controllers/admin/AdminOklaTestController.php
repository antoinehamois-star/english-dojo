<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Contrôleur de diagnostic minimal. Aucune dépendance, aucune logique.
 * Si cette page affiche "OK - le serveur peut charger un contrôleur admin
 * de module", le problème du module oklaschenker vient de son propre code.
 * Si cette page échoue avec la même erreur "contrôleur manquant", le
 * problème est côté serveur/hébergement, indépendant de notre code.
 */
class AdminOklaTestController extends AdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign('content', '<div class="panel"><h1>OK - le serveur peut charger un controleur admin de module.</h1><p>PHP version: ' . htmlspecialchars(PHP_VERSION) . '</p></div>');
    }
}
