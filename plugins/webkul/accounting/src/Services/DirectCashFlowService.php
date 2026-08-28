<?php

namespace Webkul\Accounting\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Enums\ReportCurrencyMode;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class DirectCashFlowService
{
    public function __construct(protected ExchangeRateService $exchangeRates) {}

    public function calculateForMode(
        int $companyId,
        string $dateFrom,
        string $dateTo,
        string $currencyMode,
        ?int $reportingCurrencyId = null,
    ): array {
        return match ($currencyMode) {
            ReportCurrencyMode::Original->value  => $this->calculateOriginal($companyId, $dateFrom, $dateTo),
            ReportCurrencyMode::Reporting->value => $this->calculateReporting(
                $companyId,
                $dateFrom,
                $dateTo,
                Currency::query()->findOrFail((int) $reportingCurrencyId),
            ),
            default => $this->calculate($companyId, $dateFrom, $dateTo),
        };
    }

    public function calculate(int $companyId, string $dateFrom, string $dateTo): array
    {
        $categories = collect(CashFlowCategory::cases())->mapWithKeys(fn (CashFlowCategory $category) => [$category->value => 0.0])->all();

        $bankAccountIds = DB::table('accounts_bank_statements')
            ->where('company_id', $companyId)
            ->whereNotNull('bank_gl_account_id')
            ->distinct()
            ->pluck('bank_gl_account_id')
            ->all();

        if ($bankAccountIds !== []) {
            $rows = DB::table('accounts_account_move_lines as lines')
                ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
                ->where('moves.company_id', $companyId)
                ->where('moves.state', MoveState::POSTED->value)
                ->where('moves.accounting_source_type', 'bank_mapping')
                ->whereBetween('moves.date', [$dateFrom, $dateTo])
                ->whereIn('lines.account_id', $bankAccountIds)
                ->whereNotNull('moves.cash_flow_category')
                ->groupBy('moves.cash_flow_category')
                ->selectRaw('moves.cash_flow_category as category, SUM(lines.balance) as amount')
                ->get();

            foreach ($rows as $row) {
                $categories[$row->category] = round((float) $row->amount, 2);
            }
        }

        $statementOpeningCash = (float) DB::table('accounts_bank_statements')
            ->where('company_id', $companyId)
            ->whereDate('statement_start_date', $dateFrom)
            ->selectRaw('SUM(CASE WHEN company_currency_id IS NULL OR currency_id = company_currency_id THEN opening_balance ELSE company_opening_balance END) amount')
            ->value('amount');
        $openingCash = $bankAccountIds === [] ? 0.0 : (float) DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereDate('moves.date', '<', $dateFrom)
            ->whereIn('lines.account_id', $bankAccountIds)
            ->sum('lines.balance');
        $netChange = round(array_sum($categories), 2);
        $endingCash = round($openingCash + $netChange, 2);

        $ledgerCash = $bankAccountIds === [] ? 0.0 : (float) DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereDate('moves.date', '<=', $dateTo)
            ->whereIn('lines.account_id', $bankAccountIds)
            ->sum('lines.balance');

        $company = Company::query()->with('currency')->find($companyId);
        $currencyCode = (string) ($company?->currency?->code ?: $company?->currency?->name ?: 'Company currency');
        $report = [
            'categories'             => $categories,
            'opening_cash'           => round($openingCash, 2),
            'statement_opening_cash' => round($statementOpeningCash, 2),
            'net_change'             => $netChange,
            'ending_cash'            => $endingCash,
            'ledger_cash'            => round($ledgerCash, 2),
            'difference'             => round($endingCash - $ledgerCash, 2),
        ];

        return array_merge($report, [
            'reports'           => [$currencyCode => $report],
            'currency_mode'     => ReportCurrencyMode::Company->value,
            'conversion_status' => 'complete',
            'rate_basis'        => 'Posted company-currency bank ledger values; no translation.',
            'warnings'          => [],
        ]);
    }

