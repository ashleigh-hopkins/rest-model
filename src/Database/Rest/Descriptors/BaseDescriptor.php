<?php namespace Database\Rest\Descriptors;

use Database\Rest\Client;
use Database\Rest\Collection;
use Database\Rest\Descriptors\Contracts\Descriptor;
use Database\Rest\Model;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class BaseDescriptor implements Descriptor
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
        return $this->getMany();
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
            return $this->model->hydrate($data, $this->connection);
        }

        return $data;
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

            $this->eagerLoad = array_merge($this->eagerLoad, $nonClosureRelations);
        }

        return $this;
    }

    public function find($id)
    {
        return $this->getOne($id);
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
        return $this->take(1)->getMany()->first();
    }

    public function getModel()
    {
        return $this->model;
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
                return (new Client(static::$cachedClients[$this->connection] = new GuzzleClient($config), '', []))->query($this->query);
            }

            throw new \Exception("Missing config for rest connection [{$this->connection}]");
        }

        return (new Client(static::$cachedClients[$this->connection], '', []))->query($this->query)->header($this->headers);
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

    protected function getOneResponseAccessor($body)
    {
        return data_get($body, 'data');
    }

    protected function getManyResponseAccessor($body)
    {
        return data_get($body, 'data');
    }

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
