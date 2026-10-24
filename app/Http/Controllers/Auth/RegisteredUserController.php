<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Gère l'inscription : création d'un nouveau tenant (entreprise cliente) et
 * de son premier utilisateur, puis connexion immédiate de cet utilisateur.
 *
 * @see \App\Http\Requests\Auth\RegisterRequest Règles de validation appliquées en amont.
 */
class RegisteredUserController extends Controller
{
    /**
     * Crée un tenant et son premier utilisateur, puis connecte l'utilisateur
     * (authentification par cookie de session --- mode SPA, voir
     * Mondelice_Phase2_Backend.tex, décision A).
     *
     * IMPORTANT : "tenant_id" et "preferred_language" ne font pas partie du
     * #[Fillable] du modèle User (restriction volontaire). Ils sont donc
     * toujours assignés explicitement après instanciation, jamais via le
     * tableau validé du formulaire --- point de vigilance identifié en
     * Phase 1 (voir Mondelice_Phase1_Base_de_Donnees.tex, section 2.6).
     *
     * La création du tenant et de l'utilisateur est enveloppée dans une
     * transaction : si la création de l'utilisateur échoue pour une raison
     * quelconque, le tenant nouvellement créé est annulé avec elle, plutôt
     * que de laisser un tenant orphelin, sans utilisateur, en base.
     *
     * Tâche 2.12 : ce point d'inscription crée systématiquement un nouveau
     * tenant en même temps que l'utilisateur --- il n'existe aujourd'hui
     * aucun flux d'invitation d'un utilisateur supplémentaire dans un
     * tenant déjà existant. L'utilisateur créé ici est donc toujours,
     * par construction, le premier utilisateur de son tenant, et reçoit
     * systématiquement le rôle "Direction" (voir CONTEXTE_PROJET_IA.md,
     * section 5.6). Le jour où un flux d'invitation est ajouté, cette
     * attribution automatique devra être déplacée pour ne s'appliquer
     * qu'à la création du tenant lui-même, pas à chaque nouvel utilisateur
     * invité dans un tenant existant.
     *
     * @param RegisterRequest $request Les données d'inscription, déjà validées.
     * @return JsonResponse L'utilisateur créé (le mot de passe est masqué
     *                       automatiquement par #[Hidden] sur le modèle
     *                       User), avec le code HTTP 201 (Created).
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = DB::transaction(function () use ($validated): User {
            $tenant = Tenant::create([
                'preferred_language' => 'fr',
            ]);

            $user = new User([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
            $user->tenant_id = $tenant->id;
            $user->preferred_language = 'fr';
            $user->save();

            $user->assignRole('Direction');

            return $user;
        });

        // Initialise le tenant dès ce point de la requête : le middleware IdentifyTenantByUser
        // s'est exécuté avant l'authentification et n'avait donc aucun tenant actif. Sans cet
        // appel, tenant_id() renverrait null pour la suite de la requête (notamment l'entrée
        // activity_log créée par le listener de connexion). Corrige le point ouvert "tenant_id
        // du journal non vérifié en conditions réelles" (Mondelice_Phase2_Backend.tex, §6).
        //
        // IMPORTANT : doit s'exécuter AVANT Auth::login(), pas après --- Auth::login() déclenche
        // l'événement Login de façon synchrone, qui écrit dans activity_log immédiatement.
        // Initialiser le tenant après coup arrive trop tard pour cette écriture.
        tenancy()->initialize($user->tenant_id);
        Auth::guard('web')->login($user);

        // Régénère l'identifiant de session après connexion : empêche la
        // fixation de session (un identifiant de session obtenu avant la
        // connexion ne doit jamais rester valide après).
        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
        ], 201);
    }
}
