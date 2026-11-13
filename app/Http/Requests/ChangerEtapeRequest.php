<?php

namespace App\Http\Requests;

use App\Models\EtapePipeline;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changement d'etape d'une affaire.
 */
class ChangerEtapeRequest extends FormRequest
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
        return [
            'etape_id' => ['required', 'integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (! EtapePipeline::query()->whereKey($valeur)->exists()) {
                    $echec('Cette étape n\'existe pas.');
                }
            }],
            'motif_perte_id' => ['sometimes', 'nullable', 'integer'],
            'concurrent' => ['sometimes', 'nullable', 'string', 'max:150'],
            'prochaine_action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'prochaine_action_le' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
