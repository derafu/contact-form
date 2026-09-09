<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ContactForm\Translation;

use Derafu\Translation\Contract\TranslationResourceProviderInterface;

/**
 * Exposes this package's own translation files to a
 * `TranslationResourceRegistrar`.
 */
final class ContactFormTranslationResourceProvider implements TranslationResourceProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function getDirectories(): iterable
    {
        return [__DIR__ . '/../../resources/translations'];
    }
}
