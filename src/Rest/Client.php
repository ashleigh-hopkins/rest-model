<?php namespace Rest;

class Client
{
    protected $connection;

    protected $endpoint;

    protected $query = [];

    public function __construct(\GuzzleHttp\Client $connection, $endpoint)
    {
        $this->connection = $connection;
        $this->endpoint = $endpoint;
    }

    public function destroy($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        return $this->connection->delete("$endpoint/{$objectId}");
    }

    public function head($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        return $this->connection->head("$endpoint/{$objectId}");
    }

    public function index($id = null)
    {
        $id = (array)$id;

        $endpoint = str_replace_template($this->endpoint, $id);

        return $this->connection->get($endpoint);
    }

    public function show($id)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        return $this->connection->get("$endpoint/{$objectId}");
    }

    public function store($id = null, $data)
    {
        $id = (array)$id;

        $endpoint = str_replace_template($this->endpoint, $id);

        return $this->connection->post($endpoint, ['json' => $data]);
    }

    public function update($id, $data)
    {
        $id = (array)$id;

        $objectId = array_pop($id);

        $endpoint = str_replace_template($this->endpoint, $id);

        return $this->connection->put("$endpoint/{$objectId}", ['json' => $data, 'query' => $this->query]);
    }

    public function query($key, $value = null)
    {
        if(is_array($key) == false)
        {
            $key = [$key => $value];
        }

        foreach($key as $k => $v)
        {
            $this->query[] = [$k => $v];
        }

        return $this;
    }

    public function with($relations)
    {
        $relations = is_array($relations) ? $relations : func_get_args();

        foreach ($relations as $relation)
        {
            $this->query('with[]', $relation);
        }

        return $this;
    }
}
