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
use Derafu\Http\Response;
use Derafu\Renderer\Contract\RendererInterface;
use Exception;

/**
 * Controller for the contact form.
 */
class ContactController
{
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
     */
    public function __construct(
        private readonly RendererInterface $renderer,
        private readonly ContactService $contactService,
    ) {
    }

    /**
     * Render the contact form.
     *
     * @return string
     */
    public function index(): string
    {
        return $this->renderer->render(static::TEMPLATE_INDEX, [
            'captchaSiteKey' => $this->contactService->getCaptchaSiteKey(),
            'form' => $this->contactService->createForm(static::FORM_DEFINITION),
        ]);
    }

    /**
     * Process the data sent by the user.
     *
     * @return string|ResponseInterface
     */
    public function submit(): string|ResponseInterface
    {
        $form = $this->contactService->createForm(static::FORM_DEFINITION);

        try {
            $result = $this->contactService->process($form);

            // If the form is not valid, return the view with the form and the
            // errors to be shown to the user.
            if (!$result->isValid()) {
                return $this->renderer->render(static::TEMPLATE_INDEX, [
                    'captchaSiteKey' => $this->contactService->getCaptchaSiteKey(),
                    'form' => $result->getForm(),
                    'error' => $result->hasErrors()
                        ? 'There were errors in the form. Please fix them and try again.'
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
                'error' => $e->getMessage(),
            ]);
        }
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
