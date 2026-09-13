<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Mpadmin2fa\Translation\TranslatesMessages;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OneTimeCodeType extends AbstractType
{
    use TranslatesMessages;

    public function __construct(private readonly ?TranslatorInterface $translator = null)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => $options['code_label'],
                'help' => $this->trans('Enter the six-digit code currently shown in your authenticator app.', [], 'Modules.Mpadmin2fa.Admin'),
                'attr' => [
                    'autocomplete' => 'one-time-code',
                    'autofocus' => $options['autofocus'],
                    'inputmode' => 'numeric',
                ],
                'constraints' => [
                    new NotBlank(),
                    new Regex(
                        pattern: '/^\d{6}$/',
                        message: $this->trans('Enter the six-digit code shown by your authenticator app.', [], 'Modules.Mpadmin2fa.Admin')
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
                'code_label' => $this->trans('Authenticator app code', [], 'Modules.Mpadmin2fa.Admin'),
                'csrf_token_id' => 'mp2fa_code',
                'submit_class' => 'btn-primary',
                'submit_label' => $this->trans('Verify', [], 'Modules.Mpadmin2fa.Admin'),
            ])
            ->setAllowedTypes('autofocus', 'bool')
            ->setAllowedTypes('code_label', 'string')
            ->setAllowedTypes('submit_class', 'string')
            ->setAllowedTypes('submit_label', 'string');
    }
}
