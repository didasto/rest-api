<?php

/**
 * Reports reads of variables that were never assigned in their method.
 *
 * php -l does not catch this: the file is syntactically fine and the
 * error only surfaces at runtime, usually at a user's site. That is
 * exactly how a $resource once ended up in a method that has none.
 *
 *     php .gitlab/scripts/undefined-variables.php src
 */

require __DIR__.'/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$directory = $argv[1] ?? 'src';

$parser   = (new ParserFactory())->createForNewestSupportedVersion();
$finder   = new NodeFinder();
$findings = 0;

/** Functions that assign through a by-reference argument: name => argument index. */
$byReference = [
    'preg_match'     => 2,
    'preg_match_all' => 2,
    'openssl_sign'   => 1,
    'similar_text'   => 2,
    'str_replace'    => 3,
    'sscanf'         => 2,
];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $ast = $parser->parse(file_get_contents($file->getRealPath()));

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        if (! $method->stmts) {
            continue;
        }

        $known = ['this'];

        foreach ($method->params as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)) {
                $known[] = $parameter->var->name;
            }
        }

        $assignments = $finder->find($method->stmts, fn (Node $node) => $node instanceof Node\Expr\Assign
            || $node instanceof Node\Expr\AssignOp
            || $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\Catch_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Expr\FuncCall);

        foreach ($assignments as $node) {
            $targets = [];

            if ($node instanceof Node\Stmt\Foreach_) {
                $targets = array_filter([$node->keyVar, $node->valueVar]);
            } elseif ($node instanceof Node\Stmt\Catch_) {
                $targets = array_filter([$node->var]);
            } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                foreach ($node->params as $parameter) {
                    if ($parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)) {
                        $known[] = $parameter->var->name;
                    }
                }
            } elseif ($node instanceof Node\Expr\FuncCall) {
                $name = $node->name instanceof Node\Name ? strtolower($node->name->toString()) : null;

                if ($name && isset($byReference[$name])) {
                    $targets = array_slice($node->args, $byReference[$name], 1);
                }
            } else {
                $targets = [$node->var];
            }

            foreach ($targets as $target) {
                foreach ($finder->findInstanceOf([$target], Node\Expr\Variable::class) as $variable) {
                    if (is_string($variable->name)) {
                        $known[] = $variable->name;
                    }
                }
            }
        }

        foreach ($finder->findInstanceOf($method->stmts, Node\Expr\Variable::class) as $variable) {
            if (! is_string($variable->name) || in_array($variable->name, $known, true)) {
                continue;
            }

            printf(
                "  %s:%d  %s()  ->  \$%s\n",
                $file->getPathname(),
                $variable->getLine(),
                $method->name->toString(),
                $variable->name,
            );

            $findings++;
        }
    }
}

if ($findings === 0) {
    echo "No undefined variables found.\n";
    exit(0);
}

echo "\n{$findings} finding(s).\n";
exit(1);
