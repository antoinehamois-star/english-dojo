# Exemple de configuration — sans secret

Un module PrestaShop stocke sa configuration dans la table `ps_configuration`,
via l'écran back-office du module (il n'y a pas de fichier `.env` en jeu).
Ce document donne, à titre d'exemple **non pré-rempli dans le code**, les
valeurs types que le gestionnaire OK-LA doit saisir lui-même.

| Champ back-office | Exemple de valeur | Obligatoire Phase 1 ? |
|---|---|---|
| Groupe de règles de taxe | « TVA FR 20% » (à choisir dans la liste existante du site) | Oui (pour activer le transporteur) |
| Délai annoncé au client | `2 à 4 jours ouvrés` | Non |
| Journalisation activée | Coché | Recommandé |
| Suppléments auto (4 cases) | Cochés par défaut | Non (désactivables) |
| Mode | `test` | Sans effet en Phase 1 |
| URL WSDL Test | `https://eschenker-fat.dbschenker.com/webservice/bookingWebServiceV1_1?wsdl` | Réservé Phase 2, sans effet |
| URL WSDL Production | `https://eschenker.dbschenker.com/webservice/bookingWebServiceV1_1?wsdl` | Réservé Phase 2, sans effet |
| Access Key | *(laisser vide)* | Réservé Phase 2 — **ne jamais committer de vraie clé dans Git** |
| Group ID | *(laisser vide sauf indication de l'administrateur du portail Connect)* | Réservé Phase 2 |
| Numéro de compte Schenker | *(laisser vide)* | Réservé Phase 2 |

**Aucun identifiant réel n'est fourni avec ce module.** Les URLs WSDL
ci-dessus sont recopiées telles quelles depuis
`ConnectBookingWebservice_v1.3.3.xlsx` (feuille « 1. General information »)
à titre de référence documentaire — elles ne sont interrogées par aucun code
de la Phase 1.
