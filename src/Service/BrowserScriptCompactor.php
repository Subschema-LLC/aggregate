<?php

declare(strict_types=1);

namespace App\Service;

use Peast\Formatter\Compact;
use Peast\Peast;
use Peast\Renderer;
use Peast\Syntax\Node;
use Peast\Traverser;

/**
 * Compacts browser scripts when no Terser build (app:assets:build-js) matches
 * the current source, as on a server updated from Git without Node. The
 * program is parsed and printed again without comments and formatting; names
 * are unchanged and every block keeps its braces. A leading license comment
 * is kept. Results are remembered by the source's hash.
 *
 * The tracker's build switches (see scripts/build-js.cjs) are applied here as
 * in the Terser build: each `var name = true;` declaration is removed and an
 * if statement that tests a switch keeps only the branch that runs.
 */
final class BrowserScriptCompactor
{
    /** Changing the output format changes this, so older results are not reused. */
    private const VERSION = '1';

    /** @var array<string, string> */
    private static array $memory = [];

    public function __construct(private readonly ?string $cacheDirectory = null)
    {
    }

    /**
     * The compact program, or null when it cannot be parsed or a build switch
     * is declared or used other than as described above.
     *
     * @param array<string, bool> $switches build switch name => value
     */
    public function compact(string $source, array $switches = []): ?string
    {
        ksort($switches);
        $key = hash('sha256', self::VERSION."\0".json_encode($switches)."\0".$source);
        if (isset(self::$memory[$key])) {
            return self::$memory[$key];
        }
        $file = $this->cacheDirectory !== null ? rtrim($this->cacheDirectory, '/').'/'.$key.'.js' : null;
        if ($file !== null && is_file($file)) {
            $cached = @file_get_contents($file);
            if (is_string($cached) && $cached !== '') {
                return self::$memory[$key] = $cached;
            }
        }

        try {
            $program = Peast::latest($source, ['sourceType' => Peast::SOURCE_TYPE_SCRIPT])->parse();
            if ($switches !== [] && !self::applySwitches($program, $switches)) {
                return null;
            }
            $compact = (new Renderer())->setFormatter(new class extends Compact {
                // Braces stay, so an else can never attach to a different if.
                protected $alwaysWrapBlocks = true;
            })->render($program);
        } catch (\Throwable) {
            return null;
        }
        $compact = self::license($source).$compact."\n";

        if ($file !== null) {
            // Only a speed-up: a missing or unwritable cache means compacting again.
            if (is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0770, true) || is_dir($this->cacheDirectory)) {
                $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
                if (@file_put_contents($temporary, $compact) !== false && !@rename($temporary, $file)) {
                    @unlink($temporary);
                }
            }
        }

        return self::$memory[$key] = $compact;
    }

    /**
     * A compact template for a configured script: each declaration is first
     * replaced by its placeholder line, as the Terser build does, so settings
     * are inserted per request without compacting again. Null when a
     * declaration or placeholder is not found exactly once.
     *
     * @param array<string, string> $declarations source declaration => placeholder declaration
     * @param list<string> $placeholders
     * @param array<string, bool> $switches build switch name => value
     */
    public function template(string $source, array $declarations, array $placeholders, array $switches = []): ?string
    {
        foreach (array_keys($declarations) as $declaration) {
            if (substr_count($source, $declaration) !== 1) {
                return null;
            }
        }
        $template = $this->compact(strtr($source, $declarations), $switches);
        foreach ($placeholders as $placeholder) {
            if ($template === null || substr_count($template, $placeholder) !== 1) {
                return null;
            }
        }

        return $template;
    }

    /**
     * Removes each switch declaration and keeps the branch of every if that
     * tests a switch. False when a switch is not declared exactly once as
     * `var name = true;` in a statement list, or is used any other way.
     *
     * @param array<string, bool> $switches
     */
    private static function applySwitches(Node\Program $program, array $switches): bool
    {
        $declared = [];
        $valid = true;
        $inList = static fn (?Node\Node $parent): bool => $parent instanceof Node\Program
            || $parent instanceof Node\BlockStatement || $parent instanceof Node\SwitchCase;
        // The value an if statement's test has: a switch or its negation.
        $test = static function (Node\Node $test) use ($switches): ?bool {
            $negated = $test instanceof Node\UnaryExpression && $test->getOperator() === '!';
            $name = $negated ? $test->getArgument() : $test;
            if (!$name instanceof Node\Identifier || !array_key_exists($name->getName(), $switches)) {
                return null;
            }

            return $negated ? !$switches[$name->getName()] : $switches[$name->getName()];
        };

        $traverser = new Traverser(['passParentNode' => true]);
        $traverser->addFunction(static function (Node\Node $node, ?Node\Node $parent) use ($switches, $inList, $test, &$declared, &$valid) {
            if ($node instanceof Node\VariableDeclaration) {
                foreach ($node->getDeclarations() as $declarator) {
                    $id = $declarator->getId();
                    if (!$id instanceof Node\Identifier || !array_key_exists($id->getName(), $switches)) {
                        continue;
                    }
                    $init = $declarator->getInit();
                    if ($node->getKind() !== 'var' || count($node->getDeclarations()) !== 1 || !$inList($parent)
                        || !$init instanceof Node\BooleanLiteral || $init->getValue() !== true) {
                        $valid = false;

                        return Traverser::STOP_TRAVERSING;
                    }
                    $declared[$id->getName()] = ($declared[$id->getName()] ?? 0) + 1;

                    return Traverser::REMOVE_NODE;
                }
            }
            // A kept branch can itself be an if on a switch (else if).
            $replaced = false;
            while ($node instanceof Node\IfStatement && ($value = $test($node->getTest())) !== null) {
                $node = $value ? $node->getConsequent() : $node->getAlternate();
                $replaced = true;
            }
            if (!$replaced) {
                return null;
            }
            if ($node === null) {
                return $inList($parent) ? Traverser::REMOVE_NODE : new Node\EmptyStatement();
            }
            // A one-statement block needs no braces unless its statement is block scoped.
            if ($node instanceof Node\BlockStatement && count($node->getBody()) === 1 && $inList($parent)) {
                $statement = $node->getBody()[0];
                if (!$statement instanceof Node\FunctionDeclaration && !$statement instanceof Node\ClassDeclaration
                    && !($statement instanceof Node\VariableDeclaration && $statement->getKind() !== 'var')) {
                    return $statement;
                }
            }

            return $node;
        });
        $traverser->traverse($program);
        foreach (array_keys($switches) as $name) {
            if (!$valid || ($declared[$name] ?? 0) !== 1) {
                return false;
            }
        }

        // Anything left, such as a switch inside an expression, would be undefined.
        $remaining = false;
        (new Traverser())->addFunction(static function (Node\Node $node) use ($switches, &$remaining) {
            if ($node instanceof Node\Identifier && array_key_exists($node->getName(), $switches)) {
                $remaining = true;

                return Traverser::STOP_TRAVERSING;
            }

            return null;
        })->traverse($program);

        return !$remaining;
    }

    /** A /*! comment at the start of the source: the script's license notice. */
    private static function license(string $source): string
    {
        return preg_match('~\A\s*(/\*!.*?\*/)~s', $source, $match) === 1 ? $match[1]."\n" : '';
    }
}
