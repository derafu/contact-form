<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm;

use Derafu\ContactForm\ContactService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactService::class)]
class DefaultContactFormTest extends TestCase
{
    public function testCreateAndProcessForm(): void
    {
        // TODO: Implement testCreateAndProcessForm().
        $this->markTestSkipped('Not implemented.');
    }
}
