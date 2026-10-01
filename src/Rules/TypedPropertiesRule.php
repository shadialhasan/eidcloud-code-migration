<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Rules;

use EidCloud\CodeMigration\Ast\AstTransformer;
use EidCloud\CodeMigration\Ast\TokenStream;

/**
 * Migrates PHPDoc var/type annotations on class properties to PHP 7.4+ / PHP 8.x typed properties.
 * E.g.:
 * /** @var string * /
 * private $name;
 * =>
 * private string $name;
 */
class TypedPropertiesRule implements RuleInterface
{
    public function getId(): string
    {
        return 'php84.typed_properties';
    }

    public function getDescription(): string
    {
        return 'Migrate PHPDoc @var annotations to native PHP typed properties';
    }

    public function getPack(): string
    {
        return 'php84';
    }

    public function apply(AstTransformer $transformer): int
    {
        $stream = $transformer->getStream();
        $changes = 0;

        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY];

        for ($i = 0; $i < $stream->count(); $i++) {
            $tok = $stream->get($i);
            if ($tok === null) {
                continue;
            }

            // Look for Doc Comment
            if ($tok->id === T_DOC_COMMENT) {
                $docText = $tok->text;
                $inferredType = $this->extractVarType($docText);
                if ($inferredType === null) {
                    continue;
                }

                // Check next tokens for visibility modifiers followed by T_VARIABLE
                $next = $stream->nextMeaningfulIndex($i);
                if ($next === null) {
                    continue;
                }

                $visibilityIndex = null;
                $varIndex = null;
                $curr = $next;

                while ($curr < $stream->count()) {
                    $cTok = $stream->get($curr);
                    if ($cTok === null) {
                        break;
                    }
                    if (in_array($cTok->id, $modifiers, true)) {
                        $visibilityIndex = $curr;
                        $curr = $stream->nextMeaningfulIndex($curr);
                        continue;
                    }
                    if ($cTok->id === T_VARIABLE) {
                        $varIndex = $curr;
                        break;
                    }
                    // If we encounter function or other constructs, this is not a property
                    break;
                }

                if ($visibilityIndex !== null && $varIndex !== null) {
                    // Check if already typed (i.e. tokens between visibility and variable)
                    $inBetween = false;
                    for ($k = $visibilityIndex + 1; $k < $varIndex; $k++) {
                        $mid = $stream->get($k);
                        if ($mid->id !== T_WHITESPACE && !in_array($mid->id, $modifiers, true)) {
                            $inBetween = true;
                            break;
                        }
                    }

                    if (!$inBetween) {
                        // Insert the type with a space before the variable
                        $stream->insertAt($varIndex, $inferredType . ' ');
                        $changes++;
                        $i = $varIndex + 1;
                    }
                }
            }
        }

        return $changes;
    }

    protected function extractVarType(string $doc): ?string
    {
        // match /** @var string $foo */ or /** @var int */
        if (preg_match('/@var\s+([\w\\|?]+)(?:\s+\$\w+)?/i', $doc, $matches)) {
            $type = trim($matches[1]);
            // Simplify or map standard types
            if ($type === '$this') {
                return null;
            }
            return $type;
        }
        return null;
    }
}
