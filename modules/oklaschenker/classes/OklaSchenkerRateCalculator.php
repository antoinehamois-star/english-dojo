<?php
/**
 * Moteur de calcul tarifaire Schenker — Phase 1 (calcul local uniquement).
 *
 * Classe volontairement dépourvue de toute dépendance PrestaShop directe
 * (aucun appel à Db, Configuration, Context, etc.) afin de pouvoir être
 * exécutée et testée en PHP CLI pur. L'intégration PrestaShop se fait via
 * OklaSchenkerTariffRepositoryInterface (implémentée par
 * Repository/OklaSchenkerDbTariffRepository côté module).
 *
 * Ne calcule JAMAIS la TVA : le HT calculé ici est ensuite soumis au moteur
 * de taxes standard de PrestaShop (id_tax_rules_group du transporteur), pour
 * respecter la consigne « respecter les taxes et devises PrestaShop ».
 */
class OklaSchenkerRateCalculator
{
    public const SURCHARGE_URBAN_ZONE = 'URBAN_ZONE';
    public const SURCHARGE_SEASONAL = 'SEASONAL';
    public const SURCHARGE_SAFETY_QUALITY = 'SAFETY_QUALITY';
    public const SURCHARGE_ENERGY_CONTRIBUTION = 'ENERGY_CONTRIBUTION';
    /**
     * Ajustement gazole — absent du fichier source initial (schenker_tarifs_extraits.json
     * ne contenait aucune ligne "carburant"/"gazole", voir test 13 historique). Confirmé
     * réel et communiqué directement par le gestionnaire le 22/09/2026 à 19,8 % du tarif
     * de base, après comparaison de deux extraits de grille Schenker à jour (fichiers
     * "-100kg" et "+100kg") montrant une ligne "ajustement gazole" non reprise jusque-là.
     * Taux fixe déclaré par le gestionnaire, pas une valeur recalculée depuis les fichiers
     * (les deux exemples fournis donnaient ~25,6 % et ~26,5 %, incohérents entre eux).
     */
    public const SURCHARGE_FUEL_ADJUSTMENT = 'FUEL_ADJUSTMENT';
    /**
     * Région Parisienne — supplément DISTINCT de la zone urbaine (URBAN_ZONE), avec sa
     * propre liste de départements. Confirmé par le gestionnaire le 22/09/2026 :
     * 6,36 € par expédition (et non 3,36 € comme indiqué dans le fichier source initial —
     * valeur corrigée), départements 75, 76, 77, 78, 91, 92, 93, 94, 95.
     */
    public const SURCHARGE_PARIS_REGION = 'PARIS_REGION';

    /** Suppléments applicables automatiquement (déclencheur non ambigu). */
    public const AUTO_SURCHARGE_CODES = [
        self::SURCHARGE_URBAN_ZONE,
        self::SURCHARGE_SEASONAL,
        self::SURCHARGE_SAFETY_QUALITY,
        self::SURCHARGE_ENERGY_CONTRIBUTION,
        self::SURCHARGE_FUEL_ADJUSTMENT,
        self::SURCHARGE_PARIS_REGION,
    ];

    public const REASON_INVALID_DEPARTMENT = 'INVALID_DEPARTMENT';
    public const REASON_INVALID_WEIGHT = 'INVALID_WEIGHT';
    public const REASON_NO_RATE_FOUND = 'NO_RATE_FOUND';

    /** Poids maximal couvert par la grille tarifaire fournie (au-delà : devis manuel). */
    public const MAX_COVERED_WEIGHT_KG = 999.0;

    /** @var OklaSchenkerTariffRepositoryInterface */
    private $repository;

