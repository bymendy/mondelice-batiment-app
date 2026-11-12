<?php

namespace App\Support;

/**
 * Normalisation des numeros de telephone, pour reconnaitre un meme numero
 * saisi sous deux formats ("06 12 34 56 78", "+33 6 12 34 56 78").
 */
final class Telephone
{
    /**
     * Normalise un numero : chiffres seuls, format national pour la France
     * (0612345678), "+" suivi des chiffres pour un numero etranger.
     * Renvoie null si la valeur ne contient pas assez de chiffres.
     *
     * @param string|null $numero Numero tel que saisi.
     * @return string|null
     */
    public static function normaliser(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }

        $brut = preg_replace('/[^0-9+]/', '', $numero) ?? '';
        $chiffres = preg_replace('/\D/', '', $brut) ?? '';

        $international = str_starts_with($brut, '+') || str_starts_with($chiffres, '00');
        if (str_starts_with($chiffres, '00')) {
            $chiffres = substr($chiffres, 2);
        }

        if (strlen($chiffres) < 6) {
            return null;
        }

        if (! $international) {
            return $chiffres;
        }

        // France : +33 6..., 0033 6... et +33 (0)6... deviennent 06...
        if (str_starts_with($chiffres, '330') && strlen($chiffres) === 12) {
            return substr($chiffres, 2);
        }
        if (str_starts_with($chiffres, '33') && strlen($chiffres) === 11) {
            return '0' . substr($chiffres, 2);
        }

        return '+' . $chiffres;
    }
}
