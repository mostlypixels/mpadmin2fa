<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class RecoveryCodeChallengeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recovery_code', TextType::class, [
                'label' => 'Recovery code',
                'attr' => ['autocomplete' => 'one-time-code'],
                'constraints' => [
                    new NotBlank(),
                    new Regex([
                        'pattern' => '/^[A-Fa-f0-9]{5}(?:-[A-Fa-f0-9]{5}){3}$/',
                        'message' => 'Enter a recovery code in the format XXXXX-XXXXX-XXXXX-XXXXX.',
                    ]),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Use recovery code',
                'attr' => ['class' => 'btn-outline-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('csrf_token_id', 'mp2fa_recovery_challenge');
    }
}
