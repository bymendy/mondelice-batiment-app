<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\DevisParametres;
use App\Models\DevisTauxHoraire;
use App\Models\LigneDevis;
use App\Models\Parametre;
use App\Models\TauxHoraire;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres du devis (décisions du 20 novembre 2026).
 *
 *  - À la création, le devis copie les réglages de l'entreprise : taux
 *    horaires, TVA par défaut, marge des fournitures. Un avenant copie ceux
 *    de son devis d'origine.
 *  - Ces valeurs s'ajustent pour ce devis seulement ; l'entreprise n'est
 *    jamais modifiée. Un changement ne touche que les lignes ajoutées ensuite.
 *  - Difficulté : coefficient de 1,00 à 1,50 (pas de 0,05) qui multiplie le
 *    TEMPS de main-d'œuvre : le prix de vente et le prix de revient montent
 *    ensemble.
 *  - Zone : coefficient préparé, toujours 1,00. Il multipliera le taux
 *    horaire de VENTE (pas le coût de revient) le jour où il sera activé.
 *  - Un devis déjà envoyé avant ce lot n'a pas de paramètres : on ne lui
 *    invente pas les taux d'aujourd'hui.
 */
class ParametresDevis
{
    public const DIFFICULTE_MIN = 1.0;

    public const DIFFICULTE_MAX = 1.5;

    public const DIFFICULTE_PAS = 0.05;

    /**
     * Paramètres du devis. Un devis encore modifiable qui n'en a pas les
     * reçoit ici ; un devis figé sans paramètres renvoie null.
     *
     * @param Devis $devis
     * @return DevisParametres|null
     */
    public function pour(Devis $devis): ?DevisParametres
    {
        $parametres = DevisParametres::query()->where('devis_id', $devis->getKey())->first();
        if ($parametres !== null) {
            return $parametres;
        }

        return AccesDevis::estModifiable($devis) ? $this->creer($devis) : null;
    }

    /**
     * Copie les paramètres dans le devis : ceux du devis d'origine pour un
     * avenant, sinon ceux de l'entreprise. Sans effet s'ils existent déjà.
     *
     * @param Devis $devis
     * @return DevisParametres
     */
    public function creer(Devis $devis): DevisParametres
    {
        $existants = DevisParametres::query()->where('devis_id', $devis->getKey())->first();
        if ($existants !== null) {
            return $existants;
        }

        return DB::transaction(function () use ($devis): DevisParametres {
            $origine = $devis->devis_parent_id !== null
                ? DevisParametres::query()->where('devis_id', $devis->devis_parent_id)->first()
                : null;

            if ($origine !== null) {
                $parametres = DevisParametres::create([
                    'devis_id' => $devis->getKey(),
                    'coefficient_difficulte' => $origine->coefficient_difficulte,
                    'coefficient_zone' => $origine->coefficient_zone,
                    'tva_defaut' => $origine->tva_defaut,
                    'tva_source' => $origine->tva_source,
                    'marge_fournitures' => $origine->marge_fournitures,
                    'frais_generaux_fournitures' => $origine->frais_generaux_fournitures,
                ]);
                $lignes = DevisTauxHoraire::query()->where('devis_id', $devis->devis_parent_id)->orderBy('ordre')->orderBy('id')->get();
                foreach ($lignes as $taux) {
                    DevisTauxHoraire::create([
                        'devis_id' => $devis->getKey(),
                        'code' => $taux->code,
                        'libelle' => $taux->libelle,
                        'taux_vente_ht' => $taux->taux_vente_ht,
                        'taux_vente_entreprise' => $taux->taux_vente_entreprise,
                        'cout_revient_ht' => $taux->cout_revient_ht,
                        'source' => $taux->source,
                        'ordre' => $taux->ordre,
                    ]);
                }

                return $parametres;
            }

            $parametres = DevisParametres::create([
                'devis_id' => $devis->getKey(),
                'coefficient_difficulte' => 1,
                'coefficient_zone' => 1,
                'tva_defaut' => (float) Parametre::lire('tva_defaut'),
                'tva_source' => 'entreprise',
                'marge_fournitures' => Parametre::lire('marge_fournitures_defaut'),
                'frais_generaux_fournitures' => (float) Parametre::lire('frais_generaux_fournitures'),
            ]);
            foreach (TauxHoraire::query()->orderBy('ordre')->orderBy('id')->get() as $taux) {
                $this->copierTaux($devis, $taux);
            }

            return $parametres;
        });
    }