    public function __construct(OklaSchenkerTariffRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param string        $department   Code département sur 2 caractères (ex: "01", "2A"/"2B" -> normaliser en amont vers "20")
     * @param float         $weightKg     Poids total du panier en kg
     * @param \DateTimeImmutable|null $calculationDate Date de référence pour les règles saisonnières (défaut : maintenant)
     *
     * @return array Trace de calcul complète (voir structure ci-dessous)
     */
    public function calculate(string $department, float $weightKg, ?\DateTimeImmutable $calculationDate = null): array
    {
        $calculationDate = $calculationDate ?? new \DateTimeImmutable();
        $department = strtoupper(trim($department));

        $trace = [
            'ok' => false,
            'reason' => null,
            'department' => $department,
            'weight_kg' => $weightKg,
            'bracket' => null,
            'base_price_ht' => null,
            'supplements' => [],
            'total_price_ht' => null,
            'rule_applied' => null,
            'manual_quote_required' => false,
            'calculated_at' => $calculationDate->format(DATE_ATOM),
        ];

        if ($department === '') {
            $trace['reason'] = self::REASON_INVALID_DEPARTMENT;
            $trace['manual_quote_required'] = true;

            return $trace;
        }

        if (!is_finite($weightKg) || $weightKg <= 0) {
            $trace['reason'] = self::REASON_INVALID_WEIGHT;
            $trace['manual_quote_required'] = true;

            return $trace;
        }

        if ($weightKg > self::MAX_COVERED_WEIGHT_KG) {
            $trace['reason'] = self::REASON_NO_RATE_FOUND;
            $trace['manual_quote_required'] = true;

            return $trace;
        }

        if ($weightKg <= 100.0) {
            $bracket = $this->repository->findLessThan100Bracket($department, $weightKg);
            if ($bracket === null) {
                $trace['reason'] = self::REASON_NO_RATE_FOUND;
                $trace['manual_quote_required'] = true;

                return $trace;
            }
            $baseHt = round((float) $bracket['price_ht'], 2);
            $trace['rule_applied'] = 'less_100kg_flat_bracket';
        } else {
            $bracket = $this->repository->findOver100Bracket($department, $weightKg);
            if ($bracket === null) {
                $trace['reason'] = self::REASON_NO_RATE_FOUND;
                $trace['manual_quote_required'] = true;

                return $trace;
            }
            $baseHt = round(($weightKg / 100.0) * (float) $bracket['price_per_100kg_ht'], 2);
            $trace['rule_applied'] = 'over_100kg_per_100kg';
        }

        $trace['bracket'] = $bracket;
        $trace['base_price_ht'] = $baseHt;

        $supplements = [];
        $runningTotal = $baseHt;

        foreach (self::AUTO_SURCHARGE_CODES as $code) {
            if (!$this->repository->isSurchargeEnabled($code)) {
                continue;
            }

            $amount = $this->computeSurchargeAmount($code, $department, $baseHt, $calculationDate);
            if ($amount === null) {
                continue;
            }

            $supplements[] = [
                'code' => $code,
                'amount' => $amount,
            ];
            $runningTotal += $amount;
        }

        $trace['supplements'] = $supplements;
        $trace['total_price_ht'] = round($runningTotal, 2);
        $trace['ok'] = true;

        return $trace;
    }

    private function computeSurchargeAmount(string $code, string $department, float $baseHt, \DateTimeImmutable $calculationDate): ?float
    {
        switch ($code) {
            case self::SURCHARGE_URBAN_ZONE:
                if (!$this->repository->isUrbanDepartment($department)) {
                    return null;
                }
                $definition = $this->repository->getSurchargeDefinition($code);

                return $definition !== null ? round((float) $definition['amount'], 2) : null;

            case self::SURCHARGE_SEASONAL:
                // Fenêtre du 1er juin au 31 août inclus, selon le libellé source.
                // La date de calcul doit être exprimée dans le fuseau du site (Europe/Paris).
                $definition = $this->repository->getSurchargeDefinition($code);
                if ($definition === null) {
                    return null;
                }
                $month = (int) $calculationDate->format('n');
                if ($month < 6 || $month > 8) {
                    return null;
                }
                $pct = (float) $definition['amount'];

                return round($baseHt * $pct / 100.0, 2);

            case self::SURCHARGE_SAFETY_QUALITY:
            case self::SURCHARGE_ENERGY_CONTRIBUTION:
                $definition = $this->repository->getSurchargeDefinition($code);

                return $definition !== null ? round((float) $definition['amount'], 2) : null;

            case self::SURCHARGE_PARIS_REGION:
                // Forfait fixe par expédition, liste de départements distincte de
                // URBAN_ZONE (voir constante SURCHARGE_PARIS_REGION) — les deux peuvent
                // se cumuler si un département figure dans les deux listes, aucune
                // exclusion mutuelle n'a été demandée par le gestionnaire.
                if (!$this->repository->isParisRegionDepartment($department)) {
                    return null;
                }
                $definition = $this->repository->getSurchargeDefinition($code);

                return $definition !== null ? round((float) $definition['amount'], 2) : null;

            case self::SURCHARGE_FUEL_ADJUSTMENT:
                // Pourcentage fixe du tarif de base, appliqué par expédition, sans
                // restriction saisonnière (confirmé par le gestionnaire — voir la
                // constante SURCHARGE_FUEL_ADJUSTMENT).
                $definition = $this->repository->getSurchargeDefinition($code);
                if ($definition === null) {
                    return null;
                }
                $pct = (float) $definition['amount'];

                return round($baseHt * $pct / 100.0, 2);

            default:
                return null;
        }
    }
}
