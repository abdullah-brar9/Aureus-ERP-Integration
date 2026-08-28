<?php

namespace Webkul\Recruitment\Contracts;

interface ApplicantSourceAdapter
{
    public function key(): string;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function normalize(array $payload): array;
}
