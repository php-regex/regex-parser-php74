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
 * Base exception for all regex-related errors.
 */
class RegexException extends \Exception implements ExceptionInterface
{
    /**
     *
     * @var \PHPRegex\Parser\ErrorCode
     */
    public $errorCode;

    /**
     *
     * @var int|null
     */
    public $position;

    /**
     *
     * @var string|null
     */
    public $snippet;

    /**
     * @internal the library throws its exceptions; catch them, read them
     */
    public function __construct(
        string $message,
        $errorCode,
        ?int $position = null,
        ?string $snippet = null,
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->position = $position;
        $this->snippet = $snippet;
        parent::__construct($message, 0, $previous);
    }
}
