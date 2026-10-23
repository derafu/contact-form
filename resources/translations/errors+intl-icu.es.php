<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

return [
    'Webhook URL is not configured for the contact form.' =>
        'La URL del webhook no está configurada para el formulario de contacto.',
    'Webhook secret key is not configured for the contact form.' =>
        'La clave secreta del webhook no está configurada para el formulario de contacto.',
    'Parameter form.contact.source is not configured.' =>
        'El parámetro form.contact.source no está configurado.',
    'Error sending the message: {reason}.' =>
        'Error al enviar el mensaje: {reason}.',
    'Parameter {name} must be a string or null.' =>
        'El parámetro {name} debe ser un string o null.',
    'Parameter kernel.context must be an array or null.' =>
        'El parámetro kernel.context debe ser un arreglo o null.',
    'Parameter kernel.context.URL_HOST must be a string or null.' =>
        'El parámetro kernel.context.URL_HOST debe ser un string o null.',
    'The contact form definition must be an array with string keys.' =>
        'La definición del formulario de contacto debe ser un arreglo con claves string.',
    'The processed contact form data must be an array.' =>
        'Los datos procesados del formulario de contacto deben ser un arreglo.',
    'The webhook response must be a valid JSON object or array.' =>
        'La respuesta del webhook debe ser un objeto o arreglo JSON válido.',
];
