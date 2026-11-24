<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\DevisParametres;
use App\Models\DevisTauxHoraire;
use App\Models\LigneDevis;
use App\Models\User;
use App\Services\Bibliotheque\Arrondi;
use Illuminate\Support\Facades\DB;

/**
 * « Recalculer le devis » (décisions du 20 novembre 2026).
 *
 * Un changement de paramètre ne touche que les lignes ajoutées ensuite. Ce
 * service applique, sur demande, les paramètres du devis (taux horaires,
 * difficulté, zone) aux lignes déjà écrites.
 *
 *  - Ligne recalculée : une ligne qui a un sous-détail (copiée depuis la
 *    bibliothèque) et dont le prix n'est pas forcé.
 *  - Ligne à prix forcé : conservée telle quelle, sauf si on demande de
 *    recalculer aussi les prix forcés (elle perd alors sa marque).
 *  - Ligne sans sous-détail (ligne libre) : jamais recalculée.
 *
 * Seule la main-d'œuvre change : temps de base x difficulté, au taux horaire
 * du devis. Les fournitures gardent leurs prix. Le recalcul réel passe par le
 * contrôle des seuils ; une simulation n'écrit rien.
 */
class RecalculDevis
{
    /**
     * @param ParametresDevis $parametres
     * @param ControleSeuilsDevis $controle
     */
    public function __construct(private ParametresDevis $parametres, private ControleSeuilsDevis $controle)
    {
    }

    /**
     * @param Devis $devis Devis modifiable.
     * @param User|null $auteur
     * @param bool $inclureForces Recalculer aussi les lignes à prix forcé.
     * @param bool $simulation Compter sans rien écrire.
     * @return array<string, mixed> Compteurs, totaux avant et après, message.
     */
    public function executer(Devis $devis, ?User $auteur, bool $inclureForces, bool $simulation): array
    {
        $parametres = $this->parametres->pour($devis) ?? $this->parametres->creer($devis);
        $avant = (float) $devis->recalculerTotaux()['total_ht'];
        $compteurs = [];

        $ecriture = function () use ($devis, $parametres, $inclureForces, &$compteurs): void {
            $compteurs = $this->appliquer($devis, $parametres, $inclureForces);
            $devis->recalculerTotaux();
        };

        if ($simulation) {
            DB::beginTransaction();
            try {
                $ecriture();
                $apres = (float) $devis->total_ht;
            } finally {
                DB::rollBack();
                $devis->refresh();
            }
        } else {
            $this->controle->executer($devis, $auteur, $ecriture);
            $apres = (float) $devis->refresh()->total_ht;
        }

        return array_merge($compteurs, [
            'simulation' => $simulation,
            'prix_forces_inclus' => $inclureForces,
            'total_ht_avant' => $avant,
            'total_ht_apres' => $apres,
            'message' => $this->message($compteurs),
        ]);
    }

    /**
     * Met toutes les lignes du devis à sa TVA par défaut.
     *
     * @param Devis $devis Devis modifiable.
     * @return array{taux_tva: float, lignes_modifiees: int}
     */
    public function appliquerTva(Devis $devis): array
    {
        $taux = $this->parametres->tvaParDefaut($devis);

        return DB::transaction(function () use ($devis, $taux): array {
            $modifiees = 0;
            foreach (LigneDevis::query()->where('devis_id', $devis->getKey())->get() as $ligne) {
                if (abs((float) $ligne->taux_tva - $taux) < 0.005) {
                    continue;
                }
                $ligne->taux_tva = $taux;
                $ligne->save();
                $modifiees++;
            }
            $devis->recalculerTotaux();

            return ['taux_tva' => $taux, 'lignes_modifiees' => $modifiees];
        });
    }

