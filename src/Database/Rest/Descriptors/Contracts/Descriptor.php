<?php namespace Database\Rest\Descriptors\Contracts;

use Database\Rest\Collection;
use Database\Rest\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface Descriptor
{
    function deleteOne($id);

    function getOne($id);

    function getMany();

    function getManyOne($ids);

    function storeOne($attributes);

    function updateOne($id, $attributes);

    /**
     * @param string|string[] $key
     * @param mixed $value
     * @return static
     */
    function where($key, $value = null);

    /**
     * @param $limit
     * @return static
     */
    function take($limit);

    /**
     * @return Collection|\Illuminate\Database\Eloquent\Collection|Model|Model[]
     */
    function get();

    /**
     * @param $id
     * @return Model
     */
    function find($id);

    /**
     * @param $id
     * @return Model
     * @throws ModelNotFoundException
     */
    function findOrFail($id);

    /**
     * @param $relations
     * @return static
     */
    function with($relations);

    /**
     * @param $relations
     * @return static
     */
    function remoteLoad($relations);

    /**
     * @return Model
     */
    function getModel();
}
