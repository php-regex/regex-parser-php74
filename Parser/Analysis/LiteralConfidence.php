<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Parser\Analysis;

/**
 * Cached-instance twin of the LiteralConfidence enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class LiteralConfidence
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var string
     */
    public $value;

    /**
     * @var array<string, self>
     */
    private static $instances = [];

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function Low(): self
    {
        if (!isset(self::$instances['Low'])) {
            self::$instances['Low'] = new self('Low', 'low');
        }

        return self::$instances['Low'];
    }

    public static function Medium(): self
    {
        if (!isset(self::$instances['Medium'])) {
            self::$instances['Medium'] = new self('Medium', 'medium');
        }

        return self::$instances['Medium'];
    }

    public static function High(): self
    {
        if (!isset(self::$instances['High'])) {
            self::$instances['High'] = new self('High', 'high');
        }

        return self::$instances['High'];
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Low(),
            self::Medium(),
            self::High(),
        ];
    }

    public static function from(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for LiteralConfidence.', $value));
    }

    public static function tryFrom(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }
}
