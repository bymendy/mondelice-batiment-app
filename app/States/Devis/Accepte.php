<?php

namespace App\States\Devis;

/**
 * Devis accepté par le client — statut final positif.
 * Famille de badge UI : Positif / validé (vert, verrouillé niveau système,
 * non personnalisable en marque blanche SaaS).
 *
 * Déclenche AUTO-02 (tâche 3.9) : création du Chantier + acompte + dossier
 * documents. Aucune transition sortante déclarée : statut final, modifiable
 * uniquement par un rôle supérieur si une correction manuelle est needed
 * (règle générale des statuts finaux, section 7 du document de référence).
 */
class Accepte extends DevisState
{
    public function label(): string
    {
        return 'Accepté';
    }
}
