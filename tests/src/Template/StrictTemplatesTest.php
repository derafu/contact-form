<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form - Contact form with webhook delivery.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm\Template;

use Derafu\ContactForm\ContactController;
use Derafu\Http\Contract\ResponseInterface;
use Derafu\Http\Request;
use Derafu\Renderer\Contract\RendererInterface;
use Derafu\Renderer\Engine\Html\TwigHtmlEngine;
use Derafu\TestsContactForm\Fixture\ContactApplication;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Render package pages and their components with real dependencies and strict
 * Twig variables, using only an application's layout fixture.
 */
#[CoversNothing]
final class StrictTemplatesTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FORM_CONTACT_WEBHOOK_URL=https://hooks.example.com/contact');
        putenv('FORM_CONTACT_WEBHOOK_SECRET_KEY=test-secret');
    }

    protected function tearDown(): void
    {
        foreach (['FORM_CONTACT_WEBHOOK_URL', 'FORM_CONTACT_WEBHOOK_SECRET_KEY', 'FORM_CONTACT_ENABLED'] as $name) {
            putenv($name);
        }
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function pages(): array
    {
        return [
            'initial form' => ['index', 200, 'name="name"'],
            'invalid submission' => ['submit', 200, 'Hay errores en el formulario.'],
            'success' => ['success', 200, 'Gracias por contactarnos.'],
            'disabled with debug details' => ['disabled', 200, 'FORM_CONTACT_ENABLED'],
            'missing configuration with debug details' => ['unconfigured', 503, 'FORM_CONTACT_WEBHOOK_URL'],
        ];
    }

    #[DataProvider('pages')]
    public function testPagesRenderWithStrictVariables(string $page, int $status, string $content): void
    {
        if ($page === 'disabled') {
            putenv('FORM_CONTACT_ENABLED=false');
        } elseif ($page === 'unconfigured') {
            putenv('FORM_CONTACT_WEBHOOK_URL');
            putenv('FORM_CONTACT_WEBHOOK_SECRET_KEY');
        }
        $container = ContactApplication::create(debug: true);
        $renderer = $container->get(RendererInterface::class);
        self::assertInstanceOf(RendererInterface::class, $renderer);
        $engine = $renderer->getEngine('twig');
        self::assertInstanceOf(TwigHtmlEngine::class, $engine);
        self::assertTrue($engine->getTwig()->isStrictVariables());
        $controller = $container->get(ContactController::class);
        self::assertInstanceOf(ContactController::class, $controller);
        $response = match ($page) {
            'success' => $controller->success(),
            'submit' => $controller->submit((new Request('POST', 'https://contact.example.com/contact/submit'))->withParsedBody([])),
            default => $controller->index(new Request('GET', 'https://contact.example.com/contact')),
        };

        if ($response instanceof ResponseInterface) {
            self::assertSame($status, $response->getStatusCode());
            $html = (string) $response->getBody();
        } else {
            self::assertSame(200, $status);
            $html = $response;
        }
        self::assertStringContainsString($content, $html);
        self::assertStringContainsString('<main>', $html);
    }
}
