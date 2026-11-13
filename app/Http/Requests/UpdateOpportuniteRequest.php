<?php

namespace App\Http\Requests;

/**
 * Modification d'une affaire : memes regles, tous les champs facultatifs.
 * Le client et l'etape ne changent pas ici (l'etape a sa propre route).
 */
class UpdateOpportuniteRequest extends StoreOpportuniteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->regles(false);
    }
}
