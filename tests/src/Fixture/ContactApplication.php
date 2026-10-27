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

use Composer\InstalledVersions;
use Derafu\Csrf\Contract\SessionCsrfTokenManagerInterface;
use Derafu\Form\Contract\Renderer\FormRendererInterface;
use Derafu\Renderer\Contract\RendererInterface;
use Derafu\Twig\Contract\TwigServiceInterface;
use LogicException;
use Mezzio\Session\Session;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/** Loads the real package configurations and a consuming application's YAML. */
final class ContactApplication
{
    public static function create(bool $debug = false): ContainerBuilder
    {
        $root = dirname(__DIR__, 3);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $root);
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.context', ['URL_HOST' => 'contact.example.com']);
        (new YamlFileLoader($container, new FileLocator([
            $root . '/tests/fixtures/config',
            (InstalledVersions::getInstallPath('derafu/renderer')
                ?? throw new LogicException('derafu/renderer must be installed for these tests.')) . '/resources/config',
        ])))->load('application.yaml');
        foreach ([SessionCsrfTokenManagerInterface::class, TwigServiceInterface::class, RendererInterface::class, FormRendererInterface::class] as $id) {
            $container->getDefinition($id)->setPublic(true);
        }
        $container->compile(true);
        $csrf = $container->get(SessionCsrfTokenManagerInterface::class);
        if (!$csrf instanceof SessionCsrfTokenManagerInterface) {
            throw new LogicException('The configured CSRF manager has an unexpected type.');
        }
        $csrf->useSession(new Session([]));
        $twig = $container->get(TwigServiceInterface::class);
        if (!$twig instanceof TwigServiceInterface) {
            throw new LogicException('The configured Twig service has an unexpected type.');
        }
        $twig->getTwig()->enableStrictVariables();

        return $container;
    }
}
