<?php namespace RestModel\Repositories\Contracts;

interface PivotEntityRepository extends \LaravelResource\Repositories\Contracts\PivotEntityRepository
{
    public function descriptorForParent($parent);
}
