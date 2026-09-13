<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Mpadmin2fa\Translation\TranslatesMessages;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class FactorConfirmationType extends AbstractType
{
    use TranslatesMessages;

    /** @var TranslatorInterface|null */
    private $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordHelp = $options['password_required']
            ? null
            : $this->trans('You do not need to enter it again because you signed in recently.', [], 'Modules.Mpadmin2fa.Admin');

        $builder
            ->add('password', PasswordType::class, [
                'label' => $options['password_required']
                    ? $this->trans('Your PrestaShop password', [], 'Modules.Mpadmin2fa.Admin')
                    : $this->trans('Your PrestaShop password (optional)', [], 'Modules.Mpadmin2fa.Admin'),
                'required' => $options['password_required'],
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => $options['password_required'] ? [new NotBlank()] : [],
            ])
            ->add('code', TextType::class, [
                'label' => $this->trans('Code from your authenticator app', [], 'Modules.Mpadmin2fa.Admin'),
                'attr' => [
                    'autocomplete' => 'one-time-code',
                    'inputmode' => 'numeric',
                ],
                'constraints' => [
                    new NotBlank(),
                    new Regex([
                        'pattern' => '/^\d{6}$/',
                        'message' => $this->trans('Enter the six-digit code shown by your authenticator app.', [], 'Modules.Mpadmin2fa.Admin'),
                    ]),
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
            ->setRequired(['csrf_token_id', 'submit_label'])
            ->setDefaults([
                'password_required' => true,
                'submit_class' => 'btn-outline-primary',
            ])
            ->setAllowedTypes('password_required', 'bool')
            ->setAllowedTypes('submit_class', 'string')
            ->setAllowedTypes('submit_label', 'string');
    }
}
