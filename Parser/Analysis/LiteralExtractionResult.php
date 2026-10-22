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
 * Structured literal extraction output for fast prefilters.
 */
final class LiteralExtractionResult
{
    /**
     * @var array<string>
     *
     */
    public $literals;

    /**
     * @var array<string>
     *
     */
    public $patterns;

    /**
     *
     * @var \PHPRegex\Parser\Analysis\LiteralConfidence
     */
    public $confidence;

    /**
     *
     * @var \PHPRegex\Parser\Analysis\LiteralSet
     */
    public $literalSet;

    /**
     * @internal built by Regex::literals()
     *
     * @param array<string> $literals
     * @param array<string> $patterns
     */
    public function __construct(array $literals, array $patterns, $confidence, LiteralSet $literalSet)
    {
        $this->literals = $literals;
        $this->patterns = $patterns;
        $this->confidence = $confidence;
        $this->literalSet = $literalSet;
    }
}
