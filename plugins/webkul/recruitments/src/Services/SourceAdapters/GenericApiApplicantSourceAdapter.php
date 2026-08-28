<?php

namespace Webkul\Recruitment\Services\SourceAdapters;

use Webkul\Recruitment\Contracts\ApplicantSourceAdapter;

class GenericApiApplicantSourceAdapter implements ApplicantSourceAdapter
{
    public function key(): string
    {
        return 'api';
    }

    public function normalize(array $payload): array
    {
        return [
            ...$payload,
            'external_application_id' => $payload['external_application_id']
                ?? $payload['application_id']
                ?? $payload['id']
                ?? null,
            'candidate_name'  => $payload['candidate_name'] ?? $payload['name'] ?? null,
            'candidate_email' => $payload['candidate_email'] ?? $payload['email'] ?? null,
            'candidate_phone' => $payload['candidate_phone'] ?? $payload['phone'] ?? null,
            'source_details'  => $payload['source_details'] ?? $payload['source'] ?? 'Authenticated API import',
        ];
    }
}
