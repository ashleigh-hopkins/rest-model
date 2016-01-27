<?php namespace Database\Rest\Relations;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\Collection;

class BelongsTo extends Relation
{
    protected $otherKey;

    protected $relation;

    protected $foreignKey;

    /**
     * @var PromiseInterface[]
     */
    protected $pendingQueries = [];

    public function __construct($client, $parent, $foreignKey, $otherKey, $relation)
    {
        $this->otherKey = $otherKey;
        $this->relation = $relation;
        $this->foreignKey = $foreignKey;

        parent::__construct($client, $parent);
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
        $ids = [];

        foreach ($models as $model)
        {
            $attribute = $model->getAttribute($this->foreignKey);

            if($attribute !== null)
            {
                $ids[] = $attribute;
            }
        }

        if($this->related->getClientHasFilter())
        {
            $this->client->query("filter[{$this->otherKey}]", $ids);
            $this->pendingQueries[] = $this->client->indexAsync();
        }
        else
        {
            foreach ($ids as $id)
            {
                $this->pendingQueries[$id] = $this->client->showAsync($id);
            }
        }
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
     * Get the relationship for eager loading.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getEager()
    {
        if($this->related->getClientHasFilter())
        {
            $items = $this->pendingQueries[0]->wait();
        }
        else
        {
            $items = [];

            foreach ($this->pendingQueries as $query)
            {
                $item = $query->wait(true);

                if($item !== null)
                {
                    $items[] = $item;
                }
            }
        }

        return new Collection($items);
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
        $foreign = $this->foreignKey;

        $other = $this->otherKey;

        // First we will get to build a dictionary of the child models by their primary
        // key of the relationship, then we can easily match the children back onto
        // the parents using that dictionary and the primary key of the children.
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->getAttribute($other)] = $result;
        }

        // Once we have the dictionary constructed, we can loop through all the parents
        // and match back onto their children using these keys of the dictionary and
        // the primary key of the children to map them onto the correct instances.
        foreach ($models as $model) {
            if (isset($dictionary[$model->$foreign])) {
                $model->setRelation($relation, $dictionary[$model->$foreign]);
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
