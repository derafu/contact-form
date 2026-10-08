<?php

declare(strict_types=1);

/**
 * Derafu: Contact Form - Contact form with webhook delivery.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsContactForm;

use Derafu\ContactForm\ContactController;
use Derafu\ContactForm\ContactService;
use Derafu\ContactForm\Exception\ContactFormException;
use Derafu\Form\Contract\FormInterface;
use Derafu\Form\Contract\Processor\ProcessResultInterface;
use Derafu\Http\Contract\ResponseInterface;
use Derafu\Http\Request;
use Derafu\Renderer\Contract\RendererInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What the controller answers when the contact form is not available (turned
 * off, or on without what it needs to send), and when it is. The service and the
 * renderer are doubles: what is checked is the decision, with the page that is
 * rendered and the status.
 */
#[CoversClass(ContactController::class)]
#[UsesClass(ContactFormException::class)]
final class ContactControllerTest extends TestCase
{
    private const UNAVAILABLE = 'contact/unavailable.html.twig';

    /**
     * What was rendered: the template and its variables, in order.
     *
     * @var list<array{string, array<string, mixed>}>
     */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->rendered = [];
    }

    private function renderer(): RendererInterface
    {
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            function (string $template, array $variables = []): string {
                $this->rendered[] = [$template, $variables];

                return 'RENDERED ' . $template;
            }
        );

        return $renderer;
    }

    /**
     * A service that answers what it is told and checks nothing.
     *
     * @param list<string> $missing
     */
    private function service(bool $enabled = true, array $missing = [], bool $debug = false): ContactService&Stub
    {
        return $this->answer($this->createStub(ContactService::class), $enabled, $missing, $debug);
    }

    /**
     * A service that also checks what the controller does with it.
     *
     * @param list<string> $missing
     */
    private function watchedService(bool $enabled = true, array $missing = [], bool $debug = false): ContactService&MockObject
    {
        return $this->answer($this->createMock(ContactService::class), $enabled, $missing, $debug);
    }

    /**
     * @template T of Stub
     * @param T $service
     * @param list<string> $missing
     * @return T
     */
    private function answer(Stub $service, bool $enabled, array $missing, bool $debug): Stub
    {
        $service->method('isEnabled')->willReturn($enabled);
        $service->method('missingConfiguration')->willReturn($missing);
        $service->method('isDebug')->willReturn($debug);

        return $service;
    }

    private function controller(ContactService $service): ContactController
    {
        return new ContactController($this->renderer(), $service);
    }

    private function post(array $data = ['name' => 'Ana']): Request
    {
        return (new Request('POST', 'http://localhost/contact/submit'))->withParsedBody($data);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'the form' => ['index'],
            'the submission' => ['submit'],
            'the success page' => ['success'],
        ];
    }

    private function page(ContactController $controller, string $page): string|ResponseInterface
    {
        if ($page === 'index') {
            return $controller->index(new Request('GET', 'http://localhost/contact'));
        }

        return $page === 'submit' ? $controller->submit($this->post()) : $controller->success();
    }

    #[Test]
    #[DataProvider('pages')]
    public function aFormThatIsTurnedOffSaysSoWith200(string $page): void
    {
        $service = $this->watchedService(enabled: false);
        $service->expects($this->never())->method('process');
        $service->expects($this->never())->method('sendToWebhook');

        $response = $this->page($this->controller($service), $page);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertSame('RENDERED ' . self::UNAVAILABLE, (string) $response->getBody());
        $this->assertSame([[self::UNAVAILABLE, ['detail' => null]]], $this->rendered);
    }

    #[Test]
    #[DataProvider('pages')]
    public function aFormThatLacksWhatItNeedsToSendSaysTheSameWith503(string $page): void
    {
        $service = $this->watchedService(missing: ['FORM_CONTACT_WEBHOOK_URL', 'FORM_CONTACT_WEBHOOK_SECRET_KEY']);
        $service->expects($this->never())->method('process');
        $service->expects($this->never())->method('sendToWebhook');

        $response = $this->page($this->controller($service), $page);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('RENDERED ' . self::UNAVAILABLE, (string) $response->getBody());
        // Without debug the visitor is not told what the configuration lacks.
        $this->assertSame([[self::UNAVAILABLE, ['detail' => null]]], $this->rendered);
    }

    #[Test]
    public function inDebugThePageTellsWhatIsWrong(): void
    {
        $this->page($this->controller($this->service(enabled: false, debug: true)), 'index');
        $this->page($this->controller($this->service(missing: ['FORM_CONTACT_WEBHOOK_SECRET_KEY'], debug: true)), 'index');

        $this->assertCount(2, $this->rendered);
        $this->assertStringContainsString('FORM_CONTACT_ENABLED', (string) $this->rendered[0][1]['detail']);
        $this->assertStringContainsString('FORM_CONTACT_WEBHOOK_SECRET_KEY', (string) $this->rendered[1][1]['detail']);
        $this->assertStringNotContainsString('FORM_CONTACT_WEBHOOK_URL', (string) $this->rendered[1][1]['detail']);
    }

    #[Test]
    public function aFormThatIsReadyRendersTheFormAndTheSuccessPage(): void
    {
        $form = $this->createStub(FormInterface::class);
        $service = $this->service();
        $service->method('createForm')->willReturn($form);
        $controller = $this->controller($service);

        $this->assertSame(
            'RENDERED contact/index.html.twig',
            $controller->index(new Request('GET', 'http://localhost/contact'))
        );
        $this->assertSame('RENDERED contact/success.html.twig', $controller->success());
        $this->assertSame($form, $this->rendered[0][1]['form']);
    }

    #[Test]
    public function aValidSubmissionIsSentAndRedirectsToTheSuccessPage(): void
    {
        $result = $this->createStub(ProcessResultInterface::class);
        $result->method('isValid')->willReturn(true);
        $result->method('getProcessedData')->willReturn(['name' => 'Ana']);
        $service = $this->watchedService();
        $service->method('createForm')->willReturn($this->createStub(FormInterface::class));
        $service->method('process')->willReturn($result);
        $service->expects($this->once())->method('sendToWebhook')->with(['name' => 'Ana'], ['form' => 'contact']);

        $response = $this->controller($service)->submit($this->post());

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/contact/success', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function aMessageThatCanNotBeSentGivesTheFormBackWithWhatTheUserWrote(): void
    {
        $result = $this->createStub(ProcessResultInterface::class);
        $result->method('isValid')->willReturn(true);
        $result->method('getProcessedData')->willReturn([]);

        $written = $this->createStub(FormInterface::class);
        $service = $this->service();
        $service->method('createForm')->willReturnCallback(
            fn (string|array $definition = '', array $data = []): FormInterface => $data === [] ? $this->createStub(FormInterface::class) : $written
        );
        $service->method('process')->willReturn($result);
        $service->method('sendToWebhook')->willThrowException(new ContactFormException('The webhook is down.'));

        $response = $this->controller($service)->submit($this->post(['name' => 'Ana', 'message' => 'Hello']));

        $this->assertSame('RENDERED contact/index.html.twig', $response);
        $this->assertCount(1, $this->rendered);
        // The form of the user, so it can try again, and the reason.
        $this->assertSame($written, $this->rendered[0][1]['form']);
        $this->assertSame('The webhook is down.', $this->rendered[0][1]['error']);
    }
}
