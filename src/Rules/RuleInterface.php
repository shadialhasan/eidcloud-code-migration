<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Rules;

use EidCloud\CodeMigration\Ast\AstTransformer;

interface RuleInterface
{
    /**
     * Unique identifier for the rule (e.g. "php84.nullsafe", "mysqli.to_pdo").
     */
    public function getId(): string;

    /**
     * Human-readable description of what the rule migrates.
     */
    public function getDescription(): string;

    /**
     * Migration pack / group name (e.g. "php84", "mysqli", "callbacks", "all").
     */
    public function getPack(): string;

    /**
     * Applies transformation to the AstTransformer.
     * Returns the count of changes/transformations applied.
     */
    public function apply(AstTransformer $transformer): int;
}
