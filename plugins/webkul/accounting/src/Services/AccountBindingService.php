<?php

namespace Webkul\Accounting\Services;

use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\ReportLine;

/**
 * Resolves a report line's account bindings into a concrete, signed set of
 * chart-of-account account ids.
 *
 * A binding may point at a parent account; by default the parent's descendant
 * accounts are included as well, reusing the existing Account `parent_id`
 * tree (the same hierarchy the rest of Aureus uses). Each resolved account
 * carries the sign from its binding so a single line can add some accounts and
 * subtract others.
 */
class AccountBindingService
{
    /**
     * Whether binding to a parent account also includes its descendants.
     */
    public function __construct(
        protected bool $includeDescendants = true,
    ) {}

    /**
     * Resolve one line's bindings to [account_id => sign].
     *
     * Later bindings win if the same account id appears twice.
     *
     * @return array<int, int>
     */
    public function resolveSignedAccounts(ReportLine $line): array
    {
        $line->loadMissing('accountBindings');

        $signedAccounts = [];

        foreach ($line->accountBindings as $binding) {
            $accountId = (int) $binding->account_id;
            $sign      = (int) $binding->sign === -1 ? -1 : 1;

            foreach ($this->expandAccountId($accountId) as $resolvedId) {
                $signedAccounts[$resolvedId] = $sign;
            }
        }

        return $signedAccounts;
    }

    /**
     * The flat list of account ids a line reads from (no signs).
     *
     * @return array<int, int>
     */
    public function resolveAccountIds(ReportLine $line): array
    {
        return array_values(array_keys($this->resolveSignedAccounts($line)));
    }

    /**
     * Expand a single account id to itself plus (optionally) its descendants.
     *
     * @return array<int, int>
     */
    protected function expandAccountId(int $accountId): array
    {
        if (! $this->includeDescendants) {
            return [$accountId];
        }

        $account = Account::query()->find($accountId);

        if ($account === null) {
            return [$accountId];
        }

        return [$accountId, ...$this->descendantIdsOf($account)];
    }

    /**
     * Descendant account ids, walking the existing Account parent/children tree.
     *
     * @return array<int, int>
     */
    protected function descendantIdsOf(Account $account): array
    {
        $ids = [];

        foreach ($account->children as $child) {
            $ids = [
                ...$ids,
                (int) $child->id,
                ...$this->descendantIdsOf($child),
            ];
        }

        return $ids;
    }
}
