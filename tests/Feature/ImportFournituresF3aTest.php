<?php

namespace Tests\Feature;

use App\Models\Fourniture;
use App\Models\ImportFournitures;
use App\Models\ImportFournituresLigne;
use App\Models\Parametre;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * Tâche 3.6 bis, lot F3a : envoi d'un catalogue de fournisseur, colonnes,
 * analyse et comparatif. Rien n'est écrit dans la Base de fournitures.
 */
class ImportFournituresF3aTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private const CATALOGUE = "Réf;Désignation;Unité;Prix public HT\n"
        . "A-1;Carrelage A;m2;100,00\n"
        . "B-1;Carrelage B;m2;105,00\n"
        . "C-1;Carrelage C;m2;150,00\n"
        . "N-1;Nouveau produit;u;12,50\n"
        . ";Sans référence;u;10\n"
        . "E-1;Prix faux;u;abc\n"
        . "B-1;Doublon;m2;106\n"
        . "N-2;;u;8\n";

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
        // Trois fournitures existantes : tarif 100, achat net 90, vente saisie 150.
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

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function envoyer(string|UploadedFile $fichier, array $donnees = [], ?User $qui = null, string $nom = 'catalogue.csv')
    {
        $fichier = is_string($fichier) ? UploadedFile::fake()->createWithContent($nom, $fichier) : $fichier;

        return $this->agirEn($qui ?? $this->direction)->post('/api/imports-fournitures', $donnees + [
            'fichier' => $fichier, 'fournisseur_id' => $this->fournisseur, 'mode' => 'complet',
        ], ['Accept' => 'application/json']);
    }

    /**
     * @param array<string, int|null> $colonnes
     * @return \Illuminate\Testing\TestResponse
     */
    private function analyser(int $import, array $colonnes = self::COLONNES, ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->direction)
            ->putJson("/api/imports-fournitures/{$import}/correspondance", ['correspondance' => $colonnes]);
    }

    /**
     * Fichier Excel minimal : une feuille, textes en ligne et nombres.
     *
     * @param array<int, array<int, string|int|float>> $lignes
     */
    private function xlsx(array $lignes): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($chemin, ZipArchive::OVERWRITE);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($lignes as $i => $ligne) {
            $xml .= '<row r="' . ($i + 1) . '">';
            foreach ($ligne as $j => $valeur) {
                $cellule = chr(65 + $j) . ($i + 1);
                $xml .= is_string($valeur)
                    ? '<c r="' . $cellule . '" t="inlineStr"><is><t>' . htmlspecialchars($valeur, ENT_XML1) . '</t></is></c>'
                    : '<c r="' . $cellule . '"><v>' . $valeur . '</v></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml . '</sheetData></worksheet>');
        $zip->close();

        return new UploadedFile($chemin, 'catalogue.xlsx', null, null, true);
    }

    /**
     * @return array<string, array{0: string, 1: string}> Référence -> [tarif, désignation] des fournitures en base.
     */
    private function base(): array
    {
        return $this->chez(fn () => Fourniture::query()->orderBy('reference')->get()
            ->mapWithKeys(fn (Fourniture $f) => [$f->reference => [(string) $f->prix_tarif_ht, $f->designation]])->all());
    }

    public function test_l_envoi_lit_l_en_tete_et_propose_les_colonnes(): void
    {
        // Fichier de tableur français : Windows-1252, point-virgule, virgule décimale.
        $contenu = mb_convert_encoding(self::CATALOGUE, 'Windows-1252', 'UTF-8');

        $reponse = $this->envoyer($contenu, ['date_tarif' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('statut', 'envoye')
            ->assertJsonPath('format', 'csv')
            ->assertJsonPath('mode', 'complet')
            ->assertJsonPath('nom_fichier', 'catalogue.csv')
            ->assertJsonPath('fournisseur.nom', 'Point P')
            ->assertJsonPath('date_tarif', '2026-11-01')
            ->assertJsonPath('nb_lignes', 8)
            ->assertJsonPath('entetes', ['Réf', 'Désignation', 'Unité', 'Prix public HT'])
            ->assertJsonPath('apercu.0', ['A-1', 'Carrelage A', 'm2', '100,00'])
            ->assertJsonCount(5, 'apercu')
            ->assertJsonPath('correspondance_proposee.reference', 0)
            ->assertJsonPath('correspondance_proposee.designation', 1)
            ->assertJsonPath('correspondance_proposee.unite', 2)
            ->assertJsonPath('correspondance_proposee.prix_tarif_ht', 3)
            ->assertJsonPath('correspondance_proposee.prix_achat_ht', null)
            ->assertJsonPath('correspondance', null)
            ->assertJsonPath('resume', null);

        $import = $this->chez(fn () => ImportFournitures::query()->findOrFail($reponse->json('id')));
        Storage::disk('local')->assertExists($import->chemin);
        $this->assertSame($this->direction->id, (int) $import->user_id);
        $this->assertSame(1, $this->chez(fn () => Activity::query()->where('event', 'import_fournitures_envoye')->count()));

        $this->agirEn($this->direction)->getJson('/api/imports-fournitures')->assertOk()->assertJsonCount(1, 'data');
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import->id}")->assertOk()->assertJsonPath('nb_lignes', 8);
    }

    public function test_l_analyse_complete_prepare_le_comparatif_sans_rien_ecrire(): void
    {
        $avant = $this->base();
        $import = $this->envoyer(self::CATALOGUE)->assertCreated()->json('id');

        $this->analyser($import)
            ->assertOk()
            ->assertJsonPath('statut', 'analyse')
            ->assertJsonPath('correspondance.reference', 0)
            ->assertJsonPath('correspondance.prix_achat_ht', null)
            ->assertJsonPath('resume.a_creer', 1)
            ->assertJsonPath('resume.a_modifier', 2)
            ->assertJsonPath('resume.inchangees', 1)
            ->assertJsonPath('resume.ignorees', 0)
            ->assertJsonPath('resume.erreurs', 4)
            ->assertJsonPath('resume.a_valider', 1)
            ->assertJsonPath('resume.retenues', 2);

        // Rien n'a bougé dans la Base de fournitures.
        $this->assertSame($avant, $this->base());
        $this->assertSame(3, $this->chez(fn () => Fourniture::query()->count()));

        $lignes = fn (string $filtre) => $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}/lignes?{$filtre}")->assertOk();
        $lignes('')->assertJsonCount(8, 'data')->assertJsonPath('data.0.numero_ligne', 2)->assertJsonPath('data.0.action', 'inchange');

        // +5 % : retenue d'office.
        $modifiees = $lignes('action=modifier')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', 'B-1')
            ->assertJsonPath('data.0.modifications', ['prix_tarif_ht'])
            ->assertJsonPath('data.0.a_valider', false)
            ->assertJsonPath('data.0.retenue', true);
        $this->assertEqualsWithDelta(5, (float) $modifiees->json('data.0.ecart_pct'), 0.001);
        $this->assertEqualsWithDelta(105, (float) $modifiees->json('data.0.prix_tarif_ht'), 0.0001);
        $this->assertEqualsWithDelta(100, (float) $modifiees->json('data.0.anciennes.prix_tarif_ht'), 0.0001);

        // +50 % : au-delà du seuil de 20 %, à valider, pas retenue.
        $aValider = $lignes('a_valider=1')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'C-1')
            ->assertJsonPath('data.0.retenue', false);
        $this->assertEqualsWithDelta(50, (float) $aValider->json('data.0.ecart_pct'), 0.001);

        $lignes('action=creer')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'N-1')
            ->assertJsonPath('data.0.designation', 'Nouveau produit')
            ->assertJsonPath('data.0.unite', 'u')
            ->assertJsonPath('data.0.fourniture_id', null)
            ->assertJsonPath('data.0.retenue', true);

        $lignes('action=erreur')->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.erreurs.0', 'Référence manquante.')
            ->assertJsonPath('data.1.erreurs.0', 'Prix illisible : « abc ».')
            ->assertJsonPath('data.2.erreurs.0', 'Référence en double dans le fichier (déjà à la ligne 3).')
            ->assertJsonPath('data.3.numero_ligne', 9)
            ->assertJsonPath('data.3.retenue', false);
        $lignes('retenue=1')->assertJsonCount(2, 'data');

        // Relancer l'analyse remplace le comparatif, sans le doubler.
        $this->analyser($import)->assertOk();
        $this->assertSame(8, $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->count()));
        $this->assertSame(2, $this->chez(fn () => Activity::query()->where('event', 'import_fournitures_analyse')->count()));
    }

    public function test_le_mode_prix_ne_cree_rien_et_ne_regarde_que_les_prix(): void
    {
        // La désignation de A-1 diffère : en mode « prix », elle ne compte pas.
        $contenu = str_replace('A-1;Carrelage A;', 'A-1;Autre nom;', self::CATALOGUE);
        $import = $this->envoyer($contenu, ['mode' => 'prix'])->assertCreated()->assertJsonPath('mode', 'prix')->json('id');

        // La désignation n'est pas obligatoire en mode « prix ».
        $this->analyser($import, ['reference' => 0, 'prix_tarif_ht' => 3])
            ->assertOk()
            ->assertJsonPath('resume.a_creer', 0)
            ->assertJsonPath('resume.a_modifier', 2)
            ->assertJsonPath('resume.inchangees', 1)
            ->assertJsonPath('resume.ignorees', 2)
            ->assertJsonPath('resume.erreurs', 3)
            ->assertJsonPath('resume.retenues', 1);

        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}/lignes?action=ignorer")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.reference', 'N-1')->assertJsonPath('data.1.reference', 'N-2');

        // En import complet, la même ligne A-1 devient une modification de la désignation.
        $complet = $this->envoyer($contenu)->assertCreated()->json('id');
        $this->analyser($complet)->assertOk()->assertJsonPath('resume.a_modifier', 3)->assertJsonPath('resume.inchangees', 0);
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$complet}/lignes?action=modifier")
            ->assertOk()->assertJsonPath('data.0.reference', 'A-1')->assertJsonPath('data.0.modifications', ['designation'])
            ->assertJsonPath('data.0.ecart_pct', null)->assertJsonPath('data.0.retenue', true);
    }

    public function test_un_fichier_excel_se_lit_comme_un_csv(): void
    {
        $fichier = $this->xlsx([
            ['Code', 'Libellé', 'UV', 'Prix net HT', 'Remarque'],
            ['A-1', 'Carrelage A', 'm2', 90, ''],
            ['B-1', 'Carrelage B', 'm2', 99.5, 'promo'],
            ['N-1', 'Colle « flex » & joint', 'sac', 23.456789, ''],
        ]);

        $import = $this->envoyer($fichier)
            ->assertCreated()
            ->assertJsonPath('format', 'xlsx')
            ->assertJsonPath('nb_lignes', 3)
            ->assertJsonPath('entetes', ['Code', 'Libellé', 'UV', 'Prix net HT', 'Remarque'])
            ->assertJsonPath('apercu.2.1', 'Colle « flex » & joint')
            ->assertJsonPath('correspondance_proposee.reference', 0)
            ->assertJsonPath('correspondance_proposee.designation', 1)
            ->assertJsonPath('correspondance_proposee.unite', 2)
            ->assertJsonPath('correspondance_proposee.prix_achat_ht', 3)
            ->assertJsonPath('correspondance_proposee.prix_tarif_ht', null)
            ->json('id');

        // Un prix d'achat net importé sur des fournitures à prix tarif : c'est un changement, même à prix égal (A-1 : 90).
        $this->analyser($import, ['reference' => 0, 'designation' => 1, 'unite' => 2, 'prix_achat_ht' => 3])
            ->assertOk()
            ->assertJsonPath('resume.a_modifier', 2)
            ->assertJsonPath('resume.a_creer', 1)
            ->assertJsonPath('resume.erreurs', 0);

        $lignes = $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}/lignes")->assertOk()
            ->assertJsonPath('data.0.modifications', ['prix_achat_ht'])
            ->assertJsonPath('data.2.action', 'creer');
        $this->assertEqualsWithDelta(0, (float) $lignes->json('data.0.ecart_pct'), 0.001);
        // 99,50 contre 90 : +10,56 %.
        $this->assertEqualsWithDelta(10.56, (float) $lignes->json('data.1.ecart_pct'), 0.001);
        $this->assertEqualsWithDelta(23.4568, (float) $lignes->json('data.2.prix_achat_ht'), 0.0001);
    }

    public function test_la_remise_et_le_prix_de_vente_s_importent_s_ils_sont_donnes(): void
    {
        $contenu = "Ref;Tarif;Remise;PV\nA-1;100;15;\nB-1;100;;160\nC-1;100;abc;\n";
        $import = $this->envoyer($contenu, ['mode' => 'prix'])->assertCreated()
            ->assertJsonPath('correspondance_proposee.remise_pct', 2)
            ->assertJsonPath('correspondance_proposee.prix_vente_ht', 3)
            ->json('id');

        $this->analyser($import, ['reference' => 0, 'prix_tarif_ht' => 1, 'remise_pct' => 2, 'prix_vente_ht' => 3])
            ->assertOk()->assertJsonPath('resume.a_modifier', 2)->assertJsonPath('resume.erreurs', 1);

        // Une cellule vide ne remplace pas la valeur existante.
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}/lignes")->assertOk()
            ->assertJsonPath('data.0.modifications', ['remise_pct'])
            ->assertJsonPath('data.0.prix_vente_ht', null)
            ->assertJsonPath('data.1.modifications', ['prix_vente_ht'])
            ->assertJsonPath('data.1.remise_pct', null)
            ->assertJsonPath('data.2.erreurs.0', 'Remise illisible : « abc ».');
    }

    public function test_le_seuil_d_ecart_est_un_reglage(): void
    {
        $this->chez(fn () => Parametre::ecrire('import_ecart_prix_pct', 60));
        $import = $this->envoyer(self::CATALOGUE)->assertCreated()->json('id');

        // +50 % reste sous le seuil de 60 % : la ligne est retenue.
        $reponse = $this->analyser($import)->assertOk()->assertJsonPath('resume.a_valider', 0)->assertJsonPath('resume.retenues', 3);
        $this->assertEqualsWithDelta(60, (float) $reponse->json('seuil_ecart_pct'), 0.001);
    }

    public function test_les_colonnes_choisies_sont_controlees(): void
    {
        $import = $this->envoyer(self::CATALOGUE)->assertCreated()->json('id');
        $refus = fn (array $colonnes, string $champ) => $this->analyser($import, $colonnes)->assertUnprocessable()->assertJsonValidationErrors($champ);

        $refus(['designation' => 1, 'prix_tarif_ht' => 3], 'correspondance.reference');
        $refus(['reference' => 0, 'designation' => 1], 'correspondance.prix_tarif_ht');
        $refus(['reference' => 0, 'designation' => 1, 'prix_tarif_ht' => 3, 'prix_achat_ht' => 2], 'correspondance.prix_tarif_ht');
        $refus(['reference' => 0, 'prix_tarif_ht' => 3], 'correspondance.designation');
        $refus(['reference' => 0, 'designation' => 1, 'prix_achat_ht' => 3, 'remise_pct' => 2], 'correspondance.remise_pct');
        $refus(['reference' => 0, 'designation' => 0, 'prix_tarif_ht' => 3], 'correspondance');
        $refus(['reference' => 0, 'designation' => 1, 'prix_tarif_ht' => 9], 'correspondance.prix_tarif_ht');
        $refus(['reference' => 'A', 'designation' => 1, 'prix_tarif_ht' => 3], 'correspondance.reference');
        $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/correspondance", [])
            ->assertUnprocessable()->assertJsonValidationErrors('correspondance');

        // Aucune analyse n'a été enregistrée.
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonPath('statut', 'envoye');
        $this->assertSame(0, $this->chez(fn () => ImportFournituresLigne::query()->count()));
    }

    public function test_les_fichiers_refuses(): void
    {
        $refus = fn ($reponse) => $reponse->assertUnprocessable()->assertJsonValidationErrors('fichier');

        $refus($this->envoyer('%PDF-1.4', [], null, 'catalogue.pdf'));
        $refus($this->envoyer("Réf;Désignation;Unité;Prix\n"));
        $refus($this->envoyer('ceci n est pas un fichier excel', [], null, 'catalogue.xlsx'));
        $refus($this->agirEn($this->direction)->post('/api/imports-fournitures', ['fournisseur_id' => $this->fournisseur, 'mode' => 'complet'], ['Accept' => 'application/json']));

        // Plus de 5 000 lignes de données.
        $long = "Ref;Nom;U;Prix\n";
        for ($i = 1; $i <= 5001; $i++) {
            $long .= "R{$i};Produit {$i};u;1\n";
        }
        $refus($this->envoyer($long));

        $this->envoyer(self::CATALOGUE, ['mode' => 'tout'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->envoyer(self::CATALOGUE, ['fournisseur_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('fournisseur_id');
        $this->envoyer(self::CATALOGUE, ['date_tarif' => 'hier soir peut-etre'])->assertUnprocessable()->assertJsonValidationErrors('date_tarif');

        // Aucun import enregistré, aucun fichier gardé.
        $this->assertSame(0, $this->chez(fn () => ImportFournitures::query()->count()));
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Exactement 5 000 lignes : accepté.
        $limite = "Ref;Nom;U;Prix\n";
        for ($i = 1; $i <= 5000; $i++) {
            $limite .= "R{$i};Produit {$i};u;1\n";
        }
        $this->envoyer($limite)->assertCreated()->assertJsonPath('nb_lignes', 5000)->assertJsonPath('limite_lignes', 5000);
    }

    public function test_les_droits_d_import(): void
    {
        $import = $this->envoyer(self::CATALOGUE)->assertCreated()->json('id');

        // Le Conducteur de travaux importe aussi.
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->envoyer(self::CATALOGUE, [], $conducteur)->assertCreated();
        $this->analyser($import, self::COLONNES, $conducteur)->assertOk();

        // Commercial et Administratif consultent la bibliothèque mais n'importent pas.
        foreach (['Commercial', 'Administratif'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role);
            $this->envoyer(self::CATALOGUE, [], $utilisateur)->assertForbidden();
            $this->agirEn($utilisateur)->getJson('/api/imports-fournitures')->assertForbidden();
            $this->agirEn($utilisateur)->getJson("/api/imports-fournitures/{$import}")->assertForbidden();
            $this->agirEn($utilisateur)->getJson("/api/imports-fournitures/{$import}/lignes")->assertForbidden();
            $this->analyser($import, self::COLONNES, $utilisateur)->assertForbidden();
            $this->agirEn($utilisateur)->deleteJson("/api/imports-fournitures/{$import}")->assertForbidden();
        }

        // Une autre entreprise ne voit ni l'import ni le fournisseur.
        $autre = $this->creerEntreprise();
        $etranger = $this->creerUtilisateur($autre, 'Direction');
        $this->agirEn($etranger)->getJson('/api/imports-fournitures')->assertOk()->assertJsonCount(0, 'data');
        $this->agirEn($etranger)->getJson("/api/imports-fournitures/{$import}")->assertNotFound();
        $this->agirEn($etranger)->getJson("/api/imports-fournitures/{$import}/lignes")->assertNotFound();
        $this->analyser($import, self::COLONNES, $etranger)->assertNotFound();
        $this->agirEn($etranger)->deleteJson("/api/imports-fournitures/{$import}")->assertNotFound();
        $this->envoyer(self::CATALOGUE, [], $etranger)->assertUnprocessable()->assertJsonValidationErrors('fournisseur_id');
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        // Le setUp a agi en tant que Direction : on repart sans utilisateur connecté.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/imports-fournitures')->assertUnauthorized();
        $this->post('/api/imports-fournitures', [], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->putJson('/api/imports-fournitures/1/correspondance', [])->assertUnauthorized();
        $this->getJson('/api/imports-fournitures/1/lignes')->assertUnauthorized();
        $this->deleteJson('/api/imports-fournitures/1')->assertUnauthorized();
    }

    public function test_l_annulation_supprime_le_fichier_et_le_comparatif(): void
    {
        $import = $this->envoyer(self::CATALOGUE)->assertCreated()->json('id');
        $this->analyser($import)->assertOk();
        $chemin = $this->chez(fn () => ImportFournitures::query()->findOrFail($import)->chemin);
        Storage::disk('local')->assertExists($chemin);

        $this->agirEn($this->direction)->deleteJson("/api/imports-fournitures/{$import}")->assertNoContent();

        Storage::disk('local')->assertMissing($chemin);
        $this->assertSame(0, $this->chez(fn () => ImportFournituresLigne::query()->count()));
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonPath('statut', 'annule');
        $this->analyser($import)->assertUnprocessable()->assertJsonValidationErrors('import');
        // Annuler deux fois ne casse rien et ne journalise qu'une fois.
        $this->agirEn($this->direction)->deleteJson("/api/imports-fournitures/{$import}")->assertNoContent();
        $this->assertSame(1, $this->chez(fn () => Activity::query()->where('event', 'import_fournitures_annule')->count()));
        $this->agirEn($this->direction)->getJson('/api/imports-fournitures?statut=annule')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_les_colonnes_du_dernier_import_du_fournisseur_sont_reproposees(): void
    {
        // En-têtes que l'application ne sait pas deviner.
        $contenu = "Col1;Col2;Col3;Col4\nA-1;Carrelage A;m2;100\n";
        $premier = $this->envoyer($contenu)->assertCreated()
            ->assertJsonPath('correspondance_proposee.reference', null)
            ->assertJsonPath('correspondance_proposee.prix_tarif_ht', null)
            ->json('id');
        $this->analyser($premier)->assertOk()->assertJsonPath('resume.inchangees', 1);

        // Même fournisseur, mêmes en-têtes : les colonnes choisies sont reprises.
        $this->envoyer($contenu)->assertCreated()
            ->assertJsonPath('correspondance_proposee.reference', 0)
            ->assertJsonPath('correspondance_proposee.designation', 1)
            ->assertJsonPath('correspondance_proposee.unite', 2)
            ->assertJsonPath('correspondance_proposee.prix_tarif_ht', 3)
            ->assertJsonPath('correspondance_proposee.prix_achat_ht', null);

        // Un autre fournisseur ne les reprend pas.
        $autre = $this->agirEn($this->direction)->postJson('/api/fournisseurs', ['nom' => 'Cedeo'])->assertCreated()->json('id');
        $this->envoyer($contenu, ['fournisseur_id' => $autre])->assertCreated()->assertJsonPath('correspondance_proposee.reference', null);
    }
}
