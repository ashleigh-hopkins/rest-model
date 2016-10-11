<?php namespace RestModel\Repositories;

use RestModel\Database\Rest\Descriptors\Contracts\Descriptor;
use RestModel\Database\Rest\Model;

abstract class RestEntityRepository
{
    /**
     * @var Model
     */
    protected $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all()
    {
        return $this->model->all();
    }

    /**
     * @param array $input
     * @return Model
     */
    public function create($input)
    {
        return $this->model->create($input);
    }

    /**
     * @param int|Model $object
     * @return Model
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete($object)
    {
        if ($object instanceof Model == false) {
            $object = $this->get($object);
        }

        $object->delete();

        return $object;
    }

    /**
     * @param $id
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @return Model
     */
    public function get($id)
    {
        return $this->model->findOrFail($id);
    }

    /**
     * @param int $perPage
     * @return \Illuminate\Pagination\Paginator
     */
    public function paginate($perPage = null)
    {
        return $this->model->paginate($perPage);
    }

    public function query()
    {
        return $this->descriptor();
    }

    /**
     * @return Descriptor
     */
    public function descriptor()
    {
        return $this->model->newDescriptor();
    }

    /**
     * @param int|Model $object
     * @param array $input
     * @return Model
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update($object, $input)
    {
        if ($object instanceof Model == false) {
            $object = $this->get($object);
        }

        $object->fill($input);

        $object->save();

        return $object;
    }
}
