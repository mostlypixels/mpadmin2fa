<?php

declare(strict_types=1);

namespace Mpadmin2fa\Translation;

/**
 * Keeps the English source when the class is built without a translator, as unit tests do.
 */
trait TranslatesMessages
{
    /**
     * @param array<string, string> $parameters
     */
    protected function trans(string $id, array $parameters, string $domain): string
    {
        if (null === $this->translator) {
            return strtr($id, $parameters);
        }

        return $this->translator->trans($id, $parameters, $domain);
    }
}