    /**
     * TVA d'une ligne qui n'en donne pas : celle du devis, sinon celle de
     * l'entreprise.
     *
     * @param Devis $devis
     * @return float
     */
    public function tvaParDefaut(Devis $devis): float
    {
        $parametres = $this->pour($devis);

        return (float) ($parametres !== null ? $parametres->tva_defaut : Parametre::lire('tva_defaut'));
    }

    /**
     * Taux horaires du devis pour calculer une ligne, indexés par code, au
     * format attendu par CalculPrixArticle. Une qualification créée dans
     * l'entreprise après le devis est copiée à ce moment-là.
     *
     * Le coefficient de zone multiplie ici le taux de vente (1,00 pour l'instant).
     *
     * @param Devis $devis
     * @param DevisParametres $parametres
     * @param iterable<string> $codes Qualifications dont la ligne a besoin.
     * @return array<string, object{libelle: string, taux_vente_ht: float, cout_revient_ht: float|null}>
     */
    public function tauxPourCalcul(Devis $devis, DevisParametres $parametres, iterable $codes): array
    {
        $taux = DevisTauxHoraire::query()->where('devis_id', $devis->getKey())->get()->keyBy('code');

        foreach ($codes as $code) {
            $code = (string) $code;
            if ($code === '' || $taux->has($code)) {
                continue;
            }
            $entreprise = TauxHoraire::query()->where('code', $code)->first();
            if ($entreprise !== null) {
                $taux->put($code, $this->copierTaux($devis, $entreprise));
            }
        }

        $zone = (float) $parametres->coefficient_zone;
        $resultat = [];
        foreach ($taux as $code => $ligne) {
            $resultat[(string) $code] = (object) [
                'libelle' => (string) $ligne->libelle,
                'taux_vente_ht' => round((float) $ligne->taux_vente_ht * $zone, 2),
                'cout_revient_ht' => $ligne->cout_revient_ht === null ? null : (float) $ligne->cout_revient_ht,
            ];
        }

        return $resultat;
    }

    /**
     * Modifie les paramètres d'un devis (déjà vérifié modifiable).
     *
     * @param Devis $devis
     * @param array<string, mixed> $donnees Champs validés : coefficient_difficulte,
     *        tva_defaut, taux_horaires[{code, taux_vente_ht}].
     * @return array{avant: array<string, mixed>, apres: array<string, mixed>} Ce qui a changé.
     *
     * @throws ValidationException Si une qualification n'existe pas dans le devis.
     */
    public function modifier(Devis $devis, array $donnees): array
    {
        $parametres = $this->pour($devis) ?? $this->creer($devis);

        return DB::transaction(function () use ($devis, $parametres, $donnees): array {
            $avant = [];
            $apres = [];

            if (array_key_exists('coefficient_difficulte', $donnees)
                && ! $this->egal($donnees['coefficient_difficulte'], $parametres->coefficient_difficulte)) {
                $avant['coefficient_difficulte'] = (float) $parametres->coefficient_difficulte;
                $apres['coefficient_difficulte'] = round((float) $donnees['coefficient_difficulte'], 2);
                $parametres->coefficient_difficulte = $apres['coefficient_difficulte'];
            }

            if (array_key_exists('tva_defaut', $donnees) && ! $this->egal($donnees['tva_defaut'], $parametres->tva_defaut)) {
                $avant['tva_defaut'] = (float) $parametres->tva_defaut;
                $apres['tva_defaut'] = round((float) $donnees['tva_defaut'], 2);
                $parametres->tva_defaut = $apres['tva_defaut'];
                $parametres->tva_source = $this->egal($apres['tva_defaut'], Parametre::lire('tva_defaut')) ? 'entreprise' : 'modifie';
            }

            $parametres->save();

            foreach ($donnees['taux_horaires'] ?? [] as $rang => $demande) {
                $taux = DevisTauxHoraire::query()
                    ->where('devis_id', $devis->getKey())
                    ->where('code', (string) $demande['code'])
                    ->first();
                if ($taux === null) {
                    throw ValidationException::withMessages([
                        "taux_horaires.{$rang}.code" => 'Cette qualification n\'existe pas dans ce devis.',
                    ]);
                }
                if ($this->egal($demande['taux_vente_ht'], $taux->taux_vente_ht)) {
                    continue;
                }
                $avant['taux_horaires'][$taux->code] = (float) $taux->taux_vente_ht;
                $apres['taux_horaires'][$taux->code] = round((float) $demande['taux_vente_ht'], 2);
                $taux->taux_vente_ht = $apres['taux_horaires'][$taux->code];
                $taux->source = $this->egal($taux->taux_vente_ht, $taux->taux_vente_entreprise) ? 'entreprise' : 'modifie';
                $taux->save();
            }

            return ['avant' => $avant, 'apres' => $apres];
        });
    }

