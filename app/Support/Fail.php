<?php

namespace App\Support;

/** The same error helpers the Node API used (`fail.*`). */
final class Fail
{
    public static function unauthorized(string $message = 'Session expired. Please log in again.'): ApiException
    {
        return new ApiException(401, 'UNAUTHORIZED', $message);
    }

    public static function forbidden(string $message = "You don't have access to this.", ?string $reason = null, array $extra = []): ApiException
    {
        return new ApiException(403, 'FORBIDDEN', $message, array_filter(['reason' => $reason, ...$extra], fn ($v) => $v !== null));
    }

    /** A feature or limit above the workspace's plan → 403 { reason: 'plan_limit', required_plan }. */
    public static function planLimit(string $message, string $requiredPlan): ApiException
    {
        return new ApiException(403, 'FORBIDDEN', $message, ['reason' => 'plan_limit', 'required_plan' => $requiredPlan]);
    }

    public static function notFound(string $message = 'Not found.'): ApiException
    {
        return new ApiException(404, 'NOT_FOUND', $message);
    }

    public static function conflict(string $message, ?string $reason = null): ApiException
    {
        return new ApiException(409, 'CONFLICT', $message, array_filter(['reason' => $reason]));
    }

    public static function bad(string $message): ApiException
    {
        return new ApiException(400, 'BAD_REQUEST', $message);
    }

    public static function unprocessable(string $message): ApiException
    {
        return new ApiException(422, 'UNPROCESSABLE_CONTENT', $message);
    }

    public static function tooLarge(string $message = 'Storage quota exceeded. Delete unused deployments to free space.'): ApiException
    {
        return new ApiException(413, 'PAYLOAD_TOO_LARGE', $message);
    }

    public static function rateLimited(string $message): ApiException
    {
        return new ApiException(429, 'TOO_MANY_REQUESTS', $message);
    }
}
