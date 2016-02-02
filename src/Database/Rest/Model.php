<?php namespace RestModel\Database\Rest;

use ArrayAccess;
use Carbon\Carbon;
use RestModel\Database\Rest\Descriptors\Contracts\Descriptor;
use DateTime;
use Exception;
use Illuminate\Contracts\Queue\QueueableEntity;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonSerializable;

abstract class Model implements ArrayAccess, Arrayable, Jsonable, JsonSerializable, QueueableEntity, UrlRoutable
{
    protected $primaryKey = 'id';

    protected $attributes = [];

    protected $original = [];

    protected $relations = [];

    protected $dates = [];

    protected $dateFormat;

    protected $connection;

    protected $endpoint;

    protected $casts = [];

    protected $wasRecentlyCreated;

    public $exists = false;

    public $incrementing = true;

    protected $with = [];

    protected static $cachedDescriptors;

    public static $snakeAttributes = true;

    public static $resolver = null;

    public function __construct(array $attributes = [])
    {
        $this->syncOriginal();

        $this->fill($attributes);
    }

    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function newInstance($attributes = [], $exists = false)
    {
        // This method just provides a convenient way for us to generate fresh model
        // instances of this current model. It is particularly useful during the
        // hydration of new objects via the Eloquent query builder instances.
        $model = new static((array) $attributes);

        $model->exists = $exists;

        return $model;
    }

    /**
     * @param array $attributes
     * @param string|null $connection
     * @return static
     */
    public function newFromDescriptor($attributes = [], $connection = null)
    {
        $model = $this->newInstance([], true);

        $model->setRawAttributes((array) $attributes, true);

        $model->setConnection($connection ?: $this->connection);

        return $model;
    }

    /**
     * @param $items
     * @param null $connection
     * @return Collection
     */
    public static function hydrate($items, $connection = null)
    {
        $instance = (new static)->setConnection($connection);

        if($items == null)
        {
            return $instance->newCollection([]);
        }

        $items = array_map(function ($item) use ($instance) {
            return $instance->newFromDescriptor($item, null);
        }, $items);

        return $instance->newCollection($items);
    }

    public static function create(array $attributes = [])
    {
        $model = new static($attributes);

        $model->save();

        return $model;
    }

    public static function all()
    {
        $instance = new static;

        return $instance->newDescriptor()->get();
    }

    public static function findOrNew($id)
    {
        if (! is_null($model = static::find($id))) {
            return $model;
        }

        return new static;
    }

    /**
     * @return static
     */
    public function fresh()
    {
        if (! $this->exists) {
            return null;
        }

        $instance = new static;

        return $instance->newDescriptor()->find($this->getKey());
    }

