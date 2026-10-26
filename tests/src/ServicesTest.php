<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form - Contact form with webhook delivery.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm;

use Derafu\ContactForm\ContactController;
use Derafu\ContactForm\ContactService;
use Derafu\ContactForm\Exception\ContactFormException;
use Derafu\ContactForm\Translation\ContactFormTranslationResourceProvider;
use Derafu\Http\Contract\ResponseInterface;
use Derafu\Http\Request;
use Derafu\TestsContactForm\Fixture\ContactApplication;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The complete package configuration, imported by a real consumer application.
 */
#[CoversNothing]
final class ServicesTest extends TestCase
{
    private const VARIABLES = [
        'FORM_CONTACT_ENABLED',
        'FORM_CONTACT_SOURCE',
        'FORM_CONTACT_WEBHOOK_URL',
        'FORM_CONTACT_WEBHOOK_SECRET_KEY',
    ];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    private function parameter(string $name): mixed
    {
        return ContactApplication::create()->getParameter($name);
    }

    #[Test]
    public function theFormIsEnabledByDefault(): void
    {
        $this->assertTrue($this->parameter('form.contact.enabled'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function values(): array
    {
        return [
            'the text false' => ['false', false],
            'zero' => ['0', false],
            'the text true' => ['true', true],
            'one' => ['1', true],
        ];
    }

    #[Test]
    #[DataProvider('values')]
    public function theEnvironmentVariableTurnsItOnOrOff(string $value, bool $expected): void
    {
        putenv('FORM_CONTACT_ENABLED=' . $value);

        $this->assertSame($expected, $this->parameter('form.contact.enabled'));
    }

    #[Test]
    public function theWebhookIsNotSetByDefault(): void
    {
        $this->assertEmpty($this->parameter('form.contact.webhook.url'));
        $this->assertEmpty($this->parameter('form.contact.webhook.secret_key'));
    }

    #[Test]
    public function theWebhookIsTheOneOfTheEnvironment(): void
    {
        putenv('FORM_CONTACT_WEBHOOK_URL=https://hooks.example.com/contact');
        putenv('FORM_CONTACT_WEBHOOK_SECRET_KEY=the-secret');

        $this->assertSame('https://hooks.example.com/contact', $this->parameter('form.contact.webhook.url'));
        $this->assertSame('the-secret', $this->parameter('form.contact.webhook.secret_key'));
    }

    #[Test]
    public function allPackageServicesAreConstructedFromTheCompleteConfiguration(): void
    {
        $container = ContactApplication::create();
        foreach ([ContactController::class, ContactService::class, ContactFormTranslationResourceProvider::class] as $class) {
            $this->assertInstanceOf($class, $container->get($class));
        }
        $service = $container->get(ContactService::class);
        $this->assertInstanceOf(ContactService::class, $service);
        $this->assertTrue($service->isEnabled());
        $this->assertSame(['FORM_CONTACT_WEBHOOK_URL', 'FORM_CONTACT_WEBHOOK_SECRET_KEY'], $service->missingConfiguration());
        $form = $service->createForm();
        $this->assertSame('contact', $form->getSchema()->getName());
        $this->assertTrue($form->isCsrfProtected());
        $result = $service->process($form, []);
        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
    }

    #[Test]
    public function configuredControllerRendersTheUnavailablePageWithItsRealService(): void
    {
        $container = ContactApplication::create(debug: true);
        $controller = $container->get(ContactController::class);
        $this->assertInstanceOf(ContactController::class, $controller);
        $response = $controller->index(new Request('GET', 'https://contact.example.com/contact'));
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('FORM_CONTACT_WEBHOOK_URL', $body);
        $this->assertStringContainsString('FORM_CONTACT_WEBHOOK_SECRET_KEY', $body);
        $this->assertStringContainsString('Contáctanos', $body);
    }

    #[Test]
    public function taggedResourcesTranslateTemplatesAndRealServiceExceptions(): void
    {
        $container = ContactApplication::create();
        $translator = $container->get(TranslatorInterface::class);
        $this->assertInstanceOf(TranslatorInterface::class, $translator);
        $this->assertSame('Contáctanos', $translator->trans('Contact Us', [], 'contact-form'));
        $resources = $container->get(ContactFormTranslationResourceProvider::class);
        $this->assertInstanceOf(ContactFormTranslationResourceProvider::class, $resources);
        $this->assertNotEmpty([...$resources->getDirectories()]);
        $service = $container->get(ContactService::class);
        $this->assertInstanceOf(ContactService::class, $service);
        try {
            $service->sendToWebhook([]);
            $this->fail('The missing webhook configuration was accepted.');
        } catch (ContactFormException $exception) {
            $this->assertSame('Webhook URL is not configured for the contact form.', $exception->getMessage());
            $this->assertSame('La URL del webhook no está configurada para el formulario de contacto.', $exception->trans($translator));
        }
    }
}
