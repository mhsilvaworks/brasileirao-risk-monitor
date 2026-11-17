<?php

declare(strict_types=1);

namespace App\Infrastructure\FootballData;

use RuntimeException;

final class ApiRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $requestCounterReset = null
    ) {
        parent::__construct($message, $httpStatus ?? 0);
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Valor bruto do cabeçalho X-RequestCounter-Reset, quando fornecido.
     */
    public function getRequestCounterReset(): ?string
    {
        return $this->requestCounterReset;
    }
}
