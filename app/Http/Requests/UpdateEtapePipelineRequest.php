<?php

namespace App\Http\Requests;

use App\Models\EtapePipeline;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la modification d'une etape du pipeline : nom et
 * probabilite par defaut seulement (code, ordre et type ne changent pas).
 * La probabilite de Gagne (100) et de Perdu / Sans suite (0) est fixe.
 */
class UpdateEtapePipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $etape = $this->route('etape');

        return [
            'nom' => ['sometimes', 'required', 'string', 'max:60'],
            'probabilite_defaut' => [
                'sometimes', 'required', 'integer', 'between:0,100',
                function (string $attribute, mixed $valeur, Closure $echec) use ($etape): void {
                    $fixe = $etape instanceof EtapePipeline ? $etape->probabiliteFixe() : null;
                    if ($fixe !== null && (int) $valeur !== $fixe) {
                        $echec("La probabilite de cette etape est fixe ({$fixe} %).");
                    }
                },
            ],
        ];
    }
}
