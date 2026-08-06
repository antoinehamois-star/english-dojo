# Analyse technique — module oklaschenker (Phase 1)

Date : 2026-08-06
Périmètre couvert par cette analyse : les fichiers effectivement fournis à ce jour.

## 0. Cadrage — évolution du périmètre en cours de mission

La mission initiale demandait un module complet (calcul tarifaire **+** réservation SOAP
**+** étiquetage **+** suivi). Un second document, `SPEC_MODULE_PRESTASHOP_SCHENKER.md`,
fourni ensuite par le donneur d'ordre, **réduit explicitement le périmètre de cette
livraison à la Phase 1** :

> Calcul local du prix transport uniquement. Pas d'appel SOAP Schenker. Pas de
> réservation réelle. Pas d'étiquette réelle.

Cette analyse et le module livré suivent donc ce périmètre réduit, qui est la version
la plus récente et la plus spécifique des instructions reçues. La documentation SOAP
(`ConnectBookingWebservice_v1.3.3.xlsx`) a néanmoins été analysée intégralement pour :
préparer une Phase 2 sans reprendre le travail à zéro, et documenter dans le back-office
les champs de configuration qui seront nécessaires plus tard (laissés vides et inertes).

## 1. Sources analysées

| # | Fichier | Statut | Usage |
|---|---|---|---|
| 1 | `ConnectBookingWebservice_v1.3.3.xlsx` (21 feuilles) | Fourni, analysé intégralement | Référence pour la Phase 2 (SOAP), non utilisé en Phase 1 |
| 2 | `TARIF_SCHENKER.xlsx` (13 feuilles) | Fourni, analysé | Source brute de la grille tarifaire |
| 3 | `schenker_tarifs_extraits.json` | Fourni | **Source de données utilisée par le module** |
| 4 | `schenker_tarifs_extraits.csv` | Fourni | Copie lisible du même extrait, pour contrôle humain croisé |
| 5 | `SPEC_MODULE_PRESTASHOP_SCHENKER.md` | Fourni | Cadrage Phase 1, liste des risques à valider |
| 6 | `classic_5.zip` (thème Classic, `theme.yml` : compatibilité `1.7.0.0 → ~`) | Fourni | Référence en lecture seule, **jamais modifié** |
| 7 | Code cœur réel de PrestaShop 1.7.8 du site OK-LA | **Non fourni, aucun dépôt accessible** | Voir section 6 « Points bloquants » |

Le fichier `OKLA-Schenker-tariff-spec-v1.xlsx` mentionné dans la mission initiale n'a
jamais été fourni ; `schenker_tarifs_extraits.json/csv` en tient lieu et est la source
qu'utilise le module.

## 2. Structure des données tarifaires (`schenker_tarifs_extraits.json`)

```
{
  "source_file": "TARIF SCHENKER(1).xlsx",
  "carrier": "SCHENKER",
  "vat_rate": 0.2,                 // informatif seulement — voir §4
  "less_100kg_rates":  [ { department, label, bracket, min_kg, max_kg, price_ht } ],   // 1053 lignes
  "over_100kg_rates":  [ { department, label, bracket, min_kg, max_kg, price_per_100kg_ht } ], // 468 lignes
  "urban_departments": [ "06", "13", ... ],   // 27 départements
  "surcharges_raw":    [ { label, application, amount, extra_1, extra_2 } ],  // 19 règles
  "notes": [ ... ]
}
```

Points vérifiés par script (`python3` + `openpyxl`/`json`, exécuté réellement) :

- **96 départements couverts**, du 01 au 95 + 98 (Monaco). Le département 20 est présent
  sous une seule entrée « CORSE » (tarif dédié, plus élevé — ex. 79,77 € HT pour la
  tranche 1-9 kg contre 29,50 € pour le 01). **Aucun département/territoire d'outre-mer
  (971-976) n'est présent** → aucune règle tarifaire n'existe pour ces destinations.
- Tranches < 100 kg : 9 à 18 paliers par département, prix **forfaitaire** par palier
  (`price_ht`), pas un prix au kg.
- Tranches ≥ 100 kg : 4 à 8 paliers par département, de 101 à **999 kg maximum**,
  prix **au 100 kg** (`price_per_100kg_ht`), à appliquer selon la note du fichier
  source : `poids/100 × prix_aux_100kg_ht`.
- Au-delà de 999 kg, ou pour un département/CP non couvert : **aucune règle** →
  le module doit refuser d'afficher un tarif (devis manuel), conformément à la consigne.
- `urban_departments` : 27 codes départements en zone urbaine Schenker (supplément
  forfaitaire identifié dans `surcharges_raw`).

