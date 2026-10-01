<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Rules;

use EidCloud\CodeMigration\Ast\AstTransformer;
use EidCloud\CodeMigration\Ast\TokenStream;

/**
 * Migrates ternary checks of null to nullsafe operators:
 * e.g., $user !== null ? $user->getName() : null
 * => $user?->getName()
 * Also handles !is_null($user) ? $user->get() : null
 */
class NullsafeOperatorRule implements RuleInterface
{
    public function getId(): string
    {
        return 'php84.nullsafe';
    }

    public function getDescription(): string
    {
        return 'Migrate null-check ternaries ($obj !== null ? $obj->method() : null) to nullsafe operator ($obj?->method())';
    }

    public function getPack(): string
    {
        return 'php84';
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

            // Pattern 1: $var !== null ? $var->call() : null
            // Check for $var
            if ($tok->id === T_VARIABLE) {
                $varName = $tok->text;
                $p1 = $stream->nextMeaningfulIndex($i);
                if ($p1 !== null && $stream->get($p1)->id === T_IS_NOT_IDENTICAL) {
                    $p2 = $stream->nextMeaningfulIndex($p1);
                    if ($p2 !== null && strtolower($stream->get($p2)->text) === 'null') {
                        $p3 = $stream->nextMeaningfulIndex($p2);
                        if ($p3 !== null && $stream->get($p3)->text === '?') {
                            $res = $this->tryTransformTernary($stream, $i, $varName, $p3);
                            if ($res !== null) {
                                $changes++;
                                $i = $res;
                                continue;
                            }
                        }
                    }
                }
            }

            // Pattern 2: null !== $var ? $var->call() : null
            if ($tok->id === T_STRING && strtolower($tok->text) === 'null') {
                $p1 = $stream->nextMeaningfulIndex($i);
                if ($p1 !== null && $stream->get($p1)->id === T_IS_NOT_IDENTICAL) {
                    $p2 = $stream->nextMeaningfulIndex($p1);
                    if ($p2 !== null && $stream->get($p2)->id === T_VARIABLE) {
                        $varName = $stream->get($p2)->text;
                        $p3 = $stream->nextMeaningfulIndex($p2);
                        if ($p3 !== null && $stream->get($p3)->text === '?') {
                            $res = $this->tryTransformTernary($stream, $i, $varName, $p3);
                            if ($res !== null) {
                                $changes++;
                                $i = $res;
                                continue;
                            }
                        }
                    }
                }
            }

            $i++;
        }

        return $changes;
    }

    protected function tryTransformTernary(TokenStream $stream, int $conditionStartIndex, string $varName, int $questionIndex): ?int
    {
        // After '?' find the truthy expression
        $exprStart = $stream->nextMeaningfulIndex($questionIndex);
        if ($exprStart === null) {
            return null;
        }

        // Truthy expression should start with $var->
        $tokVar = $stream->get($exprStart);
        if ($tokVar === null || $tokVar->text !== $varName) {
            return null;
        }
        $tokArrow = $stream->nextMeaningfulIndex($exprStart);
        if ($tokArrow === null || $stream->get($tokArrow)->id !== T_OBJECT_OPERATOR) {
            return null;
        }

        // Find the colon ':' for the ternary, tracking parenthesis/bracket depth
        $colonIndex = null;
        $depth = 0;
        for ($j = $exprStart; $j < $stream->count(); $j++) {
            $t = $stream->get($j)->text;
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $depth--;
            } elseif ($t === ':' && $depth === 0) {
                $colonIndex = $j;
                break;
            }
        }

        if ($colonIndex === null) {
            return null;
        }

        // Check if the falsy expression is 'null'
        $falsyStart = $stream->nextMeaningfulIndex($colonIndex);
        if ($falsyStart === null || strtolower($stream->get($falsyStart)->text) !== 'null') {
            return null;
        }

        // Falsy end is right after 'null'
        $endIndex = $falsyStart;

        // Capture expression from exprStart to colonIndex - 1 (trimmed)
        // In the truthy expression, replace the first T_OBJECT_OPERATOR (->) with ?->
        $truthyTokens = [];
        $replacedArrow = false;
        for ($k = $exprStart; $k < $colonIndex; $k++) {
            $t = $stream->get($k);
            if (!$replacedArrow && $t->id === T_OBJECT_OPERATOR) {
                // In PHP 8.0+, T_NULLSAFE_OBJECT_OPERATOR is '?->'
                $truthyTokens[] = new \EidCloud\CodeMigration\Ast\Token(defined('T_NULLSAFE_OBJECT_OPERATOR') ? T_NULLSAFE_OBJECT_OPERATOR : 500, '?->', $t->line, $t->pos);
                $replacedArrow = true;
            } else {
                $truthyTokens[] = $t;
            }
        }

        $stream->replaceRange($conditionStartIndex, $endIndex, $truthyTokens);
        return $conditionStartIndex + count($truthyTokens);
    }
}
