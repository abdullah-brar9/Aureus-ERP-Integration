<?php

namespace Webkul\Accounting\Services\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Models\FxRevaluation;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class FxRevaluationService
{
    public function __construct(protected ExchangeRateService $exchangeRates) {}

    public function createDraft(
        Company $company,
        Currency $currency,
        string $periodEnd,
        Journal $journal,
        ?string $reversalDate = null,
    ): FxRevaluation {
        if ((int) $currency->id === (int) $company->currency_id) {
            throw new RuntimeException('The company base currency does not require FX revaluation.');
        }
        if ((int) $journal->company_id !== (int) $company->id || $journal->type !== JournalType::GENERAL) {
            throw new RuntimeException('FX revaluation requires a general journal owned by the company.');
        }
        $this->assertFxAccounts($company);
        $rate = $this->exchangeRates->resolve(
            $company,
            $currency,
            $company->currency,
            $periodEnd,
            [ExchangeRateType::PeriodClosing],
            false,
        );
        if ($rate->recordId === null) {
            throw new RuntimeException('FX revaluation requires a persisted approved closing-rate record.');
        }

        return DB::transaction(function () use ($company, $currency, $periodEnd, $journal, $reversalDate, $rate): FxRevaluation {
            $existing = FxRevaluation::query()
                ->where('company_id', $company->id)
                ->where('currency_id', $currency->id)
                ->whereDate('period_end', $periodEnd)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing->load(['move.lines', 'reversalMove.lines']);
            }

            $balances = $this->monetaryBalances($company, $currency, $periodEnd);
            $totalOriginal = BigDecimal::zero();
            $totalBook = BigDecimal::zero();
            $totalRevalued = BigDecimal::zero();
            $totalDifference = BigDecimal::zero();
            $adjustments = [];

            foreach ($balances as $balance) {
                $original = BigDecimal::of((string) $balance->original_balance);
                $book = BigDecimal::of((string) $balance->book_balance);
                $revalued = $original->multipliedBy($rate->rate)->toScale(4, RoundingMode::HalfUp);
                $difference = $revalued->minus($book)->toScale(4, RoundingMode::HalfUp);
                $totalOriginal = $totalOriginal->plus($original);
                $totalBook = $totalBook->plus($book);
                $totalRevalued = $totalRevalued->plus($revalued);
                $totalDifference = $totalDifference->plus($difference);

                if (! $difference->isZero()) {
                    $adjustments[] = ['account_id' => (int) $balance->account_id, 'difference' => $difference];
                }
            }

            $revaluation = FxRevaluation::query()->create([
                'company_id'              => $company->id,
                'currency_id'             => $currency->id,
                'period_end'              => $periodEnd,
                'exchange_rate_id'        => $rate->recordId,
                'created_by'              => Auth::id(),
                'status'                  => $adjustments === [] ? 'no_adjustment' : 'draft',
                'original_balance'        => $totalOriginal->toScale(4, RoundingMode::HalfUp)->__toString(),
                'book_company_balance'    => $totalBook->toScale(4, RoundingMode::HalfUp)->__toString(),
                'revalued_company_balance'=> $totalRevalued->toScale(4, RoundingMode::HalfUp)->__toString(),
                'difference'              => $totalDifference->toScale(4, RoundingMode::HalfUp)->__toString(),
                'reversal_date'           => $reversalDate,
            ]);

            if ($adjustments === []) {
                return $revaluation->fresh();
            }

            $moveId = $this->createMove($revaluation, $journal, $company, $currency, $periodEnd, $rate, false);
            $this->insertAdjustmentLines($moveId, $revaluation, $journal, $company, $currency, $periodEnd, $rate, $adjustments);
            $revaluation->update(['move_id' => $moveId]);

            if ($reversalDate) {
                $reversalMoveId = $this->createMove($revaluation, $journal, $company, $currency, $reversalDate, $rate, true);
                $this->insertReversalLines($moveId, $reversalMoveId, $reversalDate);
                $revaluation->update(['reversal_move_id' => $reversalMoveId]);
            }

            return $revaluation->fresh(['move.lines', 'reversalMove.lines', 'exchangeRate']);
        });
    }

    private function monetaryBalances(Company $company, Currency $currency, string $periodEnd)
    {
        return DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->join('accounts_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('lines.company_id', $company->id)
            ->where('moves.company_id', $company->id)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereDate('lines.date', '<=', $periodEnd)
            ->where('lines.original_currency_id', $currency->id)
            ->whereIn('accounts.account_type', [
                AccountType::ASSET_CASH->value,
                AccountType::ASSET_RECEIVABLE->value,
                AccountType::LIABILITY_PAYABLE->value,
            ])
            ->groupBy('lines.account_id')
            ->selectRaw('lines.account_id, SUM(COALESCE(lines.original_signed_amount, lines.amount_currency, 0)) original_balance, SUM(COALESCE(lines.company_signed_amount, lines.debit - lines.credit, 0)) book_balance')
            ->havingRaw('ABS(SUM(COALESCE(lines.original_signed_amount, lines.amount_currency, 0))) > 0.0001')
            ->get();
    }

    private function createMove($revaluation, Journal $journal, Company $company, Currency $currency, string $date, $rate, bool $reversal): int
    {
        $now = now();

        return DB::table('accounts_account_moves')->insertGetId([
            'journal_id'             => $journal->id,
            'company_id'             => $company->id,
            'currency_id'            => $currency->id,
            'original_currency_id'   => $currency->id,
            'company_currency_id'    => $company->currency_id,
            'date'                   => $date,
            'name'                   => ($reversal ? 'FX Revaluation Reversal ' : 'FX Revaluation ').$currency->code.' '.$revaluation->period_end->toDateString(),
            'reference'              => 'FXR-'.$revaluation->id,
            'move_type'              => MoveType::ENTRY->value,
            'state'                  => MoveState::DRAFT->value,
            'accounting_source_type' => $reversal ? 'fx_revaluation_reversal' : 'fx_revaluation',
            'accounting_source_id'   => $revaluation->id,
            'review_status'          => 'draft',
            'exchange_rate_id'       => $rate->recordId,
            'exchange_rate'          => $rate->rate,
            'rate_date'              => $rate->effectiveDate,
            'rate_source'            => $rate->source,
            'rate_type'              => $rate->type,
            'conversion_status'      => ConversionStatus::Complete->value,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
    }

    private function insertAdjustmentLines(int $moveId, $revaluation, Journal $journal, Company $company, Currency $currency, string $date, $rate, array $adjustments): void
    {
        $rows = [];
        $sort = 0;

        foreach ($adjustments as $adjustment) {
            $difference = $adjustment['difference'];
            $amount = $difference->abs()->__toString();
            if ($difference->isPositive()) {
                $rows[] = $this->line($moveId, $journal, $company, $currency, $date, $adjustment['account_id'], $amount, '0', '0', $rate, $sort++);
                $rows[] = $this->line($moveId, $journal, $company, $company->currency, $date, $company->fx_gain_account_id, '0', $amount, $amount, $rate, $sort++);
            } else {
                $rows[] = $this->line($moveId, $journal, $company, $company->currency, $date, $company->fx_loss_account_id, $amount, '0', $amount, $rate, $sort++);
                $rows[] = $this->line($moveId, $journal, $company, $currency, $date, $adjustment['account_id'], '0', $amount, '0', $rate, $sort++);
            }
        }

        DB::table('accounts_account_move_lines')->insert($rows);
    }

    private function line(int $moveId, Journal $journal, Company $company, Currency $currency, string $date, int $accountId, string $debit, string $credit, string $originalAmount, $rate, int $sort): array
    {
        $signed = BigDecimal::of($debit)->minus($credit)->__toString();
        $originalSigned = BigDecimal::of($originalAmount)->isZero() ? '0' : $signed;

        return [
            'move_id'                => $moveId, 'journal_id' => $journal->id, 'company_id' => $company->id,
            'company_currency_id'    => $company->currency_id, 'currency_id' => $currency->id,
            'original_currency_id'   => $currency->id, 'account_id' => $accountId, 'date' => $date,
            'debit'                  => $debit, 'credit' => $credit, 'balance' => $signed,
            'original_debit'         => BigDecimal::of($originalSigned)->isPositive() ? $originalSigned : '0',
            'original_credit'        => BigDecimal::of($originalSigned)->isNegative() ? BigDecimal::of($originalSigned)->abs()->__toString() : '0',
            'original_signed_amount' => $originalSigned, 'company_debit' => $debit,
            'company_credit'         => $credit, 'company_signed_amount' => $signed,
            'amount_currency'        => $originalSigned, 'exchange_rate_id' => $rate->recordId,
            'exchange_rate'          => $rate->rate, 'rate_date' => $rate->effectiveDate,
            'rate_source'            => $rate->source, 'rate_type' => $rate->type,
            'conversion_status'      => ConversionStatus::Complete->value,
            'parent_state'           => MoveState::DRAFT->value, 'name' => 'FX revaluation adjustment',
            'reference'              => 'FXR-'.$moveId, 'sort' => $sort, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function insertReversalLines(int $sourceMoveId, int $reversalMoveId, string $date): void
    {
        $rows = DB::table('accounts_account_move_lines')->where('move_id', $sourceMoveId)->orderBy('sort')->get();
        $now = now();

        DB::table('accounts_account_move_lines')->insert($rows->map(fn ($line): array => [
            ...collect((array) $line)->except(['id', 'created_at', 'updated_at'])->all(),
            'move_id'                => $reversalMoveId,
            'date'                   => $date,
            'debit'                  => $line->credit,
            'credit'                 => $line->debit,
            'balance'                => BigDecimal::of((string) $line->balance)->negated()->__toString(),
            'original_debit'         => $line->original_credit,
            'original_credit'        => $line->original_debit,
            'original_signed_amount' => BigDecimal::of((string) $line->original_signed_amount)->negated()->__toString(),
            'company_debit'          => $line->company_credit,
            'company_credit'         => $line->company_debit,
            'company_signed_amount'  => BigDecimal::of((string) $line->company_signed_amount)->negated()->__toString(),
            'amount_currency'        => BigDecimal::of((string) $line->amount_currency)->negated()->__toString(),
            'name'                   => 'FX revaluation reversal',
            'created_at'             => $now,
            'updated_at'             => $now,
        ])->all());
    }

    private function assertFxAccounts(Company $company): void
    {
        foreach (['fx_gain_account_id', 'fx_loss_account_id'] as $field) {
            $accountId = $company->{$field};
            if (! $accountId || ! Account::query()->postable()->whereKey($accountId)->where('deprecated', false)
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))->exists()) {
                throw new RuntimeException('Configure active, company-owned FX Gain and FX Loss accounts before revaluation.');
            }
        }
    }
}
