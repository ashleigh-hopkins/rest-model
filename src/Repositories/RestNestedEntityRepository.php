<?php namespace RestModel\Repositories;

use RestModel\Database\Rest\Descriptors\Contracts\Descriptor;
use RestModel\Database\Rest\Model;

abstract class RestNestedEntityRepository extends RestEntityRepository
{
    protected $parentModel;

    protected $relation;

    public function __construct(Model $model, Model $parentModel, $relation)
    {
        parent::__construct($model);

        $this->parentModel = $parentModel;
        $this->relation = $relation;
    }

    /**
     * @param int|object|Model $parent
     * @return object[]|Model[]
     */
    public function allForParent($parent)
    {
        $parent = $this->getParentModel($parent);

        return $parent->{$this->relation}()->all();
    }

    /**
     * @param array $input
     * @param object|Model $parent
     * @return object|Model
     */
    public function createForParent($input, $parent)
    {
        $parent = $this->getParentModel($parent);

        if($this->isVersionTracking($this->model))
        {
            $input += ['version' => 0];
        }

        return $parent->{$this->relation}()->create($input);
    }

    /**
     * @param object|Model $parent
     * @param int|object|Model $object
     * @return bool|null
     */
    public function deleteForParent($object, $parent)
    {
        $parent = $this->getParentModel($parent);

        if($object instanceof Model == false)
        {
            $object = $this->getForParent($object, $parent);
        }

        $object->delete();

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

        return $parent->{$this->relation}()->findOrFail($id);
    }

    /**
     * @param object|Model $parent
     * @param int $perPage
     * @return \Illuminate\Pagination\Paginator
     */
    public function paginateForParent($parent, $perPage = null)
    {
        $parent = $this->getParentModel($parent);

        return $parent->{$this->relation}()->paginate($perPage);
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
     * @param int|object|Model $object
     * @param object|Model $parent
     * @param array $input
     * @return object|Model
     */
    public function updateForParent($object, $parent, $input)
    {
        $parent = $this->getParentModel($parent);

        if($object instanceof Model == false)
        {
            $object = $this->getForParent($object, $parent);
        }

        return parent::update($object, $input);
    }

    /**
     * @param int|object|array|Model $mixed
     * @return Model
     */
    protected function getParentModel($mixed)
    {
        if ($mixed instanceof Model == false)
        {
            if(is_numeric($mixed) == false)
            {
                $mixed = data_get($mixed, 'id');
            }

            return $this->parentModel->newInstance(['id' => $mixed], true);
        }

        return $mixed;
    }
}
