<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;

/**
 * Cree les reglages du CRM d'une entreprise : etapes du pipeline, sources
 * et motifs. Appelee a la creation de chaque entreprise et par la migration
 * pour les entreprises existantes.
 *
 * Idempotente : ne cree que ce qui manque (par code pour les etapes et les
 * sources, par type et libelle pour les motifs) et ne touche jamais a un
 * reglage deja modifie par l'entreprise.
 */
class InitialisationCrm
{
    /**
     * Etapes : code, nom, probabilite par defaut, type. L'ordre est celui de la liste.
     *
     * @var list<array{0: string, 1: string, 2: int, 3: string}>
     */
    public const ETAPES = [
        ['nouveau', 'Nouveau', 10, 'ouverte'],
        ['a_qualifier', 'À qualifier', 15, 'ouverte'],
        ['visite_planifiee', 'Visite technique planifiée', 25, 'ouverte'],
        ['visite_faite', 'Visite faite', 35, 'ouverte'],
        ['devis_en_cours', 'Devis en cours', 45, 'ouverte'],
        ['devis_envoye', 'Devis envoyé', 55, 'ouverte'],
        ['negociation', 'Négociation', 70, 'ouverte'],
        ['gagne', 'Gagné', 100, 'gagnee'],
        ['perdu', 'Perdu', 0, 'perdue'],
        ['sans_suite', 'Sans suite', 0, 'sans_suite'],
    ];

    /**
     * Sources : code, nom, systeme. Les cinq premiers codes reprennent les
     * anciennes valeurs de l'enumeration des clients.
     *
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    public const SOURCES = [
        ['bouche_a_oreille', 'Bouche-à-oreille', false],
        ['site_web', 'Site web', false],
        ['reseaux_sociaux', 'Réseaux sociaux', false],
        ['ancien_client', 'Ancien client', false],
        ['recommandation', 'Recommandation', false],
        ['autre', 'Autre', false],
        ['import_reprise', 'Import / reprise', true],
    ];

    /**
     * Motifs : type, libelle.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const MOTIFS = [
        ['perte', 'Prix'],
        ['perte', 'Délai'],
        ['perte', 'Concurrent'],
        ['perte', 'Projet abandonné'],
        ['perte', 'Financement refusé'],
        ['perte', 'Injoignable'],
        ['perte', 'Autre'],
        ['ecart_demande', 'Spam'],
        ['ecart_demande', 'Hors zone'],
        ['ecart_demande', 'Hors métier'],
        ['ecart_demande', 'Doublon'],
        ['ecart_demande', 'Injoignable'],
        ['ecart_demande', 'Autre'],
    ];

    /**
     * Cree les reglages manquants d'une entreprise.
     *
     * @param string $entrepriseId Identifiant de l'entreprise (tenants.id).
     * @return void
     */
    public static function pour(string $entrepriseId): void
    {
        $maintenant = now();

        foreach (self::ETAPES as $rang => [$code, $nom, $probabilite, $type]) {
            if (DB::table('etapes_pipeline')->where('tenant_id', $entrepriseId)->where('code', $code)->doesntExist()) {
                DB::table('etapes_pipeline')->insert([
                    'tenant_id' => $entrepriseId, 'code' => $code, 'nom' => $nom, 'systeme' => true,
                    'ordre' => $rang + 1, 'probabilite_defaut' => $probabilite, 'type' => $type,
                    'created_at' => $maintenant, 'updated_at' => $maintenant,
                ]);
            }
        }

        foreach (self::SOURCES as $rang => [$code, $nom, $systeme]) {
            if (DB::table('sources_leads')->where('tenant_id', $entrepriseId)->where('code', $code)->doesntExist()) {
                DB::table('sources_leads')->insert([
                    'tenant_id' => $entrepriseId, 'code' => $code, 'nom' => $nom, 'systeme' => $systeme,
                    'actif' => true, 'ordre' => $rang + 1,
                    'created_at' => $maintenant, 'updated_at' => $maintenant,
                ]);
            }
        }

        $ordres = [];
        foreach (self::MOTIFS as [$type, $libelle]) {
            $ordres[$type] = ($ordres[$type] ?? 0) + 1;
            $existe = DB::table('motifs')->where('tenant_id', $entrepriseId)->where('type', $type)->where('libelle', $libelle)->exists();
            if (! $existe) {
                DB::table('motifs')->insert([
                    'tenant_id' => $entrepriseId, 'type' => $type, 'libelle' => $libelle,
                    'actif' => true, 'ordre' => $ordres[$type],
                    'created_at' => $maintenant, 'updated_at' => $maintenant,
                ]);
            }
        }
    }
}
