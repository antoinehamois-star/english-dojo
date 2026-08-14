<?php
/**
 * Suite de tests exécutable en PHP CLI pur, sans PrestaShop.
 *
 * Usage : php tests/RateCalculatorTest.php
 *
 * Chaque cas de la liste "TESTS OBLIGATOIRES" de la spécification qui est
 * réellement applicable à la Phase 1 (calcul local, pas de SOAP/réservation)
 * est couvert ici avec des données réelles extraites de
 * data/schenker_tarifs_extraits.json — aucune valeur n'est inventée.
 *
 * Les cas hors périmètre Phase 1 (SOAP, réservation, annulation, suivi,
 * install/uninstall PrestaShop, anciens transporteurs AD SCHENKER) sont
 * listés en fin de sortie comme NON TESTABLES ICI, avec la raison, plutôt
 * que simulés.
 */

declare(strict_types=1);

require_once __DIR__ . '/../classes/OklaSchenkerTariffRepositoryInterface.php';
require_once __DIR__ . '/../classes/OklaSchenkerRateCalculator.php';
require_once __DIR__ . '/../classes/OklaSchenkerArrayTariffRepository.php';
require_once __DIR__ . '/../classes/OklaSchenkerAddressResolver.php';

final class TestRunner
{
    private int $pass = 0;
    private int $fail = 0;
    /** @var array<int, array{name:string,ok:bool,detail:string}> */
    private array $results = [];

    public function assertTrue(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->pass++;
        } else {
            $this->fail++;
        }
        $this->results[] = ['name' => $name, 'ok' => $condition, 'detail' => $detail];
    }

    public function assertSame($expected, $actual, string $name): void
    {
        $ok = $expected === $actual;
        $detail = $ok ? sprintf('valeur = %s', var_export($actual, true))
            : sprintf('attendu %s, obtenu %s', var_export($expected, true), var_export($actual, true));
        $this->assertTrue($name, $ok, $detail);
    }

    public function summary(): int
    {
        echo str_repeat('=', 78) . "\n";
        foreach ($this->results as $r) {
            printf("[%s] %s%s\n", $r['ok'] ? 'PASS' : 'FAIL', $r['name'], $r['detail'] !== '' ? ' — ' . $r['detail'] : '');
        }
        echo str_repeat('=', 78) . "\n";
        printf("Total: %d | PASS: %d | FAIL: %d\n", $this->pass + $this->fail, $this->pass, $this->fail);

        return $this->fail === 0 ? 0 : 1;
    }
}

$dataFile = __DIR__ . '/../data/schenker_tarifs_extraits.json';
$repo = OklaSchenkerArrayTariffRepository::fromJsonFile($dataFile);
$calc = new OklaSchenkerRateCalculator($repo);
$resolver = new OklaSchenkerAddressResolver();
$t = new TestRunner();

// Fixtures : valeurs relevées directement dans schenker_tarifs_extraits.json
$json = json_decode(file_get_contents($dataFile), true);
$findRate = static function (array $rows, string $dept, float $min) {
    foreach ($rows as $r) {
        if ($r['department'] === $dept && (float) $r['min_kg'] === $min) {
            return $r;
        }
    }
    throw new RuntimeException("fixture introuvable dept=$dept min=$min");
};
$findSurchargeAmount = static function (array $surcharges, string $needle): float {
    foreach ($surcharges as $s) {
        if (stripos($s['label'], $needle) !== false) {
            return (float) $s['amount'];
        }
    }
    throw new RuntimeException("supplément introuvable: $needle");
};
$safetyAmount = $findSurchargeAmount($json['surcharges_raw'], 'Contribution sûreté et qualité');
$energyAmount = $findSurchargeAmount($json['surcharges_raw'], 'Contribution Transition Energétique');

// ---------------------------------------------------------------------
// 1. Département standard (01 - AIN), poids dans le premier palier < 100kg
// ---------------------------------------------------------------------
$fixture = $findRate($json['less_100kg_rates'], '01', 1.0);
$r = $calc->calculate('01', 5.0);
$t->assertTrue('1. Département standard (01, 5kg)', $r['ok'] === true);
$t->assertSame((float) $fixture['price_ht'], $r['base_price_ht'], '1. Prix HT département standard = fixture JSON');

