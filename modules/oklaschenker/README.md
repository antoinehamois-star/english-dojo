# oklaschenker — Module PrestaShop 1.7.8 (Phase 1)

Calcul tarifaire local Schenker pour le tunnel de commande OK-LA, à partir de la grille
tarifaire contractuelle. **Phase 1 uniquement : aucun appel SOAP, aucune réservation,
aucune étiquette réelle.** Voir `docs/ANALYSE_TECHNIQUE.md` pour le détail complet.

## ⚠️ Statut de vérification — à lire avant toute mise en production

Ce module a été écrit conformément à l'API documentée de PrestaShop 1.7
(`Module`, `CarrierModule`, `Carrier`, `ObjectModel`, `Db`). Le moteur de calcul
tarifaire pur PHP (`classes/OklaSchenkerRateCalculator.php`) a été réellement
testé et exécuté (25 tests, voir `docs/RAPPORT_TESTS.md`).

Le module a ensuite été **réellement installé et testé sur le site OK-LA en
production** par le gestionnaire. Cela a permis de corriger deux bugs concrets
(propriété `Carrier->delay` vide, incompatibilité de syntaxe PHP 7.4+), puis de
constater qu'une page de configuration exposée via un `Tab`/`AdminController`
dédié — pourtant l'architecture standard PrestaShop — ne se chargeait jamais
sur ce site précis (« Le contrôleur ... est manquant ou non valable. »,
reproductible à 100 %, sur trois modules de test indépendants). **La
configuration a donc été refondue pour utiliser `getContent()`** (formulaire
affiché directement dans la liste des modules via le bouton « Configurer »),
mécanisme confirmé fonctionnel sur ce site via un autre module déjà installé.
Voir `docs/RAPPORT_POINTS_BLOQUANTS.md` pour l'historique complet. **Cette
nouvelle version n'a pas encore été retestée en conditions réelles** — c'est
la prochaine étape.

**Avant toute mise en production, un gestionnaire/développeur ayant accès au vrai
site doit :**
1. Installer le module sur un environnement de recette OK-LA (pas en production).
2. Vérifier l'écran de configuration (Modules > Schenker - OK-LA > bouton « Configurer »).
3. Contrôler puis importer la grille tarifaire (bouton dédié).
4. Vérifier le rapport des anciens transporteurs « AD SCHENKER » qui s'affiche
   à l'installation, et ne rien désactiver sans être certain que ce n'est pas
   utilisé par des commandes en cours.
5. Configurer le groupe de règles de taxe et activer le transporteur.
6. Tester un tarif avec l'outil intégré, puis passer une commande réelle en
   recette pour vérifier l'affichage dans le tunnel de commande.

## Installation

1. Copier le dossier `modules/oklaschenker/` (ou le ZIP `oklaschenker.zip`, voir
   plus bas) dans `modules/` de l'installation PrestaShop 1.7.8.
2. Back-office > Modules > Rechercher « Schenker - OK-LA » > Installer.
3. À l'installation, le module :
   - crée ses tables (`ps_oklaschenker_*`) ;
   - crée un transporteur nommé exactement **« Schenker - OK-LA »**, inactif par
     défaut ;
   - détecte (sans les toucher) les transporteurs existants dont le nom contient
     « SCHENKER » (dont d'éventuels « AD SCHENKER ») et les liste en back-office ;
   - **ne modifie, ne supprime, ni ne désactive aucun transporteur existant.**
4. Aller dans Modules > Schenker - OK-LA > bouton « Configurer » pour configurer (pas de menu latéral dédié — voir docs/RAPPORT_POINTS_BLOQUANTS.md).

## Désinstallation

La désinstallation standard (Modules > Désinstaller) désactive uniquement le
transporteur créé par le module. **Aucune donnée, aucune table, aucun ancien
transporteur n'est supprimé.** La suppression définitive des données du module
se fait séparément, depuis la page de configuration, section « Zone
dangereuse », en tapant `SUPPRIMER` pour confirmer.

## Documentation

- `docs/ANALYSE_TECHNIQUE.md` — analyse complète des sources, données tarifaires,
  API Schenker, architecture, points bloquants.
- `docs/GUIDE_CONFIGURATION.md` — guide pas à pas de configuration back-office.
- `docs/RAPPORT_TESTS.md` — résultats réels des tests exécutés.
- `docs/RAPPORT_POINTS_BLOQUANTS.md` — synthèse des points bloquants.
- `docs/ROLLBACK.md` — procédure de retour arrière.
- `docs/config-exemple.md` — exemple de configuration sans secret.

## Périmètre Phase 1 (rappel)

Inclus : calcul tarifaire local (département + poids + suppléments identifiés
sans ambiguïté), affichage dans le tunnel de commande, outil de test tarifaire,
import contrôlé de la grille, bloc d'information en page commande.

Explicitement exclu : tout appel SOAP Schenker, toute réservation, toute
étiquette/waybill, tout numéro de suivi réel.
