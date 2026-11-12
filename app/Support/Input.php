<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Request validation that answers 400 BAD_REQUEST in the API's error shape (the Node app used Zod). */
final class Input
{
    /** Validated input from the JSON body / query (only the keys named in $rules). */
    public static function get(Request $r, array $rules, ?array $source = null): array
    {
        $data = $source ?? [...$r->query(), ...$r->json()->all(), ...$r->post()];
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            throw new ApiException(400, 'BAD_REQUEST', $v->errors()->first() ?: 'Input validation failed', ['issues' => $v->errors()->toArray()]);
        }
        $out = [];
        foreach (array_keys($rules) as $k) {
            if (! str_contains($k, '.') && array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }

        return $out;
    }
}
