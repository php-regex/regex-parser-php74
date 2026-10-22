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

namespace PHPRegex\Parser\Exception;

/**
 * Represents a semantic validation error in a regex pattern.
 */
final class SemanticErrorException extends RegexException implements ExceptionInterface, VisualContextInterface
{
    use VisualContextTrait;

    /**
     *
     * @var string|null
     */
    public $hint;

    /**
     * @internal the library throws its exceptions; catch them, read them
     */
    public function __construct(
        string $message,
        $errorCode,
        ?int $position = null,
        ?string $pattern = null,
        ?\Throwable $previous = null,
        ?string $hint = null
    ) {
        $this->hint = $hint;
        $snippet = $this->initializeContext($position, $pattern);

        parent::__construct($message, $errorCode, $position, $snippet, $previous);
    }
}
