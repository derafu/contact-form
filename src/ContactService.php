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

use Derafu\ContactForm\Exception\ContactFormException;
use Derafu\Form\Contract\Factory\FormFactoryInterface;
use Derafu\Form\Contract\FormInterface;
use Derafu\Form\Contract\Processor\FormDataProcessorInterface;
use Derafu\Form\Contract\Processor\ProcessResultInterface;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
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
     * Constructor.
     *
     * @param FormFactoryInterface $formFactory
     * @param FormDataProcessorInterface $formDataProcessor
     * @param ParameterBagInterface $parameterBag
     * @param ClientInterface $client HTTP client (PSR-18) that sends the
     * message to the webhook. Its timeout is the one of the client.
     * @param RequestFactoryInterface $requestFactory
     * @param StreamFactoryInterface $streamFactory
     */
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly FormDataProcessorInterface $formDataProcessor,
        private readonly ParameterBagInterface $parameterBag,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        // Load the webhook configuration.
        $this->webhookUrl = $this->parameterBag->get('form.contact.webhook.url');
        $this->webhookSecretKey = $this->parameterBag->get(
            'form.contact.webhook.secret_key'
        );
    }

    /**
     * Create a new form instance.
     *
     * @param string|array $formDefinition The form definition to use.
     * @param array $data The data to use for the form.
     * @return FormInterface
     */
    public function createForm(
        string|array $formDefinition = self::DEFAULT_FORM_DEFINITION,
        array $data = []
    ): FormInterface {
        if (is_string($formDefinition)) {
            $formDefinition = Yaml::parseFile($formDefinition);
        }

        if (!empty($data)) {
            $formDefinition['data'] = $data;
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
        FormInterface|string|array $form = self::DEFAULT_FORM_DEFINITION,
        array $data = []
    ): ProcessResultInterface {
        if (!$form instanceof FormInterface) {
            $form = $this->createForm($form);
        }

        return $this->formDataProcessor->process($form, $data);
    }

    /**
     * Send the processed data to the webhook.
     *
     * @param array $data The processed data of the form to send.
     * @param array $meta The meta data of the form to send.
     * @return array
     * @throws ContactFormException
     */
    public function sendToWebhook(array $data, array $meta = []): array
    {
        if (!$this->webhookUrl) {
            throw new ContactFormException(
                'Webhook URL is not configured for the contact form.'
            );
        }

        $data = $this->serializeUploadedFiles($data);

        return $this->sendMessage($data, $meta);
    }

    /**
     * Send the message of the form using the webhook.
     *
     * @param array $data The processed data of the form to send.
     * @param array $meta The meta data of the form to send.
     * @return array
     * @throws ContactFormException
     */
    private function sendMessage(array $data, array $meta = []): array
    {
        // Build the payload.
        $payload = [
            'meta' => array_merge([
                'source' =>
                    $this->parameterBag->get('form.contact.source')
                    ?? $this->parameterBag->get('kernel.context')['URL_HOST']
                    ?? throw new ContactFormException(
                        'Parameter form.contact.source is not configured.'
                    )
                ,
                'form' => 'contact',
                'timestamp' => time(),
            ], $meta),
            'data' => $data,
        ];

        // Send the payload to the webhook.
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);

            $request = $this->requestFactory
                ->createRequest('POST', $this->webhookUrl)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body))
            ;

            // If the webhook secret key is configured, sign the payload.
            if ($this->webhookSecretKey) {
                $request = $request->withHeader(
                    'X-Signature',
                    hash_hmac('sha256', $body, $this->webhookSecretKey)
                );
            }

            $response = $this->client->sendRequest($request);
        } catch (JsonException | ClientExceptionInterface $e) {
            throw new ContactFormException([
                'Error sending the message: {reason}.',
                'reason' => $e->getMessage(),
            ], $e);
        }

        // A PSR-18 client does not fail on an error status of the server.
        if ($response->getStatusCode() >= 400) {
            throw new ContactFormException([
                'Error sending the message: {reason}.',
                'reason' => 'HTTP ' . $response->getStatusCode() . ' '
                    . $response->getReasonPhrase(),
            ]);
        }

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Serialize the uploaded files.
     *
     * @param mixed $data
     * @return array
     */
    private function serializeUploadedFiles(mixed $data): array
    {
        $walk = function ($item) use (&$walk) {
            if ($item instanceof UploadedFileInterface) {
                if ($item->getError() !== UPLOAD_ERR_OK) {
                    return null;
                }

                return [
                    'filename' => $item->getClientFilename(),
                    'media_type' => $item->getClientMediaType(),
                    'size' => $item->getSize(),
                    'content' => base64_encode((string) $item->getStream()),
                ];
            }

            if (is_array($item)) {
                $mapped = [];
                foreach ($item as $k => $v) {
                    $mapped[$k] = $walk($v);
                }
                return $mapped;
            }

            return $item;
        };

        return $walk($data);
    }
}
