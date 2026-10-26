<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form - Contact form with webhook delivery.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm\Fixture;

use Derafu\Captcha\Provider\DisabledCaptchaProvider;
use Derafu\Csrf\SessionCsrfTokenManager;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Renderer\FormTwigExtension;
use Derafu\Renderer\Contract\RendererInterface;
use Derafu\Renderer\Engine\Html\TwigHtmlEngine;
use Derafu\Renderer\Renderer;
use Derafu\Routing\Router;
use Derafu\Translation\TranslatorFactory;
use Derafu\Twig\Extension\RoutingExtension;
use Derafu\Twig\Extension\TranslationExtension;
use Derafu\Twig\Provider\DirectoryComponentProvider;
use Derafu\Twig\Service\TwigService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Mezzio\Session\Session;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A consumer application with real Form wiring, session, HTTP client and Twig.
 */
final class ContactApplication
{
    public static function create(bool $debug = false): ContainerBuilder
    {
        $root = dirname(__DIR__, 3);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $root);
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.context', ['URL_HOST' => 'contact.example.com']);
        $loader = new YamlFileLoader($container, new FileLocator($root));
        foreach (['data-processor', 'form', 'csrf'] as $package) {
            $loader->load('vendor/derafu/' . $package . '/resources/config/' . $package . '-services.yaml');
        }
        $loader->load('resources/config/contact-form-services.yaml');
        $container->register(ParameterBagInterface::class, ContainerBag::class)
            ->setArguments([new Reference('service_container')]);
        $container->register(ClientInterface::class, Client::class)
            ->setArguments([['timeout' => 5]]);
        $container->register(HttpFactory::class);
        $container->setAlias(RequestFactoryInterface::class, HttpFactory::class);
        $container->setAlias(StreamFactoryInterface::class, HttpFactory::class);
        // This application explicitly disables captcha; the form retains CSRF.
        $container->register(CaptchaProviderInterface::class, DisabledCaptchaProvider::class);
        $container->register(TranslatorInterface::class, Translator::class)
            ->setFactory([TranslatorFactory::class, 'create'])
            ->setArguments(['es', ['en'], new TaggedIteratorArgument('derafu_translation.resource_provider')])
            ->setPublic(true);
        $container->register(RendererInterface::class)
            ->setFactory([self::class, 'renderer'])
            ->setArguments([new Reference(TranslatorInterface::class), new Reference(FormTwigExtension::class)])
            ->setPublic(true);
        $container->getDefinition(SessionCsrfTokenManager::class)->setPublic(true);
        $container->compile(true);
        $csrf = $container->get(SessionCsrfTokenManager::class);
        if (!$csrf instanceof SessionCsrfTokenManager) {
            throw new \LogicException('The configured CSRF manager has an unexpected type.');
        }
        $csrf->useSession(new Session([]));

        return $container;
    }

    public static function renderer(TranslatorInterface $translator, FormTwigExtension $forms): RendererInterface
    {
        $root = dirname(__DIR__, 3);
        $router = new Router(routes: [
            'homepage' => ['path' => '/', 'handler' => 'app.home'],
            'contact_submit' => ['path' => '/contact/submit', 'handler' => 'app.contact'],
        ]);
        $twig = new TwigService([
            'paths' => [
                $root . '/tests/fixtures/templates',
                $root . '/resources/templates',
                $root . '/vendor/derafu/twig-components/resources/templates',
            ],
            'extra' => false,
            'extensions' => [new TranslationExtension($translator), new RoutingExtension($router), $forms],
            'components' => new DirectoryComponentProvider($root . '/vendor/derafu/twig-components/src/Component'),
        ]);
        $twig->getTwig()->enableStrictVariables();

        return new Renderer(['twig' => new TwigHtmlEngine($twig)]);
    }
}
