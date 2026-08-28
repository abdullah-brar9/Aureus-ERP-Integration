<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Currency\ExchangeRateService;

class BankStatementConversionService
{
    public function __construct(protected ExchangeRateService $exchangeRates) {}

    public function refreshForCompany(int $companyId): int
    {
        $updated = 0;

        BankStatementLine::query()
            ->where('company_id', $companyId)
            ->whereIn('conversion_status', [ConversionStatus::MissingRate->value, ConversionStatus::Pending->value])
            ->with(['company.currency', 'originalCurrency', 'statement'])
            ->orderBy('id')
            ->chunkById(200, function ($lines) use (&$updated): void {
                foreach ($lines as $line) {
                    if ($this->refreshLine($line)) {
                        $updated++;
                    }
                }
            });

        BankStatement::query()
            ->where('company_id', $companyId)
            ->whereIn('conversion_status', [ConversionStatus::MissingRate->value, ConversionStatus::Pending->value])
            ->with(['company.currency', 'currency'])
            ->each(fn (BankStatement $statement) => $this->refreshStatement($statement));

        return $updated;
    }

    public function refreshLine(BankStatementLine $line): bool
    {
        $line->loadMissing(['company.currency', 'originalCurrency']);
        $date = $line->transaction_date?->toDateString() ?: $line->value_date?->toDateString();
        if (! $line->company || ! $line->originalCurrency || ! $line->company->currency || ! $date) {
            return false;
        }

        try {
            $rate = $this->exchangeRates->resolveForBankTransaction(
                $line->company,
                $line->originalCurrency,
                $line->company->currency,
                $date,
            );
        } catch (MissingExchangeRateException) {
            return false;
        }

        $companyDebit = $this->exchangeRates->convert((string) $line->original_debit, $rate);
        $companyCredit = $this->exchangeRates->convert((string) $line->original_credit, $rate);
        $companySigned = BigDecimal::of($companyCredit)->minus($companyDebit)->__toString();
        $snapshot = [
            'company_debit'         => $companyDebit,
            'company_credit'        => $companyCredit,
            'company_signed_amount' => $companySigned,
            'amount'                => $companySigned,
            'amount_residual'       => $companySigned,
            'exchange_rate_id'      => $rate->recordId,
            'exchange_rate'         => $rate->rate,
            'rate_date'             => $rate->effectiveDate,
            'rate_source'           => $rate->source,
            'rate_type'             => $rate->type,
            'conversion_status'     => ConversionStatus::Complete->value,
        ];

        DB::transaction(function () use ($line, $snapshot): void {
            $line->update($snapshot);
            BankTransactionMapping::query()->where('statement_line_id', $line->id)->update([
                'exchange_rate_id'  => $snapshot['exchange_rate_id'],
                'exchange_rate'     => $snapshot['exchange_rate'],
                'rate_date'         => $snapshot['rate_date'],
                'rate_source'       => $snapshot['rate_source'],
                'rate_type'         => $snapshot['rate_type'],
                'conversion_status' => $snapshot['conversion_status'],
            ]);
        });

        return true;
    }

    public function refreshStatement(BankStatement $statement): void
    {
        $statement->loadMissing(['company.currency', 'currency']);
        if ($statement->lines()->where('conversion_status', '!=', ConversionStatus::Complete->value)->exists()) {
            return;
        }

        try {
            $openingRate = $this->exchangeRates->resolveForBankTransaction(
                $statement->company,
                $statement->currency,
                $statement->company->currency,
                $statement->statement_start_date->toDateString(),
            );
            $closingRate = $this->exchangeRates->resolveForBankTransaction(
                $statement->company,
                $statement->currency,
                $statement->company->currency,
                $statement->statement_end_date->toDateString(),
            );
        } catch (MissingExchangeRateException) {
            return;
        }

        $totals = $statement->lines()->selectRaw(
            'SUM(company_debit) company_debits, SUM(company_credit) company_credits',
        )->first();

        $statement->update([
            'company_opening_balance' => $this->exchangeRates->convert((string) $statement->opening_balance, $openingRate),
            'company_total_debits'    => (string) ($totals->company_debits ?? '0'),
            'company_total_credits'   => (string) ($totals->company_credits ?? '0'),
            'company_closing_balance' => $this->exchangeRates->convert((string) $statement->closing_balance, $closingRate),
            'conversion_status'       => ConversionStatus::Complete->value,
        ]);
    }
}
