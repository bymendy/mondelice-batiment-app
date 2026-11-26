<?php

namespace Tests\Feature;

use App\Models\Fourniture;
use App\Models\ImportFournitures;
use App\Models\ImportFournituresLigne;
use App\Models\Parametre;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tâche 3.6 bis, lot F3b : choix des lignes, application d'un import à la
 * Base de fournitures (tout ou rien), journal, export pour un tableur.
 */
class ImportFournituresF3bTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    // A-1 inchangée, B-1 +5 %, C-1 +50 % (à valider), N-1 nouvelle, une ligne en erreur.
    private const CATALOGUE = "Réf;Désignation;Unité;Prix public HT\n"
        . "A-1;Carrelage A;m2;100,00\n"
        . "B-1;Carrelage B;m2;105,00\n"
        . "C-1;Carrelage C;m2;150,00\n"
        . "N-1;Nouveau produit;u;12,50\n"
        . ";Sans référence;u;10\n";

    private const COLONNES = ['reference' => 0, 'designation' => 1, 'unite' => 2, 'prix_tarif_ht' => 3];

    private Tenant $entreprise;

    private User $direction;

    private int $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->fournisseur = $this->agirEn($this->direction)
            ->postJson('/api/fournisseurs', ['nom' => 'Point P', 'remise_defaut_pct' => 10])->assertCreated()->json('id');
        // Trois fournitures : tarif 100, achat net 90, vente saisie 150.
        foreach (['A-1' => 'Carrelage A', 'B-1' => 'Carrelage B', 'C-1' => 'Carrelage C'] as $reference => $designation) {
            $this->agirEn($this->direction)->postJson('/api/fournitures', [
                'fournisseur_id' => $this->fournisseur, 'reference' => $reference, 'designation' => $designation,
                'unite' => 'm2', 'prix_tarif_ht' => 100, 'prix_vente_ht' => 150,
            ])->assertCreated();
        }
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    private function margeParDefaut(float $pct): void
    {
        $this->chez(fn () => Parametre::ecrire('marge_fournitures_defaut', $pct));
    }

    /**
     * Envoie un fichier et lance l'analyse. Renvoie l'identifiant de l'import.
     *
     * @param array<string, mixed> $donnees
     * @param array<string, int|null> $colonnes
     */
    private function importer(string|UploadedFile $fichier, array $donnees = [], array $colonnes = self::COLONNES, ?User $qui = null): int
    {
        $fichier = is_string($fichier) ? UploadedFile::fake()->createWithContent('catalogue.csv', $fichier) : $fichier;
        $import = $this->agirEn($qui ?? $this->direction)->post('/api/imports-fournitures', $donnees + [
            'fichier' => $fichier, 'fournisseur_id' => $this->fournisseur, 'mode' => 'complet',
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->analyser($import, $colonnes)->assertOk();

        return $import;
    }

    /**
     * @param array<string, int|null> $colonnes
     * @return \Illuminate\Testing\TestResponse
     */
    private function analyser(int $import, array $colonnes = self::COLONNES)
    {
        return $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/correspondance", ['correspondance' => $colonnes]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function appliquer(int $import, ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->direction)->postJson("/api/imports-fournitures/{$import}/appliquer");
    }

    private function fourniture(string $reference): ?Fourniture
    {
        return $this->chez(fn () => Fourniture::query()->where('reference', $reference)->first());
    }

    private function ligne(int $import, string $reference): int
    {
        return (int) $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->where('reference', $reference)->value('id'));
    }

    private function journal(string $evenement): int
    {
        return $this->chez(fn () => Activity::query()->where('event', $evenement)->count());
    }

    public function test_l_application_cree_et_modifie_les_lignes_retenues(): void
    {
        $this->margeParDefaut(50);
        // D-1 : prix de vente calculé (achat 90, marge 50 % : 180).
        $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $this->fournisseur, 'reference' => 'D-1', 'designation' => 'Carrelage D', 'unite' => 'm2', 'prix_tarif_ht' => 100,
        ])->assertCreated();
        $this->assertEqualsWithDelta(180, (float) $this->fourniture('D-1')->prix_vente_ht, 0.0001);

        $import = $this->importer(self::CATALOGUE . "D-1;Carrelage D;m2;110\n", ['date_tarif' => '2026-11-01']);
        $chemin = $this->chez(fn () => ImportFournitures::query()->findOrFail($import)->chemin);
        $creees = $this->journal('fourniture_creee');
        $modifiees = $this->journal('fourniture_modifiee');

        $this->appliquer($import)
            ->assertOk()
            ->assertJsonPath('statut', 'applique')
            ->assertJsonPath('bilan.creees', 1)
            ->assertJsonPath('bilan.modifiees', 2)
            ->assertJsonPath('bilan.dates_confirmees', 1)
            ->assertJsonPath('bilan.articles_mis_a_jour', 0)
            ->assertJsonPath('bilan.articles_sous_seuil', []);

        // B-1 : tarif 105, achat net 94,50 ; le prix de vente saisi ne bouge pas.
        $b = $this->fourniture('B-1');
        $this->assertEqualsWithDelta(105, (float) $b->prix_tarif_ht, 0.0001);
        $this->assertEqualsWithDelta(94.5, (float) $b->prix_achat_ht, 0.0001);
        $this->assertEqualsWithDelta(150, (float) $b->prix_vente_ht, 0.0001);
        $this->assertTrue($b->prix_vente_saisi);
        $this->assertSame('2026-11-01', $b->date_tarif->format('Y-m-d'));

        // D-1 : tarif 110, achat net 99 ; le prix de vente calculé suit (198).
        $d = $this->fourniture('D-1');
        $this->assertEqualsWithDelta(99, (float) $d->prix_achat_ht, 0.0001);
        $this->assertEqualsWithDelta(198, (float) $d->prix_vente_ht, 0.0001);
        $this->assertFalse($d->prix_vente_saisi);

        // C-1 : à valider, pas retenue : rien ne change. A-1 : inchangée, date confirmée.
        $c = $this->fourniture('C-1');
        $this->assertEqualsWithDelta(100, (float) $c->prix_tarif_ht, 0.0001);
        $this->assertNull($c->date_tarif);
        $this->assertSame('2026-11-01', $this->fourniture('A-1')->date_tarif->format('Y-m-d'));

        // N-1 : créée, achat net 11,25, vente calculée 22,50.
        $n = $this->fourniture('N-1');
        $this->assertNotNull($n);
        $this->assertSame('Nouveau produit', $n->designation);
        $this->assertSame('u', $n->unite);
        $this->assertSame($this->fournisseur, (int) $n->fournisseur_id);
        $this->assertEqualsWithDelta(12.5, (float) $n->prix_tarif_ht, 0.0001);
        $this->assertEqualsWithDelta(11.25, (float) $n->prix_achat_ht, 0.0001);
        $this->assertEqualsWithDelta(22.5, (float) $n->prix_vente_ht, 0.0001);
        $this->assertSame(5, $this->chez(fn () => Fourniture::query()->count()));

        // La date de tarif se lit sur la fiche.
        $this->agirEn($this->direction)->getJson("/api/fournitures/{$n->id}")->assertOk()->assertJsonPath('date_tarif', '2026-11-01');

        // Le fichier est supprimé, le comparatif reste, tout est journalisé.
        Storage::disk('local')->assertMissing($chemin);
        $this->assertSame(6, $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->count()));
        $this->assertSame($n->id, (int) $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->where('reference', 'N-1')->value('fourniture_id')));
        $this->assertSame(1, $this->journal('import_fournitures_applique'));
        $this->assertSame($creees + 1, $this->journal('fourniture_creee'));
        $this->assertSame($modifiees + 2, $this->journal('fourniture_modifiee'));
        $trace = $this->chez(fn () => Activity::query()->where('event', 'fourniture_modifiee')->where('subject_id', $b->id)->latest('id')->first());
        $this->assertSame($import, (int) $trace->properties['import_id']);
        $this->assertFalse($trace->properties['ecart_valide_a_la_main']);

        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()
            ->assertJsonPath('statut', 'applique')
            ->assertJsonPath('bilan.creees', 1)
            ->assertJsonPath('applique_par.id', $this->direction->id);
    }

    public function test_cocher_une_ligne_a_valider_la_valide(): void
    {
        $import = $this->importer(str_replace("N-1;Nouveau produit;u;12,50\n", '', self::CATALOGUE));
        $c = $this->ligne($import, 'C-1');

        $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/lignes/{$c}", ['retenue' => true])
            ->assertOk()
            ->assertJsonPath('ligne.retenue', true)
            ->assertJsonPath('ligne.a_valider', true)
            ->assertJsonPath('resume.retenues', 2);
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonPath('resume.retenues', 2);

        // Une ligne inchangée ou en erreur ne se retient pas ; une ligne d'un autre import est introuvable.
        foreach (['A-1', null] as $reference) {
            $id = $reference !== null
                ? $this->ligne($import, $reference)
                : (int) $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->where('action', 'erreur')->value('id'));
            $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/lignes/{$id}", ['retenue' => true])
                ->assertUnprocessable()->assertJsonValidationErrors('retenue');
        }
        $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/lignes/{$c}", [])->assertUnprocessable()->assertJsonValidationErrors('retenue');
        $autre = $this->importer(self::CATALOGUE);
        $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$autre}/lignes/{$c}", ['retenue' => true])->assertNotFound();

        $this->appliquer($import)->assertOk()->assertJsonPath('bilan.modifiees', 2);
        $this->assertEqualsWithDelta(150, (float) $this->fourniture('C-1')->prix_tarif_ht, 0.0001);
        $trace = $this->chez(fn () => Activity::query()->where('event', 'fourniture_modifiee')->where('subject_id', $this->fourniture('C-1')->id)->latest('id')->first());
        $this->assertTrue($trace->properties['ecart_valide_a_la_main']);
        $this->assertEqualsWithDelta(50, (float) $trace->properties['ecart_pct'], 0.001);
    }

    public function test_les_lignes_se_retiennent_par_groupe(): void
    {
        $import = $this->importer(self::CATALOGUE);
        $groupe = fn (array $donnees) => $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/retenues", $donnees);

        $groupe(['retenue' => false])->assertOk()->assertJsonPath('lignes_changees', 3)->assertJsonPath('resume.retenues', 0);
        // Rien de retenu et pas de date de tarif : rien à appliquer.
        $this->appliquer($import)->assertUnprocessable()->assertJsonValidationErrors('import');

        $groupe(['retenue' => true, 'action' => 'creer'])->assertOk()->assertJsonPath('lignes_changees', 1)->assertJsonPath('resume.retenues', 1);
        $groupe(['retenue' => true, 'a_valider' => true])->assertOk()->assertJsonPath('lignes_changees', 1)->assertJsonPath('resume.retenues', 2);
        $groupe(['retenue' => true])->assertOk()->assertJsonPath('resume.retenues', 3);
        $groupe(['retenue' => true, 'action' => 'erreur'])->assertUnprocessable()->assertJsonValidationErrors('action');
        $groupe([])->assertUnprocessable()->assertJsonValidationErrors('retenue');

        // Les lignes en erreur ou inchangées ne sont jamais retenues.
        $this->assertSame(0, $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)
            ->whereIn('action', ['erreur', 'inchange'])->where('retenue', true)->count()));
    }

    public function test_un_catalogue_sans_changement_confirme_la_date_de_tarif(): void
    {
        $contenu = "Réf;Désignation;Unité;Prix public HT\nA-1;Carrelage A;m2;100\nB-1;Carrelage B;m2;100\n";

        // Sans date de tarif, il n'y a rien à appliquer.
        $sansDate = $this->importer($contenu);
        $this->appliquer($sansDate)->assertUnprocessable()->assertJsonValidationErrors('import');

        $import = $this->importer($contenu, ['date_tarif' => '2026-12-01']);
        $this->appliquer($import)->assertOk()
            ->assertJsonPath('bilan.creees', 0)->assertJsonPath('bilan.modifiees', 0)->assertJsonPath('bilan.dates_confirmees', 2);
        $this->assertSame('2026-12-01', $this->fourniture('A-1')->date_tarif->format('Y-m-d'));
        $this->assertNull($this->fourniture('C-1')->date_tarif);
    }

    public function test_les_articles_suivent_l_import(): void
    {
        $this->chez(fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60, 'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));
        $article = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-F3B', 'designation' => 'Pose de carrelage', 'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_base', 'fourniture_id' => $this->fourniture('B-1')->id, 'quantite' => 2,
        ])->assertCreated();
        $prix = fn (): float => (float) $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()->json('prix_vente_ht');
        // 1,5 h x 60 + 2 x 150 = 390.
        $this->assertEqualsWithDelta(390, $prix(), 0.001);

        // Le fichier donne un nouveau prix de vente pour B-1 : 160.
        $import = $this->importer("Ref;Tarif;PV\nB-1;100;160\n", ['mode' => 'prix'], ['reference' => 0, 'prix_tarif_ht' => 1, 'prix_vente_ht' => 2]);
        $this->appliquer($import)->assertOk()->assertJsonPath('bilan.modifiees', 1)->assertJsonPath('bilan.articles_mis_a_jour', 1);

        // 90 + 2 x 160 = 410.
        $this->assertEqualsWithDelta(410, $prix(), 0.001);
    }

    public function test_une_base_modifiee_depuis_l_analyse_bloque_l_application(): void
    {
        $this->margeParDefaut(50);
        $import = $this->importer(self::CATALOGUE);

        // Quelqu'un modifie B-1 entre l'analyse et l'application.
        $b = $this->fourniture('B-1')->id;
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$b}", ['prix_tarif_ht' => 102])->assertOk();

        $this->appliquer($import)->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->assertNull($this->fourniture('N-1'));
        $this->assertEqualsWithDelta(102, (float) $this->fourniture('B-1')->prix_tarif_ht, 0.0001);
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonPath('statut', 'analyse');

        // Relancer l'analyse suffit : le comparatif repart de la base actuelle.
        $this->analyser($import)->assertOk();
        // Une référence « à créer » apparue entre-temps bloque aussi.
        $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $this->fournisseur, 'reference' => 'N-1', 'designation' => 'Déjà là', 'unite' => 'u', 'prix_vente_ht' => 5,
        ])->assertCreated();
        $this->appliquer($import)->assertUnprocessable()->assertJsonValidationErrors('import');

        $this->analyser($import)->assertOk()->assertJsonPath('resume.a_creer', 0);
        $this->appliquer($import)->assertOk()->assertJsonPath('bilan.creees', 0)->assertJsonPath('bilan.modifiees', 2);
        $this->assertEqualsWithDelta(105, (float) $this->fourniture('B-1')->prix_tarif_ht, 0.0001);
        $this->assertSame('Nouveau produit', $this->fourniture('N-1')->designation);
    }

    public function test_l_application_est_tout_ou_rien(): void
    {
        // Sans marge par défaut, le prix de vente de N-1 (nouvelle) ne se calcule pas.
        $import = $this->importer(self::CATALOGUE);
        $chemin = $this->chez(fn () => ImportFournitures::query()->findOrFail($import)->chemin);
        $modifiees = $this->journal('fourniture_modifiee');

        $reponse = $this->appliquer($import)->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->assertStringContainsString('Ligne 5 (N-1)', $reponse->json('errors.import.0'));

        // B-1 passait avant N-1 : elle n'a pas été modifiée pour autant.
        $this->assertEqualsWithDelta(100, (float) $this->fourniture('B-1')->prix_tarif_ht, 0.0001);
        $this->assertNull($this->fourniture('N-1'));
        $this->assertSame($modifiees, $this->journal('fourniture_modifiee'));
        $this->assertSame(0, $this->journal('import_fournitures_applique'));
        Storage::disk('local')->assertExists($chemin);
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonPath('statut', 'analyse')->assertJsonPath('bilan', null);

        // Une fois la marge réglée, le même import s'applique.
        $this->margeParDefaut(50);
        $this->appliquer($import)->assertOk()->assertJsonPath('bilan.creees', 1)->assertJsonPath('bilan.modifiees', 1);
    }

    public function test_un_prix_d_achat_net_importe_remplace_le_prix_tarif(): void
    {
        $import = $this->importer("Ref;Net\nA-1;80\n", ['mode' => 'prix'], ['reference' => 0, 'prix_achat_ht' => 1]);
        $this->appliquer($import)->assertOk()->assertJsonPath('bilan.modifiees', 1);

        $a = $this->fourniture('A-1');
        $this->assertNull($a->prix_tarif_ht);
        $this->assertEqualsWithDelta(80, (float) $a->prix_achat_ht, 0.0001);
        $this->assertEqualsWithDelta(150, (float) $a->prix_vente_ht, 0.0001);
        $this->assertTrue($a->prix_vente_saisi);
    }

    public function test_la_remise_et_le_prix_de_vente_du_fichier_s_appliquent(): void
    {
        $import = $this->importer("Ref;Tarif;Remise;PV\nA-1;100;15;\nB-1;100;;160\n", ['mode' => 'prix'],
            ['reference' => 0, 'prix_tarif_ht' => 1, 'remise_pct' => 2, 'prix_vente_ht' => 3]);
        $this->appliquer($import)->assertOk()->assertJsonPath('bilan.modifiees', 2);

        // A-1 : remise propre de 15 %, achat net 85 ; vente inchangée.
        $a = $this->fourniture('A-1');
        $this->assertEqualsWithDelta(15, (float) $a->remise_pct, 0.001);
        $this->assertEqualsWithDelta(85, (float) $a->prix_achat_ht, 0.0001);
        $this->assertEqualsWithDelta(150, (float) $a->prix_vente_ht, 0.0001);
        // B-1 : la remise reste celle du fournisseur, le prix de vente devient 160.
        $b = $this->fourniture('B-1');
        $this->assertNull($b->remise_pct);
        $this->assertEqualsWithDelta(90, (float) $b->prix_achat_ht, 0.0001);
        $this->assertEqualsWithDelta(160, (float) $b->prix_vente_ht, 0.0001);
        $this->assertTrue($b->prix_vente_saisi);
    }

    public function test_un_import_applique_ne_se_rejoue_pas(): void
    {
        $this->margeParDefaut(50);

        // Pas encore analysé : rien à appliquer.
        $envoye = $this->agirEn($this->direction)->post('/api/imports-fournitures', [
            'fichier' => UploadedFile::fake()->createWithContent('catalogue.csv', self::CATALOGUE), 'fournisseur_id' => $this->fournisseur, 'mode' => 'complet',
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->appliquer($envoye)->assertUnprocessable()->assertJsonValidationErrors('import');

        $import = $this->importer(self::CATALOGUE);
        $b = $this->ligne($import, 'B-1');
        $this->appliquer($import)->assertOk();

        $this->appliquer($import)->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->analyser($import)->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/lignes/{$b}", ['retenue' => false])->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/retenues", ['retenue' => false])->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->agirEn($this->direction)->deleteJson("/api/imports-fournitures/{$import}")->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->assertSame(1, $this->journal('import_fournitures_applique'));

        // Un import annulé ne s'applique pas non plus.
        $this->agirEn($this->direction)->deleteJson("/api/imports-fournitures/{$envoye}")->assertNoContent();
        $this->appliquer($envoye)->assertUnprocessable()->assertJsonValidationErrors('import');
    }

    public function test_l_export_se_reimporte_sans_reglage(): void
    {
        // Une référence qu'un tableur abîmerait dans un CSV.
        $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $this->fournisseur, 'reference' => '0012', 'designation' => 'Colle « flex » & joint', 'unite' => 'sac',
            'prix_tarif_ht' => 20, 'remise_pct' => 25, 'prix_vente_ht' => 30.5,
        ])->assertCreated();
        // Une fourniture d'un autre fournisseur ne sort pas.
        $autre = $this->agirEn($this->direction)->postJson('/api/fournisseurs', ['nom' => 'Cedeo'])->assertCreated()->json('id');
        $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $autre, 'reference' => 'Z-9', 'designation' => 'Ailleurs', 'unite' => 'u', 'prix_vente_ht' => 5,
        ])->assertCreated();

        $csv = $this->agirEn($this->direction)->get("/api/fournitures/export?fournisseur_id={$this->fournisseur}&format=csv")->assertOk();
        $this->assertStringContainsString('fournitures-point-p-', (string) $csv->headers->get('Content-Disposition'));
        $this->assertSame('tarif', $csv->headers->get('X-Colonne-Prix'));
        $contenu = (string) $csv->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFRéférence;Désignation;Unité;Prix tarif HT;Remise %;Prix de vente HT;", $contenu);
        $this->assertStringContainsString("\r\n0012;Colle « flex » & joint;sac;20;25;30,5;15;\r\n", $contenu);
        $this->assertStringContainsString("\r\nA-1;Carrelage A;m2;100;;150;90;\r\n", $contenu);
        $this->assertStringNotContainsString('Z-9', $contenu);
        $this->assertSame(1, $this->journal('fournitures_exportees'));

        // Le fichier Excel exporté se réimporte tel quel : colonnes reconnues, rien à changer.
        $xlsx = $this->agirEn($this->direction)->get("/api/fournitures/export?fournisseur_id={$this->fournisseur}")->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $xlsx->headers->get('Content-Disposition'));
        $chemin = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($chemin, $xlsx->getContent());

        $reponse = $this->agirEn($this->direction)->post('/api/imports-fournitures', [
            'fichier' => new UploadedFile($chemin, 'export.xlsx', null, null, true), 'fournisseur_id' => $this->fournisseur, 'mode' => 'complet',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('nb_lignes', 4)
            ->assertJsonPath('apercu.0.0', '0012')
            ->assertJsonPath('correspondance_proposee.reference', 0)
            ->assertJsonPath('correspondance_proposee.designation', 1)
            ->assertJsonPath('correspondance_proposee.unite', 2)
            ->assertJsonPath('correspondance_proposee.prix_tarif_ht', 3)
            ->assertJsonPath('correspondance_proposee.remise_pct', 4)
            ->assertJsonPath('correspondance_proposee.prix_vente_ht', 5)
            ->assertJsonPath('correspondance_proposee.prix_achat_ht', null);
        $this->analyser($reponse->json('id'), $reponse->json('correspondance_proposee'))->assertOk()
            ->assertJsonPath('resume.inchangees', 4)->assertJsonPath('resume.a_modifier', 0)->assertJsonPath('resume.erreurs', 0);

        // Avec le prix d'achat net pour colonne de prix.
        $achat = $this->agirEn($this->direction)->get("/api/fournitures/export?fournisseur_id={$this->fournisseur}&format=csv&prix=achat")->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBFRéférence;Désignation;Unité;Prix achat net HT;Prix de vente HT;", (string) $achat->getContent());
        $this->assertStringContainsString("\r\nA-1;Carrelage A;m2;90;150;\r\n", (string) $achat->getContent());

        $refus = fn (string $parametres, string $champ) => $this->agirEn($this->direction)
            ->getJson("/api/fournitures/export?{$parametres}")->assertUnprocessable()->assertJsonValidationErrors($champ);
        $refus('format=csv', 'fournisseur_id');
        $refus('fournisseur_id=999999', 'fournisseur_id');
        $refus("fournisseur_id={$this->fournisseur}&format=pdf", 'format');
        $refus("fournisseur_id={$this->fournisseur}&prix=vente", 'prix');
    }

    public function test_les_droits(): void
    {
        $this->margeParDefaut(50);
        $import = $this->importer(self::CATALOGUE);
        $b = $this->ligne($import, 'B-1');
        $export = "/api/fournitures/export?fournisseur_id={$this->fournisseur}&format=csv";

        foreach (['Commercial', 'Administratif'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role);
            $this->appliquer($import, $utilisateur)->assertForbidden();
            $this->agirEn($utilisateur)->putJson("/api/imports-fournitures/{$import}/lignes/{$b}", ['retenue' => false])->assertForbidden();
            $this->agirEn($utilisateur)->putJson("/api/imports-fournitures/{$import}/retenues", ['retenue' => false])->assertForbidden();
            $this->agirEn($utilisateur)->getJson($export)->assertForbidden();
        }

        // Une autre entreprise ne voit ni l'import ni le fournisseur.
        $etranger = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');
        $this->appliquer($import, $etranger)->assertNotFound();
        $this->agirEn($etranger)->putJson("/api/imports-fournitures/{$import}/lignes/{$b}", ['retenue' => false])->assertNotFound();
        $this->agirEn($etranger)->putJson("/api/imports-fournitures/{$import}/retenues", ['retenue' => false])->assertNotFound();
        $this->agirEn($etranger)->getJson($export)->assertUnprocessable()->assertJsonValidationErrors('fournisseur_id');

        // Le Conducteur de travaux choisit, applique et exporte.
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->agirEn($conducteur)->putJson("/api/imports-fournitures/{$import}/lignes/{$b}", ['retenue' => true])->assertOk();
        $this->agirEn($conducteur)->get($export)->assertOk();
        $this->appliquer($import, $conducteur)->assertOk();
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonPath('applique_par.id', $conducteur->id);
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        // Le setUp a agi en tant que Direction : on repart sans utilisateur connecté.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/imports-fournitures/1/appliquer')->assertUnauthorized();
        $this->putJson('/api/imports-fournitures/1/lignes/1', ['retenue' => true])->assertUnauthorized();
        $this->putJson('/api/imports-fournitures/1/retenues', ['retenue' => true])->assertUnauthorized();
        $this->getJson('/api/fournitures/export?fournisseur_id=1')->assertUnauthorized();
    }
}
