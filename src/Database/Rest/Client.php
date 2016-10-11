<?php namespace RestModel\Database\Rest;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

class Client
{
    protected static $log = [];

    protected static $requestReference = 0;

    protected $connection;

    protected $query = [];

    protected $headers = [];

    /**
     * @var Model
     */
    protected $model;

    protected $eagerLoad = [];

    protected $pending = [];

    public function __construct(\GuzzleHttp\Client $connection)
    {
        $this->connection = $connection;
    }

    public function clientCall($verb, $endpoint, $options = [], $name = null)
    {
        if($name === null)
        {
            list(, $caller) = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

            $name = $caller['function'];
        }

        return $this->clientCallAsync($verb, $endpoint, $options, null, null, $name)->wait();
    }

    /**
     * @param $verb
     * @param $endpoint
     * @param array $options
     * @param callable|null $successCallback
     * @param callable|null $failureCallback
     * @param null $name
     * @return PromiseInterface
     */
    public function clientCallAsync($verb, $endpoint, $options = [], callable $successCallback = null, callable $failureCallback = null, $name = null)
    {
        if($name === null)
        {
            list(, $caller) = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

            $name = $caller['function'];
        }

        $ref = $this->getNextReference();

        $this->startLog($ref, $endpoint, $verb, $name, data_get($options, 'json', data_get($options, 'body')));

        return $this->pending[$name][$ref] = $this->connection->{"{$verb}Async"}($endpoint, $options + ['query' => $this->getQuery(), 'headers' => $this->getHeaders()])
            ->then(
            function($response) use($name, $ref, $successCallback)
            {
                $this->endLog($ref, $response);

                unset($this->pending[$name][$ref]);

                if($successCallback !== null)
                {
                    return $successCallback($response);
                }

                return $response;
            },
            function($exception) use($name, $ref, $failureCallback)
            {
                $response = $exception->getResponse();

                $this->endLog($ref, $response);

                unset($this->pending[$name][$ref]);

                if($failureCallback !== null)
                {
                    return $failureCallback($exception);
                }

                return null;
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

    public static function getRequestLog()
    {
        return static::$log;
    }

    /**
     * @param $ref
     * @param $endpoint
     * @param $verb
     * @param string $method
     * @param string|array|null $body
     */
    protected function startLog($ref, $endpoint, $verb, $method, $body = null)
    {
        static::$log[$ref] = array_filter([
            'method' => $verb,
            'url' => $this->connection->getConfig('base_uri') . "$endpoint",
            'query' => $this->getQuery(),
            'headers' => $this->getHeaders(),
            'body' => is_array($body) ? json_encode($body) : $body,
            'time' => microtime(true),
        ]);
    }

    /**
     * @param int $ref
     * @param ResponseInterface|null $response
     */
    protected function endLog($ref, $response = null)
    {
        static::$log[$ref]['time'] = round((microtime(true) - static::$log[$ref]['time']) * 1000, 2);
        static::$log[$ref]['status'] = $response ? $response->getStatusCode() : -1;
    }

    /**
     * @return int
     */
    protected function getNextReference()
    {
        return static::$requestReference++;
    }
}
