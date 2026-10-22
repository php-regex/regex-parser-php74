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

namespace PHPRegex\Parser\Hir;

/**
 * Cached-instance twin of the Greed enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class Greed
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

    public static function Greedy(): self
    {
        if (!isset(self::$instances['Greedy'])) {
            self::$instances['Greedy'] = new self('Greedy', 'Greedy');
        }

        return self::$instances['Greedy'];
    }

    public static function Lazy(): self
    {
        if (!isset(self::$instances['Lazy'])) {
            self::$instances['Lazy'] = new self('Lazy', 'Lazy');
        }

        return self::$instances['Lazy'];
    }

    public static function Possessive(): self
    {
        if (!isset(self::$instances['Possessive'])) {
            self::$instances['Possessive'] = new self('Possessive', 'Possessive');
        }

        return self::$instances['Possessive'];
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Greedy(),
            self::Lazy(),
            self::Possessive(),
        ];
    }
}
