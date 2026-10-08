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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * What an application gets by importing the file of the package: the parameters
 * that the environment variables of the form set.
 */
#[CoversNothing]
final class ContactServicesTest extends TestCase
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
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/resources/config')))
            ->load('contact-form-services.yaml');

        // Only the parameters: the services need the application around them.
        foreach (array_keys($container->getDefinitions()) as $id) {
            if ($id !== 'service_container') {
                $container->removeDefinition($id);
            }
        }
        $container->compile(true);

        return $container->getParameter($name);
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
}
