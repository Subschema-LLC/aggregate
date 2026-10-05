<?php

declare(strict_types=1);

namespace App\Service;

use Peast\Peast;
use Peast\Syntax\Exception as SyntaxException;
use Peast\Syntax\Node;
use Peast\Syntax\Utils;

/**
 * Custom JavaScript for tag manager tags.
 *
 * Each tag's code becomes the body of `function (tag) { 'use strict'; ... }`,
 * compiled into the website's tag manager script. Because one syntax error
 * would stop every tag on the page, code is parsed before it is saved or
 * served: it must be a valid strict-mode function body that cannot step
 * outside its function, and a few error-prone or string-evaluating constructs
 * are refused. A parser cannot judge what code does; this checks how it is
 * written.
 */
final class TagManagerCustomCode
{
    public const MAX_BYTES = 20000;
    /** Total for one website, which keeps its tag manager script small. */
    public const MAX_TOTAL_BYTES = 65536;
    public const FEATURE = 'custom_scripts';
    /** Change this when the checks change, so cached approvals are made again. */
    private const RULES_VERSION = '1';
    private const PREFIX = "(function (tag) {\n'use strict';\n";
    private const SUFFIX = "\n})";
    private const PREFIX_LINES = 2;
    private const STRICT_RESERVED = ['eval', 'arguments'];

    /** @var array<string, true> Code already checked by this process. */
    private static array $checked = [];

