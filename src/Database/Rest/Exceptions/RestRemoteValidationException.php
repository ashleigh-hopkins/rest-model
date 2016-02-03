<?php namespace RestModel\Database\Rest\Exceptions;

use Exception;

class RestRemoteValidationException extends Exception
{
    protected $errorObject;

    protected $httpCode;

    public function __construct($errorObject, $httpCode)
    {
        parent::__construct("Remote validation exception [{$httpCode}]: ".json_encode($errorObject));

        $this->errorObject = $errorObject;
        $this->httpCode = $httpCode;
    }

    /**
     * @return string
     */
    public function getErrorObject()
    {
        return $this->errorObject;
    }

    /**
     * @return int
     */
    public function getHttpCode()
    {
        return $this->httpCode;
    }
}
