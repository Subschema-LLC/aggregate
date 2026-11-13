<?php

declare(strict_types=1);

namespace App\Tools\ArchCheck;

/**
 * What the architecture check needs to know about one PHP file, read from its
 * tokens without loading it: its classes, the App\ names it refers to, and the
 * places it reaches the outside world or the clock.
 */
final class SourceFile
{
    private const CLOCK_FUNCTIONS = ['time', 'microtime', 'hrtime', 'date_create', 'date_create_immutable'];
    private const IO_FUNCTIONS = [
        'file_get_contents', 'file_put_contents', 'fopen', 'file', 'unlink', 'mkdir', 'rmdir',
        'scandir', 'glob', 'is_file', 'is_dir', 'file_exists', 'touch', 'rename', 'copy',
        'getenv', 'putenv', 'curl_init', 'mail', 'fsockopen', 'exec', 'shell_exec', 'proc_open',
    ];
    private const SUPERGLOBALS = ['$_ENV', '$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_REQUEST'];
    private const SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /** @var list<array{name: string, kind: string, abstract: bool, final: bool, extends: ?string, entity: bool, line: int}> */
    public array $classes = [];
    /** @var list<array{name: string, line: int}> fully qualified, without the leading backslash */
    public array $references = [];
    /** @var list<array{what: string, line: int}> clock reads, I/O calls and superglobals */
    public array $outside = [];
    /** @var array<int, int> line number => length, for lines over the limit */
    public array $longLines = [];
    public int $lineCount;

    private string $namespace = '';
    /** @var array<string, string> alias => fully qualified name */
    private array $imports = [];

    public function __construct(public readonly string $path, string $code, int $maxLineLength = 100)
    {
        $lines = preg_split('/\R/', rtrim($code, "\r\n")) ?: [];
        $this->lineCount = count($lines);
        foreach ($lines as $index => $line) {
            if (mb_strlen($line) > $maxLineLength) {
                $this->longLines[$index + 1] = mb_strlen($line);
            }
        }
        $this->read(array_values(array_filter(
            token_get_all($code),
            static fn ($t) => !is_array($t) || !in_array($t[0], self::SKIP, true),
        )));
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens without whitespace or comments */
    private function read(array $tokens): void
    {
        $depth = 0;
        $entity = false;
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
                continue;
            }
            if ($token === '}') {
                --$depth;
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            $previous = $tokens[$i - 1] ?? null;
            $previousId = is_array($previous) ? $previous[0] : $previous;
            if ($id === T_NAMESPACE && $depth === 0) {
                $this->namespace = $this->text($tokens, $i + 1);
                ++$i;
                continue;
            }
            if ($id === T_USE && $depth === 0) {
                $i = $this->readUse($tokens, $i + 1, $line);
                continue;
            }
            match (true) {
                $id === T_ATTRIBUTE => $entity = $entity || $this->isEntityAttribute($tokens, $i + 1),
                in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                    && !in_array($previousId, [T_DOUBLE_COLON, T_NEW], true) => $this->readClass($tokens, $i, $entity),
                in_array($id, [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) => $this->refer($text, $line),
                $id === T_NEW => $this->readNew($tokens, $i + 1, $line),
                $id === T_VARIABLE && in_array($text, self::SUPERGLOBALS, true) => $this->outside[] = ['what' => $text, 'line' => $line],
                $id === T_STRING && ($tokens[$i + 1] ?? null) === '(' => $this->readCall($text, $previousId, $line),
                default => null,
            };
            $declares = in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION], true);
            if ($declares && !in_array($previousId, [T_DOUBLE_COLON, T_NEW], true)) {
                $entity = false;
            }
        }
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return int the index of the statement's semicolon
     */
    private function readUse(array $tokens, int $i, int $line): int
    {
        $kind = $tokens[$i];
        if (is_array($kind) && in_array($kind[1], ['function', 'const'], true)) {
            while (($tokens[$i] ?? ';') !== ';') {
                ++$i;
            }

            return $i;
        }
        $prefix = '';
        $name = '';
        for (; ($token = $tokens[$i] ?? ';') !== ';'; ++$i) {
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '{') {
                $prefix = rtrim($name, '\\').'\\';
                $name = '';
            } elseif ($text === ',' || $text === '}') {
                $name === '' || $this->import($prefix.$name, null, $line);
                $name = '';
            } elseif (is_array($token) && $token[0] === T_AS) {
                $this->import($prefix.$name, $this->text($tokens, $i + 1), $line);
                $name = '';
                ++$i;
            } elseif ($text !== '\\' || $name !== '') {
                $name .= $text;
            }
        }
        $name === '' || $this->import($prefix.$name, null, $line);

