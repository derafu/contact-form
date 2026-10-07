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

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Derafu\Captcha\Provider\AltchaProvider;
use Derafu\Captcha\Provider\DisabledCaptchaProvider;
use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\ContactForm\ContactService;
use Derafu\ContactForm\Exception\ContactFormException;
use Derafu\ContactForm\Translation\ContactFormTranslationResourceProvider;
use Derafu\Csrf\SessionCsrfTokenManager;
use Derafu\DataProcessor\ProcessorFactory;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Contract\Factory\FormFactoryInterface;
use Derafu\Form\Factory\FormFactory;
use Derafu\Form\Factory\TranslatingFormFactory;
use Derafu\Form\Processor\FormDataProcessor;
use Derafu\Form\Processor\FormRulesResolver;
use Derafu\Form\Type\TypeProvider;
use Derafu\Form\Type\TypeRegistry;
use Derafu\Form\Type\TypeResolver;
use Derafu\Translation\Exception\Core\TranslatableLogicException;
use Derafu\Translation\TranslatorFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Mezzio\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * The contact form is created and processed with the real form and data
 * processor, and sent to a real webhook: a local server of PHP that records what
 * it receives.
 */
#[CoversClass(ContactService::class)]
#[CoversClass(ContactFormException::class)]
#[UsesClass(ContactFormTranslationResourceProvider::class)]
final class ContactServiceTest extends TestCase
{
    private const SECRET = 'a-secret-key';

    /**
     * @var resource|null
     */
    private static $server = null;

    private static int $port = 0;

    private static string $log;

    private SessionCsrfTokenManager $csrf;

    private AltchaProvider $captcha;

    public static function setUpBeforeClass(): void
    {
        self::$log = tempnam(sys_get_temp_dir(), 'contact-form-webhook-');

        // A free port: it is asked to the system and released right away.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        self::$port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../fixtures/webhook-router.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ['WEBHOOK_LOG' => self::$log]
        );
        self::assertIsResource($process);
        self::$server = $process;

