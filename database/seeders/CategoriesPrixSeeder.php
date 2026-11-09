<?php

namespace Database\Seeders;

use App\Models\CategoriePrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cree (ou met a jour) les categories de reference de la Bibliotheque de prix
 * depuis database/data/arborescence_mondelice_v1.json.
 *
 * Format du fichier : [{"nom": "...", "enfants": [{"nom": "...", "enfants": []}]}]
 * Le code est calcule : position 1-based parmi les freres, precedee du code du
 * parent (ex. « 1 », « 1.4 », « 1.4.5 »).
 *
 * Idempotent : relancer ce seeder ne cree aucun doublon (une ligne de reference
 * est retrouvee par son code) et remet nom, niveau, ordre et parent a la valeur
 * du fichier. Il ne touche jamais aux categories des entreprises.
 *
 * Sur un serveur existant :
 *   php artisan db:seed --class=CategoriesPrixSeeder
 */
class CategoriesPrixSeeder extends Seeder
{
    /**
     * Chemin du fichier d'arborescence.
     *
     * @return string
     */
    private function chemin(): string
    {
        return database_path('data/arborescence_mondelice_v1.json');
    }

    /**
     * @return void
     *
     * @throws RuntimeException Fichier absent ou JSON invalide.
     * @throws \JsonException JSON mal forme.
     */
    public function run(): void
    {
        $chemin = $this->chemin();

        if (! is_file($chemin)) {
            throw new RuntimeException("Fichier d'arborescence introuvable : {$chemin}");
        }

        $arbre = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($arbre)) {
            throw new RuntimeException("Arborescence invalide : {$chemin}");
        }

        DB::transaction(function () use ($arbre): void {
            $this->importer($arbre, null, null, 1);
        });
    }

    /**
     * Importe un niveau de l'arborescence, puis ses enfants.
     *
     * @param list<array{nom: string, enfants?: list<array<string, mixed>>}> $noeuds Noeuds du niveau.
     * @param CategoriePrix|null $parent Categorie parente (NULL pour les domaines).
     * @param string|null $prefixe Code du parent (NULL pour les domaines).
     * @param int $niveau Niveau courant (1 = domaine).
     * @return void
     */
    private function importer(array $noeuds, ?CategoriePrix $parent, ?string $prefixe, int $niveau): void
    {
        foreach (array_values($noeuds) as $index => $noeud) {
            $ordre = $index + 1;
            $code = $prefixe === null ? (string) $ordre : $prefixe.'.'.$ordre;

            $attributs = [
                'parent_id' => $parent?->id,
                'niveau' => $niveau,
                'ordre' => $ordre,
                'code' => $code,
                'nom' => trim((string) $noeud['nom']),
            ];

            // Lecture volontaire hors filtre : le seeder ne depend pas du tenant courant.
            $categorie = CategoriePrix::sansFiltreTenant()
                ->whereNull('tenant_id')
                ->where('code', $code)
                ->first();

            if ($categorie === null) {
                $categorie = CategoriePrix::creerReference($attributs);
            } else {
                $categorie->autoriserModificationReference()->forceFill($attributs)->save();
            }

            $this->importer($noeud['enfants'] ?? [], $categorie, $code, $niveau + 1);
        }
    }
}
