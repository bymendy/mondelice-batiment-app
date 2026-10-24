<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un document attaché à n'importe quelle autre entité (polymorphique).
 *
 * Le fichier physique est stocké en S3-compatible ; cette table n'en
 * garde que la référence (path, nom original, type MIME, taille).
 */
class Document extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'status',
    ];

    /**
     * L'entité à laquelle ce document est attaché (client, devis, chantier...).
     *
     * @return MorphTo<Model, Document>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
