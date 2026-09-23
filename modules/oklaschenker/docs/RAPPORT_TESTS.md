# Rapport de tests réellement exécutés — oklaschenker (Phase 1)

Commande exécutée : `php tests/RateCalculatorTest.php`
Environnement : PHP 8.4.19 CLI (sandbox de développement, sans PrestaShop).
Date d'exécution : 2026-08-06 15:21:37.
Résultat : **25/25 assertions PASS, 0 FAIL** (sortie brute intégrale ci-dessous, non modifiée).

```
Résultats des tests du moteur tarifaire (Phase 1, exécutés réellement en PHP CLI le 2026-08-06 15:21:37):
==============================================================================
[PASS] 1. Département standard (01, 5kg)
[PASS] 1. Prix HT département standard = fixture JSON — valeur = 29.5
[PASS] 2a. Département 06 est bien listé comme urbain dans la fixture
[PASS] 2b. Supplément zone urbaine appliqué pour le département 06
[PASS] 2c. Total HT = base + supplément zone urbaine + sûreté/qualité + énergie (tous activés par défaut) — valeur = 54.36
[PASS] 3a. Code postal DOM-TOM (97200) résolu en département "97"
[PASS] 3b. Aucun tarif pour le département 97 (absent de la grille source)
[PASS] 3c. manual_quote_required = true pour destination exclue
[PASS] 4. Aucun supplément altitude inventé (résultat déterministe, aucune donnée source) — valeur = 36.28
[PASS] 5. Poids 95kg (< 100kg) utilise la table less_100kg_rates
[PASS] 5. Prix HT palier 80-100 (dept 01) — valeur = 61.96
[PASS] 6a. 100kg exactement -> table less_100kg_rates (borne incluse)
[PASS] 6b. Prix HT à 100kg = palier 80-100 — valeur = 61.96
[PASS] 7a. 150kg (> 100kg) utilise la table over_100kg_rates
[PASS] 7b. Prix HT = poids/100 x prix_aux_100kg_ht (fixture JSON) — valeur = 88.52
[PASS] 8. Poids nul rejeté proprement
[PASS] 9. Poids panier à 0 (produit sans poids) bloque le calcul, aucun tarif inventé
[PASS] 10. Adresse sans code postal -> résolution département refusée
[PASS] 11. Département non couvert par la grille -> pas de tarif affiché
[PASS] 12a. Supplément saisonnier appliqué le 15 juillet
[PASS] 12b. Supplément saisonnier absent le 15 novembre
[PASS] 13. Aucun supplément "carburant" inventé (absent de la source, non implémenté)
[PASS] 14. Poids agrégé multicolis traité de façon déterministe (agrégation réelle testée au niveau PrestaShop, non ici) — valeur = 50.54
[PASS] 15. Aucune clé TVA/TTC dans la trace moteur (délégué à PrestaShop)
[PASS] 15b. total_price_ht est bien un float positif
==============================================================================
Total: 25 | PASS: 25 | FAIL: 0
```

Un premier passage de ces tests avait détecté une vraie incohérence
(cas « zone urbaine » écrivait un total attendu qui omettait les 2 suppléments
« toujours actifs » sûreté+qualité et énergie, actifs par défaut) : corrigée
dans `tests/RateCalculatorTest.php`, puis re-testée avec succès. Ceci illustre
que ces tests s'exécutent réellement et ne sont pas de façade.

## Correspondance avec les 23 cas obligatoires de la spécification

| # | Cas demandé | Statut | Détail |
|---|---|---|---|
| 1 | Département standard | ✅ EXÉCUTÉ | Test 1 |
| 2 | Zone urbaine | ✅ EXÉCUTÉ | Test 2a-2c |
| 3 | Île | ✅ EXÉCUTÉ | Test 3a-3c (DOM-TOM absent de la grille → devis manuel) |
| 4 | Altitude | ✅ EXÉCUTÉ (test de non-invention) | Test 4 — aucune donnée d'altitude dans la source ; le test prouve qu'aucun effet n'est simulé |
| 5 | Moins de 100 kg | ✅ EXÉCUTÉ | Test 5 |
| 6 | Exactement 100 kg | ✅ EXÉCUTÉ | Test 6a-6b |
| 7 | Plus de 100 kg | ✅ EXÉCUTÉ | Test 7a-7b |
| 8 | Poids nul | ✅ EXÉCUTÉ | Test 8 |
| 9 | Produit sans poids | ✅ EXÉCUTÉ (au niveau moteur) | Test 9 — l'agrégation panier réelle (produit → poids total) est côté PrestaShop, non exécutable ici, cf. ligne suivante |
| 9b | Agrégation panier PrestaShop → poids | ⛔ NON EXÉCUTÉ | Nécessite une instance PrestaShop réelle (Cart::getTotalWeight) |
| 10 | Adresse sans code postal | ✅ EXÉCUTÉ | Test 10 |
| 11 | Destination exclue | ✅ EXÉCUTÉ | Test 11 |
| 12 | Supplément saison | ✅ EXÉCUTÉ | Test 12a-12b |
| 13 | Supplément carburant | ⚠️ NON APPLICABLE | Aucun supplément nommé « carburant » dans la source ; test 13 vérifie qu'aucun n'est inventé |
| 14 | Panier multicolis | ✅ EXÉCUTÉ (au niveau moteur) | Test 14 — l'agrégation multi-produits réelle est côté PrestaShop, non exécutable ici |
| 15 | Montant HT et TTC | ✅ EXÉCUTÉ (HT) / ⛔ NON EXÉCUTÉ (TTC) | Test 15 — HT vérifié ; le calcul TTC est délégué au moteur de taxes PrestaShop, non simulable sans instance |
| 16 | Ancienne occurrence AD SCHENKER | ⛔ NON EXÉCUTÉ | Code de détection écrit (`OklaSchenkerLegacyCarrierDetector`), jamais exécuté contre une vraie base — aucune instance disponible |
| 17 | Installation et désinstallation | ⛔ NON EXÉCUTÉ | Nécessite une instance PrestaShop réelle |
| 18 | Simulation SOAP | 🚫 HORS PÉRIMÈTRE PHASE 1 | Aucun client SOAP livré (spécification Phase 1) |
| 19 | Erreur SOAP | 🚫 HORS PÉRIMÈTRE PHASE 1 | idem |
| 20 | Timeout | 🚫 HORS PÉRIMÈTRE PHASE 1 | idem |
| 21 | Réservation test | 🚫 HORS PÉRIMÈTRE PHASE 1 | idem |
| 22 | Annulation test | 🚫 HORS PÉRIMÈTRE PHASE 1 | idem |
| 23 | Sauvegarde du suivi | 🚫 HORS PÉRIMÈTRE PHASE 1 | idem |

**Légende** : ✅ EXÉCUTÉ = réellement lancé et vérifié dans cette session. ⛔ NON
EXÉCUTÉ = code écrit mais non vérifiable faute d'environnement PrestaShop réel.
🚫 HORS PÉRIMÈTRE = explicitement exclu par `SPEC_MODULE_PRESTASHOP_SCHENKER.md`.
⚠️ NON APPLICABLE = donnée absente de la source, non inventée.

Aucun de ces statuts n'est présenté comme « réussi » s'il n'a pas été
effectivement exécuté.
