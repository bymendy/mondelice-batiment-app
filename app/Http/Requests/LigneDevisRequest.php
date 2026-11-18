<?php

namespace App\Http\Requests;

use App\Models\LigneDevis;
use App\Models\LotDevis;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creation (POST) et modification (PUT) d'une ligne de devis.
 *
 * Le prix de revient et le lien avec la bibliotheque de prix ne se saisissent
 * pas ici : ils viennent de la copie figee (lot D3).
 */
class LigneDevisRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creation = $this->isMethod('POST');
        $present = $creation ? ['required'] : ['sometimes'];

        return [
            'libelle' => array_merge($present, ['string', 'max:255']),
            'descriptif' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'dtu_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expression_metre' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantite' => array_merge($present, ['numeric', 'between:-9999999,9999999']),
            'unite' => ['sometimes', 'nullable', 'string', 'max:20'],
            'prix_unitaire_ht' => array_merge($present, ['numeric', 'between:0,99999999.99']),
            'taux_tva' => ['sometimes', 'numeric', 'between:0,100'],
            'remise_pct' => ['sometimes', 'numeric', 'between:0,100'],
            // Le revient ne se saisit qu'avec devis.voir_marge ; sinon il est ignore.
            'prix_revient_unitaire' => $this->user()?->can('devis.voir_marge')
                ? ['sometimes', 'nullable', 'numeric', 'between:0,99999999.99']
                : ['exclude'],
            'ordre' => ['sometimes', 'integer', 'between:0,100000'],
            'lot_id' => ['sometimes', 'nullable', 'integer', $this->regleLot('lot')],
            'emplacement_id' => ['sometimes', 'nullable', 'integer', $this->regleLot('emplacement')],
        ];
    }

    /**
     * Le lot (ou l'emplacement) doit appartenir au meme devis que la ligne.
     *
     * @param string $type
     * @return Closure
     */
    private function regleLot(string $type): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($type): void {
            $devis = $this->devisCible();
            $existe = $devis !== null && LotDevis::query()
                ->whereKey($valeur)
                ->where('devis_id', $devis)
                ->where('type', $type)
                ->exists();
            if (! $existe) {
                $echec($type === 'lot' ? 'Ce lot n\'appartient pas à ce devis.' : 'Cet emplacement n\'appartient pas à ce devis.');
            }
        };
    }

    /**
     * Devis concerne : celui de l'URL (creation) ou celui de la ligne
     * (modification).
     *
     * @return int|null
     */
    private function devisCible(): ?int
    {
        if ($this->isMethod('POST')) {
            return (int) $this->route('devis');
        }

        return LigneDevis::query()->find((int) $this->route('ligne'))?->devis_id;
    }
}
