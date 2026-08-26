<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SecurityPolicyType extends AbstractType
{
    /** @var ?ProfileChoicesProvider */
    private $profileChoicesProvider;

    /** @var ?AuthorizationCheckerInterface */
    private $authorizationChecker;

    public function __construct(
        ?ProfileChoicesProvider $profileChoicesProvider = null,
        ?AuthorizationCheckerInterface $authorizationChecker = null
    ) {
        $this->profileChoicesProvider = $profileChoicesProvider;
        $this->authorizationChecker = $authorizationChecker;

    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $profileChoices = $options['profile_choices']
            ?? (null !== $this->profileChoicesProvider ? $this->profileChoicesProvider->getChoices() : null)
            ?? [];

        $builder
            ->add('mode', ChoiceType::class, [
                'label' => 'Who must use two-factor authentication',
                'choices' => [
                    'SuperAdmins' => 'superadmins',
                    'Selected profiles' => 'profiles',
                    'All employees' => 'all',
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('profiles', ChoiceType::class, [
                'label' => 'Profiles required to use two-factor authentication',
                'choices' => $profileChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('step_up_seconds', IntegerType::class, [
                'label' => 'How long a 2FA check stays valid (seconds)',
                'attr' => ['min' => 60],
                'constraints' => [new NotBlank(), new Range(['min' => 60])],
            ])
            ->add('password_max_age', IntegerType::class, [
                'label' => 'How long a recent sign-in counts (seconds)',
                'attr' => ['min' => 60],
                'constraints' => [new NotBlank(), new Range(['min' => 60])],
            ])
            ->add('approval_profiles', ChoiceType::class, [
                'label' => 'Profiles whose first 2FA setup needs approval',
                'choices' => $profileChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('audit_days', IntegerType::class, [
                'label' => 'Keep security activity for (days)',
                'attr' => ['min' => 1],
                'constraints' => [new NotBlank(), new Range(['min' => 1])],
            ])
            ->add('security_recipients', TextType::class, [
                'label' => 'Security alert recipients',
                'required' => false,
                'constraints' => [
                    new Length(['max' => 1000]),
                    new Callback([self::class, 'validateCommaSeparatedEmails']),
                ],
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (is_array($data) && 'profiles' === ($data['mode'] ?? null) && empty($data['profiles'])) {
                    $event->getForm()->get('profiles')->addError(new FormError(
                        'Select at least one profile when "Selected profiles" is chosen.'
                    ));
                }
            });

        if ($options['show_submit']) {
            $builder->add('save', SubmitType::class, [
                'label' => 'Save security settings',
                'attr' => ['class' => 'btn-primary'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $canUpdate = null === $this->authorizationChecker
            || $this->authorizationChecker->isGranted('update', 'AdminMpAdmin2faSecurity');

        $resolver
            ->setDefaults([
                'csrf_token_id' => 'mp2fa_policy',
                'disabled' => !$canUpdate,
                'profile_choices' => null,
                'show_submit' => $canUpdate,
            ])
            ->setAllowedTypes('profile_choices', ['array', 'null'])
            ->setAllowedTypes('show_submit', 'bool');
    }

    public static function validateCommaSeparatedEmails($value, ExecutionContextInterface $context): void
    {
        if (null === $value || '' === trim((string) $value)) {
            return;
        }

        foreach (explode(',', (string) $value) as $email) {
            $email = trim($email);
            if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $context->buildViolation('Enter valid email addresses separated by commas.')
                    ->addViolation();

                return;
            }
        }
    }
}
