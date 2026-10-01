<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Ast;

/**
 * Token node representing a lexical token or AST node with metadata.
 */
class Token
{
    public function __construct(
        public int|string $id,
        public string $text,
        public int $line = 1,
        public int $pos = 0
    ) {
    }

    public function is(int|string|array $type): bool
    {
        if (is_array($type)) {
            return in_array($this->id, $type, true);
        }
        return $this->id === $type;
    }

    public function getName(): string
    {
        return is_int($this->id) ? token_name($this->id) : (string)$this->id;
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
