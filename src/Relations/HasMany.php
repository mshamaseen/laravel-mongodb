<?php

namespace Jenssegers\Mongodb\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use MongoDB\BSON\ObjectId;

class HasMany extends EloquentHasMany
{
    /**
     * Get the plain foreign key.
     *
     * @return string
     */
    public function getForeignKeyName()
    {
        return $this->foreignKey;
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     *
     * @return string
     */
    public function getHasCompareKey()
    {
        return $this->getForeignKeyName();
    }

    /**
     * @inheritdoc
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $foreignKey = $this->getHasCompareKey();

        return $query->select($foreignKey)->where($foreignKey, 'exists', true);
    }

    /**
     * Get the name of the "where in" method for eager loading.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $key
     * @return string
     */
    protected function whereInMethod(EloquentModel $model, $key)
    {
        return 'whereIn';
    }

    public function addConstraints(): void
    {
        if (static::$constraints) {
            $query = $this->getRelationQuery();

            // use ObjectId
            $key = new ObjectId($this->getParentKey());

            $query->where($this->foreignKey, '=', $key);

            $query->whereNotNull($this->foreignKey);
        }
    }
}
