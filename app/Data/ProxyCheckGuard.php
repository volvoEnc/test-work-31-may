<?php

namespace App\Data;

use App\Enums\ProxyCheckSource;
use App\Models\ProxyServer;
use InvalidArgumentException;

final readonly class ProxyCheckGuard
{
    private const ATTRIBUTE_CHECK_GENERATION = 'check_generation';

    private const ATTRIBUTE_CHECK_SOURCE = 'check_source';

    private const ATTRIBUTE_CHECK_JOB_TOKEN = 'check_job_token';

    /** @param  array<string, mixed>  $expectations */
    private function __construct(
        private array $expectations,
    ) {}

    public static function generation(?string $expectedGeneration): self
    {
        return (new self([]))->withGeneration($expectedGeneration);
    }

    public function withGeneration(?string $expectedGeneration): self
    {
        return $this->withExpectation(self::ATTRIBUTE_CHECK_GENERATION, $expectedGeneration);
    }

    public function withSource(?ProxyCheckSource $expectedSource): self
    {
        return $this->withExpectation(self::ATTRIBUTE_CHECK_SOURCE, $expectedSource);
    }

    public function withJobToken(?string $expectedJobToken): self
    {
        return $this->withExpectation(self::ATTRIBUTE_CHECK_JOB_TOKEN, $expectedJobToken);
    }

    public function allows(ProxyServer $proxy): bool
    {
        foreach ($this->expectations as $attribute => $expectedValue) {
            if ($proxy->{$attribute} !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    private function withExpectation(string $attribute, mixed $expectedValue): self
    {
        if (array_key_exists($attribute, $this->expectations) && $this->expectations[$attribute] !== $expectedValue) {
            throw new InvalidArgumentException("Conflicting proxy check guard expectation for [{$attribute}].");
        }

        return new self([
            ...$this->expectations,
            $attribute => $expectedValue,
        ]);
    }
}
