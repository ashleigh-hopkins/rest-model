<?php namespace RestModel\Database\Rest\Descriptors;

use RestModel\Database\Rest\Client;
use RestModel\Database\Rest\Collection;
use RestModel\Database\Rest\Descriptors\Contracts\Descriptor;
use RestModel\Database\Rest\Exceptions\RestRemoteValidationException;
use RestModel\Database\Rest\Model;
use RestModel\Database\Rest\Relations\ComesWithMany;
use RestModel\Database\Rest\Relations\Relation;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class BaseDescriptor implements Descriptor
{
    protected static $cachedClients = [];

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

    /**
     * BaseDescriptor constructor.
     * @param string $connection
     * @param Model $model
     * @param array $config
     */
    public function __construct($connection, $model, $config = [])
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->model = $model;
        $this->endpoint = $model->getEndpoint();
    }

    public function deleteOne($id)
    {
        return $this->deleteOneAsync($id)->wait();
    }

    public function deleteOneAsync($id)
    {
        $endpoint = $this->getOneEndpoint($id);

        return $this->clientCallAsync('delete', $endpoint, [], function($response)
        {
            return $this->processDeleteOneResponse($response);
        });
    }

    protected function clientCallAsync($method, $endpoint, $options = [], $successCallback = null, $failureCallback = null)
    {
        return $this->newClient()->clientCallAsync($method, $endpoint, $options, $successCallback, $failureCallback ?: function($exception)
        {
            $response = $exception->getResponse();

            switch($response->getStatusCode())
            {
                case Response::HTTP_UNPROCESSABLE_ENTITY:
                {
                    throw new RestRemoteValidationException(json_decode($response->getBody()->getContents())->error, $response->getStatusCode());
                }
            }

            $body = json_decode($response->getBody()->getContents());

            throw $exception;
        });
    }

    public function getOne($id)
    {
        return $this->getOneAsync($id)->wait();
    }

    public function getOneAsync($id)
    {
        return $this->getOneRawAsync($id)
            ->then(function($result)
            {
                return $this->returnOne($result);
            });
    }

    public function getOneRawAsync($id)
    {
        $endpoint = $this->getOneEndpoint($id);

        return $this->clientCallAsync('get', $endpoint, [], function($response)
        {
            return $this->processOneResponse($response);
        });
    }

    public function storeOne($attributes)
    {
        return $this->storeOneAsync($attributes)->wait();
    }

    public function storeOneAsync($attributes)
    {
        $endpoint = $this->getManyEndpoint();

        return $this->clientCallAsync('post', $endpoint, ['json' => $attributes], function($response)
        {
            $result = $this->processOneResponse($response);

            $result = $this->postProcessStoreOne($result);

            return $this->returnOne($result);
        });
    }

    public function updateOne($id, $attributes)
    {
        return $this->updateOneAsync($id, $attributes)->wait();
    }

    public function updateOneAsync($id, $attributes)
    {
        $endpoint = $this->getOneEndpoint($id);

        return $this->clientCallAsync(array_get($this->config, 'update_verb', 'put'), $endpoint, ['json' => $attributes], function($response)
        {
            $result = $this->processOneResponse($response);
            
            $result = $this->postProcessUpdateOne($result);

            return $this->returnOne($result);
        });
    }

    function getMany()
    {
        return $this->getManyAsync()->wait();
    }

    function getManyAsync()
    {
        // try to hand off to a simpler method

        $keyName = $this->model->getKeyName();

        if(count($this->query) == 1 && isset($this->query[$keyName]))
        {
            $id = $this->query[$keyName];

            unset($this->query[$keyName]);

            if(is_array($id) == false || count($id) == 1)
            {
                $id = (array)$id;

                return $this->getOneRawAsync($id[0])->then(function($result)
                {
                    return $this->returnMany([$result]);
                });
            }

            return $this->getManyOneAsync($id);
        }

        return $this->clientCallAsync('get', $this->getManyEndpoint(), [], function($response)
        {
            return $this->processManyResponse($response);
        });
    }

    function getManyOne($ids)
    {
        return $this->getManyOneAsync($ids)->wait();
    }

    function getManyOneAsync($ids)
    {
        $tasks = [];

        foreach ($ids as $id)
        {
            $endpoint = $this->getOneEndpoint($id);

            $tasks[] = $this->clientCallAsync('get', $endpoint);
        }

        $items = $this->waitForManyPromises($tasks);

        return \GuzzleHttp\Promise\promise_for($this->processManyOneResponse($items));
    }

    public function where($key, $operator = null, $value = null)
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($key)) {
            foreach($key as $k) {
                call_user_func_array([$this, 'where'], $k);
            }
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        if (func_num_args() == 2) {
            list($value, $operator) = [$operator, '='];
        }

        if($operator == null)
        {
            return $this;
        }

        return $this->addWhere($key, $operator, $value);
    }

    protected function addWhere($key, $operator, $value)
    {
        $this->query[$key] = $value;

        return $this;
    }

    public function whereIn($key, $value)
    {
        $this->query[$key] = $value;

        return $this;
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

    public function take($limit)
    {
        return $this;
    }

    public function get()
    {
        return $this->getAsync()->wait();
    }

    /**
     * @return PromiseInterface
     */
    public function getAsync()
    {
        $this->loadPreRelations();

        return $this->getManyAsync()->then(function($models)
        {
            if (count($models) > 0) {
                $models = $this->eagerLoadRelations($models);
            }

            return $this->getModel()->newCollection($models);
        });
    }

    public function eagerLoadRelations(array $models)
    {
        foreach ($this->eagerLoad as $name) {
            // For nested eager loads we'll skip loading them here and they will be set as an
            // eager load on the query to retrieve the relation so that they will be eager
            // loaded on that query, because that is where they get hydrated as models.
            if (strpos($name, '.') === false) {
                $models = $this->loadRelation($models, $name);
            }
        }

        return $models;
    }

    public function loadPreRelations()
    {
        foreach ($this->eagerLoad as $name) {
            $this->loadPreRelation($name);
        }
    }

    /**
     * @param $name
     * @return Relation
     */
    public function getRelation($name)
    {
        // We want to run a relationship query without any constrains so that we will
        // not have to remove these where clauses manually which gets really hacky
        // and is error prone while we remove the developer's own where clauses.
        $relation = $this->getModel()->$name();

        $nested = $this->nestedRelations($name);

        // If there are nested relationships set on the query, we will put those onto
        // the query instances so that they can be handled after this relationship
        // is loaded. In this way they will all trickle down as they are loaded.
        if (count($nested) > 0) {
            $relation->getQuery()->with($nested);
        }

        return $relation;
    }

    protected function isNested($name, $relation)
    {
        $dots = Str::contains($name, '.');

        return $dots && Str::startsWith($name, $relation.'.');
    }

    protected function nestedRelations($relation)
    {
        $nested = [];

        // We are basically looking for any relationships that are nested deeper than
        // the given top-level relationship. We will just check for any relations
        // that start with the given top relations and adds them to our arrays.
        foreach ($this->eagerLoad as $name) {
            if ($this->isNested($name, $relation)) {
                $nested[] = substr($name, strlen($relation.'.'));
            }
        }

        return $nested;
    }

    protected function loadRelation(array $models, $name)
    {
        // First we will "back up" the existing where conditions on the query so we can
        // add our eager constraints. Then we will merge the wheres that were on the
        // query back to it in order that any where conditions might be specified.
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        $models = $relation->initRelation($models, $name);

        // Once we have the results, we just match those back up to their parent models
        // using the relationship instance. Then we just return the finished arrays
        // of models which have been eagerly hydrated and are readied for return.
        $results = $relation->getEager();

        return $relation->match($models, $results, $name);
    }

    protected function loadPreRelation($name)
    {
        if(strstr($name, '.'))
        {
            $name = explode('.', $name);

            $obj = $this;

            foreach($name as $n)
            {
                $relation = $obj->getRelation($n);

                if ($relation instanceof ComesWithMany)
                {
                    $relation->addPreConstraints($this);

                    $obj = $relation->getRelated()->newDescriptor();
                }
                else
                {
                    break;
                }
            }
        }
        else
        {
            $relation = $this->getRelation($name);

            if ($relation instanceof ComesWithMany)
            {
                $relation->addPreConstraints($this);
            }
        }
    }

    function returnOne($data)
    {
        if($data instanceof Model == false)
        {
            return $this->model->hydrate([$data], $this->connection)->first();
        }

        return $data;
    }

    function returnMany($data)
    {
        if($data instanceof Collection == false)
        {
            return $this->model->hydrate($data, $this->connection)->all();
        }

        return $data;
    }

    public function with($relations)
    {
        $relations = is_array($relations) ? $relations : func_get_args();

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

            $this->eagerLoad = array_merge($this->eagerLoad, $nonClosureRelations);
        }

        return $this;
    }

    abstract public function remoteLoad($relations);

    public function find($id)
    {
        return $this->findAsync($id)->wait();
    }

    /**
     * @param $id
     * @return PromiseInterface
     */
    public function findAsync($id)
    {
        return $this->where($this->model->getKeyName(), $id)->firstAsync();
    }

    /**
     * @param $id
     * @return static
     */
    public function findOrFail($id)
    {
        return $this->findOrFailAsync($id)->wait();
    }

    /**
     * @param $id
     * @return PromiseInterface
     */
    public function findOrFailAsync($id)
    {
        return $this->findAsync($id)->then(function($model)
        {
            if($model)
            {
                return $model;
            }

            throw (new ModelNotFoundException())->setModel(get_class($this->model));
        });
    }

    public function first()
    {
        return $this->firstAsync()->wait();
    }

    /**
     * @return PromiseInterface
     */
    public function firstAsync()
    {
        return $this->take(1)->getAsync()->then(function($result)
        {
            return $result->first();
        });
    }

    public function firstOrFail()
    {
        return $this->firstOrFailAsync()->wait();
    }

    /**
     * @return PromiseInterface
     */
    public function firstOrFailAsync()
    {
        return $this->firstAsync()->then(function($model)
        {
            if($model)
            {
                return $model;
            }

            throw (new ModelNotFoundException())->setModel(get_class($this->model));
        });
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function setEndpoint($value)
    {
        $this->endpoint = $value;

        return $this;
    }

    protected function getQuery()
    {
        return $this->query;
    }

    /**
     * @return Client
     * @throws \Exception
     */
    protected function newClient()
    {
        if(isset(static::$cachedClients[$this->connection]) == false)
        {
            $config = $this->getClientConfig();

            if($config !== null)
            {
                return (new Client(static::$cachedClients[$this->connection] = new GuzzleClient($config), '', []))->query($this->getQuery());
            }

            throw new \Exception("Missing config for rest connection [{$this->connection}]");
        }

        return (new Client(static::$cachedClients[$this->connection], '', []))->query($this->getQuery())->header($this->headers);
    }

    protected function getClientConfig()
    {
        return array_get($this->config, 'client');
    }

    protected function getOneEndpoint($id)
    {
        return "{$this->endpoint}/{$id}";
    }

    protected function getManyEndpoint()
    {
        return $this->endpoint;
    }

    protected function processOneResponse(ResponseInterface $response)
    {
        // if caching then check and save

        $body = $this->getJsonFromResponse($response);

        return $this->getOneResponseAccessor($body);
    }
    
    protected function postProcessStoreOne($data)
    {
        return $data;
    }
    
    protected function postProcessUpdateOne($data)
    {
        return $data;
    }

    protected function processDeleteOneResponse(ResponseInterface $response)
    {
        // if caching then check and save

        return in_array($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NO_CONTENT]);
    }

    protected function processManyResponse(ResponseInterface $response)
    {
        // if caching then check and save

        $body = $this->getJsonFromResponse($response);

        $data = $this->getManyResponseAccessor($body);

        return $this->returnMany($data);
    }

    /**
     * @param ResponseInterface[] $responses
     * @return Collection|Model[]
     */
    protected function processManyOneResponse($responses)
    {
        $items = [];

        foreach($responses as $response)
        {
            // if caching then check and save

            $body = $this->getJsonFromResponse($response);

            $data = $this->getOneResponseAccessor($body);

            $items[] = $data;
        }

        return $this->returnMany($items);
    }

    abstract protected function getOneResponseAccessor($body);

    abstract protected function getManyResponseAccessor($body);

    protected function getJsonFromResponse(ResponseInterface $response)
    {
        $body = $response->getBody()->getContents();

        return json_decode($body);
    }

    /**
     * @param PromiseInterface[] $tasks
     * @return ResponseInterface[]
     */
    protected function waitForManyPromises($tasks)
    {
        $items = [];

        foreach ($tasks as $task)
        {
            $item = $task->wait();

            if ($item !== null)
            {
                $items[] = $item;
            }
        }

        return $items;
    }
}
