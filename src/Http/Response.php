<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Response
{
    public function __construct(public int $status, public array $body)
    {
    }

    public static function error(ApiError $e): self
    {
        return new self($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
    }

    public function json(): string
    {
        return json_encode($this->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        echo $this->json(), "\n";
    }
}