    /**
     * @param Devis $devis
     * @param DevisParametres $parametres
     * @param bool $inclureForces
     * @return array{lignes_recalculees: int, lignes_modifiees: int, lignes_prix_force_conservees: int, lignes_sans_sous_detail: int}
     */
    private function appliquer(Devis $devis, DevisParametres $parametres, bool $inclureForces): array
    {
        $lignes = LigneDevis::query()->where('devis_id', $devis->getKey())->with('composants')->get();

        $codes = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne->composants as $composant) {
                if ($composant->type === 'main_oeuvre' && $composant->qualification !== null) {
                    $codes[(string) $composant->qualification] = true;
                }
            }
        }
        $taux = $this->parametres->tauxPourCalcul($devis, $parametres, array_keys($codes));
        // Lignes d'avant ce lot : la qualification n'était pas notée, on la retrouve par son libellé.
        $codeParLibelle = DevisTauxHoraire::query()->where('devis_id', $devis->getKey())->pluck('code', 'libelle')->all();
        $difficulte = (float) $parametres->coefficient_difficulte;
        // Lot F2a : le revient d'une fourniture inclut les frais generaux copies dans le devis.
        $frais = 1 + (float) $parametres->frais_generaux_fournitures / 100;
        // Lot F2b : marge des fournitures du devis, pour les fournitures a prix calcule.
        $margeFournitures = $parametres->marge_fournitures;
        $fraisPct = (float) $parametres->frais_generaux_fournitures;

        $compteurs = [
            'lignes_recalculees' => 0,
            'lignes_modifiees' => 0,
            'lignes_prix_force_conservees' => 0,
            'lignes_sans_sous_detail' => 0,
        ];

        foreach ($lignes as $ligne) {
            if ($ligne->composants->isEmpty()) {
                $compteurs['lignes_sans_sous_detail']++;

                continue;
            }
            if ($ligne->prix_force && ! $inclureForces) {
                $compteurs['lignes_prix_force_conservees']++;

                continue;
            }

            $venteCts = 0;
            $revientCts = 0;
            $revientComplet = true;

            foreach ($ligne->composants as $composant) {
                if ($composant->type === 'main_oeuvre') {
                    $code = $composant->qualification ?? ($codeParLibelle[(string) $composant->taux_horaire_libelle] ?? null);
                    $tauxHoraire = $code !== null ? ($taux[(string) $code] ?? null) : null;

                    if ($tauxHoraire !== null) {
                        $base = $composant->quantite_base ?? $composant->quantite;
                        $composant->fill([
                            'quantite' => round((float) $base * $difficulte, 4),
                            'quantite_base' => $base,
                            'qualification' => (string) $code,
                            'prix_vente_ht' => $tauxHoraire->taux_vente_ht,
                            'prix_achat_ht' => $tauxHoraire->cout_revient_ht,
                            'taux_horaire_libelle' => $tauxHoraire->libelle,
                            'taux_horaire_vente' => $tauxHoraire->taux_vente_ht,
                            'cout_revient_horaire' => $tauxHoraire->cout_revient_ht,
                        ]);
                        if ($composant->isDirty()) {
                            $composant->save();
                        }
                    }
                }

                if ($composant->prix_vente_calcule && $margeFournitures !== null && $composant->prix_achat_ht !== null) {
                    $composant->prix_vente_ht = \App\Services\Bibliotheque\PrixVenteFourniture::calculer((float) $composant->prix_achat_ht, (float) $margeFournitures, $fraisPct);
                    if ($composant->isDirty()) {
                        $composant->save();
                    }
                }

                $venteCts += Arrondi::ligneEnCentimes($composant->quantite, $composant->prix_vente_ht ?? 0);
                if ($composant->prix_achat_ht === null) {
                    $revientComplet = false;
                } else {
                    $revientCts += Arrondi::ligneEnCentimes(
                        $composant->quantite,
                        in_array($composant->type, ['fourniture_base', 'fourniture_libre'], true)
                            ? (float) $composant->prix_achat_ht * $frais
                            : $composant->prix_achat_ht
                    );
                }
            }

            $ligne->prix_unitaire_ht = Arrondi::euros($venteCts);
            $ligne->prix_revient_unitaire = $revientComplet ? Arrondi::euros($revientCts) : null;
            $ligne->prix_force = false;

            $compteurs['lignes_recalculees']++;
            if ($ligne->isDirty()) {
                $compteurs['lignes_modifiees']++;
                $ligne->save();
            }
        }

        return $compteurs;
    }

    /**
     * @param array<string, int> $compteurs
     * @return string
     */
    private function message(array $compteurs): string
    {
        $recalculees = (int) ($compteurs['lignes_recalculees'] ?? 0);
        $conservees = (int) ($compteurs['lignes_prix_force_conservees'] ?? 0);

        $texte = $recalculees === 1 ? '1 ligne recalculée' : "{$recalculees} lignes recalculées";
        if ($conservees > 0) {
            $texte .= $conservees === 1 ? ', 1 ligne à prix forcé conservée' : ", {$conservees} lignes à prix forcé conservées";
        }

        return $texte . '.';
    }
}
