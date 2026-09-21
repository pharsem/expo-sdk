<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Integration;

use Expo\Push\Expo;
use Expo\Push\Http\Psr18HttpClient;
use Expo\Push\Http\RequestFactory;
use Expo\Push\PushMessage;
use Expo\Push\Tests\Support\FakeHttpClient;
use Expo\Push\Tests\Support\TestCase;

/**
 * The core package needs ext-curl and ext-json only.
 */
final class OptionalDependenciesTest extends TestCase
{
    /**
     * Only the PSR-18 adapter may name a PSR interface. Every other class of the
     * package must load without `psr/http-client` and `psr/http-factory`.
     */
    public function testOnlyThePsr18AdapterNamesAPsrInterface(): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $path) {
            $code = (string) file_get_contents($path);

            if (!str_contains($code, 'Psr\\Http\\')) {
                continue;
            }

            if (basename($path) === 'Psr18HttpClient.php') {
                continue;
            }

            $offenders[] = basename($path);
        }

        self::assertSame([], $offenders);
    }

    public function testEveryClassOutsideThePsrAdapterLoadsOnItsOwn(): void
    {
        $loaded = 0;

        foreach (self::sourceFiles() as $path) {
            $class = self::classOf($path);

            if ($class === null || $class === Psr18HttpClient::class) {
                continue;
            }

            self::assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), $class);
            ++$loaded;
        }

        self::assertGreaterThan(50, $loaded);
    }

    public function testTheComposerRequirementsStayNarrow(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);
        self::assertSame(['php', 'ext-curl', 'ext-json'], array_keys($composer['require']));
        self::assertIsArray($composer['suggest']);
        self::assertArrayHasKey('ext-zlib', $composer['suggest']);
        self::assertArrayHasKey('psr/http-client', $composer['suggest']);
        self::assertArrayHasKey('psr/http-factory', $composer['suggest']);
    }

    public function testASendWorksWithoutCompression(): void
    {
        $http = (new FakeHttpClient())->queue(['data' => self::okTickets(1)]);

        $expo = new Expo(
            httpClient: $http,
            compress: false,
            clock: $this->clock,
            sleeper: $this->sleeper,
        );

        $result = $expo->send(PushMessage::to(self::TOKEN_A)->body(str_repeat('a', 4_000)));

        self::assertCount(1, $result->accepted());
        self::assertArrayNotHasKey('content-encoding', $http->headers());
        self::assertStringStartsWith('[{', $http->requests[0]->body);
    }

    /**
     * The compression step asks for the function before it calls it, so a build
     * without zlib still sends.
     */
    public function testTheCompressionStepChecksForZlibFirst(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/RequestFactory.php');

        self::assertStringContainsString("function_exists('gzencode')", $code);
        self::assertSame(1024, RequestFactory::GZIP_THRESHOLD);
    }

    public function testThePsr18DecoderChecksForZlibFirst(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Psr18HttpClient.php');

        self::assertStringContainsString("function_exists('gzdecode')", $code);
        self::assertStringContainsString("function_exists('gzinflate')", $code);
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function classOf(string $path): ?string
    {
        $root = self::slashes(dirname(__DIR__, 2)) . '/src/';
        $file = self::slashes($path);
        $relative = str_replace($root, '', $file);

        if ($relative === $file) {
            return null;
        }

        return 'Expo\\Push\\' . str_replace('/', '\\', substr($relative, 0, -4));
    }

    private static function slashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
