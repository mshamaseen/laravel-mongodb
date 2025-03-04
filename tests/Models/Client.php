<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use MongoDB\Laravel\Eloquent\DocumentModel;

class Client extends Model
{
    use DocumentModel;

    protected $keyType = 'string';
    protected $connection = 'mongodb';
    protected $table = 'clients';
    protected static $unguarded = true;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function skillsWithCustomKeys()
    {
        return $this->belongsToMany(
            Skill::class,
            foreignPivotKey: 'cclient_ids',
            relatedPivotKey: 'cskill_ids',
            parentKey: 'cclient_id',
            relatedKey: 'cskill_id',
        );
    }

    public function photo(): MorphOne
    {
        return $this->morphOne(Photo::class, 'has_image');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'data.client_id', 'data.client_id');
    }

    public function labels()
    {
        return $this->morphToMany(Label::class, 'labelled');
    }

    public function labelsWithCustomKeys()
    {
        return $this->morphToMany(
            Label::class,
            'clabelled',
            'clabelleds',
            'cclabelled_id',
            'clabel_ids',
            'cclient_id',
            'clabel_id',
        );
    }
}
