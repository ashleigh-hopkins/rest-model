<?php namespace RestModel\Database\Rest\Relations;

class ComesWith extends ComesWithMany
{

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

                $results = $this->related->hydrate([$items]);

                $this->descriptor->eagerLoadRelations($results->all());

                $model->setRelation($relation, $results->first());

            }
        }

        return $models;
    }
}