    public static function destroy($ids)
    {
        // We'll initialize a count here so we will return the total number of deletes
        // for the operation. The developers can then check this number as a boolean
        // type value or get this total count of records deleted for logging, etc.
        $count = 0;

        $ids = is_array($ids) ? $ids : func_get_args();

        $instance = new static;

        // We will actually pull the models from the database table and call delete on
        // each of them individually so that their events get fired properly with a
        // correct set of attributes in case the developers wants to check these.

        foreach ($ids as $id)
        {
            if($model = static::find($id))
            {
                if ($model->delete())
                {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function load($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $descriptor = $this->newDescriptor()->with($relations);

        $descriptor->eagerLoadRelations([$this]);

        return $this;
    }

    public static function with($relations)
    {
        $relations = is_array($relations) ? $relations : func_get_args();

        $instance = new static;

        return $instance->newDescriptor()->with($relations);
    }

    public function delete()
    {
        if (is_null($this->getKeyName())) {
            throw new Exception('No primary key defined on model.');
        }

        if ($this->exists) {

            $descriptor = $this->newDescriptor();

            $this->performDeleteOnModel($descriptor);

            $this->exists = false;

            return true;
        }
    }

    protected function performDeleteOnModel(Descriptor $descriptor)
    {
        $descriptor->deleteOne($this->getKey());
    }

    public function update(array $attributes = [])
    {
        return $this->fill($attributes)->save();
    }

    public function save()
    {
        $descriptor = $this->newDescriptor();

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            $saved = $this->performUpdate($descriptor);
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $saved = $this->performInsert($descriptor);
        }

        if ($saved) {
            $this->finishSave();
        }

        return $saved;
    }

    protected function finishSave()
    {
        $this->syncOriginal();
    }

    protected function performUpdate(Descriptor $descriptor)
    {
        $dirty = $this->getDirty();

        if (count($dirty) > 0)
        {
            $object = $descriptor->updateOne($this->getKey(), $dirty);

            $this->fill($object->toArray());
        }

        return true;
    }

    protected function performInsert(Descriptor $descriptor)
    {
        // If the model has an incrementing key, we can use the "insertGetId" method on
        // the query builder, which will give us back the final inserted ID for this
        // table from the database. Not all tables have to be incrementing though.
        $attributes = $this->attributes;

        $object = $descriptor->storeOne($attributes);

        $this->fill($object->toArray());

        // We will go ahead and set the exists property to true, so that it is set when
        // the created event is fired, just in case the developer tries to update it
        // during the event. This will allow them to do so and run an update here.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        return true;
    }

    /**
     * @return Descriptor
     */
    public function newDescriptor()
    {
        if($this->connection === null)
        {
            $this->connection = \Config::get('rest.default');
        }

        return $this->getDescriptor()->with($this->with);
    }

    /**
     * @return Descriptor
     * @throws Exception
     */
    public function getDescriptor()
    {
        $config = config("rest.connections.{$this->connection}");

        if($config !== null)
        {
            return app(array_get($config, 'descriptor'), [$this->connection, $this, $config]);
        }

        throw new Exception("Missing config for rest connection [{$this->connection}]");
    }

    public function newCollection(array $models = [])
    {
        return new Collection($models);
    }

    public function getEndpoint()
    {
        if (isset($this->endpoint)) {
            return $this->endpoint;
        }

        return $this->endpoint = str_replace('\\', '', str_rest_url(Str::plural(class_basename($this))));
    }

    public function getKey()
    {
        $keyName = $this->getKeyName();

        if(is_array($keyName))
        {
            $result = [];

            foreach($keyName as $k)
            {
                $result[$k] = $this->getAttribute($k);
            }

            return $result;
        }

        return $this->getAttribute($this->getKeyName());
    }

    public function getKeyForStore()
    {
        $key = $this->getKey();

        // if it's an array then remove the last (current object) id off the end
        if(is_array($key))
        {
            array_pop($key);
        }

        return $key;
    }

    public function getQueueableId()
    {
        return $this->getKey();
    }

    public function getKeyName()
    {
        return $this->primaryKey;
    }

    public function getRouteKey()
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    public function getRouteKeyName()
    {
        return $this->getKeyName();
    }

    public function getForeignKey()
    {
        return Str::snake(class_basename($this)).'_id';
    }

    public function getIncrementing()
    {
        return $this->incrementing;
    }

    public function setIncrementing($value)
    {
        $this->incrementing = $value;

        return $this;
    }

    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function toArray()
    {
        $attributes = $this->attributesToArray();

        return array_merge($attributes, $this->relationsToArray());
    }

    public function attributesToArray()
    {
        $attributes = $this->getArrayableAttributes();

        // If an attribute is a date, we will cast it to a string after converting it
        // to a DateTime / Carbon instance. This is so we will get some consistent
        // formatting while accessing attributes vs. arraying / JSONing a model.
        foreach ($this->getDates() as $key) {
            if (! isset($attributes[$key])) {
                continue;
            }

            $attributes[$key] = $this->serializeDate(
                $this->asDateTime($attributes[$key])
            );
        }

        // Next we will handle any casts that have been setup for this model and cast
        // the values to their appropriate type. If the attribute has a mutator we
        // will not perform the cast on those attributes to avoid any confusion.
        foreach ($this->getCasts() as $key => $value) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $attributes[$key] = $this->castAttribute(
                $key, $attributes[$key]
            );

            if ($attributes[$key] && ($value === 'date' || $value === 'datetime')) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }
        }

        return $attributes;
    }

    protected function getArrayableAttributes()
    {
        return $this->getArrayableItems($this->attributes);
    }

    public function relationsToArray()
    {
        $attributes = [];

        foreach ($this->getArrayableRelations() as $key => $value) {
            // If the values implements the Arrayable interface we can just call this
            // toArray method on the instances which will convert both models and
            // collections to their proper array form and we'll set the values.
            if ($value instanceof Arrayable) {
                $relation = $value->toArray();
            }

            // If the value is null, we'll still go ahead and set it in this list of
            // attributes since null is used to represent empty relationships if
            // if it a has one or belongs to type relationships on the models.
            elseif (is_null($value)) {
                $relation = $value;
            }

            // If the relationships snake-casing is enabled, we will snake case this
            // key so that the relation attribute is snake cased in this returned
            // array to the developers, making this consistent with attributes.
            if (static::$snakeAttributes) {
                $key = Str::snake($key);
            }

            // If the relation value has been set, we will set it on this attributes
            // list for returning. If it was not arrayable or null, we'll not set
            // the value on the array because it is some type of invalid value.
            if (isset($relation) || is_null($value)) {
                $attributes[$key] = $relation;
            }

            unset($relation);
        }

        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable relations.
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        return $this->getArrayableItems($this->relations);
    }

    protected function getArrayableItems(array $values)
    {
        return $values;
    }

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->getAttributeValue($key);
        }

