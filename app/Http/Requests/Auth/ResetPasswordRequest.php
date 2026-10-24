<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Valide la soumission du nouveau mot de passe, avec le token reçu par
 * email (voir AppServiceProvider::boot() pour la construction du lien
 * vers le frontend Next.js).
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * Détermine si l'appelant est autorisé à effectuer cette requête.
     * Toujours vrai : la réinitialisation est un endpoint public (protégée
     * par la possession du token, pas par une session).
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise l'email en minuscules avant validation --- même traitement
     * que les autres FormRequest d'authentification.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower((string) $this->input('email')),
        ]);
    }

    /**
     * Règles de validation appliquées aux données soumises.
     *
     * "password" suit la même politique que RegisterRequest.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
