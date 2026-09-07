<?php

namespace Didasto\RestApi\Jobs;

enum JobStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Finished   = 'finished';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
