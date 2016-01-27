<?php namespace Database\Rest\Relations;

use Illuminate\Database\Eloquent\Collection;

class BelongsTo extends Relation
{

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        // TODO: Implement addConstraints() method.
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        // TODO: Implement addEagerConstraints() method.
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array $models
     * @param  string $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        // TODO: Implement initRelation() method.
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array $models
     * @param  \Illuminate\Database\Eloquent\Collection $results
     * @param  string $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        // TODO: Implement match() method.
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        // TODO: Implement getResults() method.
    }
}
