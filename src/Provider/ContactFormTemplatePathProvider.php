<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ContactForm\Provider;

use Derafu\Twig\Contract\TemplatePathProviderInterface;

final class ContactFormTemplatePathProvider implements TemplatePathProviderInterface
{
    public function getPaths(): array
    {
        return [dirname(__DIR__, 2) . '/resources/templates'];
    }
}
