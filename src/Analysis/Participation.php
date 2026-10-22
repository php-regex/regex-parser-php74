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
 * Cached-instance twin of the Participation enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class Participation
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

    public static function Always(): self
    {
        if (!isset(self::$instances['Always'])) {
            self::$instances['Always'] = new self('Always', 'always');
        }

        return self::$instances['Always'];
    }

    public static function MayBeUnset(): self
    {
        if (!isset(self::$instances['MayBeUnset'])) {
            self::$instances['MayBeUnset'] = new self('MayBeUnset', 'may_be_unset');
        }

        return self::$instances['MayBeUnset'];
    }

    public static function Never(): self
    {
        if (!isset(self::$instances['Never'])) {
            self::$instances['Never'] = new self('Never', 'never');
        }

        return self::$instances['Never'];
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Always(),
            self::MayBeUnset(),
            self::Never(),
        ];
    }

    public static function from(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for Participation.', $value));
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
