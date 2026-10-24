<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Valide les données soumises lors de l'inscription d'un nouvel utilisateur.
 *
 * Ne couvre volontairement que les champs correspondant au mass-assignment
 * autorisé du modèle User ("name", "email", "password") --- "tenant_id"
 * n'apparaît jamais ici. Le modèle User restreint son #[Fillable] pour
 * empêcher qu'un formulaire puisse auto-attribuer un tenant_id (voir
 * Mondelice_Phase1_Base_de_Donnees.tex, section 2.6) ; le contrôleur
 * (RegisteredUserController) l'assigne explicitement, après coup.
 */
class RegisterRequest extends FormRequest
{
    /**
     * Détermine si l'appelant est autorisé à effectuer cette requête.
     * Toujours vrai : l'inscription est un endpoint public.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise l'email en minuscules avant validation --- évite que
     * "Jean@Exemple.com" et "jean@exemple.com" soient traités comme deux
     * comptes distincts par la règle "unique" (PostgreSQL est sensible à
     * la casse sur les comparaisons de chaînes, contrairement à MySQL).
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
     * "email" doit être unique sur l'ensemble de la table "users" --- donc
     * unique tous tenants confondus (un même email ne peut pas servir à
     * créer deux comptes, même dans deux entreprises clientes différentes).
     *
     * "password" suit la politique par défaut de Laravel (8 caractères
     * minimum) --- suffisant pour un usage interne en V1 ; à durcir si
     * besoin au moment de l'ouverture publique du SaaS.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
