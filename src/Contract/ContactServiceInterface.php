<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ContactForm\Contract;

use Derafu\Form\Contract\FormInterface;
use Derafu\Form\Contract\Processor\ProcessResultInterface;

/** Contact form configuration, processing and webhook delivery. */
interface ContactServiceInterface
{
    public function isEnabled(): bool;

    /**
     * @return list<string>
     */
    public function missingConfiguration(): array;

    public function isDebug(): bool;

    /**
     * @param string|array<string,mixed> $formDefinition
     * @param array<array-key,mixed> $data
     */
    public function createForm(
        string|array $formDefinition = __DIR__ . '/../../resources/forms/contact-form.yaml',
        array $data = []
    ): FormInterface;

    /**
     * @param FormInterface|string|array<string,mixed> $form
     * @param array<array-key,mixed> $data
     */
    public function process(
        FormInterface|string|array $form = __DIR__ . '/../../resources/forms/contact-form.yaml',
        array $data = []
    ): ProcessResultInterface;

    /**
     * @param array<array-key,mixed> $data
     * @param array<array-key,mixed> $meta
     * @return array<array-key,mixed>
     */
    public function sendToWebhook(array $data, array $meta = []): array;
}