    /**
     * Ce que l'API renvoie des paramètres d'un devis. Les coûts de revient
     * et la marge des fournitures ne sortent qu'avec devis.voir_marge.
     *
     * @param Devis $devis
     * @param User|null $utilisateur
     * @return array<string, mixed>
     */
    public function presenter(Devis $devis, ?User $utilisateur): array
    {
        $parametres = $this->pour($devis);
        $voitMarge = PresentationDevis::voitMarge($utilisateur);

        $base = [
            'devis_id' => $devis->getKey(),
            'modifiable' => $parametres !== null && AccesDevis::estModifiable($devis),
            'bornes' => [
                'coefficient_difficulte' => [
                    'min' => self::DIFFICULTE_MIN,
                    'max' => self::DIFFICULTE_MAX,
                    'pas' => self::DIFFICULTE_PAS,
                ],
            ],
        ];

        if ($parametres === null) {
            return array_merge($base, ['parametres' => null, 'taux_horaires' => [], 'effet_difficulte' => null]);
        }

        $valeurs = [
            'coefficient_difficulte' => (float) $parametres->coefficient_difficulte,
            'coefficient_zone' => (float) $parametres->coefficient_zone,
            'tva_defaut' => (float) $parametres->tva_defaut,
            'tva_source' => (string) $parametres->tva_source,
        ];
        if ($voitMarge) {
            $valeurs['marge_fournitures'] = $parametres->marge_fournitures === null ? null : (float) $parametres->marge_fournitures;
            $valeurs['frais_generaux_fournitures'] = (float) $parametres->frais_generaux_fournitures;
        }

        $taux = DevisTauxHoraire::query()
            ->where('devis_id', $devis->getKey())
            ->orderBy('ordre')
            ->orderBy('id')
            ->get()
            ->map(function (DevisTauxHoraire $ligne) use ($voitMarge): array {
                $sortie = [
                    'code' => $ligne->code,
                    'libelle' => $ligne->libelle,
                    'taux_vente_ht' => (float) $ligne->taux_vente_ht,
                    'taux_vente_entreprise' => (float) $ligne->taux_vente_entreprise,
                    'source' => $ligne->source,
                ];
                if ($voitMarge) {
                    $sortie['cout_revient_ht'] = $ligne->cout_revient_ht === null ? null : (float) $ligne->cout_revient_ht;
                }

                return $sortie;
            })
            ->all();

        return array_merge($base, [
            'parametres' => $valeurs,
            'taux_horaires' => $taux,
            'effet_difficulte' => $this->effetDifficulte($devis),
        ]);
    }

    /**
     * Effet de la difficulté sur le devis, en heures et en euros de vente
     * HT : ce que les lignes portent en plus de leur temps de base. Les
     * lignes à prix forcé ne comptent pas (leur prix ne suit pas le temps).
     *
     * @param Devis $devis
     * @return array{heures: float, vente_ht: float}
     */
    private function effetDifficulte(Devis $devis): array
    {
        $heures = 0.0;
        $centimes = 0;

        $lignes = LigneDevis::query()
            ->where('devis_id', $devis->getKey())
            ->where('prix_force', false)
            ->with('composants')
            ->get();

        foreach ($lignes as $ligne) {
            foreach ($ligne->composants as $composant) {
                if ($composant->type !== 'main_oeuvre' || $composant->quantite_base === null) {
                    continue;
                }
                $supplement = (float) $composant->quantite - (float) $composant->quantite_base;
                if (abs($supplement) < 0.00005) {
                    continue;
                }
                $heures += $supplement * (float) $ligne->quantite;
                $centimes += (int) round($supplement * (float) $composant->prix_vente_ht * (float) $ligne->quantite * 100);
            }
        }

        return ['heures' => round($heures, 2), 'vente_ht' => $centimes / 100];
    }

    /**
     * @param Devis $devis
     * @param TauxHoraire $taux
     * @return DevisTauxHoraire
     */
    private function copierTaux(Devis $devis, TauxHoraire $taux): DevisTauxHoraire
    {
        return DevisTauxHoraire::create([
            'devis_id' => $devis->getKey(),
            'code' => $taux->code,
            'libelle' => $taux->libelle,
            'taux_vente_ht' => $taux->taux_vente_ht,
            'taux_vente_entreprise' => $taux->taux_vente_ht,
            'cout_revient_ht' => $taux->cout_revient_ht,
            'source' => 'entreprise',
            'ordre' => $taux->ordre,
        ]);
    }

    /**
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private function egal(mixed $a, mixed $b): bool
    {
        return abs((float) $a - (float) $b) < 0.005;
    }
}
