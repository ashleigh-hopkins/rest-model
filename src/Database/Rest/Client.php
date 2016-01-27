<?php namespace Database\Rest;

use Illuminate\Database\Eloquent\ModelNotFoundException;

class Client
{
    protected $config;

    protected $connection;

    protected $endpoint;

    protected $query = [];

    /**
     * @var Model
     */
    protected $model;

    protected $eagerLoad = [];

    protected $pending = [
        'ref' => 0
    ];

    public function __construct(\GuzzleHttp\Client $connection, $endpoint, $config)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->endpoint = $endpoint;
    }

    protected function onClientFailure($result, $type, $ref)
    {
        unset($this->pending[$type][$ref]);

        return null;
    }

    public function destroy($id)
    {
        return $this->destroyAsync($id)->wait(true);
    }

    public function destroyAsync($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $ref = $this->pending['ref']++;

        return $this->pending['destroy'][$ref] = $this->connection->deleteAsync("$endpoint/{$objectId}", ['query' => $this->getQuery()])
            ->then(
                function($result) use($ref)
                {
                    unset($this->pending['head'][$ref]);

                    return in_array($result->getStatusCode(), [200, 204]);
                },
                function ($result) use($ref)
                {
                    return $this->onClientFailure($result, 'destroy', $ref);
                });
    }

    public function head($id)
    {
        return $this->headAsync($id)->wait(true);
    }

    public function headAsync($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $ref = $this->pending['ref']++;

        return $this->pending['head'][$ref] = $this->connection->headAsync("$endpoint/{$objectId}", ['query' => $this->getQuery()])
            ->then(function($result) use ($ref)
            {
                unset($this->pending['head'][$ref]);

                return $result->getHeaders();
            },
            function ($result) use($ref)
            {
                return $this->onClientFailure($result, 'head', $ref);
            });
    }

    public function index($id = null)
    {
        return $this->indexAsync($id)->wait(true);
    }

    public function indexAsync($id = null)
    {
        $id = (array)$id;

        $endpoint = str_replace_template($this->endpoint, $id);

        $ref = $this->pending['ref']++;

        return $this->pending['index'][$ref] = $this->connection->getAsync($endpoint, ['query' => $this->getQuery()])
            ->then(function ($result) use ($ref)
            {
                unset($this->pending['index'][$ref]);

                $jsonResult = json_decode($result->getBody()->getContents());

                if($jsonResult !== false)
                {
                    $connection = $this->model->getConnectionName();

                    $dataVariable = $this->getDataVariable('index');

                    return $this->model->hydrate($dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult, $connection, $this->eagerLoad);
                }

                return null;
            },
            function ($result) use($ref)
            {
                return $this->onClientFailure($result, 'index', $ref);
            });
    }

    public function show($id)
    {
        return $this->showAsync($id)->wait(true);
    }

    public function showAsync($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $ref = $this->pending['ref']++;

        return $this->pending['show'][$ref] = $this->connection->getAsync("$endpoint/{$objectId}", ['query' => $this->getQuery()])
            ->then(function($result) use($ref)
            {
                unset($this->pending['show'][$ref]);

                $rawResult = $result->getBody()->getContents();

                $jsonResult = json_decode($rawResult);

                if($jsonResult !== false)
                {
                    $connection = $this->model->getConnectionName();

                    $dataVariable = $this->getDataVariable('show');

                    return $this->model->hydrate([$dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult], $connection, $this->eagerLoad)->first();
                }

                return null;
            },
            function ($result) use($ref)
            {
                return $this->onClientFailure($result, 'show', $ref);
            });
    }

    public function store($id = null, $data = [], $returnResult = false)
    {
        return $this->storeAsync($id, $data, $returnResult)->wait(true);
    }

    public function storeAsync($id = null, $data = [], $returnResult = false)
    {
        $id = (array)$id;

        $endpoint = str_replace_template($this->endpoint, $id);

        $ref = $this->pending['ref']++;

        return $this->pending['store'][$ref] = $this->connection->postAsync($endpoint, ['json' => $data, 'query' => $this->getQuery()])
            ->then(function($result) use ($ref, $returnResult)
            {
                unset($this->pending['store'][$ref]);

                if($returnResult == false)
                {
                    return in_array($result->getStatusCode(), [200, 304]);
                }

                $rawResult = $result->getBody()->getContents();

                $jsonResult = json_decode($rawResult);

                if($jsonResult !== false)
                {
                    $connection = $this->model->getConnectionName();

                    $dataVariable = $this->getDataVariable('store');

                    return $this->model->hydrate([$dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult], $connection, $this->eagerLoad)->first();
                }

                return null;
            },
            function ($result) use($ref)
            {
                return $this->onClientFailure($result, 'store', $ref);
            });
    }

    public function updateAsync($id, $data = [], $returnResult = false)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $ref = $this->pending['ref']++;

        return $this->pending['update'][$ref] = $this->connection->put("$endpoint/{$objectId}", ['json' => $data, 'query' => $this->getQuery()])
            ->then (function ($result) use ($ref, $returnResult)
            {
                unset($this->pending['update'][$ref]);

                if($returnResult == false)
                {
                    return in_array($result->getStatusCode(), [200, 304]);
                }

                $rawResult = $result->getBody()->getContents();

                $jsonResult = json_decode($rawResult);

                if($jsonResult !== false)
                {
                    $connection = $this->model->getConnectionName();

                    $dataVariable = $this->getDataVariable('update');

                    return $this->model->hydrate([$dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult], $connection, $this->eagerLoad)->first();
                }

                return false;
            },
            function ($result) use($ref)
            {
                return $this->onClientFailure($result, 'update', $ref);
            });
    }

    public function query($key, $value = null)
    {
        if(is_array($key) == false)
        {
            $key = [$key => $value];
        }

        foreach($key as $k => $v)
        {
            $this->query[$k] = $v;
        }

        return $this;
    }

    public function with($relations)
    {
        if($relations)
        {
            $nonClosureRelations = [];

            foreach ($relations as $k => $relation)
            {
                if ($relation instanceof \Closure)
                {
                    $nonClosureRelations[] = $k;
                }
                else
                {
                    $nonClosureRelations[] = $relation;
                }
            }

            $this->query('with', $nonClosureRelations);

            $this->eagerLoad = array_merge($this->eagerLoad, $nonClosureRelations);
        }

        return $this;
    }

    public function find($id)
    {
        return $this->show($id);
    }

    /**
     * @param $id
     * @return static
     */
    public function findOrFail($id)
    {
        if($model = $this->find($id))
        {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->model));
    }

    private function getDataVariable($method)
    {
        $item = array_get($this->config, 'variables');

        if(is_array($item))
        {
            if(isset($item[$method]))
            {
                $item = $item[$method];
            }
            else if (isset($item['*']))
            {
                $item = $item['*'];
            }
        }

        $result = str_replace_template($item, [
            'endpoint' => $this->endpoint
        ]);

        return $result;
    }

    /**
     * @return array
     */
    private function getQuery()
    {
        return $this->connection->getConfig('query') ?: [] + $this->query;
    }

    /**
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * @param Model $model
     * @return static
     */
    public function setModel($model)
    {
        $this->model = $model;

        return $this;
    }

    public function __call($method, $parameters)
    {
        if (method_exists($this->model, $method)) {
            return call_user_func_array([$this->model, $method], $parameters);
        }

        return $this;
    }
}
