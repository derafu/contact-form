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

use Derafu\ContactForm\Contract\ContactServiceInterface;
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
class ContactService implements ContactServiceInterface
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
     * Webhook secret key for signing the message sent to the webhook. It is
     * required: every message is signed, and the receiver decides whether to
     * check the signature.
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
        $this->webhookUrl = $this->readOptionalString('derafu.contact_form.webhook.url');
        $this->webhookSecretKey = $this->readOptionalString(
            'derafu.contact_form.webhook.secret_key'
        );
    }

    /**
     * Whether the contact form is enabled (`FORM_CONTACT_ENABLED`, `true` by
     * default). An application that does not want the form sets it to `false`:
     * the pages of the form say that it is not available.
     */
    public function isEnabled(): bool
    {
        return !$this->parameterBag->has('derafu.contact_form.enabled')
            || (bool) $this->parameterBag->get('derafu.contact_form.enabled')
        ;
    }

    /**
     * The environment variables that the form needs to send its messages and
     * that are not set.
     *
     * @return list<string> The names of the variables (none when it is ready).
     */
    public function missingConfiguration(): array
    {
        $missing = [];
        if (!$this->webhookUrl) {
            $missing[] = 'FORM_CONTACT_WEBHOOK_URL';
        }
        if (!$this->webhookSecretKey) {
            $missing[] = 'FORM_CONTACT_WEBHOOK_SECRET_KEY';
        }

        return $missing;
    }

    /**
     * Whether the application is in debug mode (`kernel.debug`): the pages of the
     * form tell what is wrong with the configuration only then.
     */
    public function isDebug(): bool
    {
        return $this->parameterBag->has('kernel.debug')
            && (bool) $this->parameterBag->get('kernel.debug')
        ;
    }

    /**
     * Create a new form instance.
     *
     * @param string|array<string, mixed> $formDefinition The form definition to use.
     * @param array<array-key, mixed> $data The data to use for the form.
     * @return FormInterface
     */
    public function createForm(
        string|array $formDefinition = self::DEFAULT_FORM_DEFINITION,
        array $data = []
    ): FormInterface {
        if (is_string($formDefinition)) {
            $formDefinition = Yaml::parseFile($formDefinition);
        }

        if (!is_array($formDefinition)) {
            throw new ContactFormException('The contact form definition must be an array with string keys.');
        }
        foreach ($formDefinition as $key => $value) {
            if (!is_string($key)) {
                throw new ContactFormException('The contact form definition must be an array with string keys.');
            }
        }

        if (!empty($data)) {
            $formDefinition['data'] = $data;
        }

        return $this->formFactory->create($formDefinition);
    }

    /**
     * Process the form data.
     *
     * @param FormInterface|string|array<string, mixed> $form The form definition to use.
     * @param array<array-key, mixed> $data The form data to process.
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
     * @param array<array-key, mixed> $data The processed data of the form to send.
     * @param array<array-key, mixed> $meta The meta data of the form to send.
     * @return array<array-key, mixed>
     * @throws ContactFormException
     */
    public function sendToWebhook(array $data, array $meta = []): array
    {
        if (!$this->webhookUrl) {
            throw new ContactFormException(
                'Webhook URL is not configured for the contact form.'
            );
        }

        if (!$this->webhookSecretKey) {
            throw new ContactFormException(
                'Webhook secret key is not configured for the contact form.'
            );
        }

        $data = $this->serializeUploadedFiles($data);

        return $this->sendMessage($this->webhookUrl, $data, $meta);
    }

    /**
     * Send the message of the form using the webhook.
     *
     * @param array<array-key, mixed> $data The processed data of the form to send.
     * @param array<array-key, mixed> $meta The meta data of the form to send.
     * @return array<array-key, mixed>
     * @throws ContactFormException
     */
    private function sendMessage(string $webhookUrl, array $data, array $meta = []): array
    {
        $source = $this->readOptionalString('derafu.contact_form.source');
        if ($source === null) {
            $context = $this->parameterBag->get('kernel.context');
            if ($context !== null && !is_array($context)) {
                throw new ContactFormException('Parameter kernel.context must be an array or null.');
            }
            $source = $context['URL_HOST'] ?? null;
            if ($source !== null && !is_string($source)) {
                throw new ContactFormException('Parameter kernel.context.URL_HOST must be a string or null.');
            }
        }
        if ($source === null) {
            throw new ContactFormException('Parameter derafu.contact_form.source is not configured.');
        }

        // Build the payload.
        $payload = [
            'meta' => array_merge([
                'source' => $source,
                'form' => 'contact',
                'timestamp' => time(),
            ], $meta),
            'data' => $data,
        ];

        // Send the payload to the webhook.
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);

            $request = $this->requestFactory
                ->createRequest('POST', $webhookUrl)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body))
            ;

            // The payload is always signed: the receiver decides whether to
            // check the signature.
            $request = $request->withHeader(
                'X-Signature',
                hash_hmac('sha256', $body, (string) $this->webhookSecretKey)
            );

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

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ContactFormException('The webhook response must be a valid JSON object or array.', $e);
        }
        if (!is_array($decoded)) {
            throw new ContactFormException('The webhook response must be a valid JSON object or array.');
        }

        return $decoded;
    }

    /**
     * Read a nullable string parameter without coercing configuration values.
     */
    private function readOptionalString(string $name): ?string
    {
        $value = $this->parameterBag->get($name);
        if ($value !== null && !is_string($value)) {
            throw new ContactFormException([
                'Parameter {name} must be a string or null.',
                'name' => $name,
            ]);
        }

        return $value;
    }

    /**
     * Serialize the uploaded files.
     *
     * @param mixed $data
     * @return array<array-key, mixed>
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
