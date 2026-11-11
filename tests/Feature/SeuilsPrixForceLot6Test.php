<?php

namespace Tests\Feature;

use App\Models\ArticlePrix;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lot 6 de la Bibliotheque de prix : seuils planchers de la Direction et
 * controle du prix force d'un article (refus 422 des roles autres que la
 * Direction quand le prix est sous le minimum calcule ou non verifiable).
 */
class SeuilsPrixForceLot6Test extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $conducteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->dansEntreprise($this->entreprise, fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60,
            'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));
    }

    /**
     * Fixe un seuil comme la Direction.
     *
     * @param string $type
     * @param float|int $valeur
     * @param bool $actif
     * @return void
     */
    private function seuil(string $type, float|int $valeur, bool $actif = true): void
    {
        $this->agirEn($this->direction)
            ->putJson("/api/parametres/seuils/{$type}", ['valeur' => $valeur, 'actif' => $actif])
            ->assertOk();
    }

    /**
     * @param string $reference
     * @return int
     */
    private function article(string $reference = 'ART-S'): int
    {
        return $this->agirEn($this->direction)
            ->postJson('/api/bibliotheque-prix', [
                'reference' => $reference, 'designation' => 'Pose de carrelage',
                'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
            ])
            ->assertCreated()
            ->json('id');
    }

    /**
     * Article de 1 h de main-d'oeuvre (revient 40) et 2 fournitures a 5 EUR
     * d'achat (revient 10).
     *
     * @param string $reference
     * @return int
     */
    private function articleAvecSousDetail(string $reference = 'ART-S'): int
    {
        $id = $this->article($reference);
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1,
        ])->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2,
            'unite' => 'u', 'prix_achat_ht' => 5, 'prix_vente_ht' => 8,
        ])->assertCreated();

        return $id;
    }

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     * @param string $chemin
     * @param float|null $attendu
     * @return void
     */
    private function montant($reponse, string $chemin, ?float $attendu): void
    {
        $valeur = $reponse->json($chemin);

        if ($attendu === null) {
            $this->assertNull($valeur, $chemin);

            return;
        }

        $this->assertEqualsWithDelta($attendu, $valeur, 0.001, $chemin);
    }

    #[Test]
    public function la_direction_fixe_les_seuils_et_les_autres_roles_n_y_ont_pas_acces(): void
    {
        $liste = $this->agirEn($this->direction)->getJson('/api/parametres/seuils')->assertOk();
        $this->assertSame(['marge_mo_min', 'marge_fournitures_min'], collect($liste->json('data'))->pluck('type')->all());
        $this->assertNull($liste->json('data.0.valeur'));
        $this->assertFalse($liste->json('data.0.actif'));

        $this->agirEn($this->direction)
            ->putJson('/api/parametres/seuils/marge_mo_min', ['valeur' => 20])
            ->assertOk()
            ->assertJsonPath('actif', true)
            ->assertJsonPath('defini_par', $this->direction->id);
        $this->agirEn($this->direction)
            ->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 10, 'actif' => false])
            ->assertOk()
            ->assertJsonPath('actif', false);

        // Changer la valeur sans parler d'« actif » ne le remet pas a zero.
        $this->agirEn($this->direction)
            ->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 12])
            ->assertOk()
            ->assertJsonPath('actif', false);
        $this->agirEn($this->direction)
            ->putJson('/api/parametres/seuils/marge_mo_min', ['valeur' => 25])
            ->assertOk()
            ->assertJsonPath('actif', true);

        $liste = $this->agirEn($this->direction)->getJson('/api/parametres/seuils')->assertOk();
        $this->montant($liste, 'data.0.valeur', 25);
        $this->montant($liste, 'data.1.valeur', 12);
        $this->assertSame(2, DB::table('seuils_tarifaires')->count());

        foreach (['valeur' => -1] as $cle => $mauvaise) {
            $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_mo_min', [$cle => $mauvaise])->assertUnprocessable();
        }
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_mo_min', ['valeur' => 100])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_mo_min', ['valeur' => 'abc'])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_mo_min', [])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_mo_min', ['valeur' => 20, 'actif' => 'peut-etre'])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/autre', ['valeur' => 20])->assertNotFound();

        foreach (['Conducteur travaux', 'Commercial', 'Administratif', 'Salarié terrain'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role);
            $this->agirEn($utilisateur)->getJson('/api/parametres/seuils')->assertForbidden();
            $this->agirEn($utilisateur)->putJson('/api/parametres/seuils/marge_mo_min', ['valeur' => 1])->assertForbidden();
        }
        $this->montant($this->agirEn($this->direction)->getJson('/api/parametres/seuils'), 'data.0.valeur', 25);
    }

    #[Test]
    public function la_base_refuse_un_seuil_hors_limites(): void
    {
        $ligne = ['tenant_id' => $this->entreprise->getTenantKey(), 'type' => 'marge_mo_min', 'valeur' => 100, 'created_at' => now(), 'updated_at' => now()];

        $this->expectException(QueryException::class);

        DB::table('seuils_tarifaires')->insert($ligne);
    }

    #[Test]
    public function les_seuils_d_une_entreprise_ne_sont_pas_visibles_d_une_autre(): void
    {
        $this->seuil('marge_mo_min', 20);
        $autre = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');

        $liste = $this->agirEn($autre)->getJson('/api/parametres/seuils')->assertOk();
        $this->assertNull($liste->json('data.0.valeur'));
    }

    #[Test]
    public function sans_seuil_actif_le_prix_force_n_est_pas_controle(): void
    {
        $id = $this->articleAvecSousDetail();

        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 1])->assertOk();

        $this->seuil('marge_mo_min', 20, false);
        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 2])->assertOk();
    }

    #[Test]
    public function un_prix_force_sous_le_minimum_est_refuse_sauf_pour_la_direction(): void
    {
        // Minimum : 40 / 0,80 + 10 / 0,90 = 61,11 -> arrondi au centime superieur : 61,12.
        $this->seuil('marge_mo_min', 20);
        $this->seuil('marge_fournitures_min', 10);
        $id = $this->articleAvecSousDetail();

        $reponse = $this->agirEn($this->conducteur)
            ->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 61])
            ->assertUnprocessable()
            ->assertJsonPath('motif', 'sous_le_seuil')
            ->assertJsonStructure(['message', 'errors' => ['prix_force_ht'], 'seuils' => ['marge_mo_min', 'marge_fournitures_min']]);
        $this->montant($reponse, 'prix_minimum_ht', 61.12);
        $this->montant($reponse, 'seuils.marge_mo_min', 20);
        $this->assertNull(ArticlePrix::sansFiltreTenant()->find($id)->prix_force_ht);

        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 61.12])->assertOk();

        // La Direction n'est jamais bloquee.
        $reponse = $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 10])->assertOk();
        $this->montant($reponse, 'prix_force_ht', 10);
    }

    #[Test]
    public function un_type_de_ligne_sans_seuil_actif_est_controle_a_zero_pour_cent_de_marge(): void
    {
        // Seule la main-d'oeuvre a un seuil : 40 / 0,80 + 10 = 60,00 exactement.
        $this->seuil('marge_mo_min', 20);
        $this->seuil('marge_fournitures_min', 30, false);
        $id = $this->articleAvecSousDetail();

        $reponse = $this->agirEn($this->conducteur)
            ->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 59.99])
            ->assertUnprocessable();
        $this->montant($reponse, 'prix_minimum_ht', 60);
        $this->assertNull($reponse->json('seuils.marge_fournitures_min'));

        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 60])->assertOk();
    }

    #[Test]
    public function un_revient_inconnu_empeche_un_prix_force_hors_direction(): void
    {
        $this->seuil('marge_mo_min', 20);
        $this->dansEntreprise($this->entreprise, fn () => TauxHoraire::create([
            'code' => 'maitre', 'libelle' => 'Maitre', 'taux_vente_ht' => 90,
            'cout_revient_ht' => null, 'par_defaut' => false, 'ordre' => 2,
        ]));

        // Sans sous-detail.
        $vide = $this->article('ART-VIDE');
        $reponse = $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$vide}", ['prix_force_ht' => 100])
            ->assertUnprocessable()
            ->assertJsonPath('motif', 'revient_inconnu');
        $this->montant($reponse, 'prix_minimum_ht', null);

        // Fourniture sans prix d'achat.
        $sansAchat = $this->article('ART-SANS-ACHAT');
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$sansAchat}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 8,
        ])->assertCreated();
        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$sansAchat}", ['prix_force_ht' => 100])
            ->assertUnprocessable()
            ->assertJsonPath('motif', 'revient_inconnu');

        // Taux sans cout de revient.
        $sansCout = $this->article('ART-SANS-COUT');
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$sansCout}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'maitre', 'quantite' => 1,
        ])->assertCreated();
        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$sansCout}", ['prix_force_ht' => 100])
            ->assertUnprocessable()
            ->assertJsonPath('motif', 'revient_inconnu');

        // La Direction fixe son prix quand meme.
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$vide}", ['prix_force_ht' => 100])->assertOk();
    }

    #[Test]
    public function a_la_creation_seule_la_direction_peut_poser_un_prix_force(): void
    {
        $this->seuil('marge_mo_min', 20);
        $donnees = [
            'reference' => 'NEUF-1', 'designation' => 'Neuf', 'type_article' => 'forfait',
            'unite' => 'u', 'taux_tva' => 20,
        ];

        $this->agirEn($this->conducteur)
            ->postJson('/api/bibliotheque-prix', $donnees + ['prix_force_ht' => 50])
            ->assertUnprocessable()
            ->assertJsonPath('motif', 'revient_inconnu');
        $this->assertDatabaseMissing('articles_prix', ['reference' => 'NEUF-1']);

        $this->agirEn($this->conducteur)->postJson('/api/bibliotheque-prix', $donnees)->assertCreated();
        $this->agirEn($this->direction)
            ->postJson('/api/bibliotheque-prix', ['reference' => 'NEUF-2'] + $donnees + ['prix_force_ht' => 5])
            ->assertCreated();
    }

    #[Test]
    public function un_prix_inchange_ou_supprime_n_est_pas_controle(): void
    {
        $this->seuil('marge_mo_min', 20);
        $id = $this->articleAvecSousDetail();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 10])->assertOk();

        // Un prix deja sous le seuil ne bloque pas les autres modifications...
        $this->agirEn($this->conducteur)
            ->putJson("/api/bibliotheque-prix/{$id}", ['designation' => 'Nouvelle designation', 'prix_force_ht' => 10])
            ->assertOk()
            ->assertJsonPath('designation', 'Nouvelle designation');
        // ... mais on ne peut pas le remplacer par un autre prix sous le seuil.
        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 11])->assertUnprocessable();
        // Retirer le prix force est toujours permis : le prix redevient calcule.
        $this->agirEn($this->conducteur)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => null])->assertOk();
        $this->assertNull(ArticlePrix::sansFiltreTenant()->find($id)->prix_force_ht);
    }

    #[Test]
    public function les_seuils_d_une_entreprise_ne_controlent_pas_les_prix_d_une_autre(): void
    {
        $this->seuil('marge_mo_min', 20);
        $autre = $this->creerEntreprise();
        $directionAutre = $this->creerUtilisateur($autre, 'Direction');
        $conducteurAutre = $this->creerUtilisateur($autre, 'Conducteur travaux');
        $id = $this->agirEn($directionAutre)
            ->postJson('/api/bibliotheque-prix', [
                'reference' => 'ART-B', 'designation' => 'Article B', 'type_article' => 'forfait', 'unite' => 'u', 'taux_tva' => 20,
            ])
            ->json('id');

        $this->agirEn($conducteurAutre)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 1])->assertOk();
    }

    #[Test]
    public function la_personnalisation_d_une_reference_n_est_pas_un_prix_force_a_la_main(): void
    {
        $this->seuil('marge_mo_min', 20);
        $reference = ArticlePrix::creerReference([
            'reference' => 'R-0001', 'designation' => 'Reference', 'type_article' => 'materiaux',
            'unite' => 'u', 'taux_tva' => 20, 'prix_force_ht' => 1, 'source' => 'mondelice',
        ]);

        $this->agirEn($this->conducteur)
            ->postJson("/api/bibliotheque-prix/{$reference->id}/personnaliser")
            ->assertCreated();
    }
}
