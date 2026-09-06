<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Mpadmin2fa\Controller\Admin\MfaController;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\EnrollmentApprovalAuthorizer;
use Mpadmin2fa\Security\Policy;
use Mpadmin2fa\Security\SessionState;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Adapter\Configuration;
use PrestaShopBundle\Security\Admin\Employee;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class EnrollmentApprovalIntegrationTest extends TestCase
{
    public function approvalCases(): array
    {
        return [
            'self approval' => [true, 1, true, 'self_approval'],
            'delegated profile' => [false, 2, true, 'superadmin_required'],
            'missing native permission' => [false, 1, false, 'native_permission_missing'],
            'second SuperAdmin' => [false, 1, true, null],
        ];
    }

    /** @dataProvider approvalCases */
    public function testControllerPersistsOnlyAuthorizedApproval(
        bool $self,
        int $profile,
        bool $permission,
        ?string $reason
    ): void {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => _DB_SERVER_, 'dbname' => _DB_NAME_,
            'user' => _DB_USER_, 'password' => _DB_PASSWD_,
        ]);
        $connection->beginTransaction();
        try {
            $ids = $connection->fetchAll('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee ORDER BY id_employee LIMIT 2');
            self::assertCount(2, $ids, 'Run the employee request fixtures first.');
            $actorId = (int) $ids[0]['id_employee'];
            $targetId = $self ? $actorId : (int) $ids[1]['id_employee'];
            $repository = new SecurityRepository($connection, _DB_PREFIX_);
            $connection->delete(_DB_PREFIX_ . 'mp2fa_approval', ['id_employee' => $targetId]);
            $repository->requestEnrollmentApproval($targetId);
            $lastAudit = (int) $connection->fetchColumn('SELECT MAX(id_audit) FROM ' . _DB_PREFIX_ . 'mp2fa_audit');

            $native = new \Employee($actorId);
            $native->id_profile = $profile;
            $actor = new Employee($native);
            $tokens = new TokenStorage();
            $tokens->setToken(new UsernamePasswordToken($actor, null, 'admin', []));
            $session = new Session(new MockArraySessionStorage());
            $request = Request::create('https://localhost/approval', 'POST');
            $request->setSession($session);
            $stack = new RequestStack();
            $stack->push($request);
            $state = new SessionState($stack);
            $state->markVerified($actorId);
            $csrf = new CsrfTokenManager(null, new SessionTokenStorage($session));
            $request->request->set('mp2fa_csrf_token', $csrf->getToken('mp2fa_approve_' . $targetId)->getValue());

            $authorization = $this->createMock(AuthorizationCheckerInterface::class);
            $authorization->expects(self::once())->method('isGranted')
                ->with('update', 'AdminMpAdmin2faEnrollment')->willReturn($permission);
            $router = $this->createMock(RouterInterface::class);
            $router->method('generate')->willReturn('/approved');
            $container = new Container();
            $container->set('session', $session);
            $container->set('router', $router);
            $container->set('security.csrf.token_manager', $csrf);
            $container->set('security.authorization_checker', $authorization);
            $controller = new MfaController($tokens);
            $controller->setContainer($container);
            $denied = false;
            try {
                $response = $controller->approveEnrollment(
                    $request, $targetId, $repository, $state, new Policy(new Configuration()), new EnrollmentApprovalAuthorizer()
                );
                self::assertSame(302, $response->getStatusCode());
            } catch (AccessDeniedException $exception) {
                $denied = true;
            }
            self::assertSame(null !== $reason, $denied);
            $approval = $connection->fetchAssoc('SELECT * FROM ' . _DB_PREFIX_ . 'mp2fa_approval WHERE id_employee = ?', [$targetId]);
            self::assertSame(null === $reason ? 'approved' : 'pending', $approval['status']);
            self::assertSame(null === $reason ? $actorId : null,
                null === $approval['approved_by'] ? null : (int) $approval['approved_by']);
            $events = $connection->fetchAll('SELECT * FROM ' . _DB_PREFIX_ . 'mp2fa_audit WHERE id_audit > ?', [$lastAudit]);
            self::assertCount(1, $events);
            self::assertSame($actorId, (int) $events[0]['id_employee']);
            self::assertSame(null === $reason ? 'enrollment.approved' : 'enrollment.approval_denied', $events[0]['event']);
            $metadata = json_decode($events[0]['metadata_json'], true);
            self::assertSame($targetId, $metadata['target_employee_id']);
            if (null !== $reason) {
                self::assertSame($reason, $metadata['reason']);
            }
        } finally {
            $connection->rollBack();
            $connection->close();
        }
    }
}
