<?php

namespace App\Notifications;

use App\Models\DerogationDevis;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient la Direction d'une demande de dérogation, puis le demandeur de la
 * réponse (lot D4c). À l'écran et par e-mail.
 */
class DerogationDevisNotification extends Notification
{
    /**
     * @param DerogationDevis $derogation Avec ses relations `devis`, `demandeur` et `decideur`.
     * @param string $evenement `demandee`, `acceptee` ou `refusee`.
     */
    public function __construct(public readonly DerogationDevis $derogation, public readonly string $evenement)
    {
    }

    /**
     * @param object $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @param object $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject($this->sujet())
            ->greeting('Bonjour,')
            ->line($this->phrase())
            ->line($this->mesure());

        if ($this->evenement === 'demandee') {
            $message->line('Motif : ' . $this->derogation->motif);
        } elseif ($this->derogation->motif_decision) {
            $message->line('Réponse : ' . $this->derogation->motif_decision);
        }

        return $message->salutation(config('app.name'));
    }

    /**
     * Contenu de l'alerte à l'écran.
     *
     * @param object $notifiable
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'derogation_id' => $this->derogation->getKey(),
            'evenement' => $this->evenement,
            'devis_id' => $this->derogation->devis_id,
            'devis_numero' => $this->derogation->devis?->numero,
            'devis_titre' => $this->derogation->devis?->titre,
            'type_seuil' => $this->derogation->type_seuil,
            'statut' => $this->derogation->statut,
            'message' => $this->phrase() . ' ' . $this->mesure(),
        ];
    }

    /**
     * @return string
     */
    private function reference(): string
    {
        return (string) ($this->derogation->devis?->numero ?? '#' . $this->derogation->devis_id);
    }

    /**
     * @return string
     */
    private function sujet(): string
    {
        return match ($this->evenement) {
            'acceptee' => 'Dérogation acceptée sur le devis ' . $this->reference(),
            'refusee' => 'Dérogation refusée sur le devis ' . $this->reference(),
            default => 'Demande de dérogation sur le devis ' . $this->reference(),
        };
    }

    /**
     * @return string
     */
    private function phrase(): string
    {
        $titre = $this->derogation->devis?->titre ? ' « ' . $this->derogation->devis->titre . ' »' : '';

        return match ($this->evenement) {
            'acceptee' => sprintf('%s a accepté votre demande de dérogation sur le devis %s%s.', $this->derogation->decideur?->name ?? 'La Direction', $this->reference(), $titre),
            'refusee' => sprintf('%s a refusé votre demande de dérogation sur le devis %s%s.', $this->derogation->decideur?->name ?? 'La Direction', $this->reference(), $titre),
            default => sprintf('%s demande une dérogation sur le devis %s%s.', $this->derogation->demandeur?->name ?? 'Un utilisateur', $this->reference(), $titre),
        };
    }

    /**
     * @return string
     */
    private function mesure(): string
    {
        $remise = $this->derogation->type_seuil === 'remise_max';
        $quoi = $remise ? 'Remises autorisées jusqu\'à' : 'Marge autorisée jusqu\'à';
        $seuil = self::pourcent((float) $this->derogation->seuil);

        if ($this->evenement === 'acceptee') {
            return sprintf('%s %s %% sur ce devis (seuil de l\'entreprise : %s %%).', $quoi, self::pourcent((float) $this->derogation->valeur_accordee), $seuil);
        }

        return sprintf('Demande : %s %s %% (seuil de l\'entreprise : %s %%).', lcfirst($quoi), self::pourcent((float) $this->derogation->valeur_demandee), $seuil);
    }

    /**
     * @param float $valeur
     * @return string
     */
    private static function pourcent(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 2, ',', ''), '0'), ',');
    }
}
