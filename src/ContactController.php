<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ContactForm;

use Derafu\Http\Contract\ResponseInterface;
use Derafu\Http\Enum\HttpStatus;
use Derafu\Http\Request;
use Derafu\Http\Response;
use Derafu\Renderer\Contract\RendererInterface;
use Derafu\Translation\Contract\TranslatableInterface;
use Derafu\Translation\TranslatableMessage;
use Exception;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Controller for the contact form.
 */
class ContactController
{
    /**
     * Translation domain for this package's own UI strings.
     *
     * @var string
     */
    protected const DOMAIN = 'contact-form+intl-icu';

    /**
     * Form type for the contact form.
     *
     * @var string
     */
    protected const FORM_TYPE = 'contact';

    /**
     * Form definition for the contact form.
     *
     * @var string
     */
    protected const FORM_DEFINITION = __DIR__ . '/../resources/forms/contact-form.yaml';

    /**
     * Template for the contact form.
     *
     * @var string
     */
    protected const TEMPLATE_INDEX = 'contact/index.html.twig';

    /**
     * Template for the contact form success page.
     *
     * @var string
     */
    protected const TEMPLATE_SUCCESS = 'contact/success.html.twig';

    /**
     * Template that says that the contact form is not available.
     *
     * @var string
     */
    protected const TEMPLATE_UNAVAILABLE = 'contact/unavailable.html.twig';

    /**
     * URI for the contact form success page.
     *
     * @var string
     */
    protected const URI_SUCCESS = '/contact/success';

    /**
     * Constructor.
     *
     * @param RendererInterface $renderer
     * @param ContactService $contactService
     * @param TranslatorInterface|null $translator The translator to use, or
     * `null` to fall back to ICU formatting without translation.
     * @param string|null $locale The locale to translate to, or `null` to
     * use the translator's default.
     */
    public function __construct(
        private readonly RendererInterface $renderer,
        private readonly ContactService $contactService,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?string $locale = null,
    ) {
    }

    /**
     * Render the contact form.
     *
     * @return string
     */
    public function index(Request $request): string|ResponseInterface
    {
        if (($unavailable = $this->unavailable()) !== null) {
            return $unavailable;
        }

        return $this->renderer->render(static::TEMPLATE_INDEX, [
            'form' => $this->contactService->createForm(
                static::FORM_DEFINITION,
                $request->all()
            ),
        ]);
    }

    /**
     * Process the data sent by the user.
     *
     * @return string|ResponseInterface
     */
    public function submit(Request $request): string|ResponseInterface
    {
        if (($unavailable = $this->unavailable()) !== null) {
            return $unavailable;
        }

        $form = $this->contactService->createForm(static::FORM_DEFINITION);

        try {
            $data = array_merge($request->all(), $request->files());
            $result = $this->contactService->process($form, $data);

            // If the form is not valid, return the view with the form and the
            // errors to be shown to the user.
            if (!$result->isValid()) {
                return $this->renderer->render(static::TEMPLATE_INDEX, [
                    'form' => $result->getForm(),
                    'error' => $result->hasErrors()
                        ? $this->trans('There were errors in the form. Please fix them and try again.')
                        : null
                    ,
                ]);
            }

            // Send the message to the webhook.
            $meta = [
                'form' => static::FORM_TYPE,
            ];
            $this->contactService->sendToWebhook($result->getProcessedData(), $meta);

            // Redirect to the success page.
            return (new Response())->redirect(static::URI_SUCCESS);
        } catch (Exception $e) {
            // The user gets the form back with what it wrote, to try again.
            return $this->renderer->render(static::TEMPLATE_INDEX, [
                'form' => $this->contactService->createForm(
                    static::FORM_DEFINITION,
                    $request->all()
                ),
                'error' => $this->transThrowable($e),
            ]);
        }
    }

    /**
     * Translates a message using this package's own domain.
     *
     * @param string $message The message to translate.
     * @param array<string, mixed> $parameters Parameters for translation
     * placeholders.
     * @return string The translated message.
     */
    private function trans(string $message, array $parameters = []): string
    {
        $translatable = new TranslatableMessage(
            $message,
            $parameters,
            static::DOMAIN,
            $this->locale
        );

        if ($this->translator === null) {
            return (string) $translatable;
        }

        return $translatable->trans($this->translator, $this->locale);
    }

    /**
     * Translates a caught throwable's message, when possible.
     *
     * @param Throwable $e The throwable to translate.
     * @return string The translated message, or the throwable's own message
     * when there is no translator or it is not translatable.
     */
    private function transThrowable(Throwable $e): string
    {
        if ($this->translator !== null && $e instanceof TranslatableInterface) {
            return $e->trans($this->translator, $this->locale);
        }

        return $e->getMessage();
    }

    /**
     * Render the success page.
     *
     * @return string
     */
    public function success(): string|ResponseInterface
    {
        if (($unavailable = $this->unavailable()) !== null) {
            return $unavailable;
        }

        return $this->renderer->render(static::TEMPLATE_SUCCESS);
    }

    /**
     * The page that says that the contact form is not available, or null if it is.
     *
     * It is the same page when the application turned the form off
     * (`FORM_CONTACT_ENABLED=false`, a `200`: it is not an error) and when the
     * form is on but lacks what it needs to send its messages (a `503`: it is
     * a configuration that is missing). It is a page of its own, and not the
     * template of the form, so every site shows it, whatever its copy of the
     * template of the form has. What is wrong is told only in debug.
     */
    private function unavailable(): ?ResponseInterface
    {
        $enabled = $this->contactService->isEnabled();
        $missing = $enabled ? $this->contactService->missingConfiguration() : [];

        if ($enabled && $missing === []) {
            return null;
        }

        $detail = null;
        if ($this->contactService->isDebug()) {
            $detail = $enabled
                ? $this->trans('The contact form needs these variables: {variables}.', [
                    'variables' => implode(', ', $missing),
                ])
                : $this->trans('The contact form is turned off: FORM_CONTACT_ENABLED is false.')
            ;
        }

        return (new Response())
            ->asHtml($this->renderer->render(static::TEMPLATE_UNAVAILABLE, [
                'detail' => $detail,
            ]))
            ->withHttpStatus($enabled ? HttpStatus::SERVICE_UNAVAILABLE : HttpStatus::OK)
        ;
    }
}
