<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Valide et traite une tentative de connexion.
 *
 * Inclut une limitation du nombre de tentatives (anti brute-force) : après
 * 5 échecs sur la même combinaison email + adresse IP, les tentatives
 * suivantes sont bloquées temporairement, avec un délai croissant.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower((string) $this->input('email')),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Vérifie les identifiants, SANS ouvrir de session (tâche 2.15) :
     * Auth::validate() contrôle email/mot de passe comme Auth::attempt(),
     * mais ne connecte jamais l'utilisateur. C'est au contrôleur de
     * décider s'il connecte directement (pas de MFA) ou s'il met la
     * connexion en attente d'un code MFA (AuthenticatedSessionController).
     *
     * @throws ValidationException Si les identifiants sont invalides, ou
     *                              si la limite de tentatives est atteinte.
     * @return void
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->validate($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Ces identifiants ne correspondent à aucun compte.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Trop de tentatives de connexion. Réessayez dans {$seconds} secondes.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
