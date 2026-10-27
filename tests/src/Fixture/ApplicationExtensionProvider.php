<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm\Fixture;

use Derafu\Routing\Contract\RouterInterface;
use Derafu\Twig\Contract\ExtensionProviderInterface;
use Derafu\Twig\Extension\RoutingExtension;

final class ApplicationExtensionProvider implements ExtensionProviderInterface
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function getExtensions(array $options = []): iterable
    {
        yield new RoutingExtension($this->router);
    }
}
