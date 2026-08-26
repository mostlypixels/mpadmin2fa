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

        self::assertSame(
            'Profiles required to use two-factor authentication',
            $form->get('profiles')->getConfig()->getOption('label')
        );

        $form->submit($this->validPolicy());

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid());
        self::assertSame([1, 2], $form->getData()['profiles']);
    }

    public function testOperationalSettingsExplainTheirValues(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/security/policy.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString(
            'How long a successful 2FA check stays valid for important security changes. For example, 300 seconds is 5 minutes.',
            $template
        );
        self::assertStringContainsString(
            'How long employees can manage their own two-factor authentication after signing in without entering their password again. For example, 900 seconds is 15 minutes.',
            $template
        );
        self::assertStringContainsString(
            'How many days to keep 2FA security activity in the log. For example, enter 90 to keep three months of activity.',
            $template
        );
        self::assertStringContainsString(
            'Enter the people who should receive 2FA security alerts. Separate multiple email addresses with commas, for example owner@example.com, security@example.com.',
            $template
        );
    }

    public function testProfileEnforcementSettingsExplainTheirValues(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/security/policy.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString(
            'Choose which employees must set up and use two-factor authentication to access the back office.',
            $template
        );
        self::assertStringContainsString(
            'Used only when "Selected profiles" is chosen above. Employees in these profiles must set up and use two-factor authentication to access the back office.',
            $template
        );
        self::assertStringContainsString(
            'Employees in these profiles need approval from another SuperAdmin who recently confirmed their own 2FA. SuperAdmin accounts also use this approval.',
            $template
        );
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
            'Select at least one profile when "Selected profiles" is chosen.',
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
