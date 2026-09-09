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
    public function index(Request $request): string
    {
        return $this->renderer->render(static::TEMPLATE_INDEX, [
            'captchaSiteKey' => $this->contactService->getCaptchaSiteKey(),
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
        $form = $this->contactService->createForm(static::FORM_DEFINITION);

        try {
            $data = array_merge($request->all(), $request->files());
            $result = $this->contactService->process($form, $data);

            // If the form is not valid, return the view with the form and the
            // errors to be shown to the user.
            if (!$result->isValid()) {
                return $this->renderer->render(static::TEMPLATE_INDEX, [
                    'captchaSiteKey' => $this->contactService->getCaptchaSiteKey(),
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
            return $this->renderer->render(static::TEMPLATE_INDEX, [
                'captchaSiteKey' => $this->contactService->getCaptchaSiteKey(),
                'form' => $form,
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
    public function success(): string
    {
        return $this->renderer->render(static::TEMPLATE_SUCCESS);
    }
}