        return $i;
    }

    private function import(string $name, ?string $alias, int $line): void
    {
        $name = trim($name, '\\ ');
        if ($name === '') {
            return;
        }
        $this->imports[$alias ?? substr($name, (int) strrpos('\\'.$name, '\\'))] = $name;
        $this->references[] = ['name' => $name, 'line' => $line];
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function readClass(array $tokens, int $i, bool $entity): void
    {
        $modifiers = [];
        for ($j = $i - 1; is_array($tokens[$j] ?? null) && in_array($tokens[$j][0], [T_FINAL, T_ABSTRACT, T_READONLY], true); --$j) {
            $modifiers[] = $tokens[$j][0];
        }
        $name = $tokens[$i + 1] ?? null;
        if (!is_array($name) || $name[0] !== T_STRING) {
            return;
        }
        $extends = null;
        if (is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_EXTENDS && $tokens[$i][0] === T_CLASS) {
            $extends = $this->resolve($this->text($tokens, $i + 3));
        }
        $this->classes[] = [
            'name' => ltrim($this->namespace.'\\'.$name[1], '\\'),
            'kind' => strtolower($tokens[$i][1]),
            'abstract' => in_array(T_ABSTRACT, $modifiers, true),
            'final' => in_array(T_FINAL, $modifiers, true),
            'extends' => $extends,
            'entity' => $entity,
            'line' => $tokens[$i][2],
        ];
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function readNew(array $tokens, int $i, int $line): void
    {
        $class = is_array($tokens[$i] ?? null) ? ltrim($tokens[$i][1], '\\') : '';
        if (!in_array(strtolower($class), ['datetime', 'datetimeimmutable'], true) || ($tokens[$i + 1] ?? null) !== '(') {
            return;
        }
        $argument = $tokens[$i + 2] ?? null;
        $now = $argument === ')' || (is_array($argument) && in_array(strtolower(trim($argument[1], '\'"')), ['now', 'today'], true));
        if ($now) {
            $this->outside[] = ['what' => 'new '.$class.'() reads the clock', 'line' => $line];
        }
    }

    private function readCall(string $function, int|string|null $previousId, int $line): void
    {
        if (in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            return;
        }
        $function = strtolower($function);
        if (in_array($function, self::CLOCK_FUNCTIONS, true)) {
            $this->outside[] = ['what' => $function.'() reads the clock', 'line' => $line];
        } elseif (in_array($function, self::IO_FUNCTIONS, true)) {
            $this->outside[] = ['what' => $function.'() reaches the outside world', 'line' => $line];
        }
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function isEntityAttribute(array $tokens, int $i): bool
    {
        $name = $this->resolve($this->text($tokens, $i));

        return $name === 'Doctrine\\ORM\\Mapping\\Entity';
    }

    private function refer(string $name, int $line): void
    {
        $this->references[] = ['name' => $this->resolve($name), 'line' => $line];
    }

    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = explode('\\', $name)[0];
        if (isset($this->imports[$first])) {
            return $this->imports[$first].substr($name, strlen($first));
        }

        return ltrim($this->namespace.'\\'.$name, '\\');
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function text(array $tokens, int $i): string
    {
        $token = $tokens[$i] ?? '';

        return is_array($token) ? $token[1] : '';
    }
}
