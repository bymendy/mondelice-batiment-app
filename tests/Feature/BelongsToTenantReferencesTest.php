<?php

namespace Tests\Feature;

use App\Exceptions\ReferenceNonModifiableException;
use App\Exceptions\TenantNonIdentifieException;
use App\Models\ArticlePrix;
use App\Models\CategoriePrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lignes « de reference » du trait BelongsToTenant (9 novembre 2026).
 *
 * Verifie que l'ajout est desactive par defaut (ArticlePrix reste strict et
 * filtre sur le seul tenant courant), et que, pour un modele qui l'active
 * (CategoriePrix), les references sont visibles mais protegees.
 */
class BelongsToTenantReferencesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    /**
     * Cree deux references (codes 1 et 2) hors de tout contexte d'entreprise.
     *
     * @return void
     */
    private function creerDeuxReferences(): void
    {
        CategoriePrix::creerReference(['niveau' => 1, 'ordre' => 1, 'code' => '1', 'nom' => 'Reference un']);
        CategoriePrix::creerReference(['niveau' => 1, 'ordre' => 2, 'code' => '2', 'nom' => 'Reference deux']);
    }

    #[Test]
    public function un_modele_sans_references_garde_le_filtre_strict_d_origine(): void
    {
        $entreprise = $this->creerEntreprise();

        $sql = $this->dansEntreprise($entreprise, fn () => ArticlePrix::query()->toSql());

        // Le filtre doit rester « tenant_id = ? » : jamais « OR tenant_id IS NULL ».
        $this->assertStringNotContainsStringIgnoringCase('"tenant_id" is null', $sql);
        $this->assertStringContainsString('"articles_prix"."tenant_id" = ?', $sql);

        $this->expectException(TenantNonIdentifieException::class);
        ArticlePrix::query()->count();
    }

    #[Test]
    public function creer_reference_est_refuse_sur_un_modele_qui_ne_l_a_pas_active(): void
    {
        $this->expectException(LogicException::class);

        ArticlePrix::creerReference(['reference' => 'X']);
    }

    #[Test]
    public function creer_reference_refuse_un_tenant_id(): void
    {
        $this->expectException(LogicException::class);

        CategoriePrix::creerReference(['tenant_id' => 'quelconque', 'niveau' => 1, 'ordre' => 1, 'code' => '1', 'nom' => 'X']);
    }

    #[Test]
    public function une_reference_a_un_tenant_id_vide_meme_si_une_entreprise_est_active(): void
    {
        $entreprise = $this->creerEntreprise();

        $reference = $this->dansEntreprise($entreprise, fn () => CategoriePrix::creerReference(
            ['niveau' => 1, 'ordre' => 1, 'code' => '1', 'nom' => 'Reference']
        ));

        $relue = CategoriePrix::sansFiltreTenant()->find($reference->id);

        $this->assertNull($relue->tenant_id);
        $this->assertTrue($relue->estReference());
    }

    #[Test]
    public function une_creation_ordinaire_sans_entreprise_reste_refusee(): void
    {
        $this->expectException(TenantNonIdentifieException::class);

        CategoriePrix::create(['niveau' => 1, 'ordre' => 1, 'code' => 'X', 'nom' => 'Sans entreprise']);
    }

    #[Test]
    public function une_lecture_sans_entreprise_reste_refusee_meme_avec_des_references(): void
    {
        $this->creerDeuxReferences();

        $this->expectException(TenantNonIdentifieException::class);
        CategoriePrix::query()->count();
    }

    #[Test]
    public function une_entreprise_voit_les_references_et_les_siennes_mais_pas_celles_des_autres(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();
        $this->creerDeuxReferences();

        $this->dansEntreprise($a, fn () => CategoriePrix::create(['niveau' => 1, 'ordre' => 3, 'code' => 'A-1', 'nom' => 'Categorie de A']));
        $this->dansEntreprise($b, fn () => CategoriePrix::create(['niveau' => 1, 'ordre' => 3, 'code' => 'B-1', 'nom' => 'Categorie de B']));

        $codesA = $this->dansEntreprise($a, fn () => CategoriePrix::orderBy('code')->pluck('code')->all());
        $codesB = $this->dansEntreprise($b, fn () => CategoriePrix::orderBy('code')->pluck('code')->all());

        $this->assertSame(['1', '2', 'A-1'], $codesA);
        $this->assertSame(['1', '2', 'B-1'], $codesB);
    }

    #[Test]
    public function une_entreprise_ne_retrouve_pas_une_categorie_d_une_autre_par_son_identifiant(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();

        $idDeB = $this->dansEntreprise($b, fn () => CategoriePrix::create(
            ['niveau' => 1, 'ordre' => 1, 'code' => 'B-1', 'nom' => 'Categorie de B']
        )->id);

        $this->assertNull($this->dansEntreprise($a, fn () => CategoriePrix::find($idDeB)));
    }

    #[Test]
    public function une_entreprise_peut_modifier_et_supprimer_ses_propres_categories(): void
    {
        $a = $this->creerEntreprise();

        $this->dansEntreprise($a, function (): void {
            $categorie = CategoriePrix::create(['niveau' => 1, 'ordre' => 1, 'code' => 'A-1', 'nom' => 'Avant']);
            $categorie->update(['nom' => 'Apres']);
            $this->assertSame('Apres', CategoriePrix::where('code', 'A-1')->value('nom'));

            $categorie->delete();
            $this->assertNull(CategoriePrix::where('code', 'A-1')->first());
        });
    }

    #[Test]
    public function une_entreprise_ne_peut_pas_modifier_une_reference(): void
    {
        $a = $this->creerEntreprise();
        $this->creerDeuxReferences();

        $this->expectException(ReferenceNonModifiableException::class);

        $this->dansEntreprise($a, fn () => CategoriePrix::where('code', '1')->firstOrFail()->update(['nom' => 'Pirate']));
    }

    #[Test]
    public function une_entreprise_ne_peut_pas_supprimer_une_reference(): void
    {
        $a = $this->creerEntreprise();
        $this->creerDeuxReferences();

        $this->expectException(ReferenceNonModifiableException::class);

        $this->dansEntreprise($a, fn () => CategoriePrix::where('code', '1')->firstOrFail()->delete());
    }

    #[Test]
    public function une_entreprise_ne_peut_pas_rattacher_une_reference_a_son_compte(): void
    {
        $a = $this->creerEntreprise();
        $this->creerDeuxReferences();

        $this->expectException(ReferenceNonModifiableException::class);

        $this->dansEntreprise($a, function () use ($a): void {
            $reference = CategoriePrix::where('code', '1')->firstOrFail();
            $reference->tenant_id = $a->getTenantKey();
            $reference->save();
        });
    }

    #[Test]
    public function l_autorisation_explicite_permet_de_modifier_une_reference(): void
    {
        $this->creerDeuxReferences();

        CategoriePrix::sansFiltreTenant()->whereNull('tenant_id')->where('code', '1')->firstOrFail()
            ->autoriserModificationReference()
            ->update(['nom' => 'Corrige par l\'editeur']);

        $this->assertSame(
            'Corrige par l\'editeur',
            CategoriePrix::sansFiltreTenant()->whereNull('tenant_id')->where('code', '1')->value('nom')
        );
    }

    #[Test]
    public function sans_filtre_tenant_voit_toutes_les_lignes(): void
    {
        $a = $this->creerEntreprise();
        $this->creerDeuxReferences();
        $this->dansEntreprise($a, fn () => CategoriePrix::create(['niveau' => 1, 'ordre' => 3, 'code' => 'A-1', 'nom' => 'Categorie de A']));

        $this->assertSame(3, CategoriePrix::sansFiltreTenant()->count());
    }
}
