<?php namespace RestModel\Database\Rest\Descriptors\Contracts;

use GuzzleHttp\Promise\PromiseInterface;
use RestModel\Database\Rest\Collection;
use RestModel\Database\Rest\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface Descriptor
{
    function deleteOne($id);
    
    function deleteOneAsync($id);

    function getOne($id);
    
    function getOneAsync($id);

    function getMany();
    
    function getManyAsync();

    function getManyOne($ids);
    
    function getManyOneAsync($ids);

    function storeOne($attributes);
    
    function storeOneAsync($attributes);

    function updateOne($id, $attributes);
    
    function updateOneAsync($id, $attributes);

    /**
     * @param string|string[] $key
     * @param mixed $value
     * @return static
     */
    function where($key, $value = null);

    /**
     * @param string $key
     * @param array $value
     * @return static
     */
    function whereIn($key, $value);

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
     * @return PromiseInterface
     */
    function getAsync();

    /**
     * @param $id
     * @return Model
     */
    function find($id);

    /**
     * @param $id
     * @return PromiseInterface
     */
    function findAsync($id);

    /**
     * @param $id
     * @return Model
     * @throws ModelNotFoundException
     */
    function findOrFail($id);

    /**
     * @param $id
     * @return PromiseInterface
     * @throws ModelNotFoundException
     */
    function findOrFailAsync($id);

    /**
     * @return Model
     */
    function first();

    /**
     * @return PromiseInterface
     */
    function firstAsync();

    /**
     * @return Model
     * @throws ModelNotFoundException
     */
    function firstOrFail();

    /**
     * @return PromiseInterface
     * @throws ModelNotFoundException
     */
    function firstOrFailAsync();

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
