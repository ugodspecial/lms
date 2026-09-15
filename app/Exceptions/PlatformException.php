<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Base class for every *expected* business-rule failure (§90).
 *
 * The distinction matters: a domain rule refusing an action is not a bug. It
 * carries a stable machine error code, a message that is safe to show a human,
 * an HTTP status, and optional field errors — all without leaking internals.
 *
 * Anything that is NOT a PlatformException is treated as an unhandled error:
 * logged with a stack trace, and rendered as a generic message (§64). A student
 * must never see "SQLSTATE[23000]: Integrity constraint violation".
 *
 * It implements HttpExceptionInterface, and that is not decorative. Laravel's
 * handler renders an exception it does not recognise as a 500 whatever status the
 * exception carries, so a refused download — a 403 that is the correct, expected,
 * everyday answer — would arrive as an Internal Server Error, be reported to the
 * error log as one, and show a person who simply lacked permission a page telling
 * them the platform had broken. The interface is what makes `getStatusCode()` the
 * status of the response, and `resources/views/errors/{status}.blade.php` the page
 * that is rendered for it (§76).
 *
 * `getHeaders()` exists for the same reason: a 429 that does not say when to come
 * back is a rate limit a client cannot respect, so DownloadAuthorizer sends
 * Retry-After with it.
 *
 * Usage:
 *   throw new PlatformException(
 *       message: 'A parent or guardian must complete this purchase.',
 *       errorCode: 'purchase.minor_requires_guardian',
 *       statusCode: 403,
 *       errors: ['student' => ['This student is under 18.']],
 *   );
 */
class PlatformException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages, for form repopulation
     * @param  array<string, mixed>  $context  logged server-side, never shown to the user
     * @param  array<string, string>  $headers  sent with the response, e.g. Retry-After on a 429
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'platform.error',
        protected int $statusCode = 422,
        private readonly array $errors = [],
        private readonly array $context = [],
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** A message that is safe and useful to show the person who hit the rule. */
    public function getUserMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * A stable, dot-namespaced code clients can branch on.
     *
     * Codes are part of the API contract: once shipped they are never renamed,
     * only deprecated (§42, §64).
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, list<string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Headers to send with the rendered response.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Set a custom HTTP status and return $this, for fluent throwing. */
    public function withStatus(int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * Shape the JSON body used by API and webhook clients (§42).
     *
     * @return array{error: array{code: string, message: string, fields: array<string, list<string>>}}
     */
    public function toApiResponse(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getUserMessage(),
                'fields' => $this->errors ?: new \stdClass,
            ],
        ];
    }

    /** Whether this exception should be written to the log. */
    public function shouldReport(): bool
    {
        // Expected business refusals are noise in an error log; server errors
        // (5xx) are not expected and must always be recorded.
        return $this->statusCode >= 500;
    }
}
