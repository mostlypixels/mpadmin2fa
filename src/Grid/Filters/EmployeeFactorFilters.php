<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Filters;

use Mpadmin2fa\Grid\Definition\Factory\EmployeeFactorGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;

final class EmployeeFactorFilters extends Filters
{
    protected $filterId = EmployeeFactorGridDefinitionFactory::GRID_ID;

    public static function getDefaults(): array
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'orderBy' => 'id_employee',
            'sortOrder' => 'asc',
            'filters' => [],
        ];
    }
}
