<?php

namespace App\Notifications;

use App\Models\AlerteSeuil;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient la Direction et l'Administratif qu'une écriture ou un envoi de
 * devis a été refusé par un seuil. Envoyée au premier refus seulement : les
 * suivants sont regroupés dans la même alerte.
 */
class AlerteSeuilDevis extends Notification
{
    /**
     * @param AlerteSeuil $alerte Avec ses relations `devis` et `auteur`.
     * @param int $delaiHeures Délai de regroupement en vigueur.
     */
    public function __construct(public readonly AlerteSeuil $alerte, public readonly int $delaiHeures)
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
        return (new MailMessage())
            ->subject('Seuil dépassé sur le devis ' . $this->reference())
            ->greeting('Bonjour,')
            ->line($this->phrase())
            ->line($this->mesure())
            ->line(sprintf(
                'Les prochaines tentatives sur ce devis dans les %d heures seront regroupées dans cette alerte, sans nouvel e-mail.',
                $this->delaiHeures
            ))
            ->salutation(config('app.name'));
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
            'alerte_id' => $this->alerte->getKey(),
            'devis_id' => $this->alerte->devis_id,
            'devis_numero' => $this->alerte->devis?->numero,
            'devis_titre' => $this->alerte->devis?->titre,
            'auteur_id' => $this->alerte->user_id,
            'auteur_nom' => $this->alerte->auteur?->name,
            'type_seuil' => $this->alerte->type_seuil,
            'moment' => $this->alerte->moment,
            'valeur' => (float) $this->alerte->derniere_valeur,
            'seuil' => (float) $this->alerte->seuil,
            'message' => $this->phrase() . ' ' . $this->mesure(),
        ];
    }

    /**
     * @return string
     */
    private function reference(): string
    {
        return (string) ($this->alerte->devis?->numero ?? '#' . $this->alerte->devis_id);
    }

    /**
     * @return string
     */
    private function phrase(): string
    {
        return sprintf(
            '%s a tenté %s sur le devis %s%s : l\'application l\'a refusé.',
            $this->alerte->auteur?->name ?? 'Un utilisateur',
            $this->alerte->moment === 'envoi' ? 'un envoi' : 'une modification',
            $this->reference(),
            $this->alerte->devis?->titre ? ' « ' . $this->alerte->devis->titre . ' »' : ''
        );
    }

    /**
     * @return string
     */
    private function mesure(): string
    {
        $valeur = self::pourcent((float) $this->alerte->derniere_valeur);
        $seuil = self::pourcent((float) $this->alerte->seuil);

        return $this->alerte->type_seuil === 'remise_max'
            ? sprintf('Remises : %s %% du devis, pour un maximum de %s %%.', $valeur, $seuil)
            : sprintf('Marge : %s %%, pour un minimum de %s %%.', $valeur, $seuil);
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
