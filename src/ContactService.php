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
     * Default form definition for the contact form.
     *
     * @var string
     */
    protected const DEFAULT_FORM_DEFINITION = __DIR__ . '/../resources/forms/contact-form.yaml';

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
        // Load the webhook configuration.
        $this->webhookUrl = $this->parameterBag->get('form.contact.webhook.url');
        $this->webhookSecretKey = $this->parameterBag->get(
            'form.contact.webhook.secret_key'
        );

        // Load the captcha configuration.
        $this->captchaSiteKey = $this->parameterBag->get('captcha.site_key');
        $this->captchaSecretKey = $this->parameterBag->get('captcha.secret_key');
    }

    /**
     * Create a new form instance.
     *
     * @param string|array $formDefinition The form definition to use.
     * @return FormInterface
     */
    public function createForm(
        string|array $formDefinition = self::DEFAULT_FORM_DEFINITION
    ): FormInterface {
        if (is_string($formDefinition)) {
            $formDefinition = Yaml::parseFile($formDefinition);
        }

        return $this->formFactory->create($formDefinition);
    }

    /**
     * Process the form data.
     *
     * @param FormInterface|string|array $form The form definition to use.
     * @return ProcessResultInterface The result of the form processing.
     */
    public function process(
        FormInterface|string|array $form = self::DEFAULT_FORM_DEFINITION
    ): ProcessResultInterface
    {
        if (!$form instanceof FormInterface) {
            $form = $this->createForm($form);
        }

        return $this->formDataProcessor->process($form);
    }

    /**
     * Send the processed data to the webhook.
     *
     * @param array $data The processed data of the form to send.
     * @param array $meta The meta data of the form to send.
     * @return array
     * @throws Exception
     */
    public function sendToWebhook(array $data, array $meta = []): array
    {
        if (!$this->webhookUrl) {
            throw new Exception('Webhook URL is not configured for the contact form.');
        }

        $this->validateCaptcha($data);

        return $this->sendMessage($data, $meta);
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
     * @param array $data The processed data of the form to send.
     * @param array $meta The meta data of the form to send.
     * @return array
     * @throws Exception
     */
    private function sendMessage(array $data, array $meta = []): array
    {
        // Initialize the client Guzzle.
        $client = new Client();

        // Build the payload.
        $payload = [
            'meta' => array_merge([
                'source' =>
                    $this->parameterBag->get('form.contact.source')
                    ?? $this->parameterBag->get('kernel.context')['URL_HOST']
                    ?? throw new Exception('Parameter form.contact.source is not configured.')
                ,
                'form' => 'contact',
                'timestamp' => time(),
            ], $meta),
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
