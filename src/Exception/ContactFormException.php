<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\ContactForm\Exception;

use Derafu\Translation\Contract\TranslatableInterface;
use Derafu\Translation\Exception\Core\TranslatableRuntimeException;
use Throwable;

/**
 * Exception for failures configuring or sending a contact form submission:
 * a missing webhook/source configuration, or the webhook request itself
 * failing.
 */
class ContactFormException extends TranslatableRuntimeException
{
    /**
     * Constructor.
     *
     * @param string|array|TranslatableInterface $message The exception message.
     * @param Throwable|null $previous The previous throwable used for
     * exception chaining.
     */
    public function __construct(
        string|array|TranslatableInterface $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
