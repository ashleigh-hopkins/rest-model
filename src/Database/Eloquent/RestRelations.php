<?php namespace RestModel\Database\Eloquent;

use Illuminate\Support\Str;
use RestModel\Database\Rest\Model as RestModel;
use RestModel\Database\Rest\Relations\BelongsTo;
use RestModel\Database\Rest\Relations\BelongsToMany;
use RestModel\Database\Rest\Relations\ComesWith;
use RestModel\Database\Rest\Relations\ComesWithMany;
use RestModel\Database\Rest\Relations\HasMany;

trait RestRelations
{
    public function belongsTo($related, $foreignKey = null, $otherKey = null, $relation = null)
    {
        $instance = new $related;

        if ($instance instanceof RestModel) {
            // If no relation name was given, we will use this debug backtrace to extract
            // the calling method's name and use that as the relationship name as most
            // of the time this will be what we desire to use for the relationships.
            if (is_null($relation)) {
                list($current, $caller) = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

                $relation = $caller['function'];
            }

            // If no foreign key was supplied, we can use a backtrace to guess the proper
            // foreign key name by using the name of the relationship function, which
            // when combined with an "_id" should conventionally match the columns.
            if (is_null($foreignKey)) {
                $foreignKey = Str::snake($relation) . '_id';
            }

            // Once we have the foreign key names, we'll just create a new Eloquent query
            // for the related models and returns the relationship instance which will
            // actually be responsible for retrieving and hydrating every relations.
            $descriptor = $instance->newDescriptor();

            $otherKey = $otherKey ?: $instance->getKeyName();

            return new BelongsTo($descriptor, $this, $foreignKey, $otherKey, $relation);
        }

        return parent::belongsTo($related, $foreignKey, $otherKey, $relation);
    }

    public function restBelongsToMany($related, $foreignKey = null, $otherKey = null, $relation = null)
    {
        $instance = new $related;

        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            list($current, $caller) = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

            $relation = $caller['function'];
        }

        // If no foreign key was supplied, we can use a backtrace to guess the proper
        // foreign key name by using the name of the relationship function, which
        // when combined with an "_id" should conventionally match the columns.
        if (is_null($foreignKey)) {
            $foreignKey = Str::snake($relation) . '_id';
        }

        // Once we have the foreign key names, we'll just create a new Eloquent query
        // for the related models and returns the relationship instance which will
        // actually be responsible for retrieving and hydrating every relations.
        $descriptor = $instance->newDescriptor();

        $otherKey = $otherKey ?: $instance->getKeyName();

        return new BelongsToMany($descriptor, $this, $foreignKey, $otherKey, $relation);
    }

    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $instance = new $related;

        if ($instance instanceof RestModel) {
            $foreignKey = $foreignKey ?: $this->getForeignKey();

            $localKey = $localKey ?: $this->getKeyName();

            return new HasMany($instance->newDescriptor(), $this, $foreignKey, $localKey);
        }

        return parent::hasMany($related, $foreignKey, $localKey);
    }

    public function comesWith($related, $accessor = null, $requestName = null)
    {
        if (is_null($accessor)) {
            list(, $caller) = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

            $accessor = $caller['function'];
        }

        if (is_null($requestName)) {
            $requestName = $accessor;
        }

        $instance = new $related;

        return new ComesWith($instance->newDescriptor(), $this, $accessor, $requestName);
    }

    public function comesWithMany($related, $accessor = null, $requestName = null)
    {
        if (is_null($accessor)) {
            list(, $caller) = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

            $accessor = $caller['function'];
        }

        if (is_null($requestName)) {
            $requestName = $accessor;
        }

        $instance = new $related;

        return new ComesWithMany($instance->newDescriptor(), $this, $accessor, $requestName);
    }
}
