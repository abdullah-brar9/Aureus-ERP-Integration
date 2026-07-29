<?php

namespace Webkul\Accounting\Services\Bank;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Security\Models\User;

class BankJournalService
{
    public function createDraft(BankTransactionMapping $mapping): Move
    {
        return DB::transaction(function () use ($mapping): Move {
            $mapping = BankTransactionMapping::query()
                ->with(['statementLine.statement', 'transferMatch.outgoingLine.mapping', 'transferMatch.incomingLine.mapping'])
                ->lockForUpdate()
                ->findOrFail($mapping->id);

            if ($mapping->move_id) {
                return Move::query()->findOrFail($mapping->move_id);
            }

            $line = $mapping->statementLine;
            $statement = $line->statement;
            if ($statement->import_status !== BankImportStatus::Validated->value) {
                throw new RuntimeException('Only reconciled, validated statements can generate journal entries.');
            }

            $isApprovedTransfer = $mapping->transferMatch
                && $mapping->transferMatch->status === 'approved'
                && $mapping->transferMatch->outgoing_statement_line_id === $line->id;

            if ($mapping->review_status !== BankReviewStatus::Approved && ! $isApprovedTransfer) {
                throw new RuntimeException('The bank mapping must be approved before a draft journal is generated.');
            }

            [$debitAccountId, $creditAccountId, $amount] = $isApprovedTransfer
                ? $this->transferAccounts($mapping)
                : $this->mappedAccounts($mapping);

            if ($amount <= 0 || ! $debitAccountId || ! $creditAccountId || $debitAccountId === $creditAccountId) {
                throw new RuntimeException('The journal mapping is incomplete or would not create a valid balanced entry.');
            }

            $now = now();
            $moveId = DB::table('accounts_account_moves')->insertGetId([
                'journal_id'             => $statement->journal_id,
                'company_id'             => $mapping->company_id,
                'currency_id'            => $statement->currency_id,
                'statement_line_id'      => $line->id,
                'date'                   => $line->transaction_date,
                'name'                   => 'Draft '.$mapping->map_reference,
                'reference'              => $line->reference,
                'move_type'              => MoveType::ENTRY->value,
                'state'                  => MoveState::DRAFT->value,
                'accounting_source_type' => 'bank_mapping',
                'accounting_source_id'   => $mapping->id,
                'bank_statement_id'      => $statement->id,
                'bank_mapping_id'        => $mapping->id,
                'cash_flow_category'     => $mapping->cash_flow_category,
                'tax_treatment'          => $mapping->tax_treatment,
                'review_status'          => $isApprovedTransfer ? 'approved_transfer' : 'approved',
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

            $this->insertLines(
                moveId: $moveId,
                statementId: $statement->id,
                statementLineId: $line->id,
                journalId: $statement->journal_id,
                companyId: $mapping->company_id,
                currencyId: $statement->currency_id,
                date: (string) $line->transaction_date?->toDateString(),
                description: (string) $line->description,
                reference: (string) $line->reference,
                debitAccountId: $debitAccountId,
                creditAccountId: $creditAccountId,
                amount: $amount,
            );

            $mapping->update([
                'move_id'        => $moveId,
                'posting_status' => BankPostingStatus::Draft,
            ]);

            if ($isApprovedTransfer) {
                $mapping->transferMatch->incomingLine->mapping?->update(['move_id' => $moveId]);
            }

            return Move::query()->with('lines')->findOrFail($moveId);
        });
    }

    public function post(BankTransactionMapping $mapping, User $reviewer): Move
    {
        return DB::transaction(function () use ($mapping, $reviewer): Move {
            $mapping = BankTransactionMapping::query()->with(['statementLine.statement', 'transferMatch.incomingLine.mapping'])->lockForUpdate()->findOrFail($mapping->id);
            $move = $mapping->move_id ? Move::query()->lockForUpdate()->find($mapping->move_id) : null;
            $move ??= $this->createDraft($mapping);

            if ($move->state === MoveState::POSTED) {
                return $move;
            }

            $totals = DB::table('accounts_account_move_lines')
                ->where('move_id', $move->id)
                ->selectRaw('ROUND(SUM(debit), 2) debit, ROUND(SUM(credit), 2) credit')
                ->first();

            if (! $totals || abs((float) $totals->debit - (float) $totals->credit) > 0.005 || (float) $totals->debit <= 0) {
                throw new RuntimeException('Draft journal is not balanced and cannot be posted.');
            }

            DB::table('accounts_account_moves')->where('id', $move->id)->update([
                'state'         => MoveState::POSTED->value,
                'review_status' => 'posted',
                'updated_at'    => now(),
            ]);
            DB::table('accounts_account_move_lines')->where('move_id', $move->id)->update([
                'parent_state' => MoveState::POSTED->value,
                'updated_at'   => now(),
            ]);

            $mapping->update([
                'review_status'  => BankReviewStatus::Posted,
                'posting_status' => BankPostingStatus::Posted,
                'reviewer_id'    => $reviewer->id,
                'reviewed_at'    => $mapping->reviewed_at ?? now(),
                'posted_at'      => now(),
            ]);

            if ($mapping->transferMatch) {
                $mapping->transferMatch->incomingLine->mapping?->update([
                    'move_id'        => $move->id,
                    'posting_status' => BankPostingStatus::MatchedDoNotPost,
                ]);
            }

            $this->refreshStatementCompletion($mapping->statementLine->statement);

            return $move->fresh('lines');
        });
    }

    /**
     * @return array{0: int, 1: int, 2: float}
     */
    protected function mappedAccounts(BankTransactionMapping $mapping): array
    {
        $line = $mapping->statementLine;
        $amount = max((float) $line->debit, (float) $line->credit);

        return (float) $line->credit > 0
            ? [$mapping->bank_gl_account_id, $mapping->offset_account_id, $amount]
            : [$mapping->offset_account_id, $mapping->bank_gl_account_id, $amount];
    }

    /**
     * @return array{0: int, 1: int, 2: float}
     */
    protected function transferAccounts(BankTransactionMapping $mapping): array
    {
        $match = $mapping->transferMatch;
        $receivingBankId = $match->incomingLine->mapping?->bank_gl_account_id;
        $sendingBankId = $match->outgoingLine->mapping?->bank_gl_account_id;

        return [$receivingBankId, $sendingBankId, (float) $match->amount];
    }

    protected function insertLines(
        int $moveId,
        int $statementId,
        int $statementLineId,
        int $journalId,
        int $companyId,
        int $currencyId,
        string $date,
        string $description,
        string $reference,
        int $debitAccountId,
        int $creditAccountId,
        float $amount,
    ): void {
        $now = now();
        DB::table('accounts_account_move_lines')->insert([
            [
                'move_id'             => $moveId, 'statement_id' => $statementId, 'statement_line_id' => $statementLineId,
                'journal_id'          => $journalId, 'account_id' => $debitAccountId, 'company_id' => $companyId,
                'company_currency_id' => $currencyId, 'currency_id' => $currencyId, 'date' => $date,
                'debit'               => $amount, 'credit' => 0, 'balance' => $amount, 'parent_state' => MoveState::DRAFT->value,
                'name'                => $description, 'reference' => $reference, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'move_id'             => $moveId, 'statement_id' => $statementId, 'statement_line_id' => $statementLineId,
                'journal_id'          => $journalId, 'account_id' => $creditAccountId, 'company_id' => $companyId,
                'company_currency_id' => $currencyId, 'currency_id' => $currencyId, 'date' => $date,
                'debit'               => 0, 'credit' => $amount, 'balance' => -$amount, 'parent_state' => MoveState::DRAFT->value,
                'name'                => $description, 'reference' => $reference, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    protected function refreshStatementCompletion($statement): void
    {
        $incomplete = BankTransactionMapping::query()
            ->whereHas('statementLine', fn ($query) => $query->where('statement_id', $statement->id))
            ->whereNotIn('posting_status', [
                BankPostingStatus::Posted->value,
                BankPostingStatus::MatchedDoNotPost->value,
                BankPostingStatus::DoNotPost->value,
            ])
            ->exists();

        $statement->update([
            'is_completed'  => ! $incomplete,
            'import_status' => $incomplete ? BankImportStatus::Validated->value : BankImportStatus::Posted->value,
        ]);
    }
}
