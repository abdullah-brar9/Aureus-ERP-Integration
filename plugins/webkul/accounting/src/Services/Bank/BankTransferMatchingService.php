<?php

namespace Webkul\Accounting\Services\Bank;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Models\BankTransferMatch;
use Webkul\Security\Models\User;

class BankTransferMatchingService
{
    /**
     * @return array<int, BankTransferMatch>
     */
    public function detect(int $companyId, int $maximumDateDistance = 3): array
    {
        $debits = BankStatementLine::query()
            ->with(['statement', 'mapping'])
            ->where('company_id', $companyId)
            ->where('debit', '>', 0)
            ->whereDoesntHave('mapping', fn ($query) => $query->whereNotNull('transfer_match_id'))
            ->orderBy('transaction_date')
            ->get();

        $credits = BankStatementLine::query()
            ->with(['statement', 'mapping'])
            ->where('company_id', $companyId)
            ->where('credit', '>', 0)
            ->whereDoesntHave('mapping', fn ($query) => $query->whereNotNull('transfer_match_id'))
            ->orderBy('transaction_date')
            ->get();

        $matches = [];
        $usedCredits = [];

        foreach ($debits as $debit) {
            $candidate = $credits->first(function (BankStatementLine $credit) use ($debit, $maximumDateDistance, $usedCredits): bool {
                if (isset($usedCredits[$credit->id])) {
                    return false;
                }

                return $credit->statement_id !== $debit->statement_id
                    && $credit->statement?->bank_account_number !== $debit->statement?->bank_account_number
                    && abs((float) $credit->credit - (float) $debit->debit) <= 0.01
                    && Carbon::parse($credit->transaction_date)->diffInDays(Carbon::parse($debit->transaction_date)) <= $maximumDateDistance
                    && $this->looksLikeTransfer((string) $debit->description, (string) $credit->description);
            });

            if (! $candidate) {
                continue;
            }

            $matches[] = DB::transaction(function () use ($companyId, $debit, $candidate): BankTransferMatch {
                $reference = 'TRF-'.Carbon::parse($debit->transaction_date)->format('md');
                if (BankTransferMatch::query()->where('match_reference', $reference)->exists()) {
                    $reference .= '-'.$debit->id;
                }

                $match = BankTransferMatch::query()->create([
                    'company_id'                  => $companyId,
                    'outgoing_statement_line_id'  => $debit->id,
                    'incoming_statement_line_id'  => $candidate->id,
                    'match_reference'             => $reference,
                    'amount'                      => $debit->debit,
                    'confidence'                  => 1,
                    'status'                      => 'suggested',
                ]);

                $debit->mapping?->update([
                    'offset_account_id'  => $candidate->mapping?->bank_gl_account_id,
                    'transfer_match_id'  => $match->id,
                    'transaction_type'   => 'Internal transfer',
                    'review_status'      => BankReviewStatus::MatchedTransfer,
                    'posting_status'     => BankPostingStatus::NotPosted,
                    'cash_flow_category' => CashFlowCategory::Transfer->value,
                    'confidence'         => 1,
                ]);

                $candidate->mapping?->update([
                    'offset_account_id'  => $debit->mapping?->bank_gl_account_id,
                    'transfer_match_id'  => $match->id,
                    'transaction_type'   => 'Internal transfer',
                    'review_status'      => BankReviewStatus::MatchedTransfer,
                    'posting_status'     => BankPostingStatus::MatchedDoNotPost,
                    'cash_flow_category' => CashFlowCategory::Transfer->value,
                    'confidence'         => 1,
                ]);

                return $match;
            });

            $usedCredits[$candidate->id] = true;
        }

        return $matches;
    }

    public function approve(BankTransferMatch $match, User $reviewer): BankTransferMatch
    {
        $match->update([
            'status'      => 'approved',
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $match->fresh();
    }

    protected function looksLikeTransfer(string $outgoing, string $incoming): bool
    {
        $combined = mb_strtolower($outgoing.' '.$incoming);
        if (str_contains($combined, 'transfer') || str_contains($combined, 'incoming ibft')) {
            return true;
        }

        similar_text(mb_strtolower($outgoing), mb_strtolower($incoming), $similarity);

        return $similarity >= 30;
    }
}
