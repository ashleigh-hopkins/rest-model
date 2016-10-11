<?php namespace RestModel\Database\Rest\Relations;

use Illuminate\Database\Eloquent\Collection;

class ComesWithMany extends Relation
{
    protected $accessor;

    protected $requestName;

    public function __construct($descriptor, $parent, $accessor, $requestName)
    {
        parent::__construct($descriptor, $parent);

        $this->accessor = $accessor;

        $this->requestName = $requestName;
    }


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
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array $models
     * @return void
     */
    public function addPreConstraints($parentDescriptor)
    {
        $parentDescriptor->remoteLoad($this->requestName);
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
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    /**
     * Get the relationship for pre-loading.
     *
     * @param array $models
     * @return Collection
     */
    public function getEager()
    {
        return $this->parent->newCollection();
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array $models
     * @param  \Illuminate\Database\Eloquent\Collection $results
     * @param  string $relation
     * @return array
     */
    public function match(array $models, \Illuminate\Database\Eloquent\Collection $results, $relation)
    {
        foreach ($models as $model) {
            if (isset($model->{$this->accessor}) && $items = $model->{$this->accessor}) {
                unset($model->{$this->accessor});

                $model->syncOriginal();

                $results = $this->related->hydrate($items);

                $this->descriptor->eagerLoadRelations($results->all());

                $model->setRelation($relation, $results);
            }
        }

        return $models;
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
