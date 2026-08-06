<?php
/**
 * Implémentation en mémoire de OklaSchenkerTariffRepositoryInterface.
 *
 * Utilisée par :
 *  - les tests PHP CLI (chargement direct de data/schenker_tarifs_extraits.json) ;
 *  - l'aperçu de contrôle avant import en base dans le back-office
 *    (AdminOklaSchenkerController::renderImportPreview).
 *
 * Ne dépend d'aucune classe PrestaShop.
 */
class OklaSchenkerArrayTariffRepository implements OklaSchenkerTariffRepositoryInterface
{
    /** @var array<string, array<int, array>> département => liste de paliers < 100kg, triés par min_kg */
    private array $less100ByDepartment = [];

    /** @var array<string, array<int, array>> département => liste de paliers > 100kg, triés par min_kg */
    private array $over100ByDepartment = [];

    /** @var array<string, true> */
    private array $urbanDepartments = [];

    /** @var array<string, array{amount:float,extra_1:mixed,extra_2:mixed}> */
    private array $surchargeDefinitions = [];

    /** @var array<string, bool> */
    private array $surchargeEnabled = [];

    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Fichier tarifaire introuvable : %s', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Impossible de lire le fichier tarifaire : %s', $path));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('JSON tarifaire invalide : %s', $path));
        }

        return self::fromDecodedJson($data);
    }

    public static function fromDecodedJson(array $data): self
    {
        $repo = new self();

        foreach ($data['less_100kg_rates'] ?? [] as $row) {
            $dept = strtoupper((string) $row['department']);
            $repo->less100ByDepartment[$dept][] = [
                'min_kg' => (float) $row['min_kg'],
                'max_kg' => (float) $row['max_kg'],
                'price_ht' => (float) $row['price_ht'],
            ];
        }

        foreach ($data['over_100kg_rates'] ?? [] as $row) {
            $dept = strtoupper((string) $row['department']);
            $repo->over100ByDepartment[$dept][] = [
                'min_kg' => (float) $row['min_kg'],
                'max_kg' => (float) $row['max_kg'],
                'price_per_100kg_ht' => (float) $row['price_per_100kg_ht'],
            ];
        }

        foreach ($repo->less100ByDepartment as &$brackets) {
            usort($brackets, static fn ($a, $b) => $a['min_kg'] <=> $b['min_kg']);
        }
        unset($brackets);
        foreach ($repo->over100ByDepartment as &$brackets) {
            usort($brackets, static fn ($a, $b) => $a['min_kg'] <=> $b['min_kg']);
        }
        unset($brackets);

        foreach ($data['urban_departments'] ?? [] as $dept) {
            $repo->urbanDepartments[strtoupper((string) $dept)] = true;
        }

        // Cf. docs/ANALYSE_TECHNIQUE.md §2.1 pour la justification de cette classification.
        $labelMap = [
            OklaSchenkerRateCalculator::SURCHARGE_URBAN_ZONE => 'Livraison pour les expéditions à destination des zones urbaines',
            OklaSchenkerRateCalculator::SURCHARGE_SEASONAL => 'Ajustement saisonnier',
            OklaSchenkerRateCalculator::SURCHARGE_SAFETY_QUALITY => 'Contribution sûreté et qualité',
            OklaSchenkerRateCalculator::SURCHARGE_ENERGY_CONTRIBUTION => 'Contribution Transition Energétique',
        ];

        foreach ($data['surcharges_raw'] ?? [] as $row) {
            $label = (string) $row['label'];
            foreach ($labelMap as $code => $needle) {
                if (stripos($label, $needle) !== false) {
                    $repo->surchargeDefinitions[$code] = [
                        'amount' => (float) $row['amount'],
                        'extra_1' => $row['extra_1'] ?? null,
                        'extra_2' => $row['extra_2'] ?? null,
                    ];
                    $repo->surchargeEnabled[$code] = true; // activé par défaut, désactivable en back-office
                }
            }
        }

        return $repo;
    }

    public function findLessThan100Bracket(string $department, float $weightKg): ?array
    {
        $department = strtoupper($department);
        foreach ($this->less100ByDepartment[$department] ?? [] as $bracket) {
            if ($weightKg >= $bracket['min_kg'] && $weightKg <= $bracket['max_kg']) {
                return $bracket;
            }
        }

        return null;
    }

    public function findOver100Bracket(string $department, float $weightKg): ?array
    {
        $department = strtoupper($department);
        foreach ($this->over100ByDepartment[$department] ?? [] as $bracket) {
            if ($weightKg >= $bracket['min_kg'] && $weightKg <= $bracket['max_kg']) {
                return $bracket;
            }
        }

        return null;
    }

    public function isUrbanDepartment(string $department): bool
    {
        return isset($this->urbanDepartments[strtoupper($department)]);
    }

    public function getSurchargeDefinition(string $code): ?array
    {
        return $this->surchargeDefinitions[$code] ?? null;
    }

    public function isSurchargeEnabled(string $code): bool
    {
        return $this->surchargeEnabled[$code] ?? false;
    }

    public function setSurchargeEnabled(string $code, bool $enabled): void
    {
        $this->surchargeEnabled[$code] = $enabled;
    }

    public function countDepartments(): int
    {
        return count(array_unique(array_merge(
            array_keys($this->less100ByDepartment),
            array_keys($this->over100ByDepartment)
        )));
    }

    public function countBrackets(): array
    {
        $less100 = 0;
        foreach ($this->less100ByDepartment as $brackets) {
            $less100 += count($brackets);
        }
        $over100 = 0;
        foreach ($this->over100ByDepartment as $brackets) {
            $over100 += count($brackets);
        }

        return ['less_100kg' => $less100, 'over_100kg' => $over100];
    }
}
