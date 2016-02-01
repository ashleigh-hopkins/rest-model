<?php namespace Repositories\Contracts;

use Database\Rest\Descriptors\Contracts\Descriptor;

interface EntityRepository extends \LaravelResource\Repositories\Contracts\EntityRepository
{
    /**
     * @return Descriptor
     */
    public function descriptor();
}
