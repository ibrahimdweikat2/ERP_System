<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Context;

/**
 * The company whose data the current request, job or command may touch.
 *
 * Modes: "company" (one company's rows), "platform" (superadmin: only
 * platform-owned users/audit rows, company tables refuse) and "bypass"
 * (unfiltered, for migrations and audited platform maintenance only).
 * With no mode set, company tables refuse every query.
 */
class CompanyContext
{
    public const COMPANY = 'company';

    public const PLATFORM = 'platform';

    public const BYPASS = 'bypass';

    /** @var array{mode: string, id: ?int}|null */
    private ?array $base = null;

    /** @var list<array{mode: string, id: ?int}> */
    private array $frames = [];

    public function setCompany(int $companyId): void
    {
        $this->base = ['mode' => self::COMPANY, 'id' => $this->validId($companyId)];
        $this->sync();
    }

    public function setPlatform(): void
    {
        $this->base = ['mode' => self::PLATFORM, 'id' => null];
        $this->sync();
    }

    public function clear(): void
    {
        $this->base = null;
        $this->frames = [];
        $this->sync();
    }

    /** Runs the callback as the given company, then restores the previous context. */
    public function run(int $companyId, callable $callback): mixed
    {
        return $this->within(['mode' => self::COMPANY, 'id' => $this->validId($companyId)], $callback);
    }

    public function platform(callable $callback): mixed
    {
        return $this->within(['mode' => self::PLATFORM, 'id' => null], $callback);
    }

    /** Unfiltered access. Allowed only in the files listed in the tenancy architecture test. */
    public function bypass(callable $callback): mixed
    {
        return $this->within(['mode' => self::BYPASS, 'id' => null], $callback);
    }

    /** Used by migration events, which have separate start and end hooks. */
    public function enterBypass(): void
    {
        $this->frames[] = ['mode' => self::BYPASS, 'id' => null];
        $this->sync();
    }

    public function leaveBypass(): void
    {
        if (($this->current()['mode'] ?? null) === self::BYPASS) {
            array_pop($this->frames);
            $this->sync();
        }
    }

    public function mode(): ?string
    {
        return $this->current()['mode'] ?? null;
    }

    public function companyId(): ?int
    {
        $frame = $this->current();

        return ($frame['mode'] ?? null) === self::COMPANY ? $frame['id'] : null;
    }

    public function requireCompanyId(): int
    {
        return $this->companyId() ?? throw new MissingCompanyContext('No company is selected for this operation.');
    }

    /** The context a queued job dispatched now should run in (never bypass). */
    public function propagated(): ?array
    {
        foreach (array_reverse([$this->base, ...$this->frames]) as $frame) {
            if ($frame && $frame['mode'] !== self::BYPASS) {
                return $frame;
            }
        }

        return null;
    }

    /** Restores a context captured by propagated(), e.g. when a queued job starts. */
    public function restore(?array $frame): void
    {
        $this->frames = [];
        match ($frame['mode'] ?? null) {
            self::COMPANY => $this->setCompany((int) $frame['id']),
            self::PLATFORM => $this->setPlatform(),
            default => $this->clear(),
        };
    }

    /** The exact context, for code that must put it back afterwards (see RequireActiveUser). */
    public function snapshot(): array
    {
        return [$this->base, $this->frames];
    }

    public function reinstate(array $snapshot): void
    {
        [$this->base, $this->frames] = $snapshot;
        $this->sync();
    }

    private function within(array $frame, callable $callback): mixed
    {
        $this->frames[] = $frame;
        $this->sync();
        try {
            return $callback();
        } finally {
            array_pop($this->frames);
            $this->sync();
        }
    }

    private function current(): ?array
    {
        return $this->frames === [] ? $this->base : $this->frames[array_key_last($this->frames)];
    }

    private function validId(int $id): int
    {
        if ($id < 1) {
            throw new MissingCompanyContext('Invalid company id.');
        }

        return $id;
    }

    // Queued jobs carry the dispatcher's company through Laravel's Context payload.
    private function sync(): void
    {
        Context::addHidden('tenancy', $this->propagated());
    }
}