    private function calculateOriginal(int $companyId, string $dateFrom, string $dateTo): array
    {
        $company = Company::query()->with('currency')->findOrFail($companyId);
        $bankAccountIds = $this->bankAccountIds($companyId);
        $currencyExpression = 'COALESCE(lines.original_currency_id, lines.currency_id, moves.original_currency_id, moves.currency_id, '.(int) $company->currency_id.')';
        $periodRows = collect();
        $openingRows = collect();
        $ledgerRows = collect();

        if ($bankAccountIds !== []) {
            $baseQuery = fn () => DB::table('accounts_account_move_lines as lines')
                ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
                ->where('moves.company_id', $companyId)
                ->where('moves.state', MoveState::POSTED->value)
                ->whereIn('lines.account_id', $bankAccountIds);

            $periodRows = $baseQuery()
                ->where('moves.accounting_source_type', 'bank_mapping')
                ->whereBetween('moves.date', [$dateFrom, $dateTo])
                ->whereNotNull('moves.cash_flow_category')
                ->selectRaw("{$currencyExpression} currency_id, moves.cash_flow_category category, SUM(COALESCE(lines.original_signed_amount, lines.amount_currency, lines.debit - lines.credit, 0)) amount")
                ->groupByRaw("{$currencyExpression}, moves.cash_flow_category")
                ->get();
            $openingRows = $baseQuery()->whereDate('moves.date', '<', $dateFrom)
                ->selectRaw("{$currencyExpression} currency_id, SUM(COALESCE(lines.original_signed_amount, lines.amount_currency, lines.debit - lines.credit, 0)) amount")
                ->groupByRaw($currencyExpression)->get();
            $ledgerRows = $baseQuery()->whereDate('moves.date', '<=', $dateTo)
                ->selectRaw("{$currencyExpression} currency_id, SUM(COALESCE(lines.original_signed_amount, lines.amount_currency, lines.debit - lines.credit, 0)) amount")
                ->groupByRaw($currencyExpression)->get();
        }

        $currencyIds = $periodRows->pluck('currency_id')->merge($openingRows->pluck('currency_id'))->merge($ledgerRows->pluck('currency_id'))->unique();
        $currencies = Currency::query()->whereKey($currencyIds)->get()->keyBy('id');
        $reports = [];
        foreach ($currencyIds as $currencyId) {
            $categories = collect(CashFlowCategory::cases())->mapWithKeys(fn (CashFlowCategory $category) => [$category->value => 0.0])->all();
            foreach ($periodRows->where('currency_id', $currencyId) as $row) {
                $categories[$row->category] = round((float) $row->amount, 4);
            }
            $opening = (float) ($openingRows->firstWhere('currency_id', $currencyId)?->amount ?? 0);
            $ledger = (float) ($ledgerRows->firstWhere('currency_id', $currencyId)?->amount ?? 0);
            $net = round(array_sum($categories), 4);
            $ending = round($opening + $net, 4);
            $currency = $currencies->get((int) $currencyId);
            $code = (string) ($currency?->code ?: $currency?->name ?: "Currency {$currencyId}");
            $reports[$code] = [
                'categories' => $categories, 'opening_cash' => $opening, 'statement_opening_cash' => 0.0,
                'net_change' => $net, 'ending_cash' => $ending, 'ledger_cash' => $ledger,
                'difference' => round($ending - $ledger, 4),
            ];
        }

        if ($reports === []) {
            $code = (string) ($company->currency?->code ?: $company->currency?->name ?: 'Company currency');
            $categories = collect(CashFlowCategory::cases())->mapWithKeys(fn (CashFlowCategory $category) => [$category->value => 0.0])->all();
            $reports[$code] = [
                'categories' => $categories, 'opening_cash' => 0.0, 'statement_opening_cash' => 0.0,
                'net_change' => 0.0, 'ending_cash' => 0.0, 'ledger_cash' => 0.0, 'difference' => 0.0,
            ];
        }

        return [
            'reports'           => $reports,
            'currency_mode'     => ReportCurrencyMode::Original->value,
            'conversion_status' => 'complete',
            'rate_basis'        => 'Stored original bank-side signed amounts, grouped separately by currency.',
            'warnings'          => [],
        ];
    }

