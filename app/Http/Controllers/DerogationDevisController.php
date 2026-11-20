<?php

namespace App\Http\Controllers;

use App\Enums\TypeSeuil;
use App\Models\DerogationDevis;
use App\Models\Devis;
use App\Models\SeuilTarifaire;
use App\Models\User;
use App\Notifications\DerogationDevisNotification;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\ChangementStatutDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Dérogations aux seuils du devis (lot D4c, décisions du 20 novembre 2026).
 *
 *  - Le Commercial demande (`devis.demander_derogation`) : un devis, un seuil,
 *    une valeur, un motif. Une seule demande en attente par devis et par seuil.
 *  - La Direction décide (`devis.valider_derogation`) : elle accepte, en
 *    ajustant la valeur si besoin, ou refuse avec un motif.
 *  - Le demandeur peut annuler sa demande tant qu'elle est en attente.
 * Chaque étape est tracée dans le journal et notifiée à l'écran et par e-mail.
 */
class DerogationDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:devis.voir_marge', only: ['duDevis']),
            new Middleware('can:devis.demander_derogation', only: ['store', 'annuler']),
            new Middleware('can:devis.valider_derogation', only: ['index', 'accepter', 'refuser']),
        ];
    }

    /**
     * Toutes les dérogations de l'entreprise, les plus récentes d'abord.
     *
     * @param Request $request Filtres facultatifs : `statut`, `devis_id`.
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $derogations = DerogationDevis::query()
            ->with(['devis:id,numero,titre', 'demandeur:id,name', 'decideur:id,name'])
            ->when($request->filled('statut'), fn ($requete) => $requete->where('statut', $request->input('statut')))
            ->when($request->filled('devis_id'), fn ($requete) => $requete->where('devis_id', $request->integer('devis_id')))
            ->orderByDesc('demandee_le')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($derogations);
    }

    /**
     * Dérogations d'un devis visible de l'utilisateur.
     *
     * @param Request $request
     * @param int $devis
     * @return JsonResponse
     */
    public function duDevis(Request $request, int $devis): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);

        return response()->json([
            'data' => DerogationDevis::query()
                ->with(['demandeur:id,name', 'decideur:id,name'])
                ->where('devis_id', $fiche->getKey())
                ->orderByDesc('demandee_le')
                ->orderByDesc('id')
                ->get(),
            'en_vigueur' => DerogationDevis::accordeesPour($fiche),
        ]);
    }

    /**
     * Demande une dérogation sur un devis en préparation.
     *
     * @param Request $request
     * @param int $devis
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function store(Request $request, int $devis): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);
        AccesDevis::exigerModifiable($fiche);

        $donnees = $request->validate([
            'type_seuil' => ['required', Rule::in([TypeSeuil::MargeGlobaleDevis->value, TypeSeuil::RemiseMaximum->value])],
            'valeur_demandee' => ['required', 'numeric', 'min:-100', 'max:100'],
            'motif' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $seuil = SeuilTarifaire::actifsParType()[$donnees['type_seuil']] ?? null;
        if ($seuil === null) {
            throw ValidationException::withMessages([
                'type_seuil' => 'Aucun seuil de ce type n\'est actif : il n\'y a rien à déroger.',
            ]);
        }
        $this->exigerAuDela($donnees['type_seuil'], (float) $donnees['valeur_demandee'], $seuil, 'valeur_demandee');

        $enAttente = DerogationDevis::query()
            ->where('devis_id', $fiche->getKey())
            ->where('type_seuil', $donnees['type_seuil'])
            ->where('statut', DerogationDevis::EN_ATTENTE)
            ->exists();
        if ($enAttente) {
            throw ValidationException::withMessages([
                'type_seuil' => 'Une demande est déjà en attente pour ce seuil sur ce devis.',
            ]);
        }

        $derogation = DerogationDevis::create([
            'devis_id' => $fiche->getKey(),
            'type_seuil' => $donnees['type_seuil'],
            'statut' => DerogationDevis::EN_ATTENTE,
            'seuil' => $seuil,
            'valeur_demandee' => $donnees['valeur_demandee'],
            'motif' => $donnees['motif'],
            'demandeur_id' => $request->user()->getKey(),
            'demandee_le' => now(),
        ]);

        $this->journaliser($request, $fiche, $derogation, 'derogation_demandee', 'Dérogation demandée');
        $this->suivreStatut($request, $fiche);

        $valideurs = User::permission('devis.valider_derogation')
            ->where('tenant_id', $fiche->tenant_id)
            ->whereKeyNot($request->user()->getKey())
            ->get();
        $this->notifier($valideurs, $derogation, $fiche, 'demandee');

        return response()->json($this->charger($derogation), 201);
    }

    /**
     * Accepte une demande en attente. Sans `valeur_accordee`, la valeur demandée est accordée.
     *
     * @param Request $request
     * @param int $derogation
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function accepter(Request $request, int $derogation): JsonResponse
    {
        $element = $this->enAttente($derogation);
        $donnees = $request->validate([
            'valeur_accordee' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'motif_decision' => ['nullable', 'string', 'max:1000'],
        ]);

        $accordee = (float) ($donnees['valeur_accordee'] ?? $element->valeur_demandee);
        $seuil = SeuilTarifaire::actifsParType()[$element->type_seuil] ?? null;
        if ($seuil !== null) {
            $this->exigerAuDela($element->type_seuil, $accordee, $seuil, 'valeur_accordee');
        }

        return $this->decider($request, $element, DerogationDevis::ACCEPTEE, $accordee, $donnees['motif_decision'] ?? null);
    }

    /**
     * Refuse une demande en attente, avec un motif.
     *
     * @param Request $request
     * @param int $derogation
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function refuser(Request $request, int $derogation): JsonResponse
    {
        $element = $this->enAttente($derogation);
        $donnees = $request->validate([
            'motif_decision' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        return $this->decider($request, $element, DerogationDevis::REFUSEE, null, $donnees['motif_decision']);
    }

    /**
     * Le demandeur annule sa propre demande en attente.
     *
     * @param Request $request
     * @param int $derogation
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function annuler(Request $request, int $derogation): JsonResponse
    {
        $element = DerogationDevis::query()->findOrFail($derogation);
        abort_unless($element->demandeur_id === $request->user()->getKey(), 403);
        $this->exigerEnAttente($element);

        $element->update(['statut' => DerogationDevis::ANNULEE, 'decidee_le' => now()]);
        $fiche = $element->devis()->firstOrFail();
        $this->journaliser($request, $fiche, $element, 'derogation_annulee', 'Dérogation annulée par le demandeur');
        $this->suivreStatut($request, $fiche);

        return response()->json($this->charger($element));
    }

    /**
     * @param Request $request
     * @param DerogationDevis $element
     * @param string $statut
     * @param float|null $accordee
     * @param string|null $motif
     * @return JsonResponse
     */
    private function decider(Request $request, DerogationDevis $element, string $statut, ?float $accordee, ?string $motif): JsonResponse
    {
        $element->update([
            'statut' => $statut,
            'valeur_accordee' => $accordee,
            'motif_decision' => $motif,
            'decideur_id' => $request->user()->getKey(),
            'decidee_le' => now(),
        ]);

        $fiche = $element->devis()->firstOrFail();
        $acceptee = $statut === DerogationDevis::ACCEPTEE;
        $this->journaliser(
            $request,
            $fiche,
            $element,
            $acceptee ? 'derogation_acceptee' : 'derogation_refusee',
            $acceptee ? 'Dérogation acceptée' : 'Dérogation refusée'
        );

        $this->suivreStatut($request, $fiche);

        $demandeur = $element->demandeur()->first();
        $this->notifier($demandeur === null ? [] : [$demandeur], $element, $fiche, $acceptee ? 'acceptee' : 'refusee');

        return response()->json($this->charger($element));
    }

    /**
     * @param int $derogation
     * @return DerogationDevis
     *
     * @throws ValidationException
     */
    private function enAttente(int $derogation): DerogationDevis
    {
        $element = DerogationDevis::query()->findOrFail($derogation);
        $this->exigerEnAttente($element);

        return $element;
    }

    /**
     * @param DerogationDevis $element
     * @return void
     *
     * @throws ValidationException
     */
    private function exigerEnAttente(DerogationDevis $element): void
    {
        if ($element->statut !== DerogationDevis::EN_ATTENTE) {
            throw ValidationException::withMessages([
                'statut' => 'Cette demande a déjà reçu une réponse.',
            ]);
        }
    }

    /**
     * Une dérogation n'a de sens qu'au-delà du seuil : marge plus basse, ou remise plus haute.
     *
     * @param string $type
     * @param float $valeur
     * @param float $seuil
     * @param string $champ
     * @return void
     *
     * @throws ValidationException
     */
    private function exigerAuDela(string $type, float $valeur, float $seuil, string $champ): void
    {
        $remise = $type === TypeSeuil::RemiseMaximum->value;
        if ($remise ? $valeur <= $seuil : $valeur >= $seuil) {
            throw ValidationException::withMessages([
                $champ => $remise
                    ? sprintf('La valeur doit dépasser le maximum en vigueur (%s %%).', rtrim(rtrim(number_format($seuil, 2, ',', ''), '0'), ','))
                    : sprintf('La valeur doit être sous le minimum en vigueur (%s %%).', rtrim(rtrim(number_format($seuil, 2, ',', ''), '0'), ',')),
            ]);
        }
    }

    /**
     * @param iterable<int, User> $destinataires
     * @param DerogationDevis $derogation
     * @param Devis $devis
     * @param string $evenement
     * @return void
     */
    private function notifier(iterable $destinataires, DerogationDevis $derogation, Devis $devis, string $evenement): void
    {
        $derogation->setRelation('devis', $devis);
        $derogation->loadMissing(['demandeur:id,name', 'decideur:id,name']);

        foreach ($destinataires as $destinataire) {
            // Un e-mail en échec ne doit ni bloquer les autres ni changer la réponse.
            try {
                $destinataire->notify(new DerogationDevisNotification($derogation, $evenement));
            } catch (Throwable $erreur) {
                report($erreur);
            }
        }
    }

    /**
     * @param Request $request
     * @param Devis $devis
     * @param DerogationDevis $derogation
     * @param string $evenement
     * @param string $libelle
     * @return void
     */
    private function journaliser(Request $request, Devis $devis, DerogationDevis $derogation, string $evenement, string $libelle): void
    {
        activity()
            ->performedOn($devis)
            ->causedBy($request->user())
            ->event($evenement)
            ->withProperties([
                'derogation_id' => $derogation->getKey(),
                'type_seuil' => $derogation->type_seuil,
                'seuil' => (float) $derogation->seuil,
                'valeur_demandee' => (float) $derogation->valeur_demandee,
                'valeur_accordee' => $derogation->valeur_accordee === null ? null : (float) $derogation->valeur_accordee,
            ])
            ->log(sprintf('Devis %s : %s.', $devis->numero, mb_strtolower($libelle)));
    }

    /**
     * @param DerogationDevis $derogation
     * @return DerogationDevis
     */
    private function charger(DerogationDevis $derogation): DerogationDevis
    {
        return $derogation->fresh(['demandeur:id,name', 'decideur:id,name']);
    }

    /**
     * Fait suivre le statut du devis à ses demandes (lot D4c bis) : « En attente
     * de dérogation » tant qu'une demande attend une réponse, retour à « En
     * préparation » ensuite. Le devis en attente reste modifiable, mais la
     * machine à états refuse son envoi.
     *
     * @param Request $request
     * @param Devis $devis
     * @return void
     */
    private function suivreStatut(Request $request, Devis $devis): void
    {
        $enAttente = DerogationDevis::query()
            ->where('devis_id', $devis->getKey())
            ->where('statut', DerogationDevis::EN_ATTENTE)
            ->exists();
        $statut = (string) $devis->statut;
        $changement = app(ChangementStatutDevis::class);

        if ($enAttente && in_array($statut, ['en_preparation', 'pret_a_envoyer'], true)) {
            if ($statut === 'pret_a_envoyer') {
                $changement->appliquer($devis, 'en_preparation', $request->user());
            }
            $changement->appliquer($devis, 'en_attente_derogation', $request->user());
        } elseif (! $enAttente && $statut === 'en_attente_derogation') {
            $changement->appliquer($devis, 'en_preparation', $request->user());
        }
    }
}
