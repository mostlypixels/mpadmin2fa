<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Mpadmin2fa\Translation\TranslatesMessages;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RecoveryCodeAcknowledgementType extends AbstractType
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
            ->add('saved', CheckboxType::class, [
                'label' => $this->trans('I saved these codes securely.', [], 'Modules.Mpadmin2fa.Admin'),
                'constraints' => [
                    new IsTrue(['message' => $this->trans('Confirm that the recovery codes were saved before continuing.', [], 'Modules.Mpadmin2fa.Admin')]),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => $this->trans('Continue', [], 'Modules.Mpadmin2fa.Admin'),
                'attr' => ['class' => 'btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('csrf_token_id', 'mp2fa_recovery_ack');
    }
}
