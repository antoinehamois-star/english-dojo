# Rapport des points bloquants — oklaschenker (Phase 1)

## Bloquants pour une mise en production directe

1. **Aucune instance PrestaShop réelle disponible pendant ce développement.**
   Ni le dépôt du site OK-LA, ni une base de données, ni un environnement
   d'exécution PrestaShop n'étaient accessibles. Conséquence directe :
   - l'installation du module (`install()`, création du transporteur, création
     des tables) n'a **jamais été exécutée réellement** ;
   - l'intégration au tunnel de commande (`getOrderShippingCost*`) n'a **jamais
     été exécutée réellement** ;
   - le rapport des anciens transporteurs « AD SCHENKER » n'a **jamais été
     généré sur des données réelles**.
   Seul le moteur de calcul tarifaire pur PHP a été exécuté et vérifié (25
   tests, voir `RAPPORT_TESTS.md`).

   **Confirmation concrète de ce risque** : lors du premier essai d'installation
   sur un vrai PrestaShop 1.7.8.11 par le gestionnaire OK-LA, l'installation a
   échoué avec `PrestaShopException: La propriété Carrier->delay est vide.`
   Le champ `delay` du transporteur est obligatoire côté cœur PrestaShop
   (validation `ObjectModel`) ; `createOwnCarrier()` l'initialisait à tort avec
   une chaîne vide. Corrigé (commit suivant) en l'initialisant à une valeur
   neutre (« Délai à configurer »), remplacée ensuite par le gestionnaire via
   l'écran de configuration. Ce type d'erreur — invisible sans exécution
   réelle — est précisément ce que `README.md` demandait de vérifier en
   recette avant toute mise en production.

   **Deuxième confirmation, plus grave** : après correction du bug ci-dessus,
   l'accès à la page de configuration échouait systématiquement avec
   « Le contrôleur AdminOklaSchenkerController est manquant ou non valable. »,
   y compris après plusieurs purges de cache et réinstallations. Cause
   identifiée : le code utilisait de la syntaxe PHP 7.4+ (fonctions fléchées
   `fn (...) => ...`, propriété de classe typée `private Type $x;`) dans
   `AdminOklaSchenkerController.php`, `oklaschenker.php` et
   `OklaSchenkerArrayTariffRepository.php`. Sur un serveur exécutant une
   version de PHP antérieure à 7.4, ceci provoque une erreur de syntaxe
   fatale et silencieuse au chargement du fichier (les erreurs d'affichage
   sont désactivées en mode production dans `config/defines.inc.php`), que
   PrestaShop traduit en message générique « contrôleur manquant ». Corrigé
   en remplaçant ces constructions par leur équivalent compatible PHP 7.1+
   (closures classiques `function (...) { return ...; }`, propriété non
   typée avec annotation `@var`). Ce diagnostic n'aurait pas pu être posé
   sans les retours d'installation réels du gestionnaire OK-LA — la
   compatibilité PHP exacte du serveur cible n'était pas connue au moment du
   développement initial.

2. **Deux fichiers de la mission initiale jamais fournis** :
   `OKLA-Schenker-tariff-spec-v1.xlsx` et `.json`. Remplacés par
   `schenker_tarifs_extraits.{json,csv}`, qui portent eux-mêmes la mention
   *« Validation humaine requise avant utilisation en production »* — cette
   validation humaine reste à faire par un responsable OK-LA/Schenker avant
   d'utiliser les tarifs en clientèle réelle.

3. **Liste des départements « Région parisienne » non fournie** par la
   source : le supplément correspondant (3,36 €) est importé en base mais
   **désactivé par défaut**, en attente d'une confirmation du périmètre
   géographique exact par le gestionnaire.

4. **Aucune règle d'altitude, de gabarit ou de poids volumétrique** n'est
   présente dans les fichiers fournis : ces critères, demandés dans la
   mission initiale, ne sont donc pas implémentés (plutôt que d'inventer des
   seuils). Seul le plafond réel de la grille (999 kg) est appliqué.

5. **Aucun supplément « carburant » distinct** n'existe dans la source ; seule
   une « Contribution Transition Énergétique » est présente et implémentée à
   sa place. À clarifier avec Schenker si un supplément carburant séparé
   existe réellement dans le contrat.

## Hors périmètre — assumé, pas un manque

6. **Aucun appel SOAP, aucune réservation, aucune étiquette** : exclu
   explicitement de la Phase 1 par `SPEC_MODULE_PRESTASHOP_SCHENKER.md`. La
   documentation `ConnectBookingWebservice_v1.3.3.xlsx` a été analysée et
   synthétisée (`ANALYSE_TECHNIQUE.md` §3) pour préparer une Phase 2 sans
   perte d'information, mais aucun code d'appel n'est livré.

## Non-invention — rappel des choix de conception associés

- Aucun identifiant Schenker (Access Key, numéro de compte) n'est pré-rempli.
- Aucun tarif, aucun seuil, aucune règle non présents dans
  `schenker_tarifs_extraits.json` n'ont été ajoutés.
- Quand une donnée manque pour calculer un tarif fiable, le module renvoie
  explicitement « aucun tarif disponible / devis manuel requis » plutôt que
  d'estimer une valeur.

## Recommandation

Avant toute activation en production : installer sur un environnement de
recette OK-LA réel, exécuter le rapport de détection des anciens
transporteurs, importer et contrôler la grille, faire valider les 4
suppléments automatiques par un responsable métier, puis passer plusieurs
commandes de test avant d'activer le transporteur pour la clientèle.
