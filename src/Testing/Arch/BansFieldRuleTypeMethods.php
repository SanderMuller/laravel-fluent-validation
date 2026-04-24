<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Testing\Arch;

use FilesystemIterator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SanderMuller\FluentValidation\Exceptions\TypedBuilderHint;
use SanderMuller\FluentValidation\FluentRule;
use SplFileInfo;

/**
 * Pest / PHPUnit arch helper that returns every PHP file under the given
 * paths containing a `FluentRule::field()->X()` chain where `X` is a
 * type-specific method that belongs on a typed builder (`::string()`,
 * `::numeric()`, etc.). Catches the Macroable silent-fatal footgun at CI
 * time, before it ever reaches production.
 *
 *     arch('FluentRule::field() does not chain type-specific methods')
 *         ->expect(BansFieldRuleTypeMethods::scope('app/'))
 *         ->toBeEmpty();
 *
 * Requires `nikic/php-parser` as a dev dependency (listed under
 * `composer.json` `suggest`). Throws `RuntimeException` with install
 * instructions when the parser is absent.
 *
 * @api
 */
final class BansFieldRuleTypeMethods
{
    /**
     * @return list<string> Absolute file paths that contain at least one violation.
     */
    public static function scope(string ...$paths): array
    {
        if (! class_exists(ParserFactory::class)) {
            throw new RuntimeException(
                'BansFieldRuleTypeMethods requires nikic/php-parser ^5.0. '
                . 'Add it to your dev dependencies: composer require --dev "nikic/php-parser:^5.0"'
            );
        }

        // `createForHostVersion()` is v5-only. v4 consumers would pass the
        // `class_exists` check above but fatal here with `Error: Call to
        // undefined method`. Catching `\Error` turns that into an actionable
        // upgrade message.
        try {
            $parser = (new ParserFactory())->createForHostVersion();
        } catch (\Error $error) {
            throw new RuntimeException('BansFieldRuleTypeMethods requires nikic/php-parser ^5.0 '
            . '(installed version is too old). '
            . 'Upgrade: composer require --dev "nikic/php-parser:^5.0"', $error->getCode(), previous: $error);
        }

        $banned = array_flip(TypedBuilderHint::knownMethods());

        $violations = [];

        foreach ($paths as $path) {
            foreach (self::phpFiles($path) as $file) {
                $code = @file_get_contents($file);

                if ($code === false) {
                    continue;
                }

                try {
                    $ast = $parser->parse($code);
                } catch (Error) {
                    continue;
                }

                if ($ast === null) {
                    continue;
                }

                if (self::containsViolation($ast, $banned)) {
                    $violations[] = $file;
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /** @return iterable<string> */
    private static function phpFiles(string $path): iterable
    {
        if (is_file($path)) {
            if (str_ends_with($path, '.php')) {
                yield $path;
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                yield $entry->getPathname();
            }
        }
    }

    /**
     * @param  array<Node>  $ast
     * @param  array<string, int>  $banned
     */
    private static function containsViolation(array $ast, array $banned): bool
    {
        $visitor = new class ($banned) extends NodeVisitorAbstract {
            public bool $found = false;

            /** @param  array<string, int>  $banned */
            public function __construct(private readonly array $banned) {}

            public function enterNode(Node $node): null
            {
                if ($this->found) {
                    return null;
                }

                if (! $node instanceof MethodCall) {
                    return null;
                }

                if (! $node->name instanceof Identifier) {
                    return null;
                }

                if (! isset($this->banned[$node->name->toString()])) {
                    return null;
                }

                if ($this->chainRootsAtFluentRuleField($node)) {
                    $this->found = true;
                }

                return null;
            }

            private function chainRootsAtFluentRuleField(MethodCall $call): bool
            {
                $current = $call->var;

                while ($current instanceof MethodCall) {
                    $current = $current->var;
                }

                if (! $current instanceof StaticCall) {
                    return false;
                }

                if (! $current->name instanceof Identifier) {
                    return false;
                }

                if ($current->name->toString() !== 'field') {
                    return false;
                }

                if (! $current->class instanceof Name) {
                    return false;
                }

                // After the separate NameResolver pass (`replaceNodes: true`
                // default), `$current->class` is the fully-qualified Name node
                // that resolves imports + aliases. `toString()` returns the
                // FQN without a leading backslash.
                return $current->class->toString() === FluentRule::class;
            }
        };

        // Two-pass traversal: NameResolver mutates class Name nodes to their
        // fully qualified form on `enterNode`. If we ran the violation
        // visitor in the same pass, it would inspect a child `StaticCall`
        // before NameResolver's `enterNode` had a chance to resolve that
        // child's class Name. Running name resolution as a separate first
        // pass guarantees the whole AST is resolved before we walk it.
        $resolve = new NodeTraverser();
        $resolve->addVisitor(new NameResolver());

        $ast = $resolve->traverse($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }
}
