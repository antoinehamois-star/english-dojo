{*
  Page unique de configuration du module oklaschenker (Phase 1).
  Toutes les actions d'écriture passent par un POST sur ce même contrôleur,
  protégé par le token CSRF standard PrestaShop ({$okla_token}).
*}
<div class="panel">
    <div class="panel-heading"><i class="icon-truck"></i> {l s='Schenker - OK-LA — Configuration' mod='oklaschenkerv2'}</div>
    <div class="alert alert-info">
        {l s='Phase 1 : calcul tarifaire local uniquement. Aucun appel SOAP, aucune réservation, aucune étiquette réelle.' mod='oklaschenkerv2'}
    </div>

    <form method="post" action="{$okla_config_form_action}">
        <input type="hidden" name="oklaSchenkerSaveConfig" value="1" />

        <h4>{l s='Transporteur' mod='oklaschenkerv2'}</h4>
        <div class="form-group">
            <label>{l s='Statut du transporteur "Schenker - OK-LA"' mod='oklaschenkerv2'}</label>
            <p>
                {if $okla_config.carrier_id}
                    ID #{$okla_config.carrier_id} —
                    {if $okla_config.carrier_active}
                        <span class="label label-success">{l s='Actif' mod='oklaschenkerv2'}</span>
                    {else}
                        <span class="label label-default">{l s='Inactif' mod='oklaschenkerv2'}</span>
                    {/if}
                {else}
                    <span class="label label-danger">{l s='Non créé' mod='oklaschenkerv2'}</span>
                {/if}
            </p>
        </div>

        <div class="form-group">
            <label for="tax_rules_group_id">{l s='Groupe de règles de taxe (obligatoire pour activer le transporteur)' mod='oklaschenkerv2'}</label>
            <select name="tax_rules_group_id" id="tax_rules_group_id" class="form-control">
                <option value="0">{l s='-- Aucune taxe --' mod='oklaschenkerv2'}</option>
                {foreach from=$okla_config.tax_rules_groups item=trg}
                    <option value="{$trg.id_tax_rules_group}" {if $trg.id_tax_rules_group == $okla_config.tax_rules_group_id}selected{/if}>{$trg.name|escape:'html':'UTF-8'}</option>
                {/foreach}
            </select>
            <p class="help-block">{l s='La TVA/TTC affichée au client est calculée par le moteur de taxes standard de PrestaShop, jamais par le module.' mod='oklaschenkerv2'}</p>
        </div>

        <div class="form-group">
            <label for="announced_delay">{l s='Délai annoncé au client' mod='oklaschenkerv2'}</label>
            <input type="text" class="form-control" id="announced_delay" name="announced_delay" value="{$okla_config.announced_delay|escape:'html':'UTF-8'}" placeholder="{l s='ex: 2 à 4 jours ouvrés' mod='oklaschenkerv2'}" />
        </div>

        <h4>{l s='Suppléments appliqués automatiquement' mod='oklaschenkerv2'}</h4>
        <p class="help-block">{l s='Voir docs/ANALYSE_TECHNIQUE.md §2.1 pour la justification de cette liste — seuls les suppléments au déclencheur non ambigu sont proposés ici.' mod='oklaschenkerv2'}</p>
        {foreach from=$okla_surcharge_codes item=code}
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="surcharge_{$code}" value="1" {if $okla_config.surcharges_enabled[$code]}checked{/if} />
                    {$code}
                </label>
            </div>
        {/foreach}

        <div class="checkbox">
            <label>
                <input type="checkbox" name="logging_enabled" value="1" {if $okla_config.logging_enabled}checked{/if} />
                {l s='Journalisation activée' mod='oklaschenkerv2'}
            </label>
        </div>

        <hr />
        <h4>{l s='Réservé Phase 2 (réservation SOAP) — champs inertes, non utilisés par le calcul tarifaire' mod='oklaschenkerv2'}</h4>
        <div class="form-group">
            <label>{l s='Mode' mod='oklaschenkerv2'}</label>
            <select name="mode" class="form-control">
                <option value="test" {if $okla_config.mode == 'test'}selected{/if}>{l s='Test' mod='oklaschenkerv2'}</option>
                <option value="production" {if $okla_config.mode == 'production'}selected{/if}>{l s='Production' mod='oklaschenkerv2'}</option>
            </select>
        </div>
        <div class="form-group">
            <label>{l s='URL WSDL Test' mod='oklaschenkerv2'}</label>
            <input type="text" class="form-control" name="wsdl_test_url" value="{$okla_config.wsdl_test_url|escape:'html':'UTF-8'}" placeholder="https://eschenker-fat.dbschenker.com/webservice/bookingWebServiceV1_1?wsdl" />
        </div>
        <div class="form-group">
            <label>{l s='URL WSDL Production' mod='oklaschenkerv2'}</label>
            <input type="text" class="form-control" name="wsdl_prod_url" value="{$okla_config.wsdl_prod_url|escape:'html':'UTF-8'}" placeholder="https://eschenker.dbschenker.com/webservice/bookingWebServiceV1_1?wsdl" />
        </div>
        <div class="form-group">
            <label>{l s='Access Key' mod='oklaschenkerv2'}</label>
            <input type="password" class="form-control" name="access_key" value="" placeholder="{if $okla_config.access_key_set}{l s='(déjà configurée — laisser vide pour ne pas la modifier)' mod='oklaschenkerv2'}{else}{l s='vide par défaut' mod='oklaschenkerv2'}{/if}" autocomplete="new-password" />
        </div>
        <div class="form-group">
            <label>{l s='Group ID (facultatif)' mod='oklaschenkerv2'}</label>
            <input type="text" class="form-control" name="group_id" value="{$okla_config.group_id|escape:'html':'UTF-8'}" />
        </div>
        <div class="form-group">
            <label>{l s='Numéro de compte Schenker' mod='oklaschenkerv2'}</label>
            <input type="text" class="form-control" name="account_number" value="{$okla_config.account_number|escape:'html':'UTF-8'}" />
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="icon-save"></i> {l s='Enregistrer' mod='oklaschenkerv2'}
        </button>
    </form>

    <form method="post" action="{$okla_config_form_action}" style="display:inline-block;margin-top:10px;">
        <input type="hidden" name="oklaSchenkerActivateCarrier" value="{if $okla_config.carrier_active}0{else}1{/if}" />
        <button type="submit" class="btn {if $okla_config.carrier_active}btn-warning{else}btn-success{/if}">
            {if $okla_config.carrier_active}
                {l s='Désactiver le transporteur' mod='oklaschenkerv2'}
            {else}
                {l s='Activer le transporteur' mod='oklaschenkerv2'}
            {/if}
        </button>
    </form>
