<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankStatementPreviewService
{
    public function __construct(
        protected BankStatementParserRegistry $parsers,
        protected BankStatementValidationService $validator,
        protected ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        string $path,
        Company $company,
        Currency $currency,
        ?string $parserKey = null,
        ?string $sheetName = null,
        int $rowLimit = 1000,
    ): array {
        $statement = $this->parsers->resolve($path, $parserKey, $sheetName)->parse($path, $sheetName);
        $rows = [];
        $missing = [];

        foreach (array_slice($statement->transactions, 0, $rowLimit) as $transaction) {
            $date = $transaction->transactionDate ?: ($transaction->valueDate ?: $statement->statementEndDate);
            $originalSigned = BigDecimal::of($transaction->credit)->minus($transaction->debit)->__toString();

            try {
                $rate = $this->exchangeRates->resolveForBankTransaction($company, $currency, $company->currency, $date);
                $companySigned = $this->exchangeRates->convert($originalSigned, $rate);
                $rateValue = $rate->rate;
                $status = 'complete';
            } catch (MissingExchangeRateException $exception) {
                $companySigned = null;
                $rateValue = null;
                $status = 'missing_rate';
                $missing[$exception->getMessage()] = true;
            }

            $rows[] = [
                'date'              => $date,
                'description'       => $transaction->description,
                'original_amount'   => $originalSigned,
                'original_currency' => $currency->code ?: $currency->name,
                'exchange_rate'     => $rateValue,
                'company_amount'    => $companySigned,
                'company_currency'  => $company->currency?->code ?: $company->currency?->name,
                'status'            => $status,
            ];
        }

        return [
            'bank'                => $statement->bank,
            'bank_account_number' => $statement->bankAccountNumber,
            'detected_currency'   => $statement->currency,
            'selected_currency'   => $currency->code ?: $currency->name,
            'period'              => "{$statement->statementStartDate} to {$statement->statementEndDate}",
            'source_totals'       => [
                'opening' => $statement->openingBalance,
                'debit'   => $statement->totalDebits,
                'credit'  => $statement->totalCredits,
                'closing' => $statement->closingBalance,
            ],
            'validation_errors' => $this->validator->validate($statement),
            'missing_rates'     => array_keys($missing),
            'rows'              => $rows,
            'row_count'         => count($statement->transactions),
            'truncated'         => count($statement->transactions) > $rowLimit,
        ];
    }
}
