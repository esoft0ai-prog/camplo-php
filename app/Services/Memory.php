<?php

namespace App\Services;

/** Campaign memory. Stage 4 connects Hindsight / the relational memory bank; for now events are not retained. */
final class Memory
{
    public static function retain(string $tenantId, array $ev): void {}
}
