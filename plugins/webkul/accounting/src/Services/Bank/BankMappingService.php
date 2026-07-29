<?php

namespace Webkul\Accounting\Services\Bank;

use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatement;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankMappingRule;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Security\Models\User;

class BankMappingService
{
    public function suggestForStatement(BankStatement $statement, float $reviewThreshold = 0.85): int
    {
        $rules = BankMappingRule::query()
            ->where('company_id', $statement->company_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderByDesc('confidence')
            ->get();

        $updated = 0;
        foreach ($statement->lines()->with('mapping')->get() as $line) {
            $mapping = $line->mapping;
            if (! $mapping || ! in_array($mapping->review_status, [BankReviewStatus::Unmapped, BankReviewStatus::Suggested, BankReviewStatus::NeedsReview], true)) {
                continue;
            }

            $rule = $rules->first(fn (BankMappingRule $candidate) => $this->matches($candidate, $statement, $line));
            if (! $rule) {
                continue;
            }

            $confidence = (float) $rule->confidence;
            $mapping->update([
                'offset_account_id'  => $rule->offset_account_id,
                'mapping_rule_id'    => $rule->id,
                'transaction_type'   => $rule->transaction_type,
                'tax_treatment'      => $rule->tax_treatment,
                'cash_flow_category' => $rule->cash_flow_category,
                'confidence'         => $confidence,
                'review_status'      => $confidence >= $reviewThreshold
                    ? BankReviewStatus::Suggested
                    : BankReviewStatus::NeedsReview,
            ]);
            $updated++;
        }

        return $updated;
    }

    public function approve(BankTransactionMapping $mapping, User $reviewer, bool $learn = true): BankTransactionMapping
    {
        $mapping->loadMissing(['statementLine.statement', 'bankGlAccount.companies', 'offsetAccount.companies']);
        $companyId = $mapping->company_id;

        if (! $mapping->bankGlAccount || ! $mapping->offsetAccount) {
            throw new \RuntimeException('Bank and offset GL accounts are required before approval.');
        }

        foreach ([$mapping->bankGlAccount, $mapping->offsetAccount] as $account) {
            if ($account->is_group || $account->deprecated || ! $account->companies->contains('id', $companyId)) {
                throw new \RuntimeException('Mapping accounts must be active, postable and owned by the selected company.');
            }
        }

        $mapping->update([
            'review_status' => BankReviewStatus::Approved,
            'reviewer_id'   => $reviewer->id,
            'reviewed_at'   => now(),
        ]);

        if ($learn) {
            $this->learnFromApproval($mapping);
        }

        return $mapping->fresh();
    }

    protected function learnFromApproval(BankTransactionMapping $mapping): void
    {
        if ($mapping->mapping_rule_id) {
            BankMappingRule::query()->whereKey($mapping->mapping_rule_id)->update([
                'usage_count' => DB::raw('usage_count + 1'),
            ]);

            return;
        }

        $line = $mapping->statementLine;
        $direction = (float) $line->credit > 0 ? 'credit' : 'debit';
        $amount = max((float) $line->debit, (float) $line->credit);
        $description = trim((string) $line->description);

        $rule = BankMappingRule::query()->firstOrCreate([
            'company_id'          => $mapping->company_id,
            'bank_gl_account_id'  => $mapping->bank_gl_account_id,
            'offset_account_id'   => $mapping->offset_account_id,
            'bank_account_number' => $line->statement?->bank_account_number,
            'description_pattern' => $description,
            'direction'           => $direction,
        ], [
            'name'               => 'Learned from '.$mapping->map_reference,
            'minimum_amount'     => round($amount * 0.9, 4),
            'maximum_amount'     => round($amount * 1.1, 4),
            'transaction_type'   => $mapping->transaction_type,
            'tax_treatment'      => $mapping->tax_treatment,
            'cash_flow_category' => $mapping->cash_flow_category,
            'confidence'         => 0.9,
            'priority'           => 100,
            'usage_count'        => 1,
            'is_active'          => true,
        ]);

        $mapping->update(['mapping_rule_id' => $rule->id]);
    }

    protected function matches(BankMappingRule $rule, BankStatement $statement, $line): bool
    {
        $amount = max((float) $line->debit, (float) $line->credit);
        $direction = (float) $line->credit > 0 ? 'credit' : 'debit';

        return ($rule->bank_account_number === null || mb_strtoupper($rule->bank_account_number) === mb_strtoupper($statement->bank_account_number))
            && ($rule->bank_gl_account_id === null || $rule->bank_gl_account_id === $statement->bank_gl_account_id)
            && ($rule->direction === null || $rule->direction === $direction)
            && ($rule->minimum_amount === null || $amount >= (float) $rule->minimum_amount)
            && ($rule->maximum_amount === null || $amount <= (float) $rule->maximum_amount)
            && $this->textMatches($rule->description_pattern, (string) $line->description)
            && $this->textMatches($rule->reference_pattern, (string) $line->reference)
            && $this->textMatches($rule->counterparty_pattern, (string) $line->description);
    }

    protected function textMatches(?string $pattern, string $value): bool
    {
        if ($pattern === null || trim($pattern) === '') {
            return true;
        }

        $pattern = trim($pattern);
        if (str_starts_with($pattern, '/') && @preg_match($pattern, '') !== false) {
            return preg_match($pattern, $value) === 1;
        }

        return str_contains(mb_strtolower($value), mb_strtolower($pattern));
    }
}
