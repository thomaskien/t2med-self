<?php
declare(strict_types=1);
namespace Checkin;

final class AppError extends \RuntimeException
{
    public function __construct(public readonly string $tag, string $message, public readonly int $http = 422,
        public readonly array $diagnostic = [])
    {
        parent::__construct($message);
    }
}