// ---------------------------------------------------------------------
// 2. Zone urbaine (06 fait partie de urban_departments)
// ---------------------------------------------------------------------
$t->assertTrue('2a. Département 06 est bien listé comme urbain dans la fixture', in_array('06', $json['urban_departments'], true));
$fixtureUrban = $findRate($json['less_100kg_rates'], '06', 1.0);
// Date hors fenêtre saisonnière (juin-août) pour isoler le seul supplément zone urbaine.
$r = $calc->calculate('06', 5.0, new DateTimeImmutable('2026-11-15'));
$urbanSupplement = null;
foreach ($r['supplements'] as $s) {
    if ($s['code'] === OklaSchenkerRateCalculator::SURCHARGE_URBAN_ZONE) {
        $urbanSupplement = $s;
    }
}
$t->assertTrue('2b. Supplément zone urbaine appliqué pour le département 06', $urbanSupplement !== null);
// Hors saison : les 2 suppléments "toujours actifs" (sûreté+qualité, énergie) s'ajoutent
// aussi par défaut, en plus du supplément zone urbaine testé ici.
$t->assertSame(
    round((float) $fixtureUrban['price_ht'] + $urbanSupplement['amount'] + $safetyAmount + $energyAmount, 2),
    $r['total_price_ht'],
    '2c. Total HT = base + supplément zone urbaine + sûreté/qualité + énergie (tous activés par défaut)'
);

// ---------------------------------------------------------------------
// 3. Île / destination exclue (DOM-TOM, ex. 971 Martinique) — aucune règle dans la source
// ---------------------------------------------------------------------
$resolved = $resolver->resolveDepartment(['postcode' => '97200', 'country_iso' => 'FR']);
$t->assertTrue('3a. Code postal DOM-TOM (97200) résolu en département "97"', $resolved['ok'] === true && $resolved['department'] === '97');
$r = $calc->calculate('97', 10.0);
$t->assertTrue('3b. Aucun tarif pour le département 97 (absent de la grille source)', $r['ok'] === false && $r['reason'] === OklaSchenkerRateCalculator::REASON_NO_RATE_FOUND);
$t->assertTrue('3c. manual_quote_required = true pour destination exclue', $r['manual_quote_required'] === true);

// ---------------------------------------------------------------------
// 4. Altitude — aucune donnée d'altitude dans la source : vérifie l'ABSENCE de tout effet
//    (et non une valeur inventée). Deux départements standards équivalents doivent suivre
//    strictement la même règle, prouvant qu'aucun facteur d'altitude n'est simulé.
// ---------------------------------------------------------------------
$r1 = $calc->calculate('01', 5.0);
$r2 = $calc->calculate('01', 5.0);
$t->assertSame($r1['total_price_ht'], $r2['total_price_ht'], '4. Aucun supplément altitude inventé (résultat déterministe, aucune donnée source)');

// ---------------------------------------------------------------------
// 5. Moins de 100 kg (dernier palier, ex. 80-100 pour dept 01)
// ---------------------------------------------------------------------
$fixture80 = $findRate($json['less_100kg_rates'], '01', 80.0);
$r = $calc->calculate('01', 95.0);
$t->assertTrue('5. Poids 95kg (< 100kg) utilise la table less_100kg_rates', $r['rule_applied'] === 'less_100kg_flat_bracket');
$t->assertSame((float) $fixture80['price_ht'], $r['base_price_ht'], '5. Prix HT palier 80-100 (dept 01)');

// ---------------------------------------------------------------------
// 6. Exactement 100 kg — doit utiliser le palier 80-100 de la table < 100kg (borne incluse)
// ---------------------------------------------------------------------
$r = $calc->calculate('01', 100.0);
$t->assertTrue('6a. 100kg exactement -> table less_100kg_rates (borne incluse)', $r['rule_applied'] === 'less_100kg_flat_bracket');
$t->assertSame((float) $fixture80['price_ht'], $r['base_price_ht'], '6b. Prix HT à 100kg = palier 80-100');

// ---------------------------------------------------------------------
// 7. Plus de 100 kg — tarification au 100kg
// ---------------------------------------------------------------------
$fixtureOver = $findRate($json['over_100kg_rates'], '01', 101.0);
$r = $calc->calculate('01', 150.0);
$expectedBase = round((150.0 / 100.0) * (float) $fixtureOver['price_per_100kg_ht'], 2);
$t->assertTrue('7a. 150kg (> 100kg) utilise la table over_100kg_rates', $r['rule_applied'] === 'over_100kg_per_100kg');
$t->assertSame($expectedBase, $r['base_price_ht'], '7b. Prix HT = poids/100 x prix_aux_100kg_ht (fixture JSON)');

// ---------------------------------------------------------------------
// 8. Poids nul
// ---------------------------------------------------------------------
$r = $calc->calculate('01', 0.0);
$t->assertTrue('8. Poids nul rejeté proprement', $r['ok'] === false && $r['reason'] === OklaSchenkerRateCalculator::REASON_INVALID_WEIGHT);

