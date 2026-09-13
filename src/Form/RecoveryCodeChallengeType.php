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

final class RecoveryCodeChallengeType extends AbstractType
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
        $builder
            ->add('recovery_code', TextType::class, [
                'label' => $this->trans('Recovery code', [], 'Modules.Mpadmin2fa.Admin'),
                'help' => $this->trans('Enter one of the recovery codes you saved when you set up two-factor authentication.', [], 'Modules.Mpadmin2fa.Admin'),
                'attr' => ['autocomplete' => 'one-time-code'],
                'constraints' => [
                    new NotBlank(),
                    new Regex([
                        'pattern' => '/^[A-Fa-f0-9]{5}(?:-[A-Fa-f0-9]{5}){3}$/',
                        'message' => $this->trans('Enter a recovery code in the format XXXXX-XXXXX-XXXXX-XXXXX.', [], 'Modules.Mpadmin2fa.Admin'),
                    ]),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => $this->trans('Use recovery code', [], 'Modules.Mpadmin2fa.Admin'),
                'attr' => ['class' => 'btn-outline-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('csrf_token_id', 'mp2fa_recovery_challenge');
    }
}
