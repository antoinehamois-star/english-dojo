# Procédure de retour arrière — oklaschenker

## Retour arrière rapide (recommandé)

1. Back-office > Modules > « Schenker - OK-LA » > **Désactiver** (ou
   **Désinstaller**).
   - La désinstallation standard désactive uniquement le transporteur créé
     par le module (`Schenker - OK-LA`). Elle **ne supprime aucune commande,
     aucun ancien transporteur, aucune donnée ni table** du module.
2. Si le transporteur avait été activé pour la clientèle, vérifier qu'aucun
   panier en cours ne le référence plus (PrestaShop masque automatiquement un
   transporteur désactivé dans le tunnel de commande).

À ce stade, le site revient à son état fonctionnel antérieur : aucun ancien
transporteur n'a été touché, aucune commande n'est affectée.

## Retour arrière complet (suppression des données du module)

Uniquement si nécessaire (ex. avant une désinstallation définitive du
module) :

1. Back-office > Modules > rechercher « Schenker - OK-LA » > bouton Configurer > section « Zone dangereuse ».
2. Taper `SUPPRIMER` dans le champ de confirmation, puis cliquer sur
   **Supprimer définitivement les données du module**.
3. Cette action exécute les requêtes de `sql/uninstall.php` (suppression des
   tables `ps_oklaschenker_*`) et purge la configuration (`ps_configuration`).
   **Elle ne supprime ni le transporteur (désactivé, conservé), ni les
   commandes historiques.**

## Retour arrière du code (dépôt Git)

```bash
# Retirer uniquement le dossier du module d'un commit donné :
git revert <sha-du-commit-oklaschenker>

# Ou, pour repartir d'un état antérieur au développement du module :
git checkout <sha-avant-le-module> -- modules/oklaschenker
git commit -m "Rollback: retrait du module oklaschenker"
```

Le module étant entièrement autonome (aucun fichier du cœur PrestaShop ni du
thème Classic modifié), un simple retrait du dossier `modules/oklaschenker/`
suffit à annuler toute trace du code côté dépôt.

## En cas de problème pendant l'installation elle-même

Si `install()` échoue en cours de route (ex. échec de création du
transporteur), PrestaShop annule automatiquement l'entrée du module en base ;
relancer l'installation après correction. Les tables déjà créées utilisent
`CREATE TABLE IF NOT EXISTS`, donc une nouvelle tentative d'installation ne
duplique rien.
