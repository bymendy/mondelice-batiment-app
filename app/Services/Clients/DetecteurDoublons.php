<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Support\Telephone;

/**
 * Cherche les fiches clients qui ressemblent a une fiche en cours de saisie :
 * meme e-mail, meme telephone (tous formats), meme SIRET ou meme adresse.
 *
 * Ne bloque rien : l'application propose de rattacher plutot que de creer.
 * Les fiches archivees sont ignorees. La recherche est limitee a l'entreprise
 * courante par le modele Client lui-meme.
 */
class DetecteurDoublons
{
    /**
     * Nombre maximal de fiches renvoyees.
     */
    private const LIMITE = 20;

    /**
     * @param array{email?: string|null, telephone?: string|null, siret?: string|null, ligne1?: string|null, code_postal?: string|null} $criteres
     * @param int|null $ignorerId Fiche a exclure (cas de la modification d'une fiche existante).
     * @return list<array{id: int, nom: string, type: string, raisons: list<string>}>
     */
    public function chercher(array $criteres, ?int $ignorerId = null): array
    {
        $trouves = [];

        $email = isset($criteres['email']) ? mb_strtolower(trim((string) $criteres['email'])) : '';
        if ($email !== '') {
            $this->ajouter($trouves, Client::query()->whereRaw('lower(email) = ?', [$email]), 'email', $ignorerId);
        }

        $telephone = Telephone::normaliser($criteres['telephone'] ?? null);
        if ($telephone !== null) {
            $this->ajouter($trouves, Client::query()->where('telephone_normalise', $telephone), 'telephone', $ignorerId);
        }

        $siret = preg_replace('/\D/', '', (string) ($criteres['siret'] ?? '')) ?? '';
        if ($siret !== '') {
            $this->ajouter($trouves, Client::query()->where('siret', $siret), 'siret', $ignorerId);
        }

        $ligne1 = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($criteres['ligne1'] ?? '')) ?? ''));
        $codePostal = trim((string) ($criteres['code_postal'] ?? ''));
        if ($ligne1 !== '' && $codePostal !== '') {
            $requete = Client::query()->whereHas(
                'adresses',
                fn ($a) => $a->whereRaw("lower(regexp_replace(trim(ligne1), '\\s+', ' ', 'g')) = ?", [$ligne1])->where('code_postal', $codePostal)
            );
            $this->ajouter($trouves, $requete, 'adresse', $ignorerId);
        }

        return array_slice(array_values($trouves), 0, self::LIMITE);
    }

    /**
     * Ajoute les fiches d'une requete au resultat, en cumulant les raisons.
     *
     * @param array<int, array{id: int, nom: string, type: string, raisons: list<string>}> $trouves
     * @param \Illuminate\Database\Eloquent\Builder<Client> $requete
     * @param string $raison
     * @param int|null $ignorerId
     * @return void
     */
    private function ajouter(array &$trouves, $requete, string $raison, ?int $ignorerId): void
    {
        if ($ignorerId !== null) {
            $requete->whereKeyNot($ignorerId);
        }

        foreach ($requete->limit(self::LIMITE)->get() as $client) {
            $trouves[$client->id] ??= [
                'id' => $client->id,
                'nom' => $client->nomAffiche(),
                'type' => $client->type->value,
                'raisons' => [],
            ];
            $trouves[$client->id]['raisons'][] = $raison;
        }
    }
}