</div>

<div class="panel">
    <div class="panel-heading"><i class="icon-upload"></i> {l s='Import de la grille tarifaire' mod='oklaschenkerv2'}</div>
    <p>
        {l s='Données actuellement en base :' mod='oklaschenkerv2'}
        {$okla_config.tariff_row_counts.less100} {l s='paliers <100kg,' mod='oklaschenkerv2'}
        {$okla_config.tariff_row_counts.over100} {l s='paliers >100kg,' mod='oklaschenkerv2'}
        {$okla_config.tariff_row_counts.urban} {l s='départements urbains.' mod='oklaschenkerv2'}
    </p>
    <form method="post" action="{$okla_config_form_action}">
        <input type="hidden" name="oklaSchenkerPreviewImport" value="1" />
        <button type="submit" class="btn btn-default">{l s='Contrôler le fichier de référence embarqué (data/schenker_tarifs_extraits.json)' mod='oklaschenkerv2'}</button>
    </form>

    {if $okla_import_preview}
        {if $okla_import_preview.ok}
            <div class="alert alert-warning" style="margin-top:10px;">
                <strong>{l s='Aperçu avant import :' mod='oklaschenkerv2'}</strong>
                {$okla_import_preview.departments_count} {l s='départements,' mod='oklaschenkerv2'}
                {$okla_import_preview.less100_count} {l s='paliers <100kg,' mod='oklaschenkerv2'}
                {$okla_import_preview.over100_count} {l s='paliers >100kg.' mod='oklaschenkerv2'}
                <br />
                {l s='Cet import REMPLACE intégralement les données tarifaires actuelles. Confirmez pour continuer.' mod='oklaschenkerv2'}
                <form method="post" action="{$okla_config_form_action}" style="margin-top:10px;">
                    <input type="hidden" name="oklaSchenkerConfirmImport" value="1" />
                    <button type="submit" class="btn btn-danger">{l s='Confirmer l\'import' mod='oklaschenkerv2'}</button>
                </form>
            </div>
        {else}
            <div class="alert alert-danger" style="margin-top:10px;">{$okla_import_preview.error|escape:'html':'UTF-8'}</div>
        {/if}
    {/if}
