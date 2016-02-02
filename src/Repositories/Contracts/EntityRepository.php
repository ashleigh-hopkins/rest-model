<?php namespace RestModel\Repositories\Contracts;

use RestModel\Database\Rest\Descriptors\Contracts\Descriptor;

interface EntityRepository extends \LaravelResource\Repositories\Contracts\EntityRepository
{
    /**
     * @return Descriptor
     */
    public function descriptor();
}
