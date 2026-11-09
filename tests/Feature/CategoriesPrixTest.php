<?php

namespace Tests\Feature;

use App\Models\CategoriePrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\CategoriesPrixSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Arborescence des categories de la Bibliotheque de prix (lot 1) :
 * migration, modele et seeder idempotent.
 */
class CategoriesPrixTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    /**
     * Lit le fichier d'arborescence.
     *
     * @return list<array<string, mixed>>
     */
    private function arbre(): array
    {
        return json_decode(
            (string) file_get_contents(database_path('data/arborescence_mondelice_v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Lit les references en base, sans passer par le filtre d'entreprise.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CategoriePrix>
     */
    private function references()
    {
        return CategoriePrix::sansFiltreTenant()->whereNull('tenant_id');
    }

    #[Test]
    public function le_seeder_cree_8_domaines_48_corps_d_etat_et_249_familles(): void
    {
        $this->seed(CategoriesPrixSeeder::class);

        $this->assertSame(8, $this->references()->where('niveau', 1)->count());
        $this->assertSame(48, $this->references()->where('niveau', 2)->count());
        $this->assertSame(249, $this->references()->where('niveau', 3)->count());
        $this->assertSame(305, $this->references()->count());
    }

    #[Test]
    public function le_seeder_est_idempotent(): void
    {
        $this->seed(CategoriesPrixSeeder::class);
        $identifiants = $this->references()->orderBy('id')->pluck('id')->all();

        $this->seed(CategoriesPrixSeeder::class);
        $this->seed(CategoriesPrixSeeder::class);

        $this->assertSame(305, $this->references()->count());
        $this->assertSame($identifiants, $this->references()->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function le_seeder_remet_le_nom_du_fichier_apres_une_modification(): void
    {
        $this->seed(CategoriesPrixSeeder::class);

        $this->references()->where('code', '1.4.5')->firstOrFail()
            ->autoriserModificationReference()->update(['nom' => 'Nom modifie a la main']);

        $this->seed(CategoriesPrixSeeder::class);

        $nomAttendu = $this->arbre()[0]['enfants'][3]['enfants'][4]['nom'];
        $this->assertSame($nomAttendu, $this->references()->where('code', '1.4.5')->value('nom'));
    }

    #[Test]
    public function chaque_noeud_du_fichier_a_son_code_son_niveau_son_ordre_et_son_nombre_d_enfants(): void
    {
        $this->seed(CategoriesPrixSeeder::class);

        $verifier = function (array $noeuds, ?string $prefixe, ?int $parentId, int $niveau) use (&$verifier): void {
            foreach (array_values($noeuds) as $index => $noeud) {
                $ordre = $index + 1;
                $code = $prefixe === null ? (string) $ordre : $prefixe.'.'.$ordre;

                $categorie = $this->references()->where('code', $code)->first();

                $this->assertNotNull($categorie, "Categorie {$code} absente");
                $this->assertSame(trim($noeud['nom']), $categorie->nom, "Nom de {$code}");
                $this->assertSame($niveau, $categorie->niveau, "Niveau de {$code}");
                $this->assertSame($ordre, $categorie->ordre, "Ordre de {$code}");
                $this->assertSame($parentId, $categorie->parent_id, "Parent de {$code}");
                $this->assertSame(
                    count($noeud['enfants']),
                    $this->references()->where('parent_id', $categorie->id)->count(),
                    "Nombre d'enfants de {$code}"
                );

                $verifier($noeud['enfants'], $code, $categorie->id, $niveau + 1);
            }
        };

        $verifier($this->arbre(), null, null, 1);
    }

    #[Test]
    public function le_deuxieme_domaine_s_ecrit_avec_la_ligature(): void
    {
        $this->seed(CategoriesPrixSeeder::class);

        $this->assertSame('Gros œuvre', $this->references()->where('code', '2')->value('nom'));
    }

    #[Test]
    public function toutes_les_categories_de_reference_n_ont_pas_d_entreprise(): void
    {
        $this->seed(CategoriesPrixSeeder::class);

        $this->assertSame(0, CategoriePrix::sansFiltreTenant()->whereNotNull('tenant_id')->count());
    }

    #[Test]
    public function une_entreprise_voit_les_references_et_les_relations_parent_enfants_fonctionnent(): void
    {
        $entreprise = $this->creerEntreprise();
        $this->seed(CategoriesPrixSeeder::class);

        $this->dansEntreprise($entreprise, function (): void {
            $this->assertSame(305, CategoriePrix::count());
            $this->assertSame(8, CategoriePrix::racines()->count());

            $famille = CategoriePrix::where('code', '1.4.5')->firstOrFail();
            $this->assertSame('1.4', $famille->parent->code);
            $this->assertSame(3, $famille->niveau);
            $this->assertCount(0, $famille->enfants);

            $this->assertSame(
                CategoriePrix::where('parent_id', $famille->parent->id)->count(),
                $famille->parent->enfants->count()
            );
        });
    }

    #[Test]
    public function une_entreprise_ajoute_sa_categorie_sans_que_les_autres_la_voient(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();
        $this->seed(CategoriesPrixSeeder::class);

        $this->dansEntreprise($a, function (): void {
            $parent = CategoriePrix::where('code', '1.4.5')->firstOrFail();
            CategoriePrix::create([
                'parent_id' => $parent->id,
                'niveau' => 4,
                'ordre' => 1,
                'code' => '1.4.5.1',
                'nom' => 'Sous-famille de A',
            ]);
            $this->assertSame(306, CategoriePrix::count());
        });

        $this->assertSame(305, $this->dansEntreprise($b, fn () => CategoriePrix::count()));
    }

    #[Test]
    public function deux_entreprises_peuvent_utiliser_le_meme_code(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();

        $this->dansEntreprise($a, fn () => CategoriePrix::create(['niveau' => 1, 'ordre' => 1, 'code' => 'PERSO', 'nom' => 'A']));
        $this->dansEntreprise($b, fn () => CategoriePrix::create(['niveau' => 1, 'ordre' => 1, 'code' => 'PERSO', 'nom' => 'B']));

        $this->assertSame(2, CategoriePrix::sansFiltreTenant()->where('code', 'PERSO')->count());
    }

    #[Test]
    public function un_code_en_double_dans_la_meme_entreprise_est_refuse(): void
    {
        $a = $this->creerEntreprise();
        $this->dansEntreprise($a, fn () => CategoriePrix::create(['niveau' => 1, 'ordre' => 1, 'code' => 'PERSO', 'nom' => 'Premiere']));

        try {
            DB::transaction(fn () => $this->dansEntreprise($a, fn () => CategoriePrix::create(
                ['niveau' => 1, 'ordre' => 2, 'code' => 'PERSO', 'nom' => 'Doublon']
            )));
            $this->fail('Le doublon de code aurait du etre refuse.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('categories_prix_tenant_code_unique', $e->getMessage());
        }
    }

    #[Test]
    public function un_code_en_double_parmi_les_references_est_refuse_malgre_le_tenant_id_vide(): void
    {
        CategoriePrix::creerReference(['niveau' => 1, 'ordre' => 1, 'code' => '1', 'nom' => 'Premiere']);

        try {
            DB::transaction(fn () => CategoriePrix::creerReference(
                ['niveau' => 1, 'ordre' => 2, 'code' => '1', 'nom' => 'Doublon']
            ));
            $this->fail('Le doublon de code de reference aurait du etre refuse (NULLS NOT DISTINCT).');
        } catch (QueryException $e) {
            $this->assertStringContainsString('categories_prix_tenant_code_unique', $e->getMessage());
        }
    }

    #[Test]
    public function un_code_supprime_peut_etre_reutilise(): void
    {
        $a = $this->creerEntreprise();

        $this->dansEntreprise($a, function (): void {
            CategoriePrix::create(['niveau' => 1, 'ordre' => 1, 'code' => 'PERSO', 'nom' => 'Ancienne'])->delete();
            CategoriePrix::create(['niveau' => 1, 'ordre' => 1, 'code' => 'PERSO', 'nom' => 'Nouvelle']);

            $this->assertSame('Nouvelle', CategoriePrix::where('code', 'PERSO')->value('nom'));
        });
    }

    #[Test]
    public function le_parent_d_une_categorie_ne_peut_pas_etre_supprime_tant_qu_il_a_des_enfants(): void
    {
        $this->seed(CategoriesPrixSeeder::class);

        $domaine = $this->references()->where('code', '1')->firstOrFail();

        try {
            DB::transaction(fn () => DB::table('categories_prix')->where('id', $domaine->id)->delete());
            $this->fail('La suppression physique d\'un parent avec enfants aurait du etre refusee.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('parent_id', $e->getMessage());
        }
    }
}
