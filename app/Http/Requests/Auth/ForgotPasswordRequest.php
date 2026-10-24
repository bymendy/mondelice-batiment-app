<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valide la demande de lien de réinitialisation de mot de passe.
 *
 * Volontairement minimaliste : seul l'email est nécessaire. La réponse
 * renvoyée au client est identique que l'email corresponde ou non à un
 * compte (voir PasswordResetLinkController), pour ne pas permettre à un
 * attaquant de découvrir quels emails sont inscrits (énumération de
 * comptes).
 */
class ForgotPasswordRequest extends FormRequest
{
    /**
     * Détermine si l'appelant est autorisé à effectuer cette requête.
     * Toujours vrai : la demande de réinitialisation est un endpoint public.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise l'email en minuscules avant validation --- même traitement
     * que RegisterRequest/LoginRequest (voir Mondelice_Phase2_Backend.tex,
     * notebox sur la sensibilité à la casse sous PostgreSQL).
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