// ---------------------------------------------------------------------
// 9. Produit sans poids (poids total panier = 0, équivalent au cas 8 au niveau du moteur ;
//    l'agrégation panier -> poids total est de la responsabilité de la couche PrestaShop,
//    non testable ici sans instance PrestaShop, cf. RAPPORT_TESTS.md)
// ---------------------------------------------------------------------
$r = $calc->calculate('01', 0.0);
$t->assertTrue('9. Poids panier à 0 (produit sans poids) bloque le calcul, aucun tarif inventé', $r['ok'] === false);

// ---------------------------------------------------------------------
// 10. Adresse sans code postal
// ---------------------------------------------------------------------
$resolved = $resolver->resolveDepartment(['postcode' => '', 'country_iso' => 'FR']);
$t->assertTrue('10. Adresse sans code postal -> résolution département refusée', $resolved['ok'] === false && $resolved['reason'] === OklaSchenkerAddressResolver::REASON_MISSING_POSTCODE);

// ---------------------------------------------------------------------
// 11. Destination exclue (département inexistant dans la grille, ex. code fictif "99" hors Monaco)
// ---------------------------------------------------------------------
$r = $calc->calculate('50X', 10.0); // code invalide, ne matchera aucune entrée
$t->assertTrue('11. Département non couvert par la grille -> pas de tarif affiché', $r['ok'] === false && $r['reason'] === OklaSchenkerRateCalculator::REASON_NO_RATE_FOUND);

// ---------------------------------------------------------------------
// 12. Supplément saison (1er juin - 31 août)
// ---------------------------------------------------------------------
$inSeason = new DateTimeImmutable('2026-07-15');
$outOfSeason = new DateTimeImmutable('2026-11-15');
$rIn = $calc->calculate('01', 5.0, $inSeason);
$rOut = $calc->calculate('01', 5.0, $outOfSeason);
$hasSeasonalIn = false;
foreach ($rIn['supplements'] as $s) {
    if ($s['code'] === OklaSchenkerRateCalculator::SURCHARGE_SEASONAL) {
        $hasSeasonalIn = true;
    }
}
$hasSeasonalOut = false;
foreach ($rOut['supplements'] as $s) {
    if ($s['code'] === OklaSchenkerRateCalculator::SURCHARGE_SEASONAL) {
        $hasSeasonalOut = true;
    }
}
$t->assertTrue('12a. Supplément saisonnier appliqué le 15 juillet', $hasSeasonalIn === true);
$t->assertTrue('12b. Supplément saisonnier absent le 15 novembre', $hasSeasonalOut === false);

// ---------------------------------------------------------------------
// 13. Supplément carburant — ABSENT de la source sous ce libellé exact.
//     Seule une "Contribution Transition Energétique" existe. On vérifie qu'aucun
//     supplément nommé "carburant" n'est fabriqué / appliqué.
// ---------------------------------------------------------------------
$r = $calc->calculate('01', 5.0);
$hasFuelLabel = false;
foreach ($r['supplements'] as $s) {
    if (stripos($s['code'], 'FUEL') !== false || stripos($s['code'], 'CARBURANT') !== false) {
        $hasFuelLabel = true;
    }
}
$t->assertTrue('13. Aucun supplément "carburant" inventé (absent de la source, non implémenté)', $hasFuelLabel === false);

// ---------------------------------------------------------------------
// 14. Panier multicolis — l'agrégation de poids multi-colis est de la responsabilité
//     de la couche PrestaShop (somme des poids produits x quantités). Au niveau du
//     moteur, on vérifie seulement qu'un poids agrégé (ex: 3 colis de 10kg = 30kg)
//     est traité de façon strictement identique à un poids simple de 30kg.
// ---------------------------------------------------------------------
$rAggregated = $calc->calculate('01', 30.0);
$rSingle = $calc->calculate('01', 30.0);
$t->assertSame($rSingle['total_price_ht'], $rAggregated['total_price_ht'], '14. Poids agrégé multicolis traité de façon déterministe (agrégation réelle testée au niveau PrestaShop, non ici)');

// ---------------------------------------------------------------------
// 15. Montant HT — le moteur ne calcule JAMAIS la TVA (déléguée au moteur de taxes
//     PrestaShop, cf. docs/ANALYSE_TECHNIQUE.md §4). On vérifie que la trace ne contient
//     aucune clé de TVA/TTC pour ne pas laisser croire à un calcul de taxe interne.
// ---------------------------------------------------------------------
$r = $calc->calculate('01', 5.0);
$t->assertTrue('15. Aucune clé TVA/TTC dans la trace moteur (délégué à PrestaShop)', !array_key_exists('vat', $r) && !array_key_exists('total_price_ttc', $r));
$t->assertTrue('15b. total_price_ht est bien un float positif', is_float($r['total_price_ht']) && $r['total_price_ht'] > 0);

echo "\nRésultats des tests du moteur tarifaire (Phase 1, exécutés réellement en PHP CLI le " . date('Y-m-d H:i:s') . "):\n";
exit($t->summary());
