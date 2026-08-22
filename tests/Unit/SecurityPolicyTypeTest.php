<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Form\SecurityPolicyType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class SecurityPolicyTypeTest extends TypeTestCase
{
    public function testValidPolicyIsAccepted(): void
    {
        $form = $this->createPolicyForm();
        $form->submit($this->validPolicy());

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid());
        self::assertSame([1, 2], $form->getData()['profiles']);
    }

    public function testUnknownProfileIdsAreRejected(): void
    {
        $form = $this->createPolicyForm();
        $data = $this->validPolicy();
        $data['profiles'] = ['999'];
        $form->submit($data);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('profiles')->getErrors(true)->count());
    }

    public function testSelectedProfileModeRequiresAProfile(): void
    {
        $form = $this->createPolicyForm();
        $data = $this->validPolicy();
        $data['profiles'] = [];
        $form->submit($data);

        self::assertFalse($form->isValid());
        self::assertSame(
            'Select at least one profile when selected-profile enforcement is enabled.',
            $form->get('profiles')->getErrors()[0]->getMessage()
        );
    }

    public function testMalformedCommaSeparatedEmailListIsRejected(): void
    {
        $form = $this->createPolicyForm();
        $data = $this->validPolicy();
        $data['security_recipients'] = 'security@example.com, definitely-not-an-email';
        $form->submit($data);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('security_recipients')->getErrors(true)->count());
    }

    public function testNonCommaSeparatorsAreRejected(): void
    {
        $form = $this->createPolicyForm();
        $data = $this->validPolicy();
        $data['security_recipients'] = 'first@example.com;second@example.com';
        $form->submit($data);

        self::assertFalse($form->isValid());
    }

    public function testOutOfRangeNumbersAreRejected(): void
    {
        $form = $this->createPolicyForm();
        $data = $this->validPolicy();
        $data['step_up_seconds'] = '59';
        $data['password_max_age'] = '0';
        $data['audit_days'] = '0';
        $form->submit($data);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('step_up_seconds')->getErrors(true)->count());
        self::assertGreaterThan(0, $form->get('password_max_age')->getErrors(true)->count());
        self::assertGreaterThan(0, $form->get('audit_days')->getErrors(true)->count());
    }

    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    private function createPolicyForm(): \Symfony\Component\Form\FormInterface
    {
        return $this->factory->create(SecurityPolicyType::class, null, [
            'profile_choices' => [
                'SuperAdmin' => 1,
                'Logistics' => 2,
            ],
            'show_submit' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPolicy(): array
    {
        return [
            'mode' => 'profiles',
            'profiles' => ['1', '2'],
            'step_up_seconds' => '300',
            'password_max_age' => '900',
            'approval_profiles' => ['2'],
            'audit_days' => '90',
            'security_recipients' => 'security@example.com, owner@example.org',
        ];
    }
}
