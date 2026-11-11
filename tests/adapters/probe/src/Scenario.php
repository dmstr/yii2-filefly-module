<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

declare(strict_types=1);

namespace hrzg\filefly\adapterprobe;

use Closure;

/**
 * One matrix row. The closure records observations as `label => text` and
 * asserts nothing.
 */
final class Scenario
{
    /**
     * @param Closure(Ctx): array<string, string> $run
     * @param list<string>|null $kinds backend kinds the row applies to, null = all
     */
    public function __construct(
        public readonly string $id,
        public readonly string $group,
        public readonly string $title,
        public readonly Closure $run,
        public readonly ?array $kinds = null,
    ) {
    }

    public function appliesTo(Backend $backend): bool
    {
        return $this->kinds === null || in_array($backend->kind, $this->kinds, true);
    }
}
