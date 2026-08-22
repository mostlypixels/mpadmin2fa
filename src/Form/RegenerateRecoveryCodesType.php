<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Symfony\Component\OptionsResolver\OptionsResolver;

final class RegenerateRecoveryCodesType extends FactorConfirmationType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'csrf_token_id' => 'mp2fa_recovery_regenerate',
            'submit_label' => 'Create new recovery codes',
        ]);
    }
}
