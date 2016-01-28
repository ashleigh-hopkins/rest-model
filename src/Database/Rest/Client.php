<?php namespace Database\Rest;

use GuzzleHttp\Promise\Promise;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class Client
{
    protected static $log = [];

    protected static $requestReference = 0;

    protected $config;

    protected $connection;

    protected $endpoint;

    protected $query = [];

    protected $headers = [];

    /**
     * @var Model
     */
    protected $model;

    protected $eagerLoad = [];

    protected $pending = [];

    public function __construct(\GuzzleHttp\Client $connection, $endpoint, $config)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->endpoint = $endpoint;
    }

    public function destroy($id)
    {
        return $this->destroyAsync($id)->wait(true);
    }

    public function destroyAsync($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id) . "/{$objectId}";

        $ref = $this->getNextReference();

        $this->startLog($ref, $endpoint, 'index');

        return $this->pending['destroy'][$ref] = $this->connection->deleteAsync($endpoint, ['query' => $this->getQuery(), 'headers' => $this->getHeaders()])
            ->then(
                function($result) use($ref)
                {
                    $this->endLog($ref, $result);

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

        $endpoint = str_replace_template($this->endpoint, $id) . "/{$objectId}";

        $ref = $this->getNextReference();

        $this->startLog($ref, $endpoint, 'head');

        return $this->pending['head'][$ref] = $this->connection->headAsync($endpoint, ['query' => $this->getQuery(), 'headers' => $this->getHeaders()])
            ->then(function($result) use ($ref)
            {
                $this->endLog($ref, $result);

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

        $ref = $this->getNextReference();

        $this->startLog($ref, $endpoint, 'index');

        return $this->pending['index'][$ref] = $this->connection->getAsync($endpoint, ['query' => $this->getQuery(), 'headers' => $this->getHeaders()])
            ->then(function ($result) use ($ref)
            {
                $this->endLog($ref, $result);

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

        $endpoint = str_replace_template($this->endpoint, $id) . "/{$objectId}";

        $hash = sha1($this->connection->getConfig('base_uri') . "_{$endpoint}_{$this->endpoint}");

        $existing = null;

        if(array_get($this->config, 'cache.enabled'))
        {
            if ($existing = \Cache::get($hash))
            {
                if(array_get($this->config, 'cache.check_304'))
                {
                    if ($etag = data_get($existing, 'etag'))
                    {
                        $this->header('If-None-Match', $etag);
                    }
                }
                else
                {
                    $promise = new Promise();

                    $connection = $this->model->getConnectionName();
                    $promise->resolve($this->model->hydrate([$existing['object']], $connection, $this->eagerLoad)->first());

                    return $promise;
                }
            }
        }

        $ref = $this->getNextReference();

        $this->startLog($ref, $endpoint, 'index');

        return $this->pending['show'][$ref] = $this->connection->getAsync($endpoint, ['query' => $this->getQuery(), 'headers' => $this->getHeaders()])
            ->then(function($clientResult) use($ref, $existing, $hash)
            {
                $this->endLog($ref, $clientResult);

                unset($this->pending['show'][$ref]);

                $connection = $this->model->getConnectionName();

                if($clientResult->getStatusCode() == 304)
                {
                    return $this->model->hydrate([$existing['object']], $connection, $this->eagerLoad)->first();
                }

                $rawResult = $clientResult->getBody()->getContents();

                $jsonResult = json_decode($rawResult);

                if($jsonResult !== false)
                {
                    $dataVariable = $this->getDataVariable('show');

                    $result = $this->model->hydrate([$dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult], $connection, $this->eagerLoad)->first();

                    if(array_get($this->config, 'cache.enabled'))
                    {
                        if ($etag = $clientResult->getHeader('ETag'))
                        {
                            \Cache::put($hash, ['etag' => $etag[0], 'object' => $result->toArray()], array_get($this->config, 'cache.lifetime', 1));
                        }
                    }
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

        $ref = $this->getNextReference();

        $this->startLog($ref, $endpoint, 'index', $data);

        return $this->pending['store'][$ref] = $this->connection->postAsync($endpoint, ['json' => $data, 'query' => $this->getQuery(), 'headers' => $this->getHeaders()])
            ->then(function($result) use ($ref, $returnResult)
            {
                $this->endLog($ref, $result);

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

        $endpoint = str_replace_template($this->endpoint, $id) . "/{$objectId}";

        $ref = $this->getNextReference();

        $this->startLog($ref, $endpoint, 'index', $data);

        return $this->pending['update'][$ref] = $this->connection->put($endpoint, ['json' => $data, 'query' => $this->getQuery(), 'headers' => $this->getHeaders()])
            ->then (function ($result) use ($ref, $returnResult)
            {
                $this->endLog($ref, $result);

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

    public function where($key, $value = null)
    {
        return $this->query($key, $value);
    }

    public function header($key, $value = null)
    {
        if(is_array($key) == false)
        {
            $key = [$key => $value];
        }

        foreach($key as $k => $v)
        {
            $this->headers[$k] = $v;
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

    public function get()
    {
        return $this->indexAsync();
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
        return ($this->connection->getConfig('query') ?: []) + $this->query;
    }

    /**
     * @return array
     */
    private function getHeaders()
    {
        return ($this->connection->getConfig('headers') ?: []) + $this->headers;
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

    public static function getRequestLog()
    {
        return static::$log;
    }

    /**
     * @param $ref
     * @param $endpoint
     * @param string $method
     * @param string|array|null $body
     */
    protected function startLog($ref, $endpoint, $method, $body = null)
    {
        static::$log[$ref] = array_filter([
            'method' => $method,
            'url' => $this->connection->getConfig('base_uri') . "$endpoint",
            'query' => $this->getQuery(),
            'headers' => $this->getHeaders(),
            'body' => is_array($body) ? json_encode($body) : $body,
            'time' => microtime(true),
        ]);
    }

    protected function endLog($ref, $response = null)
    {
        static::$log[$ref]['time'] = round((microtime(true) - static::$log[$ref]['time']) * 1000, 2);
        static::$log[$ref]['status'] = $response->getStatusCode();
    }

    /**
     * @return array
     */
    protected function getNextReference()
    {
        return static::$requestReference++;
    }

    protected function onClientFailure($result, $type, $ref)
    {
        $this->endLog($ref, $result->getResponse());

        unset($this->pending[$type][$ref]);

        return null;
    }
}
