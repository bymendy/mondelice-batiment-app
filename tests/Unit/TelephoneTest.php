<?php

namespace Tests\Unit;

use App\Support\Telephone;
use PHPUnit\Framework\TestCase;

/**
 * Normalisation des numeros de telephone (detection des doublons).
 */
class TelephoneTest extends TestCase
{
    public function test_un_meme_numero_francais_donne_le_meme_resultat(): void
    {
        foreach (['06 12 34 56 78', '06.12.34.56.78', '+33 6 12 34 56 78', '0033612345678', '+33 (0)6 12 34 56 78'] as $saisie) {
            $this->assertSame('0612345678', Telephone::normaliser($saisie), $saisie);
        }
    }

    public function test_un_numero_etranger_garde_son_indicatif(): void
    {
        $this->assertSame('+32470123456', Telephone::normaliser('+32 470 12 34 56'));
    }

    public function test_une_valeur_vide_ou_trop_courte_donne_null(): void
    {
        $this->assertNull(Telephone::normaliser(null));
        $this->assertNull(Telephone::normaliser(''));
        $this->assertNull(Telephone::normaliser('abc'));
        $this->assertNull(Telephone::normaliser('12 34'));
    }
}
