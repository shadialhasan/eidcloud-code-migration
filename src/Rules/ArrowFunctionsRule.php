<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Rules;

use EidCloud\CodeMigration\Ast\AstTransformer;
use EidCloud\CodeMigration\Ast\TokenStream;

/**
 * Migrates single-expression anonymous functions / closures to arrow functions.
 * E.g.:
 * function($x) { return $x * 2; }
 * =>
 * fn($x) => $x * 2
 *
 * function($x) use ($factor) { return $x * $factor; }
 * =>
 * fn($x) => $x * $factor
 */
class ArrowFunctionsRule implements RuleInterface
{
    public function getId(): string
    {
        return 'callbacks.arrow_functions';
    }

    public function getDescription(): string
    {
        return 'Migrate single-expression closures function(...) { return ...; } to arrow functions fn(...) => ...';
    }

    public function getPack(): string
    {
        return 'callbacks';
    }

    public function apply(AstTransformer $transformer): int
    {
        $stream = $transformer->getStream();
        $changes = 0;

        $i = 0;
        while ($i < $stream->count()) {
            $tok = $stream->get($i);
            if ($tok === null) {
                break;
            }

            if ($tok->id === T_FUNCTION) {
                // Must not be a method or named function (preceded by visibility or function followed by name)
                $prev = $stream->prevMeaningfulIndex($i);
                $prevTok = $prev !== null ? $stream->get($prev) : null;
                if ($prevTok && in_array($prevTok->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                    $i++;
                    continue;
                }

                $next = $stream->nextMeaningfulIndex($i);
                if ($next !== null && $stream->get($next)->id === T_STRING) {
                    // Named function declaration
                    $i++;
                    continue;
                }

                $res = $this->tryTransformClosure($stream, $i);
                if ($res !== null) {
                    $changes++;
                    $i = $res;
                    continue;
                }
            }

            $i++;
        }

        return $changes;
    }

    protected function tryTransformClosure(TokenStream $stream, int $functionIndex): ?int
    {
        $openParen = $stream->nextMeaningfulIndex($functionIndex);
        if ($openParen === null || $stream->get($openParen)->text !== '(') {
            return null;
        }

        $closeParen = $stream->findMatchingPair($openParen, '(', ')');
        if ($closeParen === null) {
            return null;
        }

        $paramsText = trim($stream->sliceText($openParen + 1, $closeParen - 1));

        // Optional 'use (...)' clause
        $curr = $stream->nextMeaningfulIndex($closeParen);
        if ($curr === null) {
            return null;
        }

        if ($stream->get($curr)->id === T_USE) {
            $useOpenParen = $stream->nextMeaningfulIndex($curr);
            if ($useOpenParen === null || $stream->get($useOpenParen)->text !== '(') {
                return null;
            }
            $useCloseParen = $stream->findMatchingPair($useOpenParen, '(', ')');
            if ($useCloseParen === null) {
                return null;
            }
            // Check if any use parameter is passed by reference: e.g. &$foo. Arrow functions capture by value.
            $useContent = $stream->sliceText($useOpenParen, $useCloseParen);
            if (strpos($useContent, '&') !== false) {
                return null; // Cannot migrate reference capture to arrow function safely
            }
            $curr = $stream->nextMeaningfulIndex($useCloseParen);
        }

        // Optional return type: ': type'
        $returnType = '';
        if ($curr !== null && $stream->get($curr)->text === ':') {
            $typeStart = $curr;
            $curr = $stream->nextMeaningfulIndex($curr);
            $typeTokens = [];
            while ($curr < $stream->count() && $stream->get($curr)->text !== '{') {
                $typeTokens[] = $stream->get($curr);
                $curr++;
            }
            $returnType = ': ' . trim(implode('', array_map(fn($t) => $t->text, $typeTokens))) . ' ';
        }

        if ($curr === null || $stream->get($curr)->text !== '{') {
            return null;
        }

        $openBrace = $curr;
        $closeBrace = $stream->findMatchingPair($openBrace, '{', '}');
        if ($closeBrace === null) {
            return null;
        }

        // Check if body is strictly a single return statement: 'return <expr>;'
        $bodyTokens = [];
        for ($k = $openBrace + 1; $k < $closeBrace; $k++) {
            $t = $stream->get($k);
            if ($t->id !== T_WHITESPACE && $t->id !== T_COMMENT && $t->id !== T_DOC_COMMENT) {
                $bodyTokens[] = $t;
            }
        }

        if (empty($bodyTokens) || $bodyTokens[0]->id !== T_RETURN) {
            return null;
        }

        $lastToken = end($bodyTokens);
        if ($lastToken->text !== ';') {
            return null;
        }

        // Verify there is only one statement (semicolon only at the end)
        $semiCount = 0;
        foreach ($bodyTokens as $bt) {
            if ($bt->text === ';') {
                $semiCount++;
            }
        }
        if ($semiCount !== 1) {
            return null;
        }

        // Extract return expression from source between 'return' and ';'
        $returnIdx = null;
        for ($k = $openBrace + 1; $k < $closeBrace; $k++) {
            if ($stream->get($k)->id === T_RETURN) {
                $returnIdx = $k;
                break;
            }
        }
        $semiIdx = null;
        for ($k = $closeBrace - 1; $k > $openBrace; $k--) {
            if ($stream->get($k)->text === ';') {
                $semiIdx = $k;
                break;
            }
        }

        if ($returnIdx === null || $semiIdx === null || $returnIdx >= $semiIdx) {
            return null;
        }

        $expr = trim($stream->sliceText($returnIdx + 1, $semiIdx - 1));

        $replacement = "fn({$paramsText}){$returnType} => {$expr}";
        $stream->replaceRange($functionIndex, $closeBrace, $replacement);
        return $functionIndex + 1;
    }
}
