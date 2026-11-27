<?php

namespace Tests\Feature;

use App\Models\Fourniture;
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
 * Tâche 3.6 bis, lot F3c : choix de la feuille Excel et de la ligne
 * d'en-tête, numéros de lignes réels, nombres lus par colonne et
 * avertissements quand le fichier laisse un doute.
 */
class ImportFournituresF3cTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

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
        // Une fourniture existante : tarif 100, vente saisie 150.
        $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $this->fournisseur, 'reference' => 'A-1', 'designation' => 'Carrelage A',
            'unite' => 'm2', 'prix_tarif_ht' => 100, 'prix_vente_ht' => 150,
        ])->assertCreated();
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function envoyer(string|UploadedFile $fichier, array $donnees = [], ?User $qui = null)
    {
        $fichier = is_string($fichier) ? UploadedFile::fake()->createWithContent('catalogue.csv', $fichier) : $fichier;

        return $this->agirEn($qui ?? $this->direction)->post('/api/imports-fournitures', $donnees + [
            'fichier' => $fichier, 'fournisseur_id' => $this->fournisseur, 'mode' => 'complet',
        ], ['Accept' => 'application/json']);
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
     * @param array<string, int> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function relire(int $import, array $donnees, ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->direction)->putJson("/api/imports-fournitures/{$import}/lecture", $donnees);
    }

    /**
     * Lignes du comparatif, par référence : [prix tarif, action, à valider, retenue, numéro de ligne].
     *
     * @return array<string, array{0: float|null, 1: string, 2: bool, 3: bool, 4: int}>
     */
    private function comparatif(int $import): array
    {
        return $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->orderBy('numero_ligne')->get()
            ->mapWithKeys(fn (ImportFournituresLigne $l) => [(string) $l->reference => [$l->prix_tarif_ht, $l->action, $l->a_valider, $l->retenue, $l->numero_ligne]])->all());
    }

    /**
     * Classeur Excel de plusieurs feuilles nommées.
     *
     * @param array<string, array<int, array<int, string|int|float>>> $feuilles Nom de la feuille -> lignes (une ligne vide est sautée).
     */
    private function classeur(array $feuilles): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($chemin, ZipArchive::OVERWRITE);
        $liste = '';
        $liens = '';
        $rang = 0;
        foreach ($feuilles as $nom => $lignes) {
            $rang++;
            $liste .= '<sheet name="' . htmlspecialchars($nom, ENT_XML1 | ENT_QUOTES) . '" sheetId="' . $rang . '" r:id="rId' . $rang . '"/>';
            $liens .= '<Relationship Id="rId' . $rang . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $rang . '.xml"/>';
            $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($lignes as $i => $ligne) {
                if ($ligne === []) {
                    continue;
                }
                $xml .= '<row r="' . ($i + 1) . '">';
                foreach ($ligne as $j => $valeur) {
                    $cellule = chr(65 + $j) . ($i + 1);
                    $xml .= is_string($valeur)
                        ? '<c r="' . $cellule . '" t="inlineStr"><is><t>' . htmlspecialchars($valeur, ENT_XML1) . '</t></is></c>'
                        : '<c r="' . $cellule . '"><v>' . $valeur . '</v></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet' . $rang . '.xml', $xml . '</sheetData></worksheet>');
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $liste . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $liens . '</Relationships>');
        $zip->close();

        return new UploadedFile($chemin, 'catalogue.xlsx', null, null, true);
    }

    /**
     * @return array<string, array<int, array<int, string|int|float>>>
     */
    private function deuxFeuilles(): array
    {
        return [
            'Conditions' => [['Conditions générales de vente'], ['Tarif valable au 1er novembre 2026']],
            'Tarif' => [['Réf', 'Désignation', 'Unité', 'Prix public HT'], ['A-1', 'Carrelage A', 'm2', 105], ['N-1', 'Nouveau', 'u', 12.5]],
        ];
    }

    public function test_les_feuilles_du_fichier_sont_listees_et_l_import_previent(): void
    {
        $reponse = $this->envoyer($this->classeur($this->deuxFeuilles()))
            ->assertCreated()
            ->assertJsonPath('feuilles', ['Conditions', 'Tarif'])
            ->assertJsonPath('feuille', 0)
            ->assertJsonPath('ligne_entete', 1)
            // La première feuille n'est pas le tarif : cela se voit tout de suite.
            ->assertJsonPath('entetes', ['Conditions générales de vente'])
            ->assertJsonPath('debut_fichier.0.numero', 1)
            ->assertJsonPath('debut_fichier.1.cellules.0', 'Tarif valable au 1er novembre 2026')
            ->assertJsonCount(1, 'avertissements');
        $this->assertStringContainsString('2 feuilles', $reponse->json('avertissements.0'));
        $this->assertStringContainsString('« Conditions »', $reponse->json('avertissements.0'));

        // La feuille se choisit dès l'envoi.
        $this->envoyer($this->classeur($this->deuxFeuilles()), ['feuille' => 1])
            ->assertCreated()
            ->assertJsonPath('feuille', 1)
            ->assertJsonPath('entetes', ['Réf', 'Désignation', 'Unité', 'Prix public HT'])
            ->assertJsonPath('nb_lignes', 2)
            ->assertJsonPath('correspondance_proposee.reference', 0);

        // Une feuille qui n'existe pas, dans un Excel comme dans un CSV.
        $this->envoyer($this->classeur($this->deuxFeuilles()), ['feuille' => 5])->assertUnprocessable()->assertJsonValidationErrors('feuille');
        $this->envoyer("Ref;Nom;U;Prix\nA-1;Carrelage A;m2;100\n", ['feuille' => 1])->assertUnprocessable()->assertJsonValidationErrors('feuille');
        // Un CSV n'a pas de feuille, donc pas d'avertissement.
        $this->envoyer("Ref;Nom;U;Prix\nA-1;Carrelage A;m2;100\n")->assertCreated()->assertJsonPath('feuilles', [])->assertJsonPath('avertissements', []);
        $this->assertSame(3, count(Storage::disk('local')->allFiles()));
    }

    public function test_la_feuille_se_change_apres_l_envoi(): void
    {
        $import = $this->envoyer($this->classeur(['Tarif A' => [['Réf', 'Désignation', 'Unité', 'Prix public HT'], ['A-1', 'Carrelage A', 'm2', 105]]] + $this->deuxFeuilles()))
            ->assertCreated()->json('id');
        $this->analyser($import)->assertOk()->assertJsonPath('resume.a_modifier', 1);
        $this->assertSame(1, $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->count()));

        // Changer de feuille ramène à l'étape des colonnes : l'ancien comparatif est supprimé.
        $this->relire($import, ['feuille' => 2])
            ->assertOk()
            ->assertJsonPath('statut', 'envoye')
            ->assertJsonPath('feuille', 2)
            ->assertJsonPath('nb_lignes', 2)
            ->assertJsonPath('correspondance', null)
            ->assertJsonPath('resume', null)
            ->assertJsonPath('correspondance_proposee.prix_tarif_ht', 3);
        $this->assertSame(0, $this->chez(fn () => ImportFournituresLigne::query()->where('import_id', $import)->count()));

        $this->analyser($import)->assertOk()->assertJsonPath('resume.a_modifier', 1)->assertJsonPath('resume.a_creer', 1);
        $this->assertSame(1, $this->chez(fn () => Activity::query()->where('event', 'import_fournitures_relu')->count()));

        $this->relire($import, ['feuille' => 9])->assertUnprocessable()->assertJsonValidationErrors('feuille');
        $this->relire($import, ['feuille' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('feuille');
        // Le refus n'a rien changé.
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonPath('statut', 'analyse')->assertJsonPath('feuille', 2);
    }

    public function test_la_ligne_d_en_tete_se_choisit_et_les_numeros_sont_ceux_du_fichier(): void
    {
        // Deux lignes de titre, une ligne vide, l'en-tête à la ligne 4, une ligne vide au milieu des données.
        $contenu = "Tarif, édition 2026\nPoint P - agence de Lyon\n\nRéf;Désignation;Unité;Prix public HT\nA-1;Carrelage A;m2;105,00\n\nN-1;Nouveau;u;12,50\n;Sans référence;u;3\n";

        $import = $this->envoyer($contenu)
            ->assertCreated()
            // Le titre est pris pour l'en-tête : le début du fichier montre où il se trouve vraiment.
            ->assertJsonPath('entetes', ['Tarif, édition 2026'])
            ->assertJsonPath('debut_fichier.0.numero', 1)
            ->assertJsonPath('debut_fichier.1.numero', 2)
            ->assertJsonPath('debut_fichier.2.numero', 4)
            ->assertJsonPath('debut_fichier.2.cellules', ['Réf', 'Désignation', 'Unité', 'Prix public HT'])
            ->assertJsonPath('debut_fichier.3.numero', 5)
            ->json('id');

        $this->relire($import, ['ligne_entete' => 4])
            ->assertOk()
            ->assertJsonPath('ligne_entete', 4)
            ->assertJsonPath('entetes', ['Réf', 'Désignation', 'Unité', 'Prix public HT'])
            ->assertJsonPath('nb_lignes', 3)
            ->assertJsonPath('apercu.0', ['A-1', 'Carrelage A', 'm2', '105,00'])
            ->assertJsonPath('correspondance_proposee.designation', 1);

        $this->analyser($import)->assertOk()->assertJsonPath('resume.a_modifier', 1)->assertJsonPath('resume.a_creer', 1)->assertJsonPath('resume.erreurs', 1);
        // Les numéros sont ceux du fichier, lignes vides comprises.
        $comparatif = $this->comparatif($import);
        $this->assertSame(5, $comparatif['A-1'][4]);
        $this->assertSame(7, $comparatif['N-1'][4]);
        $this->assertSame(8, $comparatif[''][4]);

        // Une ligne vide ou hors du fichier ne peut pas être l'en-tête.
        $this->relire($import, ['ligne_entete' => 3])->assertUnprocessable()->assertJsonValidationErrors('ligne_entete');
        $this->relire($import, ['ligne_entete' => 40])->assertUnprocessable()->assertJsonValidationErrors('ligne_entete');
        $this->relire($import, ['ligne_entete' => 0])->assertUnprocessable()->assertJsonValidationErrors('ligne_entete');
        // La dernière ligne comme en-tête ne laisse aucune donnée.
        $this->relire($import, ['ligne_entete' => 8])->assertUnprocessable()->assertJsonValidationErrors('fichier');

        // La ligne d'en-tête se donne aussi à l'envoi, avec la feuille.
        $classeur = $this->classeur(['Garde' => [['Page de garde']], 'Tarif' => [['Tarif 2026'], [], ['Réf', 'Désignation', 'Unité', 'Prix public HT'], ['A-1', 'Carrelage A', 'm2', 105]]]);
        $excel = $this->envoyer($classeur, ['feuille' => 1, 'ligne_entete' => 3])
            ->assertCreated()->assertJsonPath('entetes', ['Réf', 'Désignation', 'Unité', 'Prix public HT'])->assertJsonPath('nb_lignes', 1)->json('id');
        $this->analyser($excel)->assertOk();
        $this->assertSame(4, $this->comparatif($excel)['A-1'][4]);
    }

    public function test_un_fichier_au_format_anglais_est_lu_sans_equivoque(): void
    {
        // Une seule valeur comme « 100.00 » suffit : la virgule sépare alors les milliers.
        $contenu = "Ref,Name,Unit,Price\nA-1,Carrelage A,m2,100.00\nN-1,Machine,u,\"1,234.50\"\nN-2,Autre machine,u,\"2,500\"\n";
        $import = $this->envoyer($contenu)->assertCreated()->json('id');
        $this->analyser($import)->assertOk()->assertJsonPath('avertissements', [])->assertJsonPath('resume.a_creer', 2)->assertJsonPath('resume.a_valider', 0);

        $comparatif = $this->comparatif($import);
        $this->assertEqualsWithDelta(1234.5, $comparatif['N-1'][0], 0.0001);
        $this->assertEqualsWithDelta(2500, $comparatif['N-2'][0], 0.0001);
        $this->assertTrue($comparatif['N-2'][3]);
        $this->assertSame('inchange', $comparatif['A-1'][1]);
    }

    public function test_un_fichier_francais_avec_des_points_pour_les_milliers(): void
    {
        $contenu = "Réf;Désignation;Unité;Prix\nA-1;Carrelage A;m2;100,00\nN-1;Machine;u;1.234,50\nN-2;Autre machine;u;2.500\nN-3;Vis;u;0,125\n";
        $import = $this->envoyer($contenu)->assertCreated()->json('id');
        $this->analyser($import)->assertOk()->assertJsonPath('avertissements', [])->assertJsonPath('resume.a_creer', 3)->assertJsonPath('resume.a_valider', 0);

        $comparatif = $this->comparatif($import);
        $this->assertEqualsWithDelta(1234.5, $comparatif['N-1'][0], 0.0001);
        $this->assertEqualsWithDelta(2500, $comparatif['N-2'][0], 0.0001);
        $this->assertEqualsWithDelta(0.125, $comparatif['N-3'][0], 0.0001);
    }

    public function test_une_colonne_equivoque_previent_et_attend_une_validation(): void
    {
        $this->chez(fn () => Parametre::ecrire('marge_fournitures_defaut', 50));
        // Rien ne dit si « 1,234 » vaut 1,234 ou 1 234.
        $contenu = "Réf;Désignation;Unité;Prix\nN-1;Machine;u;1,234\nN-2;Autre machine;u;2,500\nN-3;Joint;u;12\n";
        $import = $this->envoyer($contenu)->assertCreated()->json('id');

        $reponse = $this->analyser($import)->assertOk()
            ->assertJsonCount(1, 'avertissements')
            ->assertJsonPath('resume.a_creer', 3)
            ->assertJsonPath('resume.a_valider', 2)
            ->assertJsonPath('resume.retenues', 1);
        $this->assertStringContainsString('Colonne « Prix »', $reponse->json('avertissements.0'));
        $this->assertStringContainsString('« 1,234 » est lu 1,234', $reponse->json('avertissements.0'));
        $this->agirEn($this->direction)->getJson("/api/imports-fournitures/{$import}")->assertOk()->assertJsonCount(1, 'avertissements');

        // Lues à la française par défaut, mais pas retenues d'office : même une création est protégée.
        $comparatif = $this->comparatif($import);
        $this->assertEqualsWithDelta(1.234, $comparatif['N-1'][0], 0.0001);
        $this->assertSame(['creer', true, false], array_slice($comparatif['N-1'], 1, 3));
        $this->assertSame(['creer', true, false], array_slice($comparatif['N-2'], 1, 3));
        $this->assertSame(['creer', false, true], array_slice($comparatif['N-3'], 1, 3));

        // Appliquer ne crée que la ligne sûre.
        $this->agirEn($this->direction)->postJson("/api/imports-fournitures/{$import}/appliquer")->assertOk()->assertJsonPath('bilan.creees', 1);
        $this->assertSame(['A-1', 'N-3'], $this->chez(fn () => Fourniture::query()->orderBy('reference')->pluck('reference')->all()));

        // Une seule valeur sans équivoque dans la colonne lève le doute.
        $sur = $this->envoyer($contenu . "N-4;Colle;u;12,50\n")->assertCreated()->json('id');
        $this->analyser($sur)->assertOk()->assertJsonPath('avertissements', [])->assertJsonPath('resume.a_valider', 0);
        $this->assertEqualsWithDelta(1.234, $this->comparatif($sur)['N-1'][0], 0.0001);
    }

    public function test_deux_formats_dans_la_meme_colonne_previennent(): void
    {
        $contenu = "Réf;Désignation;Unité;Prix\nN-1;Colle;u;12,50\nN-2;Joint;u;12.75\nN-3;Machine;u;1,234\n";
        $import = $this->envoyer($contenu)->assertCreated()->json('id');

        $reponse = $this->analyser($import)->assertOk()->assertJsonCount(1, 'avertissements')->assertJsonPath('resume.a_valider', 1);
        $this->assertStringContainsString('mélangent deux formats', $reponse->json('avertissements.0'));

        $comparatif = $this->comparatif($import);
        $this->assertEqualsWithDelta(12.5, $comparatif['N-1'][0], 0.0001);
        $this->assertEqualsWithDelta(12.75, $comparatif['N-2'][0], 0.0001);
        $this->assertTrue($comparatif['N-3'][2]);
        $this->assertFalse($comparatif['N-3'][3]);
    }

    public function test_les_nombres_d_un_fichier_excel_ne_sont_jamais_equivoques(): void
    {
        // Dans un fichier Excel, 1.234 est le nombre 1,234 : il n'y a pas de séparateur de milliers.
        $classeur = $this->classeur(['Tarif' => [['Réf', 'Désignation', 'Unité', 'Prix public HT'], ['N-1', 'Vis', 'u', 1.234], ['N-2', 'Machine', 'u', 2500]]]);
        $import = $this->envoyer($classeur)->assertCreated()->assertJsonPath('avertissements', [])->json('id');
        $this->analyser($import)->assertOk()->assertJsonPath('avertissements', [])->assertJsonPath('resume.a_valider', 0)->assertJsonPath('resume.retenues', 2);

        $comparatif = $this->comparatif($import);
        $this->assertEqualsWithDelta(1.234, $comparatif['N-1'][0], 0.0001);
        $this->assertEqualsWithDelta(2500, $comparatif['N-2'][0], 0.0001);
    }

    public function test_les_droits_de_la_relecture(): void
    {
        $import = $this->envoyer($this->classeur($this->deuxFeuilles()))->assertCreated()->json('id');

        foreach (['Commercial', 'Administratif'] as $role) {
            $this->relire($import, ['feuille' => 1], $this->creerUtilisateur($this->entreprise, $role))->assertForbidden();
        }
        $this->relire($import, ['feuille' => 1], $this->creerUtilisateur($this->creerEntreprise(), 'Direction'))->assertNotFound();
        $this->relire($import, ['feuille' => 1], $this->creerUtilisateur($this->entreprise, 'Conducteur travaux'))->assertOk()->assertJsonPath('feuille', 1);

        // Un import annulé ne se relit plus.
        $this->agirEn($this->direction)->deleteJson("/api/imports-fournitures/{$import}")->assertNoContent();
        $this->relire($import, ['feuille' => 0])->assertUnprocessable()->assertJsonValidationErrors('import');

        // Sans connexion.
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/imports-fournitures/1/lecture', ['feuille' => 1])->assertUnauthorized();
    }
}
