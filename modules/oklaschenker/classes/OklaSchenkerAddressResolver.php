<?php
/**
 * Résolution du code département à partir d'une adresse.
 *
 * Ne dépend d'aucune classe PrestaShop : reçoit un tableau simple
 * (postcode, country_iso) pour rester testable en CLI. L'appelant
 * PrestaShop (hook getOrderShippingCost) construit ce tableau à partir
 * de l'objet Address réel du panier, sans jamais le modifier.
 */
class OklaSchenkerAddressResolver
{
    public const REASON_MISSING_POSTCODE = 'MISSING_POSTCODE';
    public const REASON_UNSUPPORTED_COUNTRY = 'UNSUPPORTED_COUNTRY';
    public const REASON_UNPARSABLE_POSTCODE = 'UNPARSABLE_POSTCODE';

    /**
     * @param array{postcode:?string,country_iso:?string} $address
     *
     * @return array{ok:bool,department:?string,reason:?string}
     */
    public function resolveDepartment(array $address): array
    {
        $postcode = trim((string) ($address['postcode'] ?? ''));
        $countryIso = strtoupper(trim((string) ($address['country_iso'] ?? '')));

        if ($postcode === '') {
            return ['ok' => false, 'department' => null, 'reason' => self::REASON_MISSING_POSTCODE];
        }

        // Phase 1 : grille disponible uniquement pour la France métropolitaine + Monaco.
        if ($countryIso !== '' && !in_array($countryIso, ['FR', 'MC'], true)) {
            return ['ok' => false, 'department' => null, 'reason' => self::REASON_UNSUPPORTED_COUNTRY];
        }

        if (!preg_match('/^\d{5}$/', $postcode)) {
            return ['ok' => false, 'department' => null, 'reason' => self::REASON_UNPARSABLE_POSTCODE];
        }

        $prefix = substr($postcode, 0, 2);

        // Corse : la grille source regroupe 2A/2B sous le seul code "20".
        if ($prefix === '20') {
            return ['ok' => true, 'department' => '20', 'reason' => null];
        }

        // Monaco (98000) partage la grille avec le code "98" du fichier source.
        if ($postcode[0] === '9' && $postcode[1] === '8') {
            return ['ok' => true, 'department' => '98', 'reason' => null];
        }

        return ['ok' => true, 'department' => $prefix, 'reason' => null];
    }
}