    private function calculateReporting(int $companyId, string $dateFrom, string $dateTo, Currency $target): array
    {
        $company = Company::query()->with('currency')->findOrFail($companyId);
        if (! $company->enabledCurrencies()->where('currencies.id', $target->id)->wherePivot('reporting_enabled', true)->exists()) {
            abort(422, 'The reporting currency is not enabled for this company.');
        }
        $base = $this->calculate($companyId, $dateFrom, $dateTo);
        $categories = collect(CashFlowCategory::cases())->mapWithKeys(fn (CashFlowCategory $category) => [$category->value => BigDecimal::zero()])->all();
        $warnings = [];
        $bankAccountIds = $this->bankAccountIds($companyId);

        if ($bankAccountIds !== []) {
            $rows = DB::table('accounts_account_move_lines as lines')
                ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
                ->where('moves.company_id', $companyId)->where('moves.state', MoveState::POSTED->value)
                ->where('moves.accounting_source_type', 'bank_mapping')->whereBetween('moves.date', [$dateFrom, $dateTo])
                ->whereIn('lines.account_id', $bankAccountIds)->whereNotNull('moves.cash_flow_category')
                ->selectRaw('moves.date, moves.cash_flow_category category, SUM(lines.balance) amount')
                ->groupBy('moves.date', 'moves.cash_flow_category')->get();
            foreach ($rows as $row) {
                $rateDate = $company->pnl_translation_policy === 'monthly_average'
                    ? Carbon::parse($row->date)->endOfMonth()->toDateString()
                    : (string) $row->date;
                $types = $company->pnl_translation_policy === 'monthly_average'
                    ? [ExchangeRateType::MonthlyAverage]
                    : [ExchangeRateType::Transaction, ExchangeRateType::Daily];
                try {
                    $rate = $this->exchangeRates->resolve($company, $company->currency, $target, $rateDate, $types);
                    $categories[$row->category] = $categories[$row->category]->plus(BigDecimal::of((string) $row->amount)->multipliedBy($rate->rate));
                } catch (MissingExchangeRateException $exception) {
                    $warnings[$exception->getMessage()] = $exception->getMessage();
                }
            }
        }

        $opening = $this->convertClosing((string) $base['opening_cash'], Carbon::parse($dateFrom)->subDay()->toDateString(), $company, $target, $warnings);
        $ledger = $this->convertClosing((string) $base['ledger_cash'], $dateTo, $company, $target, $warnings);
        $numericCategories = collect($categories)->map(fn (BigDecimal $amount): float => (float) $amount->toScale(4, RoundingMode::HalfUp)->__toString())->all();
        $net = round(array_sum($numericCategories), 4);
        $ending = round($opening + $net, 4);
        if ($warnings === []) {
            $translationEffect = round($ledger - $ending, 4);
            $numericCategories['fx_translation_effect'] = $translationEffect;
            $net = round($net + $translationEffect, 4);
            $ending = round($opening + $net, 4);
        }
        $code = (string) ($target->code ?: $target->name);
        $report = [
            'categories' => $numericCategories, 'opening_cash' => $opening, 'statement_opening_cash' => 0.0,
            'net_change' => $net, 'ending_cash' => $ending, 'ledger_cash' => $ledger,
            'difference' => round($ending - $ledger, 4),
        ];

        return [
            ...$report,
            'reports'           => [$code => $report],
            'currency_mode'     => ReportCurrencyMode::Reporting->value,
            'conversion_status' => $warnings === [] ? 'complete' : 'incomplete',
            'rate_basis'        => 'Transaction-date/monthly-average flows plus approved opening and closing rates; translation effect reconciles ending cash.',
            'warnings'          => array_values($warnings),
        ];
    }

    private function bankAccountIds(int $companyId): array
    {
        return DB::table('accounts_bank_statements')->where('company_id', $companyId)
            ->whereNotNull('bank_gl_account_id')->distinct()->pluck('bank_gl_account_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function convertClosing(string $amount, string $date, Company $company, Currency $target, array &$warnings): float
    {
        try {
            $rate = $this->exchangeRates->resolve($company, $company->currency, $target, $date, [ExchangeRateType::PeriodClosing], false);

            return (float) BigDecimal::of($amount)->multipliedBy($rate->rate)->toScale(4, RoundingMode::HalfUp)->__toString();
        } catch (MissingExchangeRateException $exception) {
            $warnings[$exception->getMessage()] = $exception->getMessage();

            return 0.0;
        }
    }
}
