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
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SecurityPolicyType extends AbstractType
{
    public function __construct(
        private readonly ?ProfileChoicesProvider $profileChoicesProvider = null,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $profileChoices = $options['profile_choices']
            ?? $this->profileChoicesProvider?->getChoices()
            ?? [];

        $builder
            ->add('mode', ChoiceType::class, [
                'label' => 'Login enforcement',
                'choices' => [
                    'SuperAdmins' => 'superadmins',
                    'Selected profiles' => 'profiles',
                    'All employees' => 'all',
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('profiles', ChoiceType::class, [
                'label' => 'Profiles',
                'choices' => $profileChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Employees in these profiles must use two-factor authentication when selected-profile enforcement is enabled.',
            ])
            ->add('step_up_seconds', IntegerType::class, [
                'label' => 'Fresh verification lifetime (seconds)',
                'attr' => ['min' => 60],
                'constraints' => [new NotBlank(), new Range(min: 60)],
            ])
            ->add('password_max_age', IntegerType::class, [
                'label' => 'Password-authentication maximum age (seconds)',
                'attr' => ['min' => 60],
                'constraints' => [new NotBlank(), new Range(min: 60)],
            ])
            ->add('approval_profiles', ChoiceType::class, [
                'label' => 'Profiles requiring first-enrollment approval',
                'choices' => $profileChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('audit_days', IntegerType::class, [
                'label' => 'Activity-log retention (days)',
                'attr' => ['min' => 1],
                'constraints' => [new NotBlank(), new Positive()],
            ])
            ->add('security_recipients', TextType::class, [
                'label' => 'Security alert recipients',
                'required' => false,
                'help' => 'Separate multiple email addresses with commas.',
                'constraints' => [
                    new Length(max: 1000),
                    new Callback(self::validateCommaSeparatedEmails(...)),
                ],
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (is_array($data) && 'profiles' === ($data['mode'] ?? null) && empty($data['profiles'])) {
                    $event->getForm()->get('profiles')->addError(new FormError(
                        'Select at least one profile when selected-profile enforcement is enabled.'
                    ));
                }
            });

        if ($options['show_submit']) {
            $builder->add('save', SubmitType::class, [
                'label' => 'Save policy',
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

    public static function validateCommaSeparatedEmails(mixed $value, ExecutionContextInterface $context): void
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