        // Wait until the server accepts connections.
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(100000);
        }

        self::fail('The local webhook did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$log);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');

        $this->csrf = new SessionCsrfTokenManager();
        $this->csrf->useSession(new Session([]));

        // The captcha is ALTCHA with a cheap challenge: it needs no service.
        $this->captcha = new AltchaProvider('a-secret-key', 'en', cost: 10);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function service(array $parameters = [], ?FormFactoryInterface $formFactory = null, ?CaptchaProviderInterface $captcha = null): ContactService
    {
        return new ContactService(
            $formFactory ?? new FormFactory(new TypeResolver(new TypeRegistry(new TypeProvider()))),
            new FormDataProcessor(new FormRulesResolver(), (new ProcessorFactory())->create(), csrfTokenManager: $this->csrf, captchaProvider: $captcha ?? $this->captcha),
            new ParameterBag($parameters + [
                'form.contact.webhook.url' => 'http://127.0.0.1:' . self::$port . '/ok',
                'form.contact.webhook.secret_key' => '',
                'form.contact.source' => 'tests',
                'kernel.context' => ['URL_HOST' => 'tests.example'],
            ]),
            new Client(['timeout' => 10]),
            new HttpFactory(),
            new HttpFactory()
        );
    }

    /**
     * What the visitor sends when it solves the captcha of the form: the browser
     * solves the challenge of the widget, which is done here with the library.
     */
    private function solvedCaptcha(string $formId = 'contact'): string
    {
        $this->assertSame(1, preg_match('/ challenge="([^"]*)"/', $this->captcha->getWidget($formId), $matches));
        $challenge = Challenge::fromArray(json_decode(html_entity_decode($matches[1], ENT_QUOTES), true));
        $solution = (new Altcha(hmacSignatureSecret: 'a-secret-key'))->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2(),
            challenge: $challenge,
        ));
        $this->assertNotNull($solution, 'The challenge was not solved.');

        return (new Payload($challenge, $solution))->toBase64();
    }

    /**
     * What a visitor sends: the data of the form, the token that the form had and
     * what it solved of the captcha.
     *
     * @return array<string, string>
     */
    private function validData(): array
    {
        return [
            '_token' => $this->csrf->getToken('contact'),
            'altcha' => $this->solvedCaptcha(),
            'name' => 'Ana Perez',
            'email' => 'ana@example.com',
            'telephone' => '+56911112222',
            'company' => 'ACME',
            'subject' => str_repeat('s', 30),
            'message' => str_repeat('m', 160),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function received(): array
    {
        $received = json_decode((string) file_get_contents(self::$log), true);
        $this->assertIsArray($received, 'The webhook received nothing.');

        return $received;
    }

    #[Test]
    public function createsTheFormOfTheDefaultDefinitionWithTheGivenData(): void
    {
        $form = $this->service()->createForm(data: ['name' => 'Ana']);

        $this->assertNotNull($form->getField('email'));
        $this->assertSame('Ana', $form->getField('name')->getData());
    }

    /**
     * The form of the package says in which domain its texts are, so a factory
     * that translates gives it in the language of the translator.
     */
    #[Test]
    public function createsTheDefaultFormTranslated(): void
    {
        $translator = TranslatorFactory::create('es', ['en'], [new ContactFormTranslationResourceProvider()]);
        $service = $this->service(
            formFactory: new TranslatingFormFactory(
                new FormFactory(new TypeResolver(new TypeRegistry(new TypeProvider()))),
                $translator
            )
        );

        $form = $service->createForm()->toArray();

        $this->assertSame('Tu nombre', $form['schema']['properties']['name']['title']);
        $this->assertSame('Tu mensaje', $form['schema']['properties']['message']['title']);
        $this->assertSame('Tu correo electrónico', $form['uischema']['elements'][1]['label']);

        // In English, the texts as they were written.
        $translator->setLocale('en');
        $this->assertSame(
            'Your Name',
            $service->createForm()->toArray()['schema']['properties']['name']['title']
        );
    }

    #[Test]
    public function aSubmissionWithoutTheTokenOfTheFormIsNotValid(): void
    {
        $data = $this->validData();
        unset($data['_token']);

        $result = $this->service()->process(data: $data);

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getFormErrors());
        $this->assertFalse($result->hasFieldErrors('name'));
    }

    #[Test]
    public function aSubmissionWithoutTheCaptchaIsNotValidAndIsNotSent(): void
    {
        $data = $this->validData();
        unset($data['altcha']);

        $result = $this->service()->process(data: $data);

        $this->assertFalse($result->isValid());
        $this->assertSame(['The captcha is not valid. Try again.'], $result->getFormErrors());
        $this->assertFalse($result->hasFieldErrors('name'));
    }

    #[Test]
    public function aSubmissionWithTheCaptchaOfAnotherFormIsNotValid(): void
    {
        $result = $this->service()->process(data: ['altcha' => $this->solvedCaptcha('login')] + $this->validData());

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function theCaptchaIsNotPartOfWhatIsSentToTheWebhook(): void
    {
        $service = $this->service();
        $result = $service->process(data: $this->validData());
        $service->sendToWebhook($result->getProcessedData(), ['form' => 'contact']);

        $payload = json_decode($this->received()['body'], true);
        $this->assertArrayNotHasKey('altcha', $payload['data']);
        $this->assertArrayNotHasKey('_token', $payload['data']);
        $this->assertSame('Ana Perez', $payload['data']['name']);
    }

    #[Test]
    public function theFormIsProtectedWithTheCaptchaSoItFailsWhenTheApplicationHasNone(): void
    {
        $this->expectException(TranslatableLogicException::class);
        $this->expectExceptionMessage('The form "contact" is protected with a captcha, but the application has none.');

        $this->service(captcha: new UnavailableCaptchaProvider())->process(data: $this->validData());
    }

    #[Test]
    public function anApplicationThatDisabledTheCaptchaOnPurposeSendsTheFormWithoutIt(): void
    {
        $data = $this->validData();
        unset($data['altcha']);

        $result = $this->service(captcha: new DisabledCaptchaProvider())->process(data: $data);

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function aSubmissionWithTheTokenOfAnotherFormIsNotValid(): void
    {
        $result = $this->service()->process(data: ['_token' => $this->csrf->getToken('login')] + $this->validData());

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function processesAValidSubmission(): void
    {
        $result = $this->service()->process(data: $this->validData());

        $this->assertTrue($result->isValid());
        $this->assertSame('Ana Perez', $result->getProcessedData()['name']);
    }

    #[Test]
    public function processesAnInvalidSubmissionWithTheErrorsOfEachField(): void
    {
        $result = $this->service()->process(data: [
            'name' => 'A',
            'email' => 'not an email',
            'telephone' => '1',
            'company' => '',
        ]);

        $this->assertFalse($result->isValid());
        foreach (['name', 'email', 'telephone', 'company', 'subject', 'message'] as $field) {
            $this->assertTrue($result->hasFieldErrors($field), $field);
        }
    }

    #[Test]
    public function sendsTheDataWithItsMetaToTheWebhook(): void
    {
        $response = $this->service()->sendToWebhook(['name' => 'Ana'], ['ip' => '203.0.113.7']);

        $this->assertSame(['status' => 'received'], $response);

        $received = $this->received();
        $this->assertSame('POST', $received['method']);
        $this->assertSame('/ok', $received['path']);
        $this->assertStringStartsWith('application/json', $received['content_type']);

        $payload = json_decode($received['body'], true);
        $this->assertSame(['name' => 'Ana'], $payload['data']);
        $this->assertSame('tests', $payload['meta']['source']);
        $this->assertSame('contact', $payload['meta']['form']);
        $this->assertSame('203.0.113.7', $payload['meta']['ip']);
        $this->assertEqualsWithDelta(time(), $payload['meta']['timestamp'], 5);
    }

    #[Test]
    public function signsThePayloadWhenThereIsASecretKey(): void
    {
        $this->service(['form.contact.webhook.secret_key' => self::SECRET])->sendToWebhook(['name' => 'Ana']);

        $received = $this->received();
        $this->assertSame(
            hash_hmac('sha256', $received['body'], self::SECRET),
            $received['signature']
        );
    }

    #[Test]
    public function doesNotSignThePayloadWithoutASecretKey(): void
    {
        $this->service()->sendToWebhook(['name' => 'Ana']);

        $this->assertNull($this->received()['signature']);
    }

    #[Test]
    public function sendsTheUploadedFilesAsBase64(): void
    {
        $this->service()->sendToWebhook([
            'attachment' => new UploadedFile(Utils::streamFor('hello'), 5, UPLOAD_ERR_OK, 'hello.txt', 'text/plain'),
            'failed' => new UploadedFile(Utils::streamFor(''), 0, UPLOAD_ERR_NO_FILE),
            'nested' => ['name' => 'Ana'],
        ]);

        $data = json_decode($this->received()['body'], true)['data'];
        $this->assertSame([
            'filename' => 'hello.txt',
            'media_type' => 'text/plain',
            'size' => 5,
            'content' => base64_encode('hello'),
        ], $data['attachment']);
        // An upload that failed is not sent.
        $this->assertNull($data['failed']);
        $this->assertSame(['name' => 'Ana'], $data['nested']);
    }

    #[Test]
    public function theSourceFallsBackToTheHostOfTheKernel(): void
    {
        $this->service(['form.contact.source' => null])->sendToWebhook(['name' => 'Ana']);

        $this->assertSame(
            'tests.example',
            json_decode($this->received()['body'], true)['meta']['source']
        );
    }

    #[Test]
    public function failsWhenThereIsNoSource(): void
    {
        $service = $this->service(['form.contact.source' => null, 'kernel.context' => []]);

        $this->expectException(ContactFormException::class);
        $this->expectExceptionMessage('Parameter form.contact.source is not configured.');

        $service->sendToWebhook(['name' => 'Ana']);
    }

    #[Test]
    public function failsWhenThereIsNoWebhook(): void
    {
        $service = $this->service(['form.contact.webhook.url' => '']);

        $this->expectException(ContactFormException::class);
        $this->expectExceptionMessage('Webhook URL is not configured for the contact form.');

        $service->sendToWebhook(['name' => 'Ana']);
    }

    #[Test]
    public function failsWhenTheWebhookAnswersWithAnError(): void
    {
        $service = $this->service(['form.contact.webhook.url' => 'http://127.0.0.1:' . self::$port . '/error']);

        try {
            $service->sendToWebhook(['name' => 'Ana']);
            $this->fail('It should have failed.');
        } catch (ContactFormException $e) {
            $this->assertStringStartsWith('Error sending the message: ', $e->getMessage());
            $this->assertStringContainsString('500', $e->getMessage());
            // An answer with an error status is not an exception of the client.
            $this->assertNull($e->getPrevious());
        }
    }

    #[Test]
    public function failsWhenTheWebhookIsDown(): void
    {
        // A port where nothing listens: the one of a server that was closed.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $service = $this->service(['form.contact.webhook.url' => 'http://127.0.0.1:' . $port . '/ok']);

        try {
            $service->sendToWebhook(['name' => 'Ana']);
            $this->fail('It should have failed.');
        } catch (ContactFormException $e) {
            $this->assertStringStartsWith('Error sending the message: ', $e->getMessage());
            // The error of the HTTP client is kept as the previous one.
            $this->assertInstanceOf(ClientExceptionInterface::class, $e->getPrevious());
        }
    }
}