    /**
     * Returns the code with normalized line endings, or explains the first
     * problem. A cache directory remembers code that passed, so serving a
     * website's script does not parse it again.
     */
    public static function validate(mixed $code, string $path, ?string $cacheDirectory = null): string
    {
        if (!is_string($code) || preg_match('//u', $code) !== 1) {
            throw new \InvalidArgumentException($path.' must be JavaScript text in UTF-8.');
        }
        $code = rtrim(str_replace(["\r\n", "\r"], "\n", $code));
        if (trim($code) === '') {
            throw new \InvalidArgumentException($path.' is empty. Write the JavaScript to run, or remove the tag.');
        }
        if (strlen($code) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf('%s is %s bytes; custom JavaScript allows at most %s bytes per tag. Load larger code with tag.loadScript().', $path, number_format(strlen($code)), number_format(self::MAX_BYTES)));
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $code) === 1) {
            throw new \InvalidArgumentException($path.' contains control characters. Only tabs and line breaks are allowed.');
        }
        if (preg_match('/^\s*</', $code) === 1) {
            throw new \InvalidArgumentException($path.' looks like HTML. Custom JavaScript runs as script: remove <script> and </script> and keep only the code between them. Custom HTML tags are on the roadmap.');
        }

        $key = hash('sha256', self::RULES_VERSION."\0".$code);
        $marker = $cacheDirectory !== null ? rtrim($cacheDirectory, '/').'/'.$key : null;
        if (isset(self::$checked[$key]) || ($marker !== null && is_file($marker))) {
            return $code;
        }
        self::check($code, $path);
        self::$checked[$key] = true;
        if ($marker !== null) {
            // Only a speed-up: a missing or unwritable cache means checking again.
            if (is_dir($cacheDirectory) || @mkdir($cacheDirectory, 0770, true) || is_dir($cacheDirectory)) {
                @file_put_contents($marker, '');
            }
        }

        return $code;
    }

    /** The function expression the tag manager script contains for this code. */
    public static function wrap(string $code): string
    {
        return substr(self::PREFIX, 1).$code.substr(self::SUFFIX, 0, -1);
    }

    /**
     * Starter templates offered in the dashboard. Each is a list of code lines
     * with a suggested ID, trigger and consent category.
     *
     * @return list<array{id: string, group: string, label: string, description: string, consent: string, trigger: array, code: string}>
     */
    public static function templates(): array
    {
        $file = dirname(__DIR__, 2).'/templates/tag_manager/custom_script_templates.json';
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (array $template): array => ['code' => implode("\n", $template['code'])] + $template, $data);
    }

    private static function check(string $code, string $path): void
    {
        try {
            $program = Peast::latest(self::PREFIX.$code.self::SUFFIX, ['sourceType' => Peast::SOURCE_TYPE_SCRIPT])->parse();
        } catch (SyntaxException $e) {
            $line = $e->getPosition()->getLine() - self::PREFIX_LINES;
            throw new \InvalidArgumentException(sprintf('%s has a syntax error on line %d: %s.', $path, max(1, min($line, substr_count($code, "\n") + 1)), rtrim($e->getMessage(), '.')));
        } catch (\Throwable) {
            throw new \InvalidArgumentException($path.' could not be read as JavaScript.');
        }

        // The whole program must be the one function the code was placed in.
        $body = $program->getBody();
        $function = count($body) === 1 && $body[0] instanceof Node\ExpressionStatement ? $body[0]->getExpression() : null;
        while ($function instanceof Node\ParenthesizedExpression) {
            $function = $function->getExpression();
        }
        if (!$function instanceof Node\FunctionExpression) {
            throw new \InvalidArgumentException($path.' closes its function early. Custom JavaScript is the body of one function; check for an extra closing brace or parenthesis.');
        }

        $checker = new self($path);
        $checker->function($function);
    }

    private function __construct(private readonly string $path)
    {
    }

    /** @var list<string> Labels enclosing the current statement. */
    private array $labels = [];

    private function function(Node\Function_|Node\ArrowFunctionExpression $function): void
    {
        $parameters = [];
        foreach ($function->getParams() as $parameter) {
            foreach ($this->boundNames($parameter) as $name) {
                if (in_array($name, $parameters, true)) {
                    $this->fail($parameter, sprintf('declares the parameter "%s" twice', $name));
                }
                $parameters[] = $name;
            }
            $this->walk($parameter);
        }
        if ($function instanceof Node\Function_ && $function->getId() !== null) {
            $this->binding($function->getId());
        }
        $labels = $this->labels;
        $this->labels = [];
        $body = $function->getBody();
        if ($body instanceof Node\BlockStatement) {
            $this->scope($body->getBody(), $parameters, true);
        } else {
            $this->walk($body);
        }
        $this->labels = $labels;
    }

    /**
     * One block of statements. Strict mode forbids declaring a name twice with
     * let, const, class or a block-level function, or reusing a var or
     * parameter name for one of those.
     *
     * @param list<Node\Node> $statements
     * @param list<string> $outer names already bound in this scope (parameters)
     */
    private function scope(array $statements, array $outer = [], bool $functionBody = false): void
    {
        $lexical = [];
        foreach ($statements as $statement) {
            $names = [];
            if ($statement instanceof Node\VariableDeclaration && $statement->getKind() !== 'var') {
                foreach ($statement->getDeclarations() as $declarator) {
                    array_push($names, ...$this->boundNames($declarator->getId()));
                }
            } elseif ($statement instanceof Node\ClassDeclaration || (!$functionBody && $statement instanceof Node\FunctionDeclaration)) {
                $names[] = $statement->getId()->getName();
            }
            foreach ($names as $name) {
                if (isset($lexical[$name]) || in_array($name, $outer, true)) {
                    $this->fail($statement, sprintf('declares "%s" twice in the same scope%s', $name, $name === 'tag' && $functionBody && $outer === ['tag'] ? ', and tag is the name of the object your code receives' : ''));
                }
                $lexical[$name] = true;
            }
        }
        $hoisted = $this->varNames($statements, $functionBody);
        foreach (array_keys($lexical) as $name) {
            if (in_array($name, $hoisted, true)) {
                $this->fail($statements[0], sprintf('declares "%s" with both var and let, const, class or function in the same scope', $name));
            }
        }
        foreach ($statements as $statement) {
            $this->walk($statement);
        }
    }

    /**
     * Names declared with var anywhere in these statements, without entering
     * nested functions; at a function's top level, function declarations too.
     *
     * @param list<Node\Node> $statements
     * @return list<string>
     */
    private function varNames(array $statements, bool $functionBody): array
    {
        $names = [];
        $visit = function (Node\Node $node) use (&$visit, &$names): void {
            if ($node instanceof Node\Function_ || $node instanceof Node\ArrowFunctionExpression || $node instanceof Node\Class_) {
                return;
            }
            if ($node instanceof Node\VariableDeclaration && $node->getKind() === 'var') {
                foreach ($node->getDeclarations() as $declarator) {
                    array_push($names, ...$this->boundNames($declarator->getId()));
                }
            }
            foreach ($this->children($node) as $child) {
                $visit($child);
            }
        };
        foreach ($statements as $statement) {
            if ($functionBody && $statement instanceof Node\FunctionDeclaration) {
                $names[] = $statement->getId()->getName();
                continue;
            }
            $visit($statement);
        }

        return $names;
    }

    private function walk(Node\Node $node): void
    {
        if ($node instanceof Node\Function_ || $node instanceof Node\ArrowFunctionExpression) {
            $this->function($node);

            return;
        }
        if ($node instanceof Node\BlockStatement || $node instanceof Node\StaticBlock) {
            $this->scope($node->getBody());

            return;
        }
        if ($node instanceof Node\SwitchStatement) {
            $this->walk($node->getDiscriminant());
            $statements = [];
            foreach ($node->getCases() as $case) {
                array_push($statements, ...$case->getConsequent());
            }
            $this->scope($statements);
            foreach ($node->getCases() as $case) {
                if ($case->getTest() !== null) $this->walk($case->getTest());
            }

            return;
        }
        if ($node instanceof Node\CatchClause) {
            $parameters = $node->getParam() !== null ? $this->boundNames($node->getParam()) : [];
            if ($node->getParam() !== null) $this->walk($node->getParam());
            $this->scope($node->getBody()->getBody(), $parameters);

            return;
        }
        if ($node instanceof Node\LabeledStatement) {
            $label = $node->getLabel()->getName();
            if (in_array($label, $this->labels, true)) {
                $this->fail($node, sprintf('uses the label "%s" inside a statement with the same label', $label));
            }
            $this->labels[] = $label;
            $this->walk($node->getBody());
            array_pop($this->labels);

            return;
        }
        $this->rules($node);
        foreach ($this->children($node) as $child) {
            $this->walk($child);
        }
    }

    /** Strict-mode rules the parser leaves to the browser, and the guardrails. */
    private function rules(Node\Node $node): void
    {
        if ($node instanceof Node\WithStatement) {
            $this->fail($node, 'uses with, which strict mode does not allow');
        }
        if ($node instanceof Node\DebuggerStatement) {
            $this->fail($node, 'contains a debugger statement; remove it before saving');
        }
        if ($node instanceof Node\ImportExpression) {
            $this->fail($node, 'uses import(); load scripts with tag.loadScript(url), which accepts only HTTPS addresses');
        }
        if ($node instanceof Node\VariableDeclarator) {
            foreach ($this->boundNames($node->getId()) as $name) {
                $this->reserved($node, $name);
            }
        }
        if ($node instanceof Node\ClassDeclaration && $node->getId() !== null) {
            $this->reserved($node, $node->getId()->getName());
        }
        if (($node instanceof Node\AssignmentExpression && $node->getLeft() instanceof Node\Identifier)
            || ($node instanceof Node\UpdateExpression && $node->getArgument() instanceof Node\Identifier)) {
            $target = $node instanceof Node\AssignmentExpression ? $node->getLeft() : $node->getArgument();
            $this->reserved($node, $target->getName());
        }
        if ($node instanceof Node\CallExpression || $node instanceof Node\NewExpression) {
            $callee = $node->getCallee();
            $name = $callee instanceof Node\Identifier ? $callee->getName()
                : ($callee instanceof Node\MemberExpression && !$callee->getComputed() && $callee->getProperty() instanceof Node\Identifier ? $callee->getProperty()->getName() : null);
            if ($name === 'eval' || $name === 'Function') {
                $this->fail($node, sprintf('calls %s, which runs text as code; write the code directly instead', $name === 'eval' ? 'eval()' : 'Function()'));
            }
            $arguments = $node->getArguments();
            if (in_array($name, ['setTimeout', 'setInterval'], true) && $arguments !== []
                && ($arguments[0] instanceof Node\StringLiteral || $arguments[0] instanceof Node\TemplateLiteral)) {
                $this->fail($node, sprintf('passes text to %s(), which runs it as code; pass a function, such as %s(() => { ... }, 1000)', $name, $name));
            }
            if (in_array($name, ['write', 'writeln'], true) && $callee instanceof Node\MemberExpression
                && (($callee->getObject() instanceof Node\Identifier && $callee->getObject()->getName() === 'document')
                    || ($callee->getObject() instanceof Node\MemberExpression && $callee->getObject()->getProperty() instanceof Node\Identifier
                        && $callee->getObject()->getProperty()->getName() === 'document'))) {
                $this->fail($node, 'calls document.'.$name.'(), which erases the page when it runs after loading; add elements with DOM methods or tag.loadScript(url)');
            }
        }
    }

    private function binding(Node\Identifier $identifier): void
    {
        $this->reserved($identifier, $identifier->getName());
    }

    private function reserved(Node\Node $node, string $name): void
    {
        if (in_array($name, self::STRICT_RESERVED, true)) {
            $this->fail($node, sprintf('declares or assigns "%s", which strict mode does not allow', $name));
        }
    }

    /** @return list<string> */
    private function boundNames(?Node\Node $pattern): array
    {
        return match (true) {
            $pattern instanceof Node\Identifier => [$pattern->getName()],
            $pattern instanceof Node\AssignmentPattern => $this->boundNames($pattern->getLeft()),
            $pattern instanceof Node\RestElement => $this->boundNames($pattern->getArgument()),
            $pattern instanceof Node\ArrayPattern => array_merge([], ...array_map($this->boundNames(...), array_filter($pattern->getElements()))),
            $pattern instanceof Node\ObjectPattern => array_merge([], ...array_map(
                fn (Node\Node $property): array => $this->boundNames($property instanceof Node\RestElement ? $property : $property->getValue()),
                $pattern->getProperties(),
            )),
            default => [],
        };
    }

    /** @return list<Node\Node> */
    private function children(Node\Node $node): array
    {
        $children = [];
        foreach (Utils::getNodeProperties($node, true) as $property) {
            $child = $node->{$property['getter']}();
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node\Node) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }

    private function fail(Node\Node $node, string $problem): never
    {
        $line = max(1, $node->getLocation()->getStart()->getLine() - self::PREFIX_LINES);
        throw new \InvalidArgumentException(sprintf('%s %s (line %d).', $this->path, $problem, $line));
    }
}
