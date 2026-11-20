<?php

namespace App\Services\Devis;

use App\Enums\TypeSeuil;
use App\Models\AlerteSeuil;
use App\Models\Devis;
use App\Models\Parametre;
use App\Models\User;
use App\Notifications\AlerteSeuilDevis;
use Throwable;

/**
 * Alertes de seuil regroupées (lot D4b, décisions du 18 novembre 2026).
 *
 * Les refus d'une même personne, sur un même devis et un même seuil, sont
 * regroupés pendant le délai `alertes_seuil_delai_heures` (24 heures par
 * défaut, réglé par la Direction), compté depuis le premier refus :
 *  - premier refus : une alerte est créée, puis envoyée à l'écran et par
 *    e-mail à ceux qui ont `alertes.seuils.recevoir` (Direction, Administratif) ;
 *  - refus suivants : l'alerte garde le compteur, la dernière valeur et la
 *    pire valeur ; rien n'est renvoyé.
 * Chaque refus reste tracé un par un dans le journal (ControleSeuilsDevis).
 */
final class AlertesSeuils
{
    /**
     * @param Devis $devis
     * @param User|null $auteur
     * @param array{motif: string, valeur: float, seuil: float, message: string} $refus
     * @param string $moment `ecriture` ou `envoi`.
     * @return void
     */
    public function signaler(Devis $devis, ?User $auteur, array $refus, string $moment): void
    {
        $remise = $refus['motif'] === 'remise_au_dessus_du_maximum';
        $type = $remise ? TypeSeuil::RemiseMaximum->value : TypeSeuil::MargeGlobaleDevis->value;
        $delai = max(1, (int) Parametre::lire('alertes_seuil_delai_heures'));
        $valeur = round((float) $refus['valeur'], 2);

        $alerte = AlerteSeuil::query()
            ->where('devis_id', $devis->getKey())
            ->where('user_id', $auteur?->getKey())
            ->where('type_seuil', $type)
            ->where('premiere_le', '>', now()->subHours($delai))
            ->orderByDesc('premiere_le')
            ->first();

        if ($alerte !== null) {
            $pire = (float) $alerte->pire_valeur;
            $alerte->update([
                'nombre' => $alerte->nombre + 1,
                'moment' => $moment,
                'seuil' => $refus['seuil'],
                'derniere_valeur' => $valeur,
                'pire_valeur' => $remise ? max($pire, $valeur) : min($pire, $valeur),
                'derniere_le' => now(),
            ]);

            return;
        }

        $alerte = AlerteSeuil::create([
            'devis_id' => $devis->getKey(),
            'user_id' => $auteur?->getKey(),
            'type_seuil' => $type,
            'moment' => $moment,
            'nombre' => 1,
            'seuil' => $refus['seuil'],
            'derniere_valeur' => $valeur,
            'pire_valeur' => $valeur,
            'premiere_le' => now(),
            'derniere_le' => now(),
        ]);
        $alerte->setRelation('devis', $devis);
        $alerte->setRelation('auteur', $auteur);

        $destinataires = User::permission('alertes.seuils.recevoir')
            ->where('tenant_id', $devis->tenant_id)
            ->when($auteur !== null, fn ($requete) => $requete->whereKeyNot($auteur->getKey()))
            ->get()
            ->filter(fn (User $destinataire): bool => AccesDevis::peutVoir($destinataire, $devis));

        foreach ($destinataires as $destinataire) {
            // Un e-mail en échec ne doit ni bloquer les autres ni changer la réponse.
            try {
                $destinataire->notify(new AlerteSeuilDevis($alerte, $delai));
            } catch (Throwable $erreur) {
                report($erreur);
            }
        }
    }
}
