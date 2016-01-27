<?php namespace Rest;

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

    public function __construct(\GuzzleHttp\Client $connection, $endpoint, $config)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->endpoint = $endpoint;
    }

    public function destroy($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $result = $this->connection->delete("$endpoint/{$objectId}", ['query' => $this->getQuery()]);

        return in_array($result->getStatusCode(), [200, 204]);
    }

    public function head($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $clientResult = $this->connection->head("$endpoint/{$objectId}", ['query' => $this->getQuery()]);

        return $clientResult->getHeaders();
    }

    public function index($id = null)
    {
        $id = (array)$id;

        $endpoint = str_replace_template($this->endpoint, $id);

        $clientResult = $this->connection->get($endpoint, ['query' => $this->getQuery()]);

        $jsonResult = json_decode($clientResult->getBody()->getContents());

        if($jsonResult !== false)
        {
            $connection = $this->model->getConnectionName();

            $dataVariable = $this->getDataVariable('index');

            return $this->model->hydrate($dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult, $connection, $this->eagerLoad);
        }

        return null;
    }

    public function show($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $clientResult = $this->connection->get("$endpoint/{$objectId}", ['query' => $this->getQuery()]);

        $rawResult = $clientResult->getBody()->getContents();

        $jsonResult = json_decode($rawResult);

        if($jsonResult !== false)
        {
            $connection = $this->model->getConnectionName();

            $dataVariable = $this->getDataVariable('show');

            return $this->model->hydrate([$dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult], $connection, $this->eagerLoad)->first();
        }

        return null;
    }

    public function store($id = null, $data = [], $returnResult = false)
    {
        $id = (array)$id;

        $endpoint = str_replace_template($this->endpoint, $id);

        $clientResult = $this->connection->post($endpoint, ['json' => $data, 'query' => $this->getQuery()]);

        $jsonResult = json_decode($clientResult->getBody()->getContents());

        if($jsonResult !== false)
        {
            if($returnResult == false)
            {
                return in_array($clientResult->getStatusCode(), [200, 304]);
            }

            $connection = $this->model->getConnectionName();

            $dataVariable = $this->getDataVariable('store');

            return $this->model->hydrate([$dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult], $connection, $this->eagerLoad)->first();
        }

        return null;
    }

    public function update($id, $data = [], $returnResult = false)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        $clientResult = $this->connection->put("$endpoint/{$objectId}", ['json' => $data, 'query' => $this->getQuery()]);

        $jsonResult = json_decode($clientResult->getBody()->getContents());

        if($jsonResult !== false)
        {
            if($returnResult == false)
            {
                return in_array($clientResult->getStatusCode(), [200, 304]);
            }

            $connection = $this->model->getConnectionName();

            $dataVariable = $this->getDataVariable('update');

            return $this->model->hydrate([$dataVariable ? data_get($jsonResult, $dataVariable) : $jsonResult], $connection, $this->eagerLoad)->first();
        }

        return false;
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
        $this->query('with', $relations);

        $this->eagerLoad = array_merge($this->eagerLoad, $relations);

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
