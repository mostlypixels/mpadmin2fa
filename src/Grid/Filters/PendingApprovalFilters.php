<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Filters;

use Mpadmin2fa\Grid\Definition\Factory\PendingApprovalGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;

final class PendingApprovalFilters extends Filters
{
    protected $filterId = PendingApprovalGridDefinitionFactory::GRID_ID;

    public static function getDefaults(): array
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'orderBy' => 'date_add',
            'sortOrder' => 'asc',
            'filters' => [],
        ];
    }
}
