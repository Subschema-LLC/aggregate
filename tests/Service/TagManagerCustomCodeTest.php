<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TagManagerCustomCode;
use App\Service\TagManagerSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TagManagerCustomCodeTest extends TestCase
{
    /**
     * tests/JavaScript/tag-manager-custom.test.js confirms each entry's syntax
     * verdict with a real JavaScript engine; here the validator must agree,
     * plus refuse the valid code its guardrails exclude.
     */
    public function testValidatorAgreesWithTheEngineVerifiedCorpus(): void
    {
        $corpus = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/custom-script-corpus.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(50, count($corpus));
        foreach ($corpus as $entry) {
            try {
                TagManagerCustomCode::validate($entry['code'], 'code');
                $allowed = true;
            } catch (\InvalidArgumentException $e) {
                $allowed = false;
                self::assertStringStartsWith('code ', $e->getMessage());
            }
            self::assertSame($entry['allowed'], $allowed, $entry['code']);
        }
    }

    #[DataProvider('explanations')]
    public function testProblemsAreExplainedWithLinesInTheUsersOwnCode(string $code, string $message): void
    {
        try {
            TagManagerCustomCode::validate($code, 'Tag cta: the custom JavaScript');
            self::fail('Accepted: '.$code);
        } catch (\InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public static function explanations(): iterable
    {
        yield 'syntax' => ["const a = 1;\n\nif (a {\n}", 'Tag cta: the custom JavaScript has a syntax error on line 3: Unexpected: {.'];
        yield 'escape' => ["tag.emit('a');\n}); alert(1); (function () {", 'Tag cta: the custom JavaScript closes its function early. Custom JavaScript is the body of one function; check for an extra closing brace or parenthesis.'];
        yield 'parameter name' => ["// setup\nconst tag = 1;", 'Tag cta: the custom JavaScript declares "tag" twice in the same scope, and tag is the name of the object your code receives (line 2).'];
        yield 'eval' => ["const x = 1;\nwindow.eval('x');", 'Tag cta: the custom JavaScript calls eval(), which runs text as code; write the code directly instead (line 2).'];
        yield 'timer text' => ["setInterval('poll()', 500);", 'Tag cta: the custom JavaScript passes text to setInterval(), which runs it as code; pass a function, such as setInterval(() => { ... }, 1000) (line 1).'];
        yield 'document.write' => ["\n\ndocument.write('<b>x</b>');", 'Tag cta: the custom JavaScript calls document.write(), which erases the page when it runs after loading; add elements with DOM methods or tag.loadScript(url) (line 3).'];
        yield 'html' => ["<script>\n  tag.emit('x');\n</script>", 'Tag cta: the custom JavaScript looks like HTML. Custom JavaScript runs as script: remove <script> and </script> and keep only the code between them. Custom HTML tags are on the roadmap.'];
        yield 'empty' => ["  \n\t\n", 'Tag cta: the custom JavaScript is empty. Write the JavaScript to run, or remove the tag.'];
        yield 'control character' => ["tag.emit('a\x01');", 'Tag cta: the custom JavaScript contains control characters. Only tabs and line breaks are allowed.'];
        yield 'not text' => ["tag.emit('\xFF');", 'Tag cta: the custom JavaScript must be JavaScript text in UTF-8.'];
        yield 'too long' => ['// '.str_repeat('x', 20000), 'Tag cta: the custom JavaScript is 20,003 bytes; custom JavaScript allows at most 20,000 bytes per tag. Load larger code with tag.loadScript().'];
    }

    public function testLineEndingsAreNormalizedAndTheWrapperMatchesWhatWasParsed(): void
    {
        self::assertSame("tag.emit('a');\nreturn;", TagManagerCustomCode::validate("tag.emit('a');\r\nreturn;\r\n\n", 'code'));
        self::assertSame("function (tag) {\n'use strict';\nreturn;\n}", TagManagerCustomCode::wrap('return;'));
        self::assertSame(20000, strlen(TagManagerCustomCode::validate('//'.str_repeat('x', 19998), 'code')));
    }

    public function testAPassingResultIsRememberedInTheCacheDirectory(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-custom-code-'.bin2hex(random_bytes(8));
        try {
            $code = "tag.emit('cached-".bin2hex(random_bytes(4))."');";
            TagManagerCustomCode::validate($code, 'code', $directory);
            self::assertCount(1, glob($directory.'/*'));
            TagManagerCustomCode::validate($code, 'code', $directory);
            self::assertCount(1, glob($directory.'/*'), 'the same code is not recorded twice');
            try {
                TagManagerCustomCode::validate('eval(1);', 'code', $directory);
            } catch (\InvalidArgumentException) {
            }
            self::assertCount(1, glob($directory.'/*'), 'refused code is never remembered');
            TagManagerCustomCode::validate("tag.emit('unwritable');", 'code', '/proc/aggregate-not-writable');
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testEveryStarterTemplateIsAValidTagAndExplainsItself(): void
    {
        $templates = TagManagerCustomCode::templates();
        self::assertCount(8, $templates);
        foreach ($templates as $template) {
            self::assertSame(['code', 'id', 'group', 'label', 'description', 'consent', 'trigger'], array_keys($template));
            self::assertStringStartsWith('// ', $template['code']);
            $settings = TagManagerSettings::validate(['enabled' => true, 'tags' => [[
                'id' => $template['id'], 'type' => 'custom', 'code' => $template['code'], 'consent' => $template['consent'], 'trigger' => $template['trigger'],
            ]]]);
            self::assertSame($template['code'], $settings['tags'][0]['code'], $template['id']);
            self::assertStringNotContainsString('localStorage', $template['code'], 'templates add no browser storage');
            self::assertStringNotContainsString('document.cookie', $template['code']);
        }
    }
}
