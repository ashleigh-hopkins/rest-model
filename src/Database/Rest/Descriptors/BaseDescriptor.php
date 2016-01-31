<?php namespace Database\Rest\Descriptors;

use Database\Rest\Client;
use Database\Rest\Collection;
use Database\Rest\Descriptors\Contracts\Descriptor;
use Database\Rest\Model;
use Database\Rest\Relations\ComesWithMany;
use Database\Rest\Relations\Relation;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

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

        return $this->newClient()->clientCallAsync('delete', $endpoint, [], function($response)
        {
            return $this->processDeleteOneResponse($response);
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

        return $this->newClient()->clientCallAsync('get', $endpoint, [], function($response)
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

        return $this->newClient()->clientCallAsync('post', $endpoint, ['json' => $attributes], function($response)
        {
            $result = $this->processOneResponse($response);

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

        return $this->newClient()->clientCallAsync('put', $endpoint, ['json' => $attributes], function($response)
        {
            $result = $this->processOneResponse($response);

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

        return $this->newClient()->clientCallAsync('get', $this->getManyEndpoint(), [], function($response)
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

            $tasks[] = $this->newClient()->clientCallAsync('get', $endpoint);
        }

        $items = $this->waitForManyPromises($tasks);

        return \GuzzleHttp\Promise\promise_for($this->processManyOneResponse($items));
    }

    public function where($key, $value = null)
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
        $this->loadPreRelations();

        $models = $this->getMany();

        if (count($models) > 0) {
            $models = $this->eagerLoadRelations($models);
        }

        return $this->getModel()->newCollection($models);
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
            // For nested eager loads we'll skip loading them here and they will be set as an
            // eager load on the query to retrieve the relation so that they will be eager
            // loaded on that query, because that is where they get hydrated as models.
            if (strpos($name, '.') === false) {
                $this->loadPreRelation($name);
            }
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
        $relation = $this->getRelation($name);

        if($relation instanceof ComesWithMany)
        {
            $relation->addPreConstraints($this);
        }
    }

    function returnOne($data)
    {
        if($data instanceof Model == false)
        {
            return $this->model->hydrate([$data], $this->connection)->all();
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
        return $this->where($this->model->getKeyName(), $id)->first();
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

    public function first()
    {
        return $this->take(1)->get()->first();
    }

    public function getModel()
    {
        return $this->model;
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

    protected function getManyEndpoint($id = [])
    {
        return "{$this->endpoint}";
    }

    protected function processOneResponse(ResponseInterface $response)
    {
        // if caching then check and save

        $body = $this->getJsonFromResponse($response);

        return $this->getOneResponseAccessor($body);
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
