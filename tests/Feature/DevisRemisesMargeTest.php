<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DeductionDevis;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D4a : remises (ligne, lot, globale), marge et permission
 * devis.voir_marge.
 */
class DevisRemisesMargeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;
    private User $direction;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
    }

    /**
     * @param callable $action
     * @return mixed
     */
    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    /**
     * Devis d'entreprise en preparation, visible de tous.
     *
     * @return Devis
     */
    private function nouveauDevis(): Devis
    {
        return $this->chez(function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Dupont']);

            return Devis::create([
                'client_id' => $client->id,
                'titre' => 'Salle de bain',
                'auteur_id' => $this->direction->id,
                'portee' => 'entreprise',
            ]);
        });
    }

    /**
     * Ajoute une ligne libre par l'API (2 x 100 EUR a 20 % par defaut).
     *
     * @param Devis $devis
     * @param array<string, mixed> $surcharge
     * @return \Illuminate\Testing\TestResponse
     */
    private function ajouterLigne(Devis $devis, array $surcharge = [])
    {
        return $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/lignes", array_merge([
            'libelle' => 'Pose de carrelage',
            'quantite' => 2,
            'unite' => 'm2',
            'prix_unitaire_ht' => 100,
        ], $surcharge))->assertCreated();
    }

    /**
     * Utilisateur qui voit et modifie les devis mais n'a pas devis.voir_marge.
     *
     * @return User
     */
    private function utilisateurSansMarge(): User
    {
        $role = Role::firstOrCreate(['name' => 'Sans marge', 'guard_name' => 'web']);
        $role->syncPermissions(['devis.voir', 'devis.modifier', 'bibliotheque.consulter']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->creerUtilisateur($this->entreprise, 'Sans marge');
    }

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     * @param string $chemin
     * @param float $attendu
     * @return void
     */
    private function montant($reponse, string $chemin, float $attendu): void
    {
        $this->assertEqualsWithDelta($attendu, (float) $reponse->json($chemin), 0.001, $chemin);
    }

    public function test_la_remise_d_une_ligne_baisse_les_totaux(): void
    {
        $devis = $this->nouveauDevis();

        $reponse = $this->ajouterLigne($devis, ['remise_pct' => 10]);
        $this->montant($reponse, 'remise_pct', 10);
        $this->montant($reponse, 'totaux_devis.total_ht', 180);
        $this->montant($reponse, 'totaux_devis.total_ttc', 216);

        $modifiee = $this->agirEn($this->commercial)
            ->putJson('/api/lignes-devis/' . $reponse->json('id'), ['remise_pct' => 25])
            ->assertOk();
        $this->montant($modifiee, 'totaux_devis.total_ht', 150);

        $this->agirEn($this->commercial)
            ->putJson('/api/lignes-devis/' . $reponse->json('id'), ['remise_pct' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remise_pct');
    }

    public function test_une_remise_globale_en_pourcentage(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis);

        $reponse = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise commerciale', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertCreated()
            ->assertJsonPath('libelle', 'Remise commerciale')
            ->assertJsonPath('type', 'pourcentage')
            ->assertJsonPath('lot_id', null);
        $this->montant($reponse, 'totaux_devis.total_ht', 180);
        $this->montant($reponse, 'totaux_devis.total_tva', 36);
        $this->montant($reponse, 'detail_totaux.remises.globale', 20);

        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")
            ->assertOk()
            ->assertJsonCount(1, 'deductions')
            ->assertJsonCount(1, 'detail_totaux.remises.detail');
        $this->montant($fiche, 'total_ht', 180);
        $this->montant($fiche, 'detail_totaux.total_ht_brut', 200);
        $this->montant($fiche, 'detail_totaux.total_remises', 20);
    }

    public function test_une_remise_en_montant_est_repartie_entre_les_taux_de_tva(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, ['quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]);
        $this->ajouterLigne($devis, ['quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 10]);

        $reponse = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Geste commercial', 'type' => 'montant', 'valeur' => 30])
            ->assertCreated();

        $this->montant($reponse, 'totaux_devis.total_ht', 120);
        $this->montant($reponse, 'totaux_devis.total_tva', 20);
        $this->montant($reponse, 'totaux_devis.total_ttc', 140);
    }

    public function test_une_remise_par_lot(): void
    {
        $devis = $this->nouveauDevis();
        $autre = $this->nouveauDevis();
        $lot = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lots", ['type' => 'lot', 'libelle' => 'Plomberie'])->assertCreated()->json('id');
        $emplacement = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lots", ['type' => 'emplacement', 'libelle' => 'Cuisine'])->assertCreated()->json('id');
        $lotAutre = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$autre->id}/lots", ['type' => 'lot', 'libelle' => 'Peinture'])->assertCreated()->json('id');

        $this->ajouterLigne($devis, ['lot_id' => $lot]);
        $this->ajouterLigne($devis, ['quantite' => 1, 'prix_unitaire_ht' => 300]);

        foreach ([$lotAutre, $emplacement, 999999] as $mauvais) {
            $this->agirEn($this->commercial)
                ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'X', 'type' => 'pourcentage', 'valeur' => 10, 'lot_id' => $mauvais])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('lot_id');
        }

        $reponse = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise plomberie', 'type' => 'pourcentage', 'valeur' => 10, 'lot_id' => $lot])
            ->assertCreated()
            ->assertJsonPath('lot_id', $lot);

        $this->montant($reponse, 'totaux_devis.total_ht', 480);
        $this->montant($reponse, 'detail_totaux.remises.lots', 20);
        $this->montant($reponse, 'detail_totaux.par_lot.0.total_ht', 180);
        $this->montant($reponse, 'detail_totaux.par_lot.1.total_ht', 300);
    }

    public function test_les_remises_invalides_sont_refusees(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis);

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/remises", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['libelle', 'type', 'valeur']);

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'X', 'type' => 'cadeau', 'valeur' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'X', 'type' => 'pourcentage', 'valeur' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur');

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'X', 'type' => 'pourcentage', 'valeur' => 120])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur');

        // Montant plus fort que le devis (200 EUR) : refuse, rien n'est enregistre.
        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'X', 'type' => 'montant', 'valeur' => 250])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur');

        $this->assertSame(0, $this->chez(fn () => DeductionDevis::query()->count()));
        $this->assertEqualsWithDelta(200, (float) $this->chez(fn () => Devis::query()->findOrFail($devis->id))->total_ht, 0.001);
    }

    public function test_une_remise_se_modifie_et_se_supprime(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis);
        $id = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertCreated()->json('id');

        $modifiee = $this->agirEn($this->commercial)
            ->putJson("/api/remises-devis/{$id}", ['valeur' => 20, 'libelle' => 'Remise fidélité'])
            ->assertOk()
            ->assertJsonPath('libelle', 'Remise fidélité');
        $this->montant($modifiee, 'totaux_devis.total_ht', 160);

        // Le pourcentage reste borne a 100 meme quand seule la valeur change.
        $this->agirEn($this->commercial)->putJson("/api/remises-devis/{$id}", ['valeur' => 150])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur');
        $this->agirEn($this->commercial)->putJson("/api/remises-devis/{$id}", ['lot_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('lot_id');

        // Passage en montant trop fort : refuse, la remise garde sa valeur.
        $this->agirEn($this->commercial)->putJson("/api/remises-devis/{$id}", ['type' => 'montant', 'valeur' => 500])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur');
        $this->assertSame('pourcentage', $this->chez(fn () => DeductionDevis::query()->findOrFail($id))->type);

        $supprimee = $this->agirEn($this->commercial)->deleteJson("/api/remises-devis/{$id}")->assertOk();
        $this->montant($supprimee, 'totaux_devis.total_ht', 200);
        $this->assertSame(0, $this->chez(fn () => DeductionDevis::query()->count()));
    }

    public function test_les_droits_suivent_les_roles(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis);
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
        $remise = ['libelle' => 'Remise', 'type' => 'pourcentage', 'valeur' => 10];

        $this->agirEn($administratif)->postJson("/api/devis/{$devis->id}/remises", $remise)->assertForbidden();

        $id = $this->agirEn($this->direction)->postJson("/api/devis/{$devis->id}/remises", $remise)->assertCreated()->json('id');
        $this->agirEn($administratif)->putJson("/api/remises-devis/{$id}", ['valeur' => 5])->assertForbidden();
        $this->agirEn($administratif)->deleteJson("/api/remises-devis/{$id}")->assertForbidden();
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $devis = $this->nouveauDevis();

        $this->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertUnauthorized();
    }

    public function test_un_devis_envoye_ne_recoit_plus_de_remise(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis);
        $id = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertCreated()->json('id');
        $this->chez(fn () => $devis->forceFill(['statut' => 'envoye'])->save());

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Autre', 'type' => 'pourcentage', 'valeur' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->agirEn($this->commercial)->putJson("/api/remises-devis/{$id}", ['valeur' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->agirEn($this->commercial)->deleteJson("/api/remises-devis/{$id}")
            ->assertUnprocessable()->assertJsonValidationErrors('devis');
    }

    public function test_les_remises_d_une_autre_entreprise_sont_introuvables(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis);
        $id = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertCreated()->json('id');

        $autreEntreprise = $this->creerEntreprise();
        $etranger = $this->creerUtilisateur($autreEntreprise, 'Direction');

        $this->agirEn($etranger)->putJson("/api/remises-devis/{$id}", ['valeur' => 50])->assertNotFound();
        $this->agirEn($etranger)->deleteJson("/api/remises-devis/{$id}")->assertNotFound();
        $this->agirEn($etranger)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'X', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertNotFound();
    }

    public function test_le_revient_d_une_ligne_libre_se_saisit_et_la_marge_s_affiche(): void
    {
        $devis = $this->nouveauDevis();

        $ligne = $this->ajouterLigne($devis, ['prix_revient_unitaire' => 60]);
        $this->montant($ligne, 'prix_revient_unitaire', 60);

        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")->assertOk();
        $this->montant($fiche, 'lignes.0.prix_revient_unitaire', 60);
        $this->montant($fiche, 'detail_totaux.marge.revient_ht', 120);
        $this->montant($fiche, 'detail_totaux.marge.marge_ht', 80);
        $this->montant($fiche, 'detail_totaux.marge.taux_marge_pct', 40);
        $this->montant($fiche, 'detail_totaux.marge.coefficient', 1.67);
        $this->assertTrue($fiche->json('detail_totaux.marge.revient_complet'));

        // Une remise baisse la marge.
        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertCreated();
        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")->assertOk();
        $this->montant($fiche, 'detail_totaux.marge.marge_ht', 60);
        $this->montant($fiche, 'detail_totaux.marge.taux_marge_pct', 33.33);
    }

    public function test_une_ligne_sans_revient_rend_la_marge_incomplete(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, ['prix_revient_unitaire' => 60]);
        $sansRevient = $this->ajouterLigne($devis, ['libelle' => 'Peinture']);

        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")->assertOk();
        $this->assertFalse($fiche->json('detail_totaux.marge.revient_complet'));
        $this->assertNull($fiche->json('detail_totaux.marge.marge_ht'));
        $this->assertNull($fiche->json('detail_totaux.marge.taux_marge_pct'));
        $this->montant($fiche, 'detail_totaux.marge.revient_ht', 120);

        // Le revient saisi apres coup complete la marge ; il peut etre remis a vide.
        $this->agirEn($this->commercial)
            ->putJson('/api/lignes-devis/' . $sansRevient->json('id'), ['prix_revient_unitaire' => 70])->assertOk();
        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")->assertOk();
        $this->assertTrue($fiche->json('detail_totaux.marge.revient_complet'));
        $this->montant($fiche, 'detail_totaux.marge.marge_ht', 140);

        $this->agirEn($this->commercial)
            ->putJson('/api/lignes-devis/' . $sansRevient->json('id'), ['prix_revient_unitaire' => null])->assertOk();
        $this->assertNull($this->chez(fn () => LigneDevis::query()->findOrFail($sansRevient->json('id')))->prix_revient_unitaire);
    }

    public function test_les_roles_qui_consultent_voient_la_marge(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, ['prix_revient_unitaire' => 60]);

        foreach (['Direction', 'Conducteur travaux', 'Administratif'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role);
            $fiche = $this->agirEn($utilisateur)->getJson("/api/devis/{$devis->id}")->assertOk();

            $this->montant($fiche, 'detail_totaux.marge.marge_ht', 80);
            $this->montant($fiche, 'lignes.0.prix_revient_unitaire', 60);
        }
    }

    public function test_sans_la_permission_ni_revient_ni_marge(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, ['prix_revient_unitaire' => 60]);
        $sansMarge = $this->utilisateurSansMarge();

        $fiche = $this->agirEn($sansMarge)->getJson("/api/devis/{$devis->id}")
            ->assertOk()
            ->assertJsonMissingPath('detail_totaux.marge')
            ->assertJsonMissingPath('lignes.0.prix_revient_unitaire');
        $this->montant($fiche, 'detail_totaux.total_ht', 200);

        // Le revient envoye sans le droit est ignore, a la creation comme a la modification.
        $cree = $this->agirEn($sansMarge)->postJson("/api/devis/{$devis->id}/lignes", [
            'libelle' => 'Peinture', 'quantite' => 1, 'prix_unitaire_ht' => 50, 'prix_revient_unitaire' => 10,
        ])->assertCreated()->assertJsonMissingPath('prix_revient_unitaire');
        $this->assertNull($this->chez(fn () => LigneDevis::query()->findOrFail($cree->json('id')))->prix_revient_unitaire);

        $premiere = $this->chez(fn () => LigneDevis::query()->orderBy('id')->firstOrFail());
        $this->agirEn($sansMarge)->putJson("/api/lignes-devis/{$premiere->id}", ['prix_revient_unitaire' => 1])->assertOk();
        $this->assertEqualsWithDelta(60, (float) $this->chez(fn () => LigneDevis::query()->findOrFail($premiere->id))->prix_revient_unitaire, 0.001);

        $remise = $this->agirEn($sansMarge)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertCreated()
            ->assertJsonMissingPath('detail_totaux.marge');
        $this->montant($remise, 'detail_totaux.remises.globale', 25);
    }

    public function test_une_ligne_copiee_montre_ses_couts_avec_la_permission(): void
    {
        $this->chez(fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60,
            'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));
        $article = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-D4', 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2,
            'unite' => 'u', 'prix_achat_ht' => 5, 'prix_vente_ht' => 8,
        ])->assertCreated();
        $devis = $this->nouveauDevis();

        $ligne = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 3])
            ->assertCreated();
        $this->montant($ligne, 'prix_revient_unitaire', 70);
        $this->montant($ligne, 'composants.0.cout_revient_horaire', 40);
        $this->montant($ligne, 'composants.1.prix_achat_ht', 5);

        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")->assertOk();
        $this->montant($fiche, 'lignes.0.composants.0.cout_revient_horaire', 40);
        $this->montant($fiche, 'detail_totaux.marge.revient_ht', 210);
        $this->montant($fiche, 'detail_totaux.marge.marge_ht', 108);
        $this->montant($fiche, 'detail_totaux.marge.taux_marge_pct', 33.96);
    }

    public function test_les_remises_sont_journalisees(): void
    {
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis);
        $id = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/remises", ['libelle' => 'Remise fidélité', 'type' => 'pourcentage', 'valeur' => 10])
            ->assertCreated()->json('id');
        $this->agirEn($this->commercial)->deleteJson("/api/remises-devis/{$id}")->assertOk();

        $entrees = Activity::query()
            ->where('subject_id', $devis->id)
            ->where('description', 'like', 'Remise «%')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entrees);
        $this->assertSame('Remise « Remise fidélité » ajoutée.', $entrees[0]->description);
        $this->assertSame('pourcentage', $entrees[0]->properties['type']);
        $this->assertSame($this->commercial->id, $entrees[0]->causer_id);
        $this->assertSame('Remise « Remise fidélité » supprimée.', $entrees[1]->description);
    }
}
