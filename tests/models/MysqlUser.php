<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\Schema;
use Jenssegers\Mongodb\Eloquent\HybridRelations;

class MysqlUser extends Eloquent
{
    use HybridRelations;

    protected $connection = 'mysql';
    protected $table = 'users';
    protected static $unguarded = true;

    public function books(): HasMany
    {
        return $this->hasMany('Book', 'author_id');
    }

    public function role(): HasOne
    {
        return $this->hasOne('Role');
    }

    public function mysqlBooks(): HasMany
    {
        return $this->hasMany(MysqlBook::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(MysqlGroup::class, 'group_user', 'user_id', 'group_id');
    }

    /**
     * Check if we need to run the schema.
     */
    public static function executeSchema(): void
    {
        /** @var MySqlBuilder $schema */
        $schema = Schema::connection('mysql');

        if (! $schema->hasTable('users')) {
            Schema::connection('mysql')->create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }
    }
}
