# Guide de configuration — oklaschenker (Phase 1)

## 1. Accès à la page de configuration

Back-office > **Modules** > rechercher « Schenker - OK-LA » > bouton **Configurer**.

## 2. Importer la grille tarifaire

Section « Import de la grille tarifaire » :
1. Cliquer sur **Contrôler le fichier de référence embarqué**. Le module lit
   `data/schenker_tarifs_extraits.json` (fourni avec le module) et affiche un
   aperçu (nombre de départements, de paliers < 100 kg, de paliers > 100 kg).
2. Vérifier ces chiffres par rapport à `data/schenker_tarifs_extraits.csv`
   (lisible dans un tableur) si un contrôle humain supplémentaire est souhaité.
3. Cliquer sur **Confirmer l'import**. Cette action remplace intégralement les
   données tarifaires existantes en base.

Chaque import est journalisé (table `oklaschenker_import_log` et écran
« Journaux techniques »).

## 3. Configurer le transporteur

Section « Transporteur » :
- **Groupe de règles de taxe** : obligatoire avant activation. C'est le moteur
  de taxes standard de PrestaShop qui calcule la TVA/TTC affichée au client — le
  module ne calcule jamais de TVA lui-même.
- **Délai annoncé au client** : texte libre affiché sous le nom du transporteur
  dans le tunnel de commande (ex. « 2 à 4 jours ouvrés »).
- **Activer le transporteur** : bouton dédié en bas de la section. Bloqué tant
  qu'aucun groupe de taxe n'est sélectionné.

## 4. Suppléments appliqués automatiquement

Quatre suppléments au déclencheur non ambigu (cf.
`docs/ANALYSE_TECHNIQUE.md` §2.1) peuvent être activés/désactivés
individuellement :
- `URBAN_ZONE` — zone urbaine (liste de départements initialement fournie par
  Schenker, modifiable ensuite par le gestionnaire — voir section 4bis).
- `SEASONAL` — ajustement saisonnier (1er juin → 31 août).
- `SAFETY_QUALITY` — contribution sûreté et qualité.
- `ENERGY_CONTRIBUTION` — contribution Transition Énergétique.

Tous les autres suppléments identifiés dans la grille (contre-remboursement,
région parisienne, RDV, magasinage, etc.) sont stockés en base pour
information mais **ne sont jamais appliqués automatiquement** : leur
déclencheur ne peut pas être déduit de façon fiable du panier PrestaShop.

## 4bis. Modifier la liste des départements en zone urbaine

Section « Zone urbaine (supplément URBAN_ZONE) » : liste actuelle affichée
sous forme d'étiquettes, chacune avec un bouton « × » pour la retirer
(confirmation demandée). Pour ajouter un ou plusieurs départements, saisir
leur(s) code(s) (ex. `77, 95`) dans le champ et cliquer sur « Ajouter ».
Cette liste est une décision du gestionnaire, indépendante d'un nouvel
import de la grille tarifaire (un import ne touche ni ne réinitialise cette
liste — seuls les paliers de poids/prix sont remplacés).

## 5. Tester un tarif

Section « Tester un tarif » : saisir un code postal français et un poids en
kg, cliquer sur **Calculer**. Le résultat affiche :
- le tarif HT total et la règle appliquée (`less_100kg_flat_bracket` ou
  `over_100kg_per_100kg`) ;
- le détail des suppléments appliqués ;
- ou, si aucun tarif n'existe (destination hors grille, poids > 999 kg, etc.),
  la raison exacte et la mention « devis manuel requis ».

## 6. Anciens transporteurs Schenker

Section « Anciens transporteurs évoquant Schenker » : liste en lecture seule
des transporteurs existants dont le nom contient « SCHENKER » (hors le
transporteur créé par ce module). Un bouton **Désactiver** est disponible par
ligne, avec confirmation explicite obligatoire. Aucune suppression n'est
jamais proposée.

## 7. Champs réservés à une phase ultérieure

Les champs Mode, URL WSDL Test/Production, Access Key, Group ID, Numéro de
compte sont affichés pour préparer une Phase 2 (réservation SOAP) mais **ne
sont utilisés par aucun calcul en Phase 1**. Ils restent vides tant que
personne ne les renseigne ; aucune valeur n'est pré-remplie.
