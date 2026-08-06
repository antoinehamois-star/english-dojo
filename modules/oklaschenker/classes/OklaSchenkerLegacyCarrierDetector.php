<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Détection en LECTURE SEULE des transporteurs existants dont le nom évoque
 * Schenker (ex. anciens transporteurs "AD SCHENKER"), afin de les lister en
 * back-office sans jamais les modifier ni les supprimer.
 *
 * Consigne absolue : « ne jamais supprimer ou modifier les transporteurs
 * existants sans validation explicite » / « ne jamais écraser un transporteur
 * existant ».
 *
 * N'a jamais été exécutée contre une vraie base (aucune instance PrestaShop
 * disponible pendant ce développement) — voir docs/ANALYSE_TECHNIQUE.md §6.
 */
class OklaSchenkerLegacyCarrierDetector
{
    /**
     * @return array<int, array{id_carrier:int,name:string,active:bool,deleted:bool}>
     */
    public function findLegacySchenkerCarriers(): array
    {
        $sql = new DbQuery();
        $sql->select('id_carrier, name, active, deleted')
            ->from('carrier')
            ->where('name LIKE \'%SCHENKER%\'')
            ->orderBy('id_carrier ASC');

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        $ownCarrierId = (int) OklaSchenkerConfig::get(OklaSchenkerConfig::CARRIER_ID);

        $result = [];
        foreach ($rows as $row) {
            $idCarrier = (int) $row['id_carrier'];
            if ($idCarrier === $ownCarrierId) {
                continue; // ne pas lister le transporteur créé par le module lui-même
            }
            $result[] = [
                'id_carrier' => $idCarrier,
                'name' => $row['name'],
                'active' => (bool) $row['active'],
                'deleted' => (bool) $row['deleted'],
            ];
        }

        return $result;
    }
}
