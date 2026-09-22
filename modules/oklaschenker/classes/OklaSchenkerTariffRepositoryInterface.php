<?php
/**
 * Contrat d'accès aux données tarifaires Schenker, indépendant de PrestaShop.
 * Permet d'exécuter et de tester OklaSchenkerRateCalculator sans instance PrestaShop.
 */
interface OklaSchenkerTariffRepositoryInterface
{
    /**
     * Palier applicable pour un poids <= 100 kg dans un département donné.
     *
     * @return array{min_kg:float,max_kg:float,price_ht:float}|null
     */
    public function findLessThan100Bracket(string $department, float $weightKg): ?array;

    /**
     * Palier applicable pour un poids > 100 kg dans un département donné.
     *
     * @return array{min_kg:float,max_kg:float,price_per_100kg_ht:float}|null
     */
    public function findOver100Bracket(string $department, float $weightKg): ?array;

    public function isUrbanDepartment(string $department): bool;

    /**
     * Liste distincte de la zone urbaine (URBAN_ZONE) : départements couverts par le
     * supplément "Région Parisienne", confirmés par le gestionnaire le 22/09/2026
     * (75, 76, 77, 78, 91, 92, 93, 94, 95).
     */
    public function isParisRegionDepartment(string $department): bool;

    /**
     * @return array{amount:float,extra_1:mixed,extra_2:mixed}|null
     */
    public function getSurchargeDefinition(string $code): ?array;

    public function isSurchargeEnabled(string $code): bool;
}
