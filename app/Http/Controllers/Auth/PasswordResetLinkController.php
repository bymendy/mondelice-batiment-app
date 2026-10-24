<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

/**
 * Déclenche l'envoi du lien de réinitialisation de mot de passe.
 *
 * Renvoie volontairement le même message, que l'email corresponde ou non
 * à un compte existant --- décision actée pour empêcher l'énumération de
 * comptes (un attaquant ne doit pas pouvoir déduire quels emails sont
 * inscrits en observant la réponse de cet endpoint).
 */
class PasswordResetLinkController extends Controller
{
    /**
     * Envoie le lien de réinitialisation si l'email correspond à un
     * compte ; ne révèle jamais si ce fut le cas.
     *
     * @param ForgotPasswordRequest $request L'email soumis, déjà normalisé
     *                                        en minuscules (voir
     *                                        prepareForValidation()).
     * @return JsonResponse Message générique, identique dans tous les cas.
     */
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si un compte existe avec cet email, un lien de réinitialisation a été envoyé.',
        ]);
    }
}
