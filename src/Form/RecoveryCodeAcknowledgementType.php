<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

final class RecoveryCodeAcknowledgementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('saved', CheckboxType::class, [
                'label' => 'I saved these codes securely.',
                'constraints' => [
                    new IsTrue(['message' => 'Confirm that the recovery codes were saved before continuing.']),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Continue',
                'attr' => ['class' => 'btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('csrf_token_id', 'mp2fa_recovery_ack');
    }
}
