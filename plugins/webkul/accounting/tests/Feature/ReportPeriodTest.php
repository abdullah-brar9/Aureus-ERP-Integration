<?php

use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;

it('builds twelve months for a year in order', function () {
    $months = ReportPeriod::monthsOfYear(2025);

    expect($months)->toHaveCount(12)
        ->and($months[0]->label)->toBe('Jan')
        ->and($months[0]->startDate->toDateString())->toBe('2025-01-01')
        ->and($months[0]->endDate->toDateString())->toBe('2025-01-31')
        ->and($months[11]->label)->toBe('Dec')
        ->and($months[11]->startDate->toDateString())->toBe('2025-12-01')
        ->and($months[11]->endDate->toDateString())->toBe('2025-12-31');
});

it('handles february in a leap year', function () {
    $months = ReportPeriod::monthsOfYear(2024);

    expect($months[1]->endDate->toDateString())->toBe('2024-02-29');
});

it('builds a full-year period', function () {
    $year = ReportPeriod::fullYear(2025);

    expect($year->startDate->toDateString())->toBe('2025-01-01')
        ->and($year->endDate->toDateString())->toBe('2025-12-31')
        ->and($year->key)->toBe('2025');
});

it('normalises company scope in the context', function () {
    $context = ReportContext::forCompanies([2, 2, 3, '3', 4]);

    expect($context->companyIds)->toBe([2, 3, 4])
        ->and($context->hasCompanyScope())->toBeTrue()
        ->and($context->postedOnly)->toBeTrue();
});

it('supports an empty company scope', function () {
    $context = ReportContext::forCompanies([]);

    expect($context->hasCompanyScope())->toBeFalse();
});
