<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm\Translation;

use Derafu\ContactForm\ContactController;
use Derafu\ContactForm\Translation\ContactFormTranslationResourceProvider;
use Derafu\Form\Lint\FormTranslationAudit;
use Derafu\Translation\Lint\MessageMethod;
use Derafu\Translation\Lint\MessageReference;
use Derafu\Twig\Lint\TwigTranslationAudit;
use Derafu\Twig\Service\TwigService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The package is translated: every message of its code and of its templates has
 * its Spanish translation (in the domain of the exceptions, `errors`, and in the
 * one of the texts of the form, `contact-form`), the catalogue has nothing that
 * they do not use, every text of the templates goes through the translation, and
 * every exception that the package throws is translatable.
 *
 * It is found by reading the code and the templates, so a new message without an
 * entry in the catalogue fails here, instead of showing in the original language
 * when it is shown.
 *
 * `ContactController::trans()` is declared here as a method of messages of the
 * domain `contact-form`, so what its callers write is audited. Two calls can not
 * have a literal, by nature, and they are fixed here by their whole call, so any
 * other message that is not a literal makes this test fail:
 *
 *   - `ContactController::trans()` translates the message that it is given: it is
 *     audited where it is called.
 *   - The message of an exception is translated as it is: it is audited where
 *     the exception is thrown.
 */
#[CoversClass(ContactFormTranslationResourceProvider::class)]
final class ContactFormMessagesTest extends TestCase
{
    public function testThePackageIsTranslated(): void
    {
        $root = dirname(__DIR__, 3);

        // The templates are written for an application that has routes and the
        // form functions: they are only declared here.
        $application = new class () extends AbstractExtension {
            public function getFunctions(): array
            {
                return array_map(
                    fn (string $name) => new TwigFunction($name, fn () => ''),
                    ['path', 'form_start', 'form_global_errors', 'form_element', 'form_captcha', 'form_csrf', 'form_end']
                );
            }
        };

        $report = (new TwigTranslationAudit())->audit(
            $root . '/src',
            $root . '/resources/templates',
            new ContactFormTranslationResourceProvider(),
            (new TwigService([
                'extra' => false,
                'paths' => [$root . '/resources/templates', $root . '/vendor/derafu/twig/resources/templates'],
                'extensions' => [$application],
            ]))->getTwig(),
            messageMethods: [
                new MessageMethod(ContactController::class, 'trans', domain: 'contact-form', id: 0),
            ]
        );

        // Finding nothing would look like a clean result.
        $this->assertFalse($report->nothingFound);
        $this->assertSame([], $report->describe($report->missingTranslations));

        // The texts of the form are in the same domain as the ones of the
        // templates, so the audit of the templates sees them as not used: they
        // are the ones that the audit of the form finds.
        $forms = (new FormTranslationAudit())->audit(
            $root . '/resources/forms',
            new ContactFormTranslationResourceProvider()
        );
        $this->assertFalse($forms->nothingFound);
        $this->assertSame([], $forms->describe($forms->dynamicTexts));
        $this->assertSame([], $forms->describe($forms->withoutDomain));
        $this->assertSame([], $forms->describe($forms->missingTranslations));

        $usedByTheForms = array_map(
            fn ($text) => ['domain' => (string) $text->domain, 'id' => (string) $text->id],
            $forms->texts
        );
        $this->assertSame(
            [],
            $report->describe(array_values(array_filter(
                $report->notUsedBySources,
                fn (array $entry) => !in_array($entry, $usedByTheForms, true)
            )))
        );

        $this->assertSame([], $report->describe($report->notTranslatable));
        $this->assertSame([], $report->describe($report->untranslatedTexts));

        $this->assertSame(
            [
                'Derafu\\ContactForm\\ContactController::trans: '
                    . '$translatable->trans($this->translator, $this->locale)',
                'Derafu\\ContactForm\\ContactController::transThrowable: '
                    . '$e->trans($this->translator, $this->locale)',
            ],
            array_map(fn (MessageReference $reference) => $reference->identity(), $report->dynamicMessages)
        );
    }
}