</div>

<div class="panel">
    <div class="panel-heading"><i class="icon-calculator"></i> {l s='Tester un tarif' mod='oklaschenkerv2'}</div>
    <form method="post" action="{$okla_config_form_action}" class="form-inline">
        <input type="hidden" name="oklaSchenkerTestRate" value="1" />
        <div class="form-group">
            <label>{l s='Code postal' mod='oklaschenkerv2'}</label>
            <input type="text" class="form-control" name="test_postcode" value="{$okla_test_result.postcode|default:''|escape:'html':'UTF-8'}" placeholder="75001" />
        </div>
        <div class="form-group">
            <label>{l s='Poids total (kg)' mod='oklaschenkerv2'}</label>
            <input type="number" step="0.01" class="form-control" name="test_weight" value="{$okla_test_result.weight_kg|default:''|escape:'html':'UTF-8'}" />
        </div>
        <button type="submit" class="btn btn-primary">{l s='Calculer' mod='oklaschenkerv2'}</button>
    </form>

    {if $okla_test_result}
        <div class="well" style="margin-top:10px;">
            {if $okla_test_result.ok}
                <p><strong>{l s='Tarif HT :' mod='oklaschenkerv2'} {$okla_test_result.total_price_ht} €</strong> ({l s='règle :' mod='oklaschenkerv2'} {$okla_test_result.rule_applied})</p>
                <p>{l s='Base HT :' mod='oklaschenkerv2'} {$okla_test_result.base_price_ht} €</p>
                {if $okla_test_result.supplements|@count}
                    <p>{l s='Suppléments appliqués :' mod='oklaschenkerv2'}</p>
                    <ul>
                        {foreach from=$okla_test_result.supplements item=s}
                            <li>{$s.code} : {$s.amount} €</li>
                        {/foreach}
                    </ul>
                {/if}
                <pre>{$okla_test_result|@print_r}</pre>
            {else}
                <p class="text-danger">{l s='Aucun tarif disponible.' mod='oklaschenkerv2'} {l s='Raison :' mod='oklaschenkerv2'} {$okla_test_result.reason}</p>
                <p>{l s='Devis manuel requis.' mod='oklaschenkerv2'}</p>
            {/if}
        </div>
    {/if}
</div>

