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

    {if $okla_density_warning}
        {if $okla_density_warning.below_threshold}
            <div class="alert alert-warning">
                <strong>{l s='Densité faible : vérification manuelle recommandée.' mod='oklaschenker'}</strong>
                {l s='Poids' mod='oklaschenker'} {$okla_density_warning.total_weight_kg|number_format:2} kg
                / {l s='volume' mod='oklaschenker'} {$okla_density_warning.total_volume_m3|number_format:3} m³
                = {$okla_density_warning.density_kg_m3|number_format:1} kg/m³
                ({l s='seuil contractuel Schenker' mod='oklaschenker'} : {$okla_density_warning.threshold|number_format:0} kg/m³).
                {l s='Le contrat Schenker ne précise pas le mode de facturation en dessous de ce seuil (poids volumétrique, refus, etc.) : le tarif affiché reste basé sur le poids réel uniquement — vérifiez ce point avec Schenker avant expédition.' mod='oklaschenker'}
            </div>
        {else}
            <p class="text-muted">
                {l s='Densité' mod='oklaschenker'} {$okla_density_warning.density_kg_m3|number_format:1} kg/m³
                ({l s='≥ seuil contractuel Schenker de' mod='oklaschenker'} {$okla_density_warning.threshold|number_format:0} kg/m³).
            </p>
        {/if}
    {/if}
</div>
