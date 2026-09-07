<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\AdminMfaAccessPolicy;
use Mpadmin2fa\Security\SensitiveActions;
use PHPUnit\Framework\TestCase;

final class SensitiveActionsTest extends TestCase
{
    public function testMixedAndMalformedActionsCannotHideASensitiveOperation(): void
    {
        $cases = [
            ['action' => 'view', 'disable_device' => 1],
            ['disable_device' => 1, 'action' => 'view'],
            ['action' => 'view', 'submitAction' => 'uninstall'],
            ['module_action' => 'disable'],
            ['bulk_action' => 'upgrade'],
            ['submitBulkdisablemodule' => [1, 2]],
            ['action' => ['unexpected' => 'view']],
            ['action' => 'view', 'configure' => 'module'],
            ['submitView' => 1],
        ];
        foreach ($cases as $parameters) {
            $action = SensitiveActions::fromParameters($parameters);
            self::assertTrue(SensitiveActions::contains($action), json_encode($parameters));
            self::assertSame(AdminMfaAccessPolicy::REQUIRE_STEP_UP, (new AdminMfaAccessPolicy())->decide('legacy', 'AdminModules', $action, true, true, true, true, false, false));
        }
        self::assertFalse(SensitiveActions::contains(SensitiveActions::fromParameters(['action' => 'view'])));
        self::assertFalse(SensitiveActions::contains(SensitiveActions::fromParameters(['page' => 2])));
    }

    public function testQueryAndPostAreClassifiedIndependently(): void
    {
        $query = ['action' => 'uninstall'];
        $post = ['action' => 'view'];
        $action = SensitiveActions::fromParameters($query) . ' ' . SensitiveActions::fromParameters($post);
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_STEP_UP, (new AdminMfaAccessPolicy())->decide('legacy', 'AdminModules', $action, true, true, true, true, false, false));
    }
}
