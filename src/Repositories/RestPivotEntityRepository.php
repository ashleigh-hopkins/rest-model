<?php namespace RestModel\Repositories;

use RestModel\Database\Rest\Descriptors\Contracts\Descriptor;
use RestModel\Database\Rest\Model;

abstract class RestPivotEntityRepository extends RestEntityRepository
{
    protected $parentModel;

    protected $relation;

    protected $relationRaw;

    public function __construct(Model $model, Model $parentModel, $relation, $relationRaw = null)
    {
        parent::__construct($model);

        $this->parentModel = $parentModel;
        $this->relation = $relation;
        $this->relationRaw = $relationRaw ?: "{$relation}Raw";
    }

    /**
     * @param int|object|Model $parent
     * @return object[]|Model[]
     */
    public function allForParent($parent)
    {
        $parent = $this->getParentModel($parent);

        return $parent->{$this->relationRaw}()->get();
    }

    /**
     * @param int|object|array|Model $mixed
     * @return Model
     */
    protected function getParentModel($mixed)
    {
        if ($mixed instanceof Model == false) {
            if (is_numeric($mixed) == false) {
                $mixed = data_get($mixed, 'id');
            }

            return $this->parentModel->newInstance(['id' => $mixed], true);
        }

        return $mixed;
    }

    /**
     * @param object|Model $parent
     * @param int|object|Model $object
     * @return Model
     */
    public function deleteForParent($object, $parent)
    {
        $parent = $this->getParentModel($parent);

        if ($object instanceof Model == false) {
            $object = $this->getForParent($object, $parent);
        }

        $relation = $parent->{$this->relation}();

        // get the "other key"
        $e = explode('.', $relation->getOtherKey());
        $key = end($e);

        $relation->detach($object->{$key});

        return $object;
    }

    /**
     * @param $id
     * @param object|Model $parent
     * @return object|Model
     */
    public function getForParent($id, $parent)
    {
        $parent = $this->getParentModel($parent);

        $relation = $parent->{$this->relation}();
        $relationRaw = $parent->{$this->relationRaw}();

        return $relationRaw->where([$relation->getOtherKey() => $id])->firstOrFail();
    }

    /**
     * @param object|Model $parent
     * @param int $perPage
     * @return \Illuminate\Pagination\Paginator
     */
    public function paginateForParent($parent, $perPage = null)
    {
        $parent = $this->getParentModel($parent);

        return $parent->{$this->relationRaw}()->paginate($perPage);
    }

    /**
     * @param object|Model $parent
     * @return Descriptor
     */
    public function queryForParent($parent)
    {
        return $this->descriptorForParent($parent);
    }

    /**
     * @param object|Model $parent
     * @return Descriptor
     */
    public function descriptorForParent($parent)
    {
        $parent = $this->getParentModel($parent);

        return $parent->{$this->relation}()->newDescriptor();
    }

    /**
     * @param object|Model $parent
     * @return Descriptor
     */
    public function descriptorForParentPivot($parent)
    {
        $parent = $this->getParentModel($parent);

        return $parent->{$this->relationRaw}()->newDescriptor();
    }

    /**
     * @param int|object|Model $object
     * @param object|Model $parent
     * @param array $input
     * @return object|Model
     */
    public function updateForParent($object, $parent, $input)
    {
        $parent = $this->getParentModel($parent);

        $relation = $parent->{$this->relation}();

        $pivot = $this->model->where([
            $relation->getForeignKey() => $parent->id,
            $relation->getOtherKey() => $object,
        ])->first();

        if ($pivot === null) {
            $relation->attach($object, $input + ['version' => 0]);

            return $this->model->where([
                $relation->getForeignKey() => $parent->id,
                $relation->getOtherKey() => $object,
            ])->first();
        }

        $pivot->fill($input);

        if ($pivot->isDirty()) {
            $relation->updateExistingPivot($object, $input);
        }

        return $pivot;
    }
}
