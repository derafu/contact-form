<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm\Provider;

use Derafu\ContactForm\ContactController;
use Derafu\ContactForm\Provider\ContactFormTemplatePathProvider;
use Derafu\Csrf\Contract\SessionCsrfTokenManagerInterface;
use Derafu\Form\Contract\Renderer\FormRendererInterface;
use Derafu\Http\Request;
use Derafu\Renderer\Contract\RendererInterface;
use Derafu\Renderer\Engine\Html\TwigHtmlEngine;
use Derafu\TestsContactForm\Fixture\ContactApplication;
use Derafu\Twig\Contract\TwigServiceInterface;
use Mezzio\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

#[CoversClass(ContactFormTemplatePathProvider::class)]
#[UsesNamespace('Derafu\ContactForm')]
final class ContactFormTemplatePathProviderTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FORM_CONTACT_WEBHOOK_URL=https://hooks.example.com/contact');
        putenv('FORM_CONTACT_WEBHOOK_SECRET_KEY=test-secret');
        putenv('FORM_CONTACT_ENABLED=true');
    }

    protected function tearDown(): void
    {
        foreach (['FORM_CONTACT_WEBHOOK_URL', 'FORM_CONTACT_WEBHOOK_SECRET_KEY', 'FORM_CONTACT_ENABLED'] as $name) {
            putenv($name);
        }
    }

    private function assertSharedRendering(ContainerInterface $container): void
    {
        $renderer = $container->get(RendererInterface::class);
        $forms = $container->get(FormRendererInterface::class);
        $twig = $container->get(TwigServiceInterface::class);
        self::assertInstanceOf(RendererInterface::class, $renderer);
        self::assertInstanceOf(FormRendererInterface::class, $forms);
        self::assertInstanceOf(TwigServiceInterface::class, $twig);
        self::assertSame($renderer, $forms->getRenderer());
        $engine = $renderer->getEngine('twig');
        self::assertInstanceOf(TwigHtmlEngine::class, $engine);
        self::assertSame($twig->getTwig(), $engine->getTwig());
        $controller = $container->get(ContactController::class);
        self::assertInstanceOf(ContactController::class, $controller);
        $html = $controller->index(new Request('GET', 'https://contact.example.com/contact'));
        self::assertIsString($html);
        self::assertStringContainsString('name="name"', $html);
        self::assertStringContainsString('name="_csrf_token"', $html);
        self::assertStringContainsString('derafu-block-hero', $html);
        self::assertStringContainsString('<main>', $html);
    }

    public function testContactFormUsesSharedRenderingAndContributedTemplates(): void
    {
        $this->assertSharedRendering(ContactApplication::create());
    }

    public function testCompiledServicesRenderTheProtectedContactForm(): void
    {
        $container = ContactApplication::create();
        $class = 'ContactFormSharedServicesContainer';
        $code = '';
        if (!class_exists($class, false)) {
            $dump = (new PhpDumper($container))->dump(['class' => $class]);
            self::assertIsString($dump);
            $code = substr($dump, 5);
        }
        $compiled = eval($code . "\nreturn new " . $class . '();');
        self::assertInstanceOf(ContainerInterface::class, $compiled);
        $csrf = $compiled->get(SessionCsrfTokenManagerInterface::class);
        self::assertInstanceOf(SessionCsrfTokenManagerInterface::class, $csrf);
        $csrf->useSession(new Session([]));
        $this->assertSharedRendering($compiled);
    }
}