### 2.1 Classification des 19 suppléments (`surcharges_raw`)

Le fichier source précise lui-même : *« Validation humaine requise avant utilisation en
production »*. Je n'ai donc **pas appliqué automatiquement** les suppléments dont le
déclenchement dépend d'une information absente des données disponibles au moment du
calcul panier. Classification retenue :

**Applicables automatiquement (déclencheur non ambigu, présent dans les données)** :
- Zone urbaine — 6,36 € forfait/expédition (liste de départements fournie).
- Ajustement saisonnier — 15 % sur le tarif départ, du 1er juin au 31 août (dates
  explicites dans le libellé).
- Contribution sûreté et qualité — 1,30 € par expédition (s'applique systématiquement).
- Contribution Transition Énergétique — 1,05 € par expédition (s'applique systématiquement).

Ces 4 règles sont importées en base, **activables/désactivables individuellement** dans
le back-office (décochées = non appliquées), et tracées dans le détail de calcul.

**Importés mais désactivés par défaut, nécessitant une confirmation manuelle avant
activation** (déclencheur non déductible des données commande/panier disponibles, ou
périmètre géographique non fourni par la source) :
- Région parisienne (3,36 €) — la source ne fournit pas la liste des départements
  concernés ; je n'ai pas inventé de liste. À configurer manuellement si le gestionnaire
  confirme le périmètre (ex. Île-de-France).
- Contre-remboursement, garantie marchandise/ad valorem, frais de gestion, frais de
  dossier port dû, surcoût réapprovisionnement/retour, passage à vide, modification
  d'écriture, absence de flux informatique, Fix Day, RDV particulier/professionnel,
  magasinage au-delà de 6 jours, poids erroné, gestion des emballages : ce sont des
  suppléments **conditionnels à un événement logistique** (option choisie, incident,
  délai de stockage) que rien dans le panier PrestaShop ne permet de détecter au moment
  du tunnel de commande. Ils sont stockés en base pour information/back-office mais
  **jamais appliqués automatiquement**.

Aucun montant n'est inventé : tous proviennent tels quels de `surcharges_raw`.

## 3. Aperçu de l'API Schenker (ConnectBookingWebservice v1.3.3) — réservé Phase 2

Hôtes documentés (feuille « 1. General information ») :
- Test : `https://eschenker-fat.dbschenker.com/webservice/bookingWebServiceV1_1?wsdl`
- Production : `https://eschenker.dbschenker.com/webservice/bookingWebServiceV1_1?wsdl`
- Authentification : `Access Key` obligatoire + `Group Id` optionnel, dans
  `<applicationArea>`.

Opérations documentées (feuille « 2. Document Structure ») :
`getBookingRequestLand`, `getBookingRequestAir`, `getBookingRequestOceanLCL`,
`getBookingRequestOceanFCL`, `getBookingCancelRequest`, `getBookingBarcodeRequest`,
`getWaybillRequest`, `getFreightListRequest` (requêtes) ; `getBookingResponse`,
`getBookingBarcodeResponse` (réponses) ; 330 lignes de codes d'erreur (feuille 13) ;
matrices d'options produit système/direct (feuilles 14-15) ; XSD et exemples de requêtes
(feuilles 16-20).

**Aucune de ces opérations n'est appelée en Phase 1.** Les champs de configuration
correspondants (URL WSDL test/prod, Access Key, Group Id, n° de compte) sont présents
dans l'écran de configuration du module, **vides par défaut, non utilisés par le code**,
et clairement annotés « réservé à une phase ultérieure » — afin de ne pas devoir
retoucher le schéma de configuration quand la Phase 2 sera commanditée.

## 4. Taxes et devise

La consigne impose de respecter le moteur de taxes/devises PrestaShop plutôt que de
recalculer une TVA en dur. Le champ `vat_rate: 0.2` du JSON est **informatif** (contexte
d'extraction) et n'est pas utilisé comme taux figé : le transporteur créé référence un
groupe de règles de taxe PrestaShop (`id_tax_rules_group`), configurable dans le
back-office, laissé à 0 (pas de taxe) tant que le gestionnaire ne l'a pas choisi. Le
calcul HT provient uniquement de la grille ; la TVA/TTC est calculée par le moteur de
taxe standard de PrestaShop appliqué au transporteur.

## 5. Adresse, poids, colis — ce qui n'est pas exploité (volontairement)

Conformément à la consigne « ne jamais inventer une dimension, un volume ou un poids » :
- Aucune donnée de volumétrie n'existe dans les fichiers tarifaires fournis (pas de
  grille poids-volumétrique Schenker communiquée) → non implémenté.
- Aucune règle d'altitude n'est présente dans `surcharges_raw` → non implémentée.
- Aucune limite de gabarit (dimensions max) n'est communiquée → non implémentée ; la
  seule limite appliquée est le plafond réel de la grille (999 kg), au-delà duquel le
  module ne propose plus Schenker (devis manuel).
- Le département/code postal est déduit de l'adresse de livraison PrestaShop
  (2 premiers chiffres du code postal, Corse normalisée en « 20 »). Une adresse sans
  code postal exploitable bloque le calcul (pas de tarif affiché).

## 6. Anciens transporteurs « AD SCHENKER »

**Aucun accès à la base de données ou au code du site OK-LA n'a été possible pendant ce
développement** (aucun dépôt GitHub contenant le cœur PrestaShop n'est accessible depuis
cette session — seul `english-dojo`, une application web sans rapport, existe côté
compte GitHub connecté). En conséquence :
- Le code de détection (requête sur `ps_carrier` par nom `LIKE '%AD SCHENKER%'` ou
  `LIKE '%SCHENKER%'`) est écrit et prêt à l'emploi dans `AdminOklaSchenkerController`.
- **Il n'a jamais été exécuté contre une vraie base** : aucune liste réelle de
  transporteurs existants n'a pu être produite. Le rapport de doublons affiché en
  back-office ne sera fiable qu'une fois le module installé sur le vrai site.
- Le module ne supprime et ne modifie **aucun** transporteur existant, quel que soit le
  résultat de cette détection — conformément à la consigne absolue.

## 7. Architecture retenue (Phase 1)

```
modules/oklaschenker/
├── oklaschenker.php                         # install/uninstall, hooks de coût transporteur
├── classes/
│   ├── OklaSchenkerRateCalculator.php       # moteur pur PHP, sans dépendance PrestaShop directe
│   ├── OklaSchenkerTariffRepositoryInterface.php
│   ├── OklaSchenkerAddressResolver.php      # adresse PrestaShop -> département/CP
│   ├── OklaSchenkerConfig.php               # accès Configuration::
│   ├── OklaSchenkerLogger.php               # journal DB + masquage secrets
│   └── Repository/OklaSchenkerDbTariffRepository.php  # implémentation PrestaShop (Db)
├── controllers/admin/AdminOklaSchenkerController.php
├── sql/install.php / uninstall.php
├── data/schenker_tarifs_extraits.json       # jeu de données de référence embarqué
├── views/templates/admin/*.tpl
├── tests/                                    # tests PHP CLI exécutables sans PrestaShop
└── docs/
```

Le moteur de calcul (`OklaSchenkerRateCalculator`) ne dépend d'aucune classe
PrestaShop : il reçoit un tableau de tarifs (via une interface `TariffRepositoryInterface`)
et retourne un tableau de trace de calcul détaillé. Cela permet de l'exécuter et de le
tester réellement en CLI PHP, **sans instance PrestaShop**, ce qui a été fait (voir
`docs/RAPPORT_TESTS.md`). L'intégration PrestaShop (`Repository/OklaSchenkerDbTariffRepository`,
hooks `getOrderShippingCost`) suit l'API documentée du Carrier module externe standard
de PrestaShop 1.7 (pattern utilisé par les modules de transport du marketplace officiel),
mais **n'a pas pu être exécutée** faute d'instance PrestaShop réelle — voir section 8.

## 8. Points bloquants explicites

1. **Aucun dépôt PrestaShop/OK-LA accessible.** Le module est écrit conformément à
   l'API PrestaShop 1.7.8 documentée (classes `Module`, `Carrier`, `ObjectModel`,
   `AdminController`, `Db`), mais son **installation réelle n'a pas pu être testée**
   dans cette session. Aucune capture d'écran, aucun résultat d'installation n'est donc
   revendiqué comme vérifié.
2. **Anciens transporteurs AD SCHENKER non observables** (cf. §6) : le rapport de
   détection est fonctionnel mais jamais exécuté sur données réelles.
3. **Deux fichiers de la mission initiale jamais fournis** (`OKLA-Schenker-tariff-spec-v1.xlsx/.json`) :
   remplacés par `schenker_tarifs_extraits.{json,csv}`, qui portent eux-mêmes la mention
   « validation humaine requise avant utilisation en production ».
4. **Périmètre SOAP explicitement hors Phase 1** : aucun client SOAP fonctionnel n'est
   livré ; seule la structure de configuration est préparée (vide, inerte).
5. **Liste des départements « Région parisienne »** non fournie par la source → règle
   importée mais désactivée par défaut, à confirmer par le gestionnaire.