<div class="panel">
    <div class="panel-heading"><i class="icon-warning-sign"></i> {l s='Anciens transporteurs évoquant Schenker (lecture seule)' mod='oklaschenkerv2'}</div>
    <div class="alert alert-info">{l s='Ces transporteurs ne sont JAMAIS modifiés ni supprimés automatiquement par le module.' mod='oklaschenkerv2'}</div>
    <form method="post" action="{$okla_config_form_action}">
        <input type="hidden" name="oklaSchenkerRefreshLegacyReport" value="1" />
        <button type="submit" class="btn btn-default">{l s='Rafraîchir la détection' mod='oklaschenkerv2'}</button>
    </form>
    <table class="table" style="margin-top:10px;">
        <thead>
            <tr>
                <th>ID</th>
                <th>{l s='Nom' mod='oklaschenkerv2'}</th>
                <th>{l s='Actif' mod='oklaschenkerv2'}</th>
                <th>{l s='Supprimé' mod='oklaschenkerv2'}</th>
                <th>{l s='Détecté le' mod='oklaschenkerv2'}</th>
                <th>{l s='Action' mod='oklaschenkerv2'}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$okla_legacy_carriers item=legacy}
                <tr>
                    <td>{$legacy.id_carrier}</td>
                    <td>{$legacy.name|escape:'html':'UTF-8'}</td>
                    <td>{if $legacy.active}{l s='Oui' mod='oklaschenkerv2'}{else}{l s='Non' mod='oklaschenkerv2'}{/if}</td>
                    <td>{if $legacy.deleted}{l s='Oui' mod='oklaschenkerv2'}{else}{l s='Non' mod='oklaschenkerv2'}{/if}</td>
                    <td>{$legacy.date_detected}</td>
                    <td>
                        {if $legacy.active}
                        <form method="post" action="{$okla_config_form_action}" onsubmit="return confirm('{l s='Confirmer la désactivation de ce transporteur existant ? Il ne sera jamais supprimé.' mod='oklaschenkerv2'}');">
                            <input type="hidden" name="oklaSchenkerDisableLegacyCarrier" value="{$legacy.id_carrier}" />
                            <input type="hidden" name="oklaSchenkerConfirmDisableLegacy" value="1" />
                            <button type="submit" class="btn btn-xs btn-warning">{l s='Désactiver' mod='oklaschenkerv2'}</button>
                        </form>
                        {/if}
                    </td>
                </tr>
            {foreachelse}
                <tr><td colspan="6">{l s='Aucun ancien transporteur Schenker détecté (ou détection jamais exécutée sur ce site).' mod='oklaschenkerv2'}</td></tr>
            {/foreach}
        </tbody>
    </table>
</div>

<div class="panel">
    <div class="panel-heading"><i class="icon-list"></i> {l s='Journaux techniques récents' mod='oklaschenkerv2'}</div>
    <table class="table">
        <thead>
            <tr>
                <th>{l s='Date' mod='oklaschenkerv2'}</th>
                <th>{l s='Niveau' mod='oklaschenkerv2'}</th>
                <th>{l s='Contexte' mod='oklaschenkerv2'}</th>
                <th>{l s='Message' mod='oklaschenkerv2'}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$okla_recent_logs item=log}
                <tr>
                    <td>{$log.date_add}</td>
                    <td>{$log.level}</td>
                    <td>{$log.context}</td>
                    <td>{$log.message|escape:'html':'UTF-8'}</td>
                </tr>
            {foreachelse}
                <tr><td colspan="4">{l s='Aucun journal.' mod='oklaschenkerv2'}</td></tr>
            {/foreach}
        </tbody>
    </table>
</div>

<div class="panel">
    <div class="panel-heading text-danger"><i class="icon-trash"></i> {l s='Zone dangereuse' mod='oklaschenkerv2'}</div>
    <p>{l s='Supprime définitivement toutes les tables et données du module (tarifs, journaux, rapports). Le transporteur créé et son historique de commandes ne sont pas affectés.' mod='oklaschenkerv2'}</p>
    <form method="post" action="{$okla_config_form_action}" onsubmit="return confirm('{l s='Cette action est irréversible. Continuer ?' mod='oklaschenkerv2'}');">
        <input type="hidden" name="oklaSchenkerPurgeData" value="1" />
        <div class="form-group">
            <label>{l s='Tapez SUPPRIMER pour confirmer' mod='oklaschenkerv2'}</label>
            <input type="text" class="form-control" name="oklaSchenkerPurgeConfirmText" style="max-width:200px;" />
        </div>
        <button type="submit" class="btn btn-danger">{l s='Supprimer définitivement les données du module' mod='oklaschenkerv2'}</button>
    </form>
</div>
