<?php

namespace Tests\Feature;

use App\Models\ArticlePrix;
use App\Models\Client;
use App\Models\Opportunite;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tache 3.4 ter : plus de doublon de tables, et les relations de la Phase 1
 * (opportunites, devis, lignes) pointent bien vers contacts et articles_prix.
 */
class ReconciliationSchemaTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    /**
     * Renvoie la table pointee par la cle etrangere d'une colonne.
     *
     * @param string $table Table source.
     * @param string $colonne Colonne portant la cle etrangere.
     * @return string|null Table cible, ou null si aucune cle sur cette colonne.
     */
    private function tableCible(string $table, string $colonne): ?string
    {
        foreach (Schema::getForeignKeys($table) as $cle) {
            if ($cle['columns'] === [$colonne]) {
                return $cle['foreign_table'];
            }
        }

        return null;
    }

    public function test_les_anciennes_tables_n_existent_plus(): void
    {
        $this->assertFalse(Schema::hasTable('price_library_items'));
    }

    public function test_les_cles_etrangeres_pointent_vers_les_nouvelles_tables(): void
    {
        $this->assertSame('clients', $this->tableCible('opportunites', 'client_id'));
        $this->assertSame('clients', $this->tableCible('quotes', 'client_id'));
        $this->assertSame('articles_prix', $this->tableCible('quote_lines', 'article_prix_id'));
    }

    public function test_les_relations_fonctionnent_de_bout_en_bout(): void
    {
        $entreprise = $this->creerEntreprise();

        $this->dansEntreprise($entreprise, function () {
            $contact = Client::create(['type' => 'particulier', 'nom' => 'Dupont']);

            $article = ArticlePrix::create([
                'reference' => 'ART-REL',
                'designation' => 'Pose de carrelage',
                'type_article' => 'main_oeuvre',
                'unite' => 'm2',
                'prix_force_ht' => 40,
                'taux_tva' => 10,
            ]);

            $opportunite = Opportunite::create(['client_id' => $contact->id, 'titre' => 'Renovation']);
            $devis = Quote::create([
                'client_id' => $contact->id,
                'opportunity_id' => $opportunite->id,
            ]);
            $ligne = QuoteLine::create([
                'quote_id' => $devis->id,
                'article_prix_id' => $article->id,
                'designation' => 'Pose de carrelage',
                'unit_price_ht' => 40,
            ]);

            $this->assertTrue($opportunite->client->is($contact));
            $this->assertTrue($devis->client->is($contact));
            $this->assertTrue($ligne->articlePrix->is($article));
            $this->assertCount(1, $devis->lines);
        });
    }
}
