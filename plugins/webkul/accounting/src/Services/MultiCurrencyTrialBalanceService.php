<?php

namespace Webkul\Accounting\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Enums\ReportCurrencyMode;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class MultiCurrencyTrialBalanceService
{
    private const VALUE_KEYS = [
        'opening_debit', 'opening_credit', 'movement_debit', 'movement_credit',
        'adjustment_debit', 'adjustment_credit', 'closing_debit', 'closing_credit',
    ];

    public function __construct(protected ExchangeRateService $exchangeRates) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function compute(int $companyId, string $fromDate, string $toDate, array $filters): array
    {
        $mode = ReportCurrencyMode::from($filters['currency_mode']);

        return $mode === ReportCurrencyMode::Original
            ? $this->original($companyId, $fromDate, $toDate, $filters)
            : $this->reporting($companyId, $fromDate, $toDate, $filters);
    }

    private function original(int $companyId, string $fromDate, string $toDate, array $filters): array
    {
        $opening = $this->originalQuery($companyId, $filters)
            ->where('moves.date', '<', $fromDate)
            ->selectRaw('lines.account_id, COALESCE(lines.original_currency_id, lines.currency_id) currency_id, SUM(COALESCE(lines.original_debit, lines.debit) - COALESCE(lines.original_credit, lines.credit)) opening_net')
            ->groupBy(['lines.account_id', DB::raw('COALESCE(lines.original_currency_id, lines.currency_id)')])
            ->get();
        $range = $this->originalQuery($companyId, $filters)
            ->whereBetween('moves.date', [$fromDate, $toDate])
            ->selectRaw("lines.account_id, COALESCE(lines.original_currency_id, lines.currency_id) currency_id,
                SUM(CASE WHEN (moves.coa_migration_kind IN ('adjustment', 'opening') OR moves.accounting_source_type = 'manual_adjustment') THEN 0 ELSE COALESCE(lines.original_debit, lines.debit) END) movement_debit,
                SUM(CASE WHEN (moves.coa_migration_kind IN ('adjustment', 'opening') OR moves.accounting_source_type = 'manual_adjustment') THEN 0 ELSE COALESCE(lines.original_credit, lines.credit) END) movement_credit,
                SUM(CASE WHEN (moves.coa_migration_kind = 'adjustment' OR moves.accounting_source_type = 'manual_adjustment') THEN COALESCE(lines.original_debit, lines.debit) ELSE 0 END) adjustment_debit,
                SUM(CASE WHEN (moves.coa_migration_kind = 'adjustment' OR moves.accounting_source_type = 'manual_adjustment') THEN COALESCE(lines.original_credit, lines.credit) ELSE 0 END) adjustment_credit")
            ->groupBy(['lines.account_id', DB::raw('COALESCE(lines.original_currency_id, lines.currency_id)')])
            ->get();

        $keys = $opening->map(fn ($row): string => "{$row->account_id}:{$row->currency_id}")
            ->merge($range->map(fn ($row): string => "{$row->account_id}:{$row->currency_id}"))->unique();
        $accounts = Account::query()->whereIn('id', $keys->map(fn (string $key): int => (int) str($key)->before(':')->toString()))
            ->get(['id', 'code', 'name'])->keyBy('id');
        $currencies = Currency::query()->whereIn('id', $keys->map(fn (string $key): int => (int) str($key)->after(':')->toString()))
            ->get()->keyBy('id');
        $openingByKey = $opening->keyBy(fn ($row): string => "{$row->account_id}:{$row->currency_id}");
        $rangeByKey = $range->keyBy(fn ($row): string => "{$row->account_id}:{$row->currency_id}");
        $rows = [];
        $currencyTotals = [];

        foreach ($keys as $key) {
            [$accountId, $currencyId] = array_map('intval', explode(':', $key));
            $account = $accounts->get($accountId);
            if (! $account) {
                continue;
            }
            $currencyCode = $currencies->get($currencyId)?->code ?: $currencies->get($currencyId)?->name ?: 'UNKNOWN';
            $row = $this->row(
                $account,
                $currencyCode,
                (string) ($openingByKey->get($key)?->opening_net ?? '0'),
                (string) ($rangeByKey->get($key)?->movement_debit ?? '0'),
                (string) ($rangeByKey->get($key)?->movement_credit ?? '0'),
                (string) ($rangeByKey->get($key)?->adjustment_debit ?? '0'),
                (string) ($rangeByKey->get($key)?->adjustment_credit ?? '0'),
            );
            if (! ($filters['include_zero'] ?? false) && ! $this->hasValue($row)) {
                continue;
            }
            $rows[] = $row;
            $currencyTotals[$currencyCode] = $this->addTotals($currencyTotals[$currencyCode] ?? $this->emptyTotals(), $row);
        }

        ksort($currencyTotals);

        return [
            'rows'              => $rows,
            'totals'            => count($currencyTotals) === 1 ? array_values($currencyTotals)[0] : [],
            'currency_totals'   => $currencyTotals,
            'currency_mode'     => ReportCurrencyMode::Original->value,
            'rate_basis'        => 'Stored original amounts grouped by currency; no cross-currency summation.',
            'conversion_status' => str_contains(implode(',', array_keys($currencyTotals)), 'UNKNOWN') ? 'review_required' : 'complete',
            'warnings'          => str_contains(implode(',', array_keys($currencyTotals)), 'UNKNOWN') ? ['Some historical rows have no evidenced original currency.'] : [],
        ];
    }

    private function reporting(int $companyId, string $fromDate, string $toDate, array $filters): array
    {
        $company = Company::query()->with('currency')->findOrFail($companyId);
        $target = Currency::query()->findOrFail((int) ($filters['reporting_currency_id'] ?? 0));
        if (! $company->enabledCurrencies()->where('currencies.id', $target->id)->wherePivot('reporting_enabled', true)->exists()) {
            throw new RuntimeException('The selected reporting currency is not enabled for this company.');
        }

        $daily = DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->when($filters['posted_only'] ?? true, fn ($query) => $query->where('moves.state', MoveState::POSTED->value))
            ->when(($filters['journal_ids'] ?? []) !== [], fn ($query) => $query->whereIn('moves.journal_id', $filters['journal_ids']))
            ->when(($filters['account_ids'] ?? []) !== [], fn ($query) => $query->whereIn('lines.account_id', $filters['account_ids']))
            ->whereDate('moves.date', '<=', $toDate)
            ->selectRaw("lines.account_id, moves.date,
                CASE WHEN (moves.coa_migration_kind = 'adjustment' OR moves.accounting_source_type = 'manual_adjustment') THEN 1 ELSE 0 END is_adjustment,
                CASE WHEN moves.coa_migration_kind = 'opening' THEN 1 ELSE 0 END is_opening,
                SUM(lines.debit) debit, SUM(lines.credit) credit")
            ->groupBy([
                'lines.account_id', 'moves.date',
                DB::raw("CASE WHEN (moves.coa_migration_kind = 'adjustment' OR moves.accounting_source_type = 'manual_adjustment') THEN 1 ELSE 0 END"),
                DB::raw("CASE WHEN moves.coa_migration_kind = 'opening' THEN 1 ELSE 0 END"),
            ])
            ->orderBy('moves.date')
            ->get();
        $accounts = Account::query()->whereIn('id', $daily->pluck('account_id')->unique())->get(['id', 'code', 'name'])->keyBy('id');
        $buckets = [];
        $warnings = [];

        foreach ($daily as $item) {
            if ((int) $item->is_opening === 1 && substr((string) $item->date, 0, 10) >= $fromDate) {
                $warnings['An opening-classified journal falls inside the report period and is excluded from movement.'] = true;

                continue;
            }
            try {
                $rate = $this->exchangeRates->resolve(
                    $company,
                    $company->currency,
                    $target,
                    substr((string) $item->date, 0, 10),
                    [ExchangeRateType::Transaction, ExchangeRateType::Daily],
                );
            } catch (MissingExchangeRateException $exception) {
                $warnings[$exception->getMessage()] = true;

                continue;
            }
            $debit = BigDecimal::of($this->exchangeRates->convert((string) $item->debit, $rate));
            $credit = BigDecimal::of($this->exchangeRates->convert((string) $item->credit, $rate));
            $bucket = &$buckets[(int) $item->account_id];
            $bucket ??= ['opening_net' => BigDecimal::zero(), 'movement_debit' => BigDecimal::zero(), 'movement_credit' => BigDecimal::zero(), 'adjustment_debit' => BigDecimal::zero(), 'adjustment_credit' => BigDecimal::zero()];

            if (substr((string) $item->date, 0, 10) < $fromDate) {
                $bucket['opening_net'] = $bucket['opening_net']->plus($debit)->minus($credit);
            } elseif ((int) $item->is_adjustment === 1) {
                $bucket['adjustment_debit'] = $bucket['adjustment_debit']->plus($debit);
                $bucket['adjustment_credit'] = $bucket['adjustment_credit']->plus($credit);
            } else {
                $bucket['movement_debit'] = $bucket['movement_debit']->plus($debit);
                $bucket['movement_credit'] = $bucket['movement_credit']->plus($credit);
            }
            unset($bucket);
        }

        $rows = [];
        $totals = $this->emptyTotals();
        foreach ($buckets as $accountId => $bucket) {
            if (! $accounts->has($accountId)) {
                continue;
            }
            $row = $this->row(
                $accounts[$accountId],
                (string) ($target->code ?: $target->name),
                $bucket['opening_net']->__toString(),
                $bucket['movement_debit']->__toString(),
                $bucket['movement_credit']->__toString(),
                $bucket['adjustment_debit']->__toString(),
                $bucket['adjustment_credit']->__toString(),
            );
            if (! ($filters['include_zero'] ?? false) && ! $this->hasValue($row)) {
                continue;
            }
            $rows[] = $row;
            $totals = $this->addTotals($totals, $row);
        }

        return [
            'rows'              => $rows,
            'totals'            => $totals,
            'currency_totals'   => [(string) ($target->code ?: $target->name) => $totals],
            'currency_mode'     => ReportCurrencyMode::Reporting->value,
            'rate_basis'        => 'Approved transaction-date/daily rates; each balanced journal date is translated consistently.',
            'conversion_status' => $warnings === [] ? 'complete' : 'incomplete',
            'warnings'          => array_keys($warnings),
        ];
    }

    private function originalQuery(int $companyId, array $filters)
    {
        return DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->when($filters['posted_only'] ?? true, fn ($query) => $query->where('moves.state', MoveState::POSTED->value))
            ->when(($filters['journal_ids'] ?? []) !== [], fn ($query) => $query->whereIn('moves.journal_id', $filters['journal_ids']))
            ->when(($filters['account_ids'] ?? []) !== [], fn ($query) => $query->whereIn('lines.account_id', $filters['account_ids']));
    }

    private function row($account, string $currency, string $openingNet, string $movementDebit, string $movementCredit, string $adjustmentDebit, string $adjustmentCredit): array
    {
        $opening = BigDecimal::of($openingNet);
        $movementDr = BigDecimal::of($movementDebit);
        $movementCr = BigDecimal::of($movementCredit);
        $adjustmentDr = BigDecimal::of($adjustmentDebit);
        $adjustmentCr = BigDecimal::of($adjustmentCredit);
        $closing = $opening->plus($movementDr)->minus($movementCr)->plus($adjustmentDr)->minus($adjustmentCr);

        return [
            'account_id'       => $account->id, 'code' => $account->code, 'name' => $account->name,
            'currency'         => $currency, 'is_group' => false,
            'opening_debit'    => $this->positive($opening), 'opening_credit' => $this->negative($opening),
            'movement_debit'   => $this->scaled($movementDr), 'movement_credit' => $this->scaled($movementCr),
            'adjustment_debit' => $this->scaled($adjustmentDr), 'adjustment_credit' => $this->scaled($adjustmentCr),
            'closing_debit'    => $this->positive($closing), 'closing_credit' => $this->negative($closing),
        ];
    }

    private function addTotals(array $totals, array $row): array
    {
        foreach (self::VALUE_KEYS as $key) {
            $totals[$key] = BigDecimal::of((string) $totals[$key])->plus((string) $row[$key])->toScale(2, RoundingMode::HalfUp)->__toString();
        }
        $totals['difference'] = BigDecimal::of($totals['closing_debit'])->minus($totals['closing_credit'])->toScale(2, RoundingMode::HalfUp)->__toString();

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [...array_fill_keys(self::VALUE_KEYS, '0.00'), 'difference' => '0.00'];
    }

    private function positive(BigDecimal $value): string
    {
        return $this->scaled($value->isPositive() ? $value : BigDecimal::zero());
    }

    private function negative(BigDecimal $value): string
    {
        return $this->scaled($value->isNegative() ? $value->abs() : BigDecimal::zero());
    }

    private function scaled(BigDecimal $value): string
    {
        return $value->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    private function hasValue(array $row): bool
    {
        return collect(self::VALUE_KEYS)->contains(fn (string $key): bool => BigDecimal::of((string) $row[$key])->abs()->isGreaterThan('0.005'));
    }
}
