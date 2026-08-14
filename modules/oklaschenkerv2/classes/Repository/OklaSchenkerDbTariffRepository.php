<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Implémentation PrestaShop (table Db) de OklaSchenkerTariffRepositoryInterface.
 * Utilisée en production par les hooks getOrderShippingCost / getOrderShippingCostExternal
 * et par l'outil « Tester un tarif » du back-office.
 *
 * N'a pas pu être exécutée contre une vraie base PrestaShop pendant ce développement
 * (aucune instance disponible) — voir docs/ANALYSE_TECHNIQUE.md §8.
 */
class OklaSchenkerDbTariffRepository implements OklaSchenkerTariffRepositoryInterface
{
    public function findLessThan100Bracket(string $department, float $weightKg): ?array
    {
        $sql = new DbQuery();
        $sql->select('min_kg, max_kg, price_ht')
            ->from('oklaschenker_rate_less100')
            ->where('department = \'' . pSQL($department) . '\'')
            ->where((float) $weightKg . ' >= min_kg AND ' . (float) $weightKg . ' <= max_kg')
            ->orderBy('min_kg ASC');

        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);

        return $row === false ? null : [
            'min_kg' => (float) $row['min_kg'],
            'max_kg' => (float) $row['max_kg'],
            'price_ht' => (float) $row['price_ht'],
        ];
    }

    public function findOver100Bracket(string $department, float $weightKg): ?array
    {
        $sql = new DbQuery();
        $sql->select('min_kg, max_kg, price_per_100kg_ht')
            ->from('oklaschenker_rate_over100')
            ->where('department = \'' . pSQL($department) . '\'')
            ->where((float) $weightKg . ' >= min_kg AND ' . (float) $weightKg . ' <= max_kg')
            ->orderBy('min_kg ASC');

        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);

        return $row === false ? null : [
            'min_kg' => (float) $row['min_kg'],
            'max_kg' => (float) $row['max_kg'],
            'price_per_100kg_ht' => (float) $row['price_per_100kg_ht'],
        ];
    }

    public function isUrbanDepartment(string $department): bool
    {
        $sql = new DbQuery();
        $sql->select('1')
            ->from('oklaschenker_urban_department')
            ->where('department = \'' . pSQL($department) . '\'');

        return (bool) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    public function getSurchargeDefinition(string $code): ?array
    {
        $sql = new DbQuery();
        $sql->select('amount, extra_1, extra_2')
            ->from('oklaschenker_surcharge')
            ->where('code = \'' . pSQL($code) . '\'');

        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);

        return $row === false ? null : [
            'amount' => (float) $row['amount'],
            'extra_1' => $row['extra_1'],
            'extra_2' => $row['extra_2'],
        ];
    }

    public function isSurchargeEnabled(string $code): bool
    {
        return OklaSchenkerConfig::isSurchargeEnabled($code);
    }
}
