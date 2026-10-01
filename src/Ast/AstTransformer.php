<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Ast;

/**
 * Universal AST/CST Transformer for PHP.
 * Retains exact formatting, whitespace, comments, and structure.
 */
class AstTransformer
{
    protected TokenStream $stream;

    public function __construct(string $source)
    {
        $this->stream = TokenStream::fromSource($source);
    }

    public function getStream(): TokenStream
    {
        return $this->stream;
    }

    public function getSource(): string
    {
        return $this->stream->render();
    }

    /**
     * Apply a callback to find and mutate tokens.
     * The callback signature: function(TokenStream $stream, int $index): ?int
     * Returning a new index allows skipping transformed sections.
     *
     * @param callable(TokenStream, int): (?int) $visitor
     */
    public function walk(callable $visitor): void
    {
        $i = 0;
        while ($i < $this->stream->count()) {
            $next = $visitor($this->stream, $i);
            if ($next !== null) {
                $i = $next;
            } else {
                $i++;
            }
        }
    }
}
