<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Filters;

use Mpadmin2fa\Grid\Definition\Factory\AuditEventGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;

final class AuditEventFilters extends Filters
{
    protected $filterId = AuditEventGridDefinitionFactory::GRID_ID;

    public static function getDefaults(): array
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'orderBy' => 'id_audit',
            'sortOrder' => 'desc',
            'filters' => [],
        ];
    }
}
