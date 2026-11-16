<?php

namespace App\Http\Requests;

/**
 * Modification d'un devis : le client ne change pas.
 */
class UpdateDevisRequest extends StoreDevisRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->regles(false);
    }
}
