<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Ast;

/**
 * TokenStream provides navigation, lookahead/lookbehind, matching,
 * insertion, deletion, and replacement operations over a stream of tokens.
 */
class TokenStream
{
    /** @var array<int, Token> */
    protected array $tokens = [];

    /**
     * @param array<int, Token> $tokens
     */
    public function __construct(array $tokens = [])
    {
        $this->tokens = array_values($tokens);
    }

    public static function fromSource(string $source): self
    {
        $rawTokens = token_get_all($source);
        $tokens = [];
        $pos = 0;
        foreach ($rawTokens as $raw) {
            if (is_array($raw)) {
                $tokens[] = new Token($raw[0], $raw[1], $raw[2], $pos);
                $pos += strlen($raw[1]);
            } else {
                $line = !empty($tokens) ? $tokens[count($tokens) - 1]->line : 1;
                $tokens[] = new Token($raw, $raw, $line, $pos);
                $pos += strlen($raw);
            }
        }
        return new self($tokens);
    }

    /**
     * @return array<int, Token>
     */
    public function all(): array
    {
        return $this->tokens;
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    public function get(int $index): ?Token
    {
        return $this->tokens[$index] ?? null;
    }

    public function render(): string
    {
        $out = '';
        foreach ($this->tokens as $tok) {
            $out .= $tok->text;
        }
        return $out;
    }

    public function nextMeaningfulIndex(int $startIndex): ?int
    {
        $count = count($this->tokens);
        for ($i = $startIndex + 1; $i < $count; $i++) {
            $id = $this->tokens[$i]->id;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                return $i;
            }
        }
        return null;
    }

    public function prevMeaningfulIndex(int $startIndex): ?int
    {
        for ($i = $startIndex - 1; $i >= 0; $i--) {
            $id = $this->tokens[$i]->id;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                return $i;
            }
        }
        return null;
    }

    public function findMatchingPair(int $openIndex, string $openChar = '(', string $closeChar = ')'): ?int
    {
        if (!isset($this->tokens[$openIndex]) || $this->tokens[$openIndex]->text !== $openChar) {
            return null;
        }
        $depth = 0;
        $count = count($this->tokens);
        for ($i = $openIndex; $i < $count; $i++) {
            $text = $this->tokens[$i]->text;
            if ($text === $openChar) {
                $depth++;
            } elseif ($text === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    public function slice(int $start, int $length): array
    {
        return array_slice($this->tokens, $start, $length);
    }

    public function sliceText(int $start, int $end): string
    {
        $txt = '';
        for ($i = $start; $i <= $end && $i < count($this->tokens); $i++) {
            $txt .= $this->tokens[$i]->text;
        }
        return $txt;
    }

    public function replaceRange(int $start, int $end, string|Token|array $replacement): void
    {
        $replacementTokens = [];
        if (is_string($replacement)) {
            $sub = self::fromSource('<?php ' . $replacement)->all();
            // remove opening <?php and optional whitespace
            if (isset($sub[0]) && $sub[0]->id === T_OPEN_TAG) {
                array_shift($sub);
            }
            if (isset($sub[0]) && $sub[0]->id === T_WHITESPACE && $sub[0]->text === ' ') {
                array_shift($sub);
            }
            $replacementTokens = $sub;
        } elseif ($replacement instanceof Token) {
            $replacementTokens = [$replacement];
        } elseif (is_array($replacement)) {
            $replacementTokens = $replacement;
        }

        $length = ($end - $start) + 1;
        array_splice($this->tokens, $start, $length, $replacementTokens);
    }

    public function insertAt(int $index, string|Token|array $insertion): void
    {
        $insertTokens = [];
        if (is_string($insertion)) {
            $sub = self::fromSource('<?php ' . $insertion)->all();
            if (isset($sub[0]) && $sub[0]->id === T_OPEN_TAG) {
                array_shift($sub);
            }
            if (isset($sub[0]) && $sub[0]->id === T_WHITESPACE && $sub[0]->text === ' ') {
                array_shift($sub);
            }
            $insertTokens = $sub;
        } elseif ($insertion instanceof Token) {
            $insertTokens = [$insertion];
        } elseif (is_array($insertion)) {
            $insertTokens = $insertion;
        }

        array_splice($this->tokens, $index, 0, $insertTokens);
    }

    public function deleteAt(int $index, int $count = 1): void
    {
        array_splice($this->tokens, $index, $count);
    }
}
