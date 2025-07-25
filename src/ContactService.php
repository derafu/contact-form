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

use Derafu\Form\Contract\Factory\FormFactoryInterface;
use Derafu\Form\Contract\FormInterface;
use Derafu\Form\Contract\Processor\FormDataProcessorInterface;
use Derafu\Form\Contract\Processor\ProcessResultInterface;
use Exception;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Contact Form Service.
 *
 * Handles contact form configuration, processing, and webhook communication.
 */
class ContactService
{
    /**
     * Form definition for the contact form.
     *
     * Syntax, similar to JSON Schema and JSON Forms, using Derafu Form.
     *
     * @var array
     */
    private array $formDefinition;

    /**
     * Captcha site key.
     *
     * @var string|null
     */
    private ?string $captchaSiteKey = null;

    /**
     * Captcha secret key.
     *
     * @var string|null
     */
    private ?string $captchaSecretKey = null;

    /**
     * Webhook URL for processing the form.
     *
     * @var string|null
     */
    private ?string $webhookUrl = null;

    /**
     * Webhook secret key for signing the message sent to the webhook.
     *
     * @var string|null
     */
    private ?string $webhookSecretKey = null;

    /**
     * Constructor.
     *
     * @param FormFactoryInterface $formFactory
     * @param FormDataProcessorInterface $formDataProcessor
     * @param ParameterBagInterface $parameterBag
     */
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly FormDataProcessorInterface $formDataProcessor,
        private readonly ParameterBagInterface $parameterBag,
    ) {
        // Load the form definition.
        $formDefinition = $this->parameterBag->get('form.contact.definition');
        if (is_string($formDefinition)) {
            $this->formDefinition = Yaml::parseFile(
                __DIR__ . '/' . $formDefinition
            );
        } else {
            $this->formDefinition = $formDefinition;
        }

        // Load the captcha configuration.
        $this->captchaSiteKey = $this->parameterBag->get('captcha.site_key');
        $this->captchaSecretKey = $this->parameterBag->get('captcha.secret_key');

        // Load the webhook configuration.
        $this->webhookUrl = $this->parameterBag->get('form.contact.webhook.url');
        $this->webhookSecretKey = $this->parameterBag->get(
            'form.contact.webhook.secret_key'
        );
    }

    /**
     * Create a new form instance.
     *
     * @return FormInterface
     */
    public function createForm(): FormInterface
    {
        return $this->formFactory->create($this->formDefinition);
    }

    /**
     * Process the form data.
     *
     * @return ProcessResultInterface
     */
    public function process(): ProcessResultInterface
    {
        $form = $this->createForm();

        return $this->formDataProcessor->process($form);
    }

    /**
     * Send the processed data to the webhook.
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function sendToWebhook(array $data): array
    {
        if (!$this->webhookUrl) {
            throw new Exception('Webhook URL is not configured.');
        }

        $this->validateCaptcha($data);

        return $this->sendMessage($data);
    }

    /**
     * Get the captcha site key.
     *
     * @return string|null
     */
    public function getCaptchaSiteKey(): ?string
    {
        return $this->captchaSiteKey;
    }

    /**
     * Validate the captcha.
     *
     * @param array $data
     * @return void
     */
    private function validateCaptcha(array $data): void
    {
        // If the captcha is not configured, skip validation.
        if (!$this->captchaSiteKey || !$this->captchaSecretKey) {
            return;
        }

        // Validate the captcha.
        // TODO: Implement captcha validation and throw an exception if it fails.
    }

    /**
     * Send the message of the form using the webhook.
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    private function sendMessage(array $data): array
    {
        // Initialize the client Guzzle.
        $client = new Client();

        // Build the payload.
        $payload = [
            'meta' => [
                'app' =>
                    $this->parameterBag->get('form.contact.app')
                    ?? $this->parameterBag->get('kernel.context')['URL_HOST']
                    ?? throw new Exception('Parameter form.contact.app is not configured.')
                ,
                'form' => $this->parameterBag->get('form.contact.id'),
                'timestamp' => time(),
            ],
            'data' => $data,
        ];

        // If the webhook secret key is configured, sign the payload.
        if ($this->webhookSecretKey) {
            $signature = hash_hmac(
                'sha256',
                json_encode($payload),
                $this->webhookSecretKey
            );
        }

        // Send the payload to the webhook.
        try {
            $response = $client->post($this->webhookUrl, [
                'json' => $payload,
                'timeout' => 10,
                'headers' => isset($signature) ? ['X-Signature' => $signature] : [],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            throw new Exception(sprintf(
                'Error sending the message: %s',
                $e->getMessage()
            ));
        }
    }
}
