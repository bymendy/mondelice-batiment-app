<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Source d'un client ou d'une affaire (d'ou vient le contact) : liste
 * parametrable par entreprise. La source « Import / reprise » est une source
 * systeme, non supprimable.
 *
 * Le code et le caractere systeme ne sont jamais modifiables par l'API.
 *
 * @property int $id
 * @property string|null $code
 * @property string $nom
 * @property bool $systeme
 * @property bool $actif
 * @property int $ordre
 */
class Source extends Model
{
    use BelongsToTenant;

    protected $table = 'sources_leads';

    protected $fillable = [
        'nom',
        'actif',
        'ordre',
    ];

    protected $casts = [
        'systeme' => 'boolean',
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];
}
