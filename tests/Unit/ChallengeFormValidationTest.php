<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Form\OneTimeCodeType;
use Mpadmin2fa\Form\RecoveryCodeAcknowledgementType;
use Mpadmin2fa\Form\RecoveryCodeChallengeType;
use Mpadmin2fa\Form\ReplaceFactorType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class ChallengeFormValidationTest extends TypeTestCase
{
    public function testOneTimeCodeMustContainExactlySixDigits(): void
    {
        $form = $this->factory->create(OneTimeCodeType::class);
        $form->submit(['code' => '12345a']);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('code')->getErrors(true)->count());

        $form = $this->factory->create(OneTimeCodeType::class);
        $form->submit(['code' => '123456']);

        self::assertTrue($form->isValid());
    }

    public function testRecoveryCodeAcceptsOnlySafeFormattingVariants(): void
    {
        foreach ([
            'not-a-recovery-code',
            'A1B2C-D3E4F-56789-ABCD',
            'A1B2C-D3E4F-56789-ABCDG',
            'A1B2C.D3E4F.56789.ABCDE',
            "A1B2C\tD3E4F 56789 ABCDE",
        ] as $invalid) {
            $form = $this->factory->create(RecoveryCodeChallengeType::class);
            $form->submit(['recovery_code' => $invalid]);

            self::assertFalse($form->isValid());
        }

        foreach ([
            'A1B2C-D3E4F-56789-ABCDE',
            'a1b2c-d3e4f-56789-abcde',
            'A1B2CD3E4F56789ABCDE',
            'A1B2C D3E4F 56789 ABCDE',
            'a1b2c - D3e4f 56789-abcde',
        ] as $valid) {
            $form = $this->factory->create(RecoveryCodeChallengeType::class);
            $form->submit(['recovery_code' => $valid]);

            self::assertTrue($form->isValid());
        }
    }

    public function testRecoveryCodesMustBeAcknowledged(): void
    {
        $form = $this->factory->create(RecoveryCodeAcknowledgementType::class);
        $form->submit(['saved' => false]);

        self::assertFalse($form->isValid());

        $form = $this->factory->create(RecoveryCodeAcknowledgementType::class);
        $form->submit(['saved' => true]);

        self::assertTrue($form->isValid());
    }

    public function testFactorConfirmationRequiresPasswordWhenSessionIsNotFresh(): void
    {
        $form = $this->factory->create(ReplaceFactorType::class, null, [
            'password_required' => true,
        ]);
        $form->submit(['password' => '', 'code' => '123456']);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('password')->getErrors(true)->count());
    }

    public function testFactorConfirmationAllowsMissingPasswordForFreshSession(): void
    {
        $form = $this->factory->create(ReplaceFactorType::class, null, [
            'password_required' => false,
        ]);
        $form->submit(['password' => '', 'code' => '123456']);

        self::assertTrue($form->isValid());
    }

    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }
}
