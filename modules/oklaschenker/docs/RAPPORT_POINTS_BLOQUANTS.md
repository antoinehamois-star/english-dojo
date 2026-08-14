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

   **Troisième confirmation, la plus grave, ayant conduit à un changement
   d'architecture** : après correction du bug PHP 7.4 ci-dessus, l'accès à la
   page de configuration continuait à échouer avec le même message
   « Le contrôleur ... est manquant ou non valable. », de façon strictement
   reproductible à 100 % (à chaque tentative, tous les jours). Les hypothèses
   suivantes ont été testées puis **toutes écartées** avec le gestionnaire :
   - cache applicatif PrestaShop (`var/cache/{prod,dev}`) — vidé plusieurs
     fois, aucun effet ;
   - cache de classes PrestaShop (`class_index.php`) — supprimé, aucun effet ;
   - permissions ACL (`ps_tab` / `ps_authorization_role` / `ps_access`) —
     vérifiées en base, présentes et correctement liées au profil ;
   - OPcache serveur figé sur un ancien fichier — écarté en testant un
     **deuxième module de diagnostic** (`oklatest`, contrôleur minimal sans
     aucune dépendance) puis un **troisième**, sous un nom de fichier jamais
     vu du serveur (`oklaschenkerv2`) : échec strictement identique dans les
     deux cas ;
   - incident ponctuel de quota base de données OVH (`max_questions` /
     `max_user_connections`) — écarté par le gestionnaire lui-même, qui a fait
     remarquer à juste titre que l'échec est reproductible à 100 % sur
     plusieurs jours alors que les dépassements de quota observés dans les
     journaux sont ponctuels (quelques secondes toutes les 30–45 minutes) ;
     statistiquement incompatible avec un échec systématique.

   **Conclusion retenue** : cette installation PrestaShop spécifique ne
   parvient pas à résoudre/charger un `Tab` + `AdminController` fourni par un
   module tiers, quelle que soit sa simplicité, alors qu'un module tiers déjà
   installé sur ce même site (Smartsupp) fonctionne normalement en utilisant
   **`getContent()`** (formulaire de configuration affiché directement dans
   la liste des modules, via le bouton « Configurer », sans contrôleur
   dédié). Le gestionnaire a lui-même suggéré cette piste après plusieurs
   jours d'échecs identiques (« peut être que la solution est juste que le
   module ne s'affiche pas sur le coté mais d'une façon différente »). Le
   module a donc été refondu : toute la logique métier déjà écrite et testée
   (moteur tarifaire, import, détection des anciens transporteurs, journaux,
   zone dangereuse) est conservée à l'identique, mais son point d'entrée
   back-office est désormais `oklaschenker::getContent()` et non plus un
   `Tab`/`AdminController` séparé. `install()`/`uninstall()` ne créent plus
   aucun `Tab`. Voir l'en-tête de `oklaschenker.php` et `ANALYSE_TECHNIQUE.md`
   §4 pour le détail.

   Cette bascule d'architecture n'a, comme les points précédents, pu être
   décidée qu'à partir des retours d'installation réels — elle n'était pas
   anticipable au moment du développement initial hors ligne.

   **Cinquième confirmation, la plus subtile** : une fois `getContent()`
   fonctionnel et le transporteur activé (taxe/délai enregistrés, grille
   importée, moteur validé sur deux cas réels via l'outil de test — 20 kg et
   110 kg vers Paris), **le transporteur restait invisible dans le tunnel de
   commande**, quelles que soient l'activation, l'association boutique, les
   groupes clients, les zones ou les restrictions par fiche produit — toutes
   vérifiées correctes en base par requêtes SQL directes avec le
   gestionnaire. Une version de diagnostic journalisant systématiquement
   chaque sortie de `getOrderShippingCost()` (y compris les cas jusque-là
   silencieux) a confirmé que **le module n'était jamais appelé du tout**.
   Cause identifiée en lisant directement `classes/Carrier.php` du dépôt
   officiel PrestaShop 1.7.8.11 (`Carrier::getCarriers()`, appelée par
   `getCarriersForOrder()`) : les transporteurs de type module ne sont inclus
   dans la liste des transporteurs éligibles au tunnel de commande que si
   `need_range = 1` (condition SQL exacte :
   `AND (c.is_module = 0 OR c.need_range = 1)`). `createOwnCarrier()`
   initialisait ce champ à `false`, en supposant à tort qu'un transporteur
   externe (`shipping_external = true`) n'en avait pas besoin — alors que
   `need_range` conditionne en réalité l'apparition même du transporteur dans
   la liste, indépendamment de `shipping_external`. Corrigé (mis à `true`),
   avec une correction automatique supplémentaire à chaque activation du
   transporteur (`processActivateCarrier()`) pour rattraper les transporteurs
   déjà créés par une version antérieure sans réinstallation. Ce bug n'avait
   pu être détecté par aucun des 25 tests du moteur tarifaire (qui testent le
   calcul pur, jamais l'éligibilité du transporteur côté PrestaShop), ni par
   une simple relecture du code sans exécution réelle.

   **Quatrième confirmation** : une fois la page de configuration effectivement
   accessible via `getContent()`, l'enregistrement du formulaire (groupe de
   taxe + délai) provoquait une erreur 500 Symfony : « Attempted to call an
   undefined method named "hasPermission" of class "Profile". ». Cause :
   `canWrite()` appelait `Profile::hasPermission(...)`, une méthode qui
   n'existe pas dans l'API PrestaShop réelle (confusion avec une API d'un
   autre framework). Corrigé en utilisant `Tab::checkTabRights()`, la
   méthode réellement utilisée par le cœur PrestaShop
   (`AdminController::viewAccess()`) pour vérifier les droits de l'employé
   connecté sur un onglet donné. Ce bug bloquait uniquement l'enregistrement
   (POST), pas l'affichage de la page — d'où sa découverte seulement au
   moment de cliquer sur « Enregistrer ». Comme les points précédents, ce
   diagnostic n'était possible qu'avec un retour d'utilisation réel.

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

4. **Aucune règle d'altitude, de gabarit ou de poids volumétrique n'est
   appliquée au tarif calculé.** Un tableau des contraintes de service DB
   SCHENKER (fourni par le gestionnaire le 14/08/2026 : plafonds de poids,
   dimensions, volume et une ligne « Densité minimale (national) : 50 kg/m³ »
   par type de service system/pallet) a depuis été analysé. Il donne des
   **limites d'éligibilité physiques**, pas une formule de tarification : il
   ne précise à aucun endroit ce qui se passe en dessous de ce seuil de
   densité (facturation au poids volumétrique recalculé ? refus du colis ?
   autre ?). En l'absence de cette information, **le tarif calculé n'est
   toujours basé que sur le poids réel** — aucune formule n'a été inventée.
   Un avertissement (pas un correctif de prix) a été ajouté sur la page
   commande du back-office : si les dimensions des produits sont renseignées
   dans le catalogue, le module calcule la densité réelle de la commande et
   signale visuellement quand elle est sous 50 kg/m³, pour une vérification
   manuelle avant expédition. Si les dimensions ne sont pas renseignées en
   fiche produit, aucun avertissement n'est affiché (pas d'estimation sur
   donnée absente). Seul le plafond réel de la grille de prix (999 kg) reste
   appliqué au calcul lui-même.

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
