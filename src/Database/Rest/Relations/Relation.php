<?php namespace Database\Rest\Relations;

use Database\Rest\Client;
use Database\Rest\Descriptors\Contracts\Descriptor;
use Database\Rest\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

abstract class Relation extends \Illuminate\Database\Eloquent\Relations\Relation
{
    /**
     * The Eloquent query builder instance.
     *
     * @var Client
     */
    protected $descriptor;

    /**
     * The parent model instance.
     *
     * @var Model
     */
    protected $parent;

    /**
     * The related model instance.
     *
     * @var Model
     */
    protected $related;

    /**
     * Indicates if the relation is adding constraints.
     *
     * @var bool
     */
    protected static $constraints = false;

    /**
     * Create a new relation instance.
     *
     * @param Descriptor $descriptor
     * @param Model $parent
     */
    public function __construct($descriptor, $parent)
    {
        $this->descriptor = $descriptor;
        $this->parent = $parent;
        $this->related = $descriptor->getModel();
    }

    /**
     * Get the relationship for eager loading.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getEager()
    {

    }

    /**
     * Touch all of the related models for the relationship.
     *
     * @return void
     */
    public function touch()
    {

    }

    /**
     * Run a raw update against the base query.
     *
     * @param  array  $attributes
     * @return int
     */
    public function rawUpdate(array $attributes = [])
    {
        return $this->descriptor->update($this->related->getKey(), $attributes);
    }

    /**
     * Add the constraints for a relationship count query.
     *
     * @param  $query
     * @param  $parent
     * @return null
     */
    public function getRelationCountQuery(Builder $_ = null, Builder $__ = null, Client $query = null, Client $parent = null)
    {
        //return $this->getRelationQuery($query, $parent, new Expression('count(*)'));
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parent
     * @param  array|mixed $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationQuery(Builder $query, Builder $parent, $columns = ['*'])
    {
        $query->select($columns);

        $key = $this->wrap($this->getQualifiedParentKeyName());

        return $query->where($this->getHasCompareKey(), '=', new Expression($key));
    }

    /**
     * Get the underlying query for the relation.
     *
     * @return Client
     */
    public function getDescriptor()
    {
        return $this->descriptor;
    }

    /**
     * Get the underlying query for the relation.
     *
     * @return Client
     */
    public function getQuery()
    {
        return $this->descriptor;
    }

    /**
     * Get the base query builder driving the Eloquent builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function getBaseQuery()
    {
        return $this->descriptor;
    }

    /**
     * Get the parent model of the relation.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Get the fully qualified parent key name.
     *
     * @return string
     */
    public function getQualifiedParentKeyName()
    {
        return $this->parent->getKeyName();
    }

    /**
     * Get the related model of the relation.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getRelated()
    {
        return $this->related;
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function createdAt()
    {
        return null;
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function updatedAt()
    {
        return null;
    }

    /**
     * Get the name of the related model's "updated at" column.
     *
     * @return string
     */
    public function relatedUpdatedAt()
    {
        return null;
    }

    /**
     * Wrap the given value with the parent query's grammar.
     *
     * @param  string  $value
     * @return string
     */
    public function wrap($value)
    {
        return $value;
    }

    /**
     * Set or get the morph map for polymorphic relations.
     *
     * @param  array|null  $map
     * @param  bool  $merge
     * @return array
     */
    public static function morphMap(array $map = null, $merge = true)
    {
        $map = static::buildMorphMapFromModels($map);

        if (is_array($map)) {
            static::$morphMap = $merge ? array_merge(static::$morphMap, $map) : $map;
        }

        return static::$morphMap;
    }

    /**
     * Builds a table-keyed array from model class names.
     *
     * @param  string[]|null  $models
     * @return array|null
     */
    protected static function buildMorphMapFromModels(array $models = null)
    {
        if (is_null($models) || Arr::isAssoc($models)) {
            return $models;
        }

        $tables = array_map(function ($model) {
            return (new $model)->getEndpoint();
        }, $models);

        return array_combine($tables, $models);
    }

    /**
     * Handle dynamic method calls to the relationship.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        $result = call_user_func_array([$this->descriptor, $method], $parameters);

        if ($result === $this->descriptor) {
            return $this;
        }

        return $result;
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     *
     * @return void
     */
    public function __clone()
    {
        $this->descriptor = clone $this->descriptor;
    }
}
