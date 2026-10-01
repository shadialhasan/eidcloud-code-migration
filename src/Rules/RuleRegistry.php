<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Rules;

/**
 * Registry of migration rules and migration packs.
 */
class RuleRegistry
{
    /** @var array<string, RuleInterface> */
    protected array $rules = [];

    public function __construct()
    {
        $this->registerBuiltinRules();
    }

    public static function createDefault(): self
    {
        return new self();
    }

    protected function registerBuiltinRules(): void
    {
        $this->register(new NullsafeOperatorRule());
        $this->register(new TypedPropertiesRule());
        $this->register(new MatchExpressionRule());
        $this->register(new MysqliToPdoRule());
        $this->register(new ArrowFunctionsRule());
    }

    public function register(RuleInterface $rule): self
    {
        $this->rules[$rule->getId()] = $rule;
        return $this;
    }

    public function get(string $id): ?RuleInterface
    {
        return $this->rules[$id] ?? null;
    }

    /**
     * @return array<string, RuleInterface>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * Resolves rules by rule ID, pack name, or comma-separated list of packs/IDs.
     * E.g. "php84", "mysqli", "all", "php84,callbacks"
     *
     * @return array<RuleInterface>
     */
    public function resolve(string $selector): array
    {
        $selector = strtolower(trim($selector));
        if ($selector === 'all' || $selector === '*') {
            return array_values($this->rules);
        }

        $parts = array_map('trim', explode(',', $selector));
        $resolved = [];

        foreach ($parts as $part) {
            // Check direct ID match
            if (isset($this->rules[$part])) {
                $resolved[$this->rules[$part]->getId()] = $this->rules[$part];
                continue;
            }

            // Check pack match
            $matchedPack = false;
            foreach ($this->rules as $rule) {
                if (strtolower($rule->getPack()) === $part) {
                    $resolved[$rule->getId()] = $rule;
                    $matchedPack = true;
                }
            }

            if (!$matchedPack) {
                // Partial prefix match
                foreach ($this->rules as $id => $rule) {
                    if (str_starts_with(strtolower($id), $part . '.')) {
                        $resolved[$rule->getId()] = $rule;
                    }
                }
            }
        }

        return array_values($resolved);
    }

    /**
     * Returns list of available pack names.
     *
     * @return array<string>
     */
    public function getAvailablePacks(): array
    {
        $packs = [];
        foreach ($this->rules as $rule) {
            $packs[$rule->getPack()] = true;
        }
        return array_keys($packs);
    }
}
