<?php

namespace Webkul\Recruitment\Services\SourceAdapters;

use Webkul\Recruitment\Contracts\ApplicantSourceAdapter;

class ManualApplicantSourceAdapter implements ApplicantSourceAdapter
{
    public function key(): string
    {
        return 'manual';
    }

    public function normalize(array $payload): array
    {
        $payload['source_details'] ??= 'Manual import';

        return $payload;
    }
}
