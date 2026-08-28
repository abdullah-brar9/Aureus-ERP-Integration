<?php

namespace Webkul\Accounting\Services\Coa;

use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Data\Coa\CoaRow;

/**
 * Suggests an AccountType for a leaf account from its Nature + classification
 * path, following the agreed default mapping. Suggestions are only defaults:
 * the import UI lets the user override any account's type before confirming
 * (the import stores those overrides), so nothing here is silently forced.
 */
class CoaAccountTypeMapper
{
    /**
     * @return array<int, array{needle: string, type: AccountType}> ordered, most specific first
     */
    protected function rules(): array
    {
        return [
            // Assets — specific before generic.
            ['needle' => 'cash & bank', 'type' => AccountType::ASSET_CASH],
            ['needle' => 'cash at bank', 'type' => AccountType::ASSET_CASH],
            ['needle' => 'trade debt', 'type' => AccountType::ASSET_RECEIVABLE],
            ['needle' => 'receivable', 'type' => AccountType::ASSET_RECEIVABLE],
            ['needle' => 'accumulated depreciation', 'type' => AccountType::ASSET_NON_CURRENT],
            ['needle' => 'property and equipment', 'type' => AccountType::ASSET_FIXED],
            ['needle' => 'intangible', 'type' => AccountType::ASSET_FIXED],
            ['needle' => 'fixed asset', 'type' => AccountType::ASSET_FIXED],
            ['needle' => 'prepay', 'type' => AccountType::ASSET_PREPAYMENTS],
            ['needle' => 'advance tax', 'type' => AccountType::ASSET_CURRENT],
            ['needle' => 'provision for taxation', 'type' => AccountType::LIABILITY_CURRENT],

            // Liabilities.
            ['needle' => 'tax payable', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'payable', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'accrued', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'current liabilit', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'liabilit', 'type' => AccountType::LIABILITY_CURRENT],

            // Equity.
            ['needle' => 'accumulated losses', 'type' => AccountType::EQUITY_UNAFFECTED],
            ['needle' => 'retained earning', 'type' => AccountType::EQUITY_UNAFFECTED],
            ['needle' => 'share premium', 'type' => AccountType::EQUITY],
            ['needle' => 'share capital', 'type' => AccountType::EQUITY],
            ['needle' => 'advance against issuance', 'type' => AccountType::EQUITY],

            // Income.
            ['needle' => 'other income', 'type' => AccountType::INCOME_OTHER],
            ['needle' => 'revenue', 'type' => AccountType::INCOME],

            // Expenses.
            ['needle' => 'depreciation', 'type' => AccountType::EXPENSE_DEPRECIATION],
            ['needle' => 'amortization', 'type' => AccountType::EXPENSE_DEPRECIATION],
            ['needle' => 'fleet subsidy', 'type' => AccountType::EXPENSE_DIRECT_COST],
            ['needle' => 'carrier cost', 'type' => AccountType::EXPENSE_DIRECT_COST],
            ['needle' => 'direct expense', 'type' => AccountType::EXPENSE_DIRECT_COST],
            ['needle' => 'finance cost', 'type' => AccountType::EXPENSE],
        ];
    }

    public function suggest(CoaRow $row): AccountType
    {
        $haystack = mb_strtolower($row->classificationPathLabel().' '.$row->title);

        foreach ($this->rules() as $rule) {
            if (str_contains($haystack, $rule['needle'])) {
                return $rule['type'];
            }
        }

        // Fall back to the Nature bucket so every row still gets a sane type.
        return $this->fallbackByNature($row);
    }

    /**
     * A coarse type for group nodes and unmatched leaves, from the top-level
     * classification / nature. Group nodes are non-postable so their type is
     * only cosmetic; leaf fallbacks are the safest bucket per section.
     */
    public function fallbackByNature(CoaRow $row): AccountType
    {
        $class1 = mb_strtolower($row->classifications[0] ?? '');
        $nature = mb_strtolower($row->nature);

        return match (true) {
            str_contains($class1, 'asset')      => AccountType::ASSET_CURRENT,
            str_contains($class1, 'liab')       => AccountType::LIABILITY_CURRENT,
            str_contains($class1, 'equity')     => AccountType::EQUITY,
            str_contains($class1, 'revenue')    => AccountType::INCOME,
            str_contains($class1, 'income')     => AccountType::INCOME_OTHER,
            str_contains($class1, 'expense')    => AccountType::EXPENSE,
            $nature === 'p&l'                   => AccountType::EXPENSE,
            default                             => AccountType::ASSET_CURRENT,
        };
    }
}
