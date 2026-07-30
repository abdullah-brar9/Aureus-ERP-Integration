<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Payment;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;

final class BankMatchingPriorityService
{
    public function __construct(
        private readonly BankTransferMatchingService $transfers,
        private readonly BankMappingService $rules,
    ) {}

    /** @return array{obligations: int, payments: int, transfers: int, rules: int} */
    public function run(int $companyId): array
    {
        $obligations = 0;
        $payments = 0;
        $mappings = BankTransactionMapping::query()
            ->with('statementLine')
            ->where('company_id', $companyId)
            ->whereNull('move_id')
            ->whereNull('transfer_match_id')
            ->whereNull('matched_reference')
            ->whereIn('review_status', [BankReviewStatus::Unmapped, BankReviewStatus::Suggested, BankReviewStatus::NeedsReview])
            ->get();

        foreach ($mappings as $mapping) {
            $reference = trim((string) ($mapping->statementLine?->reference ?: $mapping->statementLine?->payment_reference));
            if ($reference === '') {
                continue;
            }

            $move = Move::query()
                ->where('company_id', $companyId)
                ->where('state', 'posted')
                ->where('amount_residual', '>', 0)
                ->where(fn ($query) => $query->where('reference', $reference)->orWhere('payment_reference', $reference)->orWhere('name', $reference))
                ->with(['lines.account'])
                ->first();
            if ($move && $this->amountMatches($mapping, (string) $move->amount_residual)) {
                $accountId = $move->lines->first(fn ($line) => in_array($line->account?->account_type?->value, ['asset_receivable', 'liability_payable'], true))?->account_id;
                $mapping->update([
                    'offset_account_id' => $accountId,
                    'match_type'        => 'obligation', 'matched_reference' => $reference,
                    'transaction_type'  => 'Open document settlement', 'review_status' => BankReviewStatus::Suggested,
                    'confidence'        => 1, 'suggestion_explanation' => "Exact open-document reference {$reference} and amount matched.",
                ]);
                $obligations++;

                continue;
            }

            $payment = Payment::query()
                ->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('payment_reference', $reference)->orWhere('name', $reference)->orWhere('memo', $reference))
                ->first();
            if ($payment && $this->amountMatches($mapping, (string) $payment->amount)) {
                $mapping->update([
                    'offset_account_id' => $payment->destination_account_id ?: $payment->outstanding_account_id,
                    'match_type'        => 'payment', 'matched_reference' => $reference,
                    'transaction_type'  => 'Registered payment', 'review_status' => BankReviewStatus::Suggested,
                    'confidence'        => 1, 'suggestion_explanation' => "Exact payment reference {$reference} and amount matched.",
                ]);
                $payments++;
            }
        }

        $transferCount = count($this->transfers->detect($companyId));
        $ruleCount = BankStatement::query()->where('company_id', $companyId)->get()
            ->sum(fn (BankStatement $statement): int => $this->rules->suggestForStatement($statement));

        return ['obligations' => $obligations, 'payments' => $payments, 'transfers' => $transferCount, 'rules' => $ruleCount];
    }

    private function amountMatches(BankTransactionMapping $mapping, string $expected): bool
    {
        $actual = BigDecimal::max(
            (string) ($mapping->statementLine?->original_debit ?? '0'),
            (string) ($mapping->statementLine?->original_credit ?? '0'),
        );

        return $actual->minus($expected)->abs()->isLessThanOrEqualTo('0.01');
    }
}
