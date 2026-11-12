<?php

namespace App\Support;

use RuntimeException;

/**
 * An expected API error. Rendered in the same JSON shape the Node (oRPC) API used, so the web client keeps
 * working unchanged: { defined, code, status, message, data, reason?, required_plan? }.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        $body = ['defined' => false, 'code' => $this->errorCode, 'status' => $this->status, 'message' => $this->getMessage()];
        if ($this->data) {
            $body['data'] = $this->data;
        }
        if (! empty($this->data['reason'])) {
            $body['reason'] = $this->data['reason'];
        }
        if (! empty($this->data['required_plan'])) {
            $body['required_plan'] = $this->data['required_plan'];
        }

        return $body;
    }
}
