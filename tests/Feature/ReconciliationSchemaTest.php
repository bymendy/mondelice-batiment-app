<?php

namespace Tests\Feature;

use App\Models\ArticlePrix;
use App\Models\Contact;
use App\Models\Opportunity;
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
        $this->assertFalse(Schema::hasTable('clients'));
        $this->assertFalse(Schema::hasTable('price_library_items'));
    }

    public function test_les_cles_etrangeres_pointent_vers_les_nouvelles_tables(): void
    {
        $this->assertSame('contacts', $this->tableCible('opportunities', 'contact_id'));
        $this->assertSame('contacts', $this->tableCible('quotes', 'contact_id'));
        $this->assertSame('articles_prix', $this->tableCible('quote_lines', 'article_prix_id'));
    }

    public function test_les_relations_fonctionnent_de_bout_en_bout(): void
    {
        $entreprise = $this->creerEntreprise();

        $this->dansEntreprise($entreprise, function () {
            $contact = Contact::create(['type' => 'particulier', 'nom' => 'Dupont']);

            $article = ArticlePrix::create([
                'reference' => 'ART-REL',
                'designation' => 'Pose de carrelage',
                'categorie' => 'main_oeuvre',
                'unite' => 'm2',
                'prix_achat_ht' => 25,
                'prix_vente_ht' => 40,
                'taux_tva' => 10,
            ]);

            $opportunite = Opportunity::create(['contact_id' => $contact->id]);
            $devis = Quote::create([
                'contact_id' => $contact->id,
                'opportunity_id' => $opportunite->id,
            ]);
            $ligne = QuoteLine::create([
                'quote_id' => $devis->id,
                'article_prix_id' => $article->id,
                'designation' => 'Pose de carrelage',
                'unit_price_ht' => 40,
            ]);

            $this->assertTrue($opportunite->contact->is($contact));
            $this->assertTrue($devis->contact->is($contact));
            $this->assertTrue($ligne->articlePrix->is($article));
            $this->assertCount(1, $devis->lines);
        });
    }
}
