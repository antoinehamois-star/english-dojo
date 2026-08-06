{* Bloc Schenker - OK-LA dans la page commande du back-office (Phase 1 : lecture seule). *}
<div class="panel">
    <div class="panel-heading"><i class="icon-truck"></i> {l s='Schenker - OK-LA' mod='oklaschenker'}</div>
    {if $okla_last_calc}
        <table class="table">
            <tr>
                <th>{l s='Statut du calcul' mod='oklaschenker'}</th>
                <td>
                    {if $okla_last_calc.ok}
                        <span class="label label-success">{l s='Tarif calculé' mod='oklaschenker'}</span>
                    {else}
                        <span class="label label-danger">{l s='Aucun tarif — devis manuel requis' mod='oklaschenker'} ({$okla_last_calc.reason})</span>
                    {/if}
                </td>
            </tr>
            <tr><th>{l s='Département' mod='oklaschenker'}</th><td>{$okla_last_calc.department}</td></tr>
            <tr><th>{l s='Poids (kg)' mod='oklaschenker'}</th><td>{$okla_last_calc.weight_kg}</td></tr>
            <tr><th>{l s='Tarif HT de base' mod='oklaschenker'}</th><td>{$okla_last_calc.base_price_ht} €</td></tr>
            <tr><th>{l s='Tarif HT total (avec suppléments)' mod='oklaschenker'}</th><td>{$okla_last_calc.total_price_ht} €</td></tr>
            <tr><th>{l s='Calculé le' mod='oklaschenker'}</th><td>{$okla_last_calc.date_add}</td></tr>
        </table>
        <p class="text-muted">
            {l s='Phase 1 : aucune réservation, aucune étiquette, aucun numéro de suivi Schenker n\'est géré par le module à ce stade.' mod='oklaschenker'}
        </p>
    {else}
        <p>{l s='Aucun calcul tarifaire Schenker enregistré pour cette commande.' mod='oklaschenker'}</p>
    {/if}
</div>
