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

final class OneTimeCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => $options['code_label'],
                'help' => 'Enter the six-digit code currently shown in your authenticator app.',
                'attr' => [
                    'autocomplete' => 'one-time-code',
                    'autofocus' => $options['autofocus'],
                    'inputmode' => 'numeric',
                ],
                'constraints' => [
                    new NotBlank(),
                    new Regex(
                        pattern: '/^\d{6}$/',
                        message: 'Enter the six-digit code shown by your authenticator app.'
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => $options['submit_label'],
                'attr' => ['class' => $options['submit_class']],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'autofocus' => false,
                'code_label' => 'Authenticator app code',
                'csrf_token_id' => 'mp2fa_code',
                'submit_class' => 'btn-primary',
                'submit_label' => 'Verify',
            ])
            ->setAllowedTypes('autofocus', 'bool')
            ->setAllowedTypes('code_label', 'string')
            ->setAllowedTypes('submit_class', 'string')
            ->setAllowedTypes('submit_label', 'string');
    }
}
