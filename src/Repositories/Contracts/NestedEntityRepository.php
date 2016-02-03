<?php namespace RestModel\Repositories\Contracts;

interface NestedEntityRepository extends \LaravelResource\Repositories\Contracts\NestedEntityRepository
{
    public function descriptorForParent($parent);
}
