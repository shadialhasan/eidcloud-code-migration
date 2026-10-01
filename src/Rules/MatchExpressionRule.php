<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Rules;

use EidCloud\CodeMigration\Ast\AstTransformer;
use EidCloud\CodeMigration\Ast\TokenStream;

/**
 * Migrates simple switch-return or switch-assignment statements to PHP 8 match expressions.
 * E.g.:
 * switch ($status) {
 *     case 'active':
 *         return 1;
 *     case 'pending':
 *         return 2;
 *     default:
 *         return 0;
 * }
 * =>
 * return match ($status) {
 *     'active' => 1,
 *     'pending' => 2,
 *     default => 0,
 * };
 */
class MatchExpressionRule implements RuleInterface
{
    public function getId(): string
    {
        return 'php84.match_expression';
    }

    public function getDescription(): string
    {
        return 'Migrate switch statements with uniform returns/assignments to PHP 8+ match expressions';
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

            if ($tok->id === T_SWITCH) {
                $res = $this->tryTransformSwitch($stream, $i);
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

    protected function tryTransformSwitch(TokenStream $stream, int $switchIndex): ?int
    {
        // switch ( $expr ) { ... }
        $openParen = $stream->nextMeaningfulIndex($switchIndex);
        if ($openParen === null || $stream->get($openParen)->text !== '(') {
            return null;
        }

        $closeParen = $stream->findMatchingPair($openParen, '(', ')');
        if ($closeParen === null) {
            return null;
        }

        $openBrace = $stream->nextMeaningfulIndex($closeParen);
        if ($openBrace === null || $stream->get($openBrace)->text !== '{') {
            return null;
        }

        $closeBrace = $stream->findMatchingPair($openBrace, '{', '}');
        if ($closeBrace === null) {
            return null;
        }

        $condition = trim($stream->sliceText($openParen + 1, $closeParen - 1));

        // Parse cases inside the switch body
        $entries = []; // list of ['is_default' => bool, 'keys' => string[], 'val' => string]
        $patternType = null; // 'return' or assignment var '$x ='
        $curr = $openBrace + 1;

        while ($curr < $closeBrace) {
            $tok = $stream->get($curr);
            if ($tok->id === T_WHITESPACE || $tok->id === T_COMMENT || $tok->id === T_DOC_COMMENT) {
                $curr++;
                continue;
            }

            if ($tok->id === T_CASE || $tok->id === T_DEFAULT) {
                $isDefault = ($tok->id === T_DEFAULT);
                $caseKeys = [];

                if (!$isDefault) {
                    // Collect condition up to ':' or ';'
                    $colonIdx = null;
                    for ($k = $curr + 1; $k < $closeBrace; $k++) {
                        if ($stream->get($k)->text === ':' || $stream->get($k)->text === ';') {
                            $colonIdx = $k;
                            break;
                        }
                    }
                    if ($colonIdx === null) {
                        return null;
                    }
                    $caseKeys[] = trim($stream->sliceText($curr + 1, $colonIdx - 1));
                    $curr = $colonIdx + 1;
                } else {
                    $colonIdx = $stream->nextMeaningfulIndex($curr);
                    if ($colonIdx === null || ($stream->get($colonIdx)->text !== ':' && $stream->get($colonIdx)->text !== ';')) {
                        return null;
                    }
                    $curr = $colonIdx + 1;
                }

                // Look for statement inside this case: either 'return <expr>;' or '$var = <expr>;'
                $stmtTokens = [];
                $breakEncountered = false;
                while ($curr < $closeBrace) {
                    $cTok = $stream->get($curr);
                    if ($cTok->id === T_CASE || $cTok->id === T_DEFAULT) {
                        break;
                    }
                    if ($cTok->id === T_BREAK) {
                        $breakSemi = $stream->nextMeaningfulIndex($curr);
                        if ($breakSemi !== null && $stream->get($breakSemi)->text === ';') {
                            $curr = $breakSemi + 1;
                        } else {
                            $curr++;
                        }
                        $breakEncountered = true;
                        continue;
                    }
                    $stmtTokens[] = $cTok;
                    $curr++;
                }

                $stmtText = trim(implode('', array_map(fn($t) => $t->text, $stmtTokens)));
                if (preg_match('/^return\s+(.+?);$/s', $stmtText, $m)) {
                    if ($patternType === null) {
                        $patternType = 'return';
                    } elseif ($patternType !== 'return') {
                        return null; // Mixed types
                    }
                    $entries[] = [
                        'default' => $isDefault,
                        'keys' => $caseKeys,
                        'val' => trim($m[1])
                    ];
                } elseif (preg_match('/^(\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*=\s*(.+?);$/s', $stmtText, $m)) {
                    $targetVar = $m[1];
                    if ($patternType === null) {
                        $patternType = $targetVar . ' =';
                    } elseif ($patternType !== $targetVar . ' =') {
                        return null; // Different target variables
                    }
                    $entries[] = [
                        'default' => $isDefault,
                        'keys' => $caseKeys,
                        'val' => trim($m[2])
                    ];
                } else {
                    // Complex body or fallthrough without simple return/assign
                    return null;
                }
            } else {
                $curr++;
            }
        }

        if (empty($entries) || $patternType === null) {
            return null;
        }

        // Build match expression
        $matchCode = ($patternType === 'return' ? 'return ' : $patternType . ' ') . "match ({$condition}) {\n";
        foreach ($entries as $entry) {
            if ($entry['default']) {
                $matchCode .= "    default => " . $entry['val'] . ",\n";
            } else {
                $keyStr = implode(', ', $entry['keys']);
                $matchCode .= "    {$keyStr} => " . $entry['val'] . ",\n";
            }
        }
        $matchCode .= "};";

        $stream->replaceRange($switchIndex, $closeBrace, $matchCode);
        return $switchIndex + 1;
    }
}
