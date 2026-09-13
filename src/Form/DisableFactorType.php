<?php

declare(strict_types=1);

namespace Mpadmin2fa\Form;

use Symfony\Component\OptionsResolver\OptionsResolver;

final class DisableFactorType extends FactorConfirmationType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'csrf_token_id' => 'mp2fa_disable',
            'submit_class' => 'btn-outline-danger',
            'submit_label' => $this->trans('Turn off two-factor authentication', [], 'Modules.Mpadmin2fa.Admin'),
        ]);
    }
}