        return $this->getRelationValue($key);
    }

    public function getAttributeValue($key)
    {
        $value = $this->getAttributeFromArray($key);

        // If the attribute exists within the cast array, we will convert it to
        // an appropriate native PHP type dependant upon the associated value
        // given with the key in the pair. Dayle made this comment line up.
        if ($this->hasCast($key)) {
            $value = $this->castAttribute($key, $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        elseif (in_array($key, $this->getDates())) {
            if (! is_null($value)) {
                return $this->asDateTime($value);
            }
        }

        return $value;
    }

    public function getRelationValue($key)
    {
        // If the key already exists in the relationships array, it just means the
        // relationship has already been loaded, so we'll just return it out of
        // here because there is no need to query within the relations twice.
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        // If the "attribute" exists as a method on the model, we will just assume
        // it is a relationship and will load and return results from the query
        // and hydrate the relationship's value on the "relationships" array.
        if (method_exists($this, $key)) {
            return $this->getRelationshipFromMethod($key);
        }

        return null;
    }

    protected function getAttributeFromArray($key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        return null;
    }

    protected function getRelationshipFromMethod($method)
    {
        $relations = $this->$method();

        return $this->relations[$method] = $relations->getResults();
    }

    public function hasCast($key, $types = null)
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }

        return false;
    }

    public function getCasts()
    {
        if ($this->incrementing) {
            return array_merge([
                $this->getKeyName() => 'int',
            ], $this->casts);
        }

        return $this->casts;
    }

    protected function isDateCastable($key)
    {
        return $this->hasCast($key, ['date', 'datetime']);
    }

    protected function isJsonCastable($key)
    {
        return $this->hasCast($key, ['array', 'json', 'object', 'collection']);
    }

    protected function getCastType($key)
    {
        return trim(strtolower($this->getCasts()[$key]));
    }

    protected function castAttribute($key, $value)
    {
        if (is_null($value)) {
            return $value;
        }

        switch ($this->getCastType($key)) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return $this->fromJson($value, true);
            case 'array':
            case 'json':
                return $this->fromJson($value);
            case 'collection':
                return new BaseCollection($this->fromJson($value));
            case 'date':
            case 'datetime':
                return $this->asDateTime($value);
            case 'timestamp':
                return $this->asTimeStamp($value);
            default:
                return $value;
        }
    }

    public function setAttribute($key, $value)
    {
        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a form proper for storage on the database tables using
        // the connection grammar's date format. We will auto set the values.
        if ($value && (in_array($key, $this->getDates()) || $this->isDateCastable($key))) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isJsonCastable($key) && ! is_null($value)) {
            $value = $this->asJson($value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    public function getDates()
    {
        return $this->dates ?: [];
    }

    public function fromDateTime($value)
    {
        $format = $this->getDateFormat();

        $value = $this->asDateTime($value);

        return $value->format($format);
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Carbon\Carbon
     */
    protected function asDateTime($value)
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to reinstantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof Carbon) {
            return $value;
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTime) {
            return Carbon::instance($value);
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        }

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        return Carbon::createFromFormat($this->getDateFormat(), $value);
    }

    protected function asTimeStamp($value)
    {
        return (int) $this->asDateTime($value)->timestamp;
    }

    protected function serializeDate(DateTime $date)
    {
        return $date->format($this->getDateFormat());
    }

    protected function getDateFormat()
    {
        return $this->dateFormat ?: 'c';
    }

    public function setDateFormat($format)
    {
        $this->dateFormat = $format;

        return $this;
    }

    protected function asJson($value)
    {
        return json_encode($value);
    }

    public function fromJson($value, $asObject = false)
    {
        return json_decode($value, ! $asObject);
    }

    public function getAttributes()
    {
        return $this->attributes;
    }

    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->syncOriginal();
        }

        return $this;
    }

    public function getOriginal($key = null, $default = null)
    {
        return Arr::get($this->original, $key, $default);
    }

    public function syncOriginal()
    {
        $this->original = $this->attributes;

        return $this;
    }

    public function syncOriginalAttribute($attribute)
    {
        $this->original[$attribute] = $this->attributes[$attribute];

        return $this;
    }

    public function isDirty($attributes = null)
    {
        $dirty = $this->getDirty();

        if (is_null($attributes)) {
            return count($dirty) > 0;
        }

        if (! is_array($attributes)) {
            $attributes = func_get_args();
        }

        foreach ($attributes as $attribute) {
            if (array_key_exists($attribute, $dirty)) {
                return true;
            }
        }

        return false;
    }

    public function getDirty()
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (! array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key] &&
                ! $this->originalIsNumericallyEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    protected function originalIsNumericallyEquivalent($key)
    {
        $current = $this->attributes[$key];

        $original = $this->original[$key];

        return is_numeric($current) && is_numeric($original) && strcmp((string) $current, (string) $original) === 0;
    }

    public function getRelations()
    {
        return $this->relations;
    }

    public function getRelation($relation)
    {
        return $this->relations[$relation];
    }

    public function relationLoaded($key)
    {
        return array_key_exists($key, $this->relations);
    }

    public function setRelation($relation, $value)
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    public function setRelations(array $relations)
    {
        $this->relations = $relations;

        return $this;
    }

    public function getConnectionName()
    {
        return $this->connection;
    }

    public function setConnection($name)
    {
        $this->connection = $name;

        return $this;
    }

    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    public function offsetExists($offset)
    {
        return isset($this->$offset);
    }

    public function offsetGet($offset)
    {
        return $this->$offset;
    }

    public function offsetSet($offset, $value)
    {
        $this->$offset = $value;
    }

    public function offsetUnset($offset)
    {
        unset($this->$offset);
    }

    public function __isset($key)
    {
        return (isset($this->attributes[$key]) || isset($this->relations[$key])) ||
        (! is_null($this->getAttributeValue($key)));
    }

    public function __unset($key)
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    public function __call($method, $parameters)
    {
        $client = $this->newDescriptor();

        return call_user_func_array([$client, $method], $parameters);
    }

    public static function __callStatic($method, $parameters)
    {
        $instance = new static;

        return call_user_func_array([$instance, $method], $parameters);
    }
}
