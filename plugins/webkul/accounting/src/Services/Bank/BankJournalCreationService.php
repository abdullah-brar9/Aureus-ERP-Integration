<?php

namespace Webkul\Accounting\Services\Bank;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Services\Account\CanonicalAccountCreationService;
use Webkul\Accounting\Services\Currency\CompanyCurrencyService;
use Webkul\Support\Models\Company;

final class BankJournalCreationService
{
    public function __construct(
        private readonly CompanyCurrencyService $companyCurrencies,
        private readonly CanonicalAccountCreationService $accounts,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, array $data): Journal
    {
        $name = trim((string) ($data['name'] ?? ''));
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $currencyId = (int) ($data['currency_id'] ?? 0);
        $accountId = (int) ($data['default_account_id'] ?? 0);

        if ($name === '' || $code === '') {
            throw new RuntimeException('Bank journal name and code are required.');
        }
        if (isset($data['company_id']) && (int) $data['company_id'] !== (int) $company->id) {
            throw new RuntimeException('The bank journal company does not match the active company.');
        }
        if (! $this->companyCurrencies->isTransactionCurrencyEnabled($company, $currencyId)) {
            throw new RuntimeException('The bank journal currency is not enabled for this company.');
        }
        if (Journal::query()->where('company_id', $company->id)->whereRaw('UPPER(code) = ?', [$code])->exists()) {
            throw new RuntimeException("Journal code {$code} already exists for this company.");
        }
        if (! $this->accounts->bankAccountQuery($company, $currencyId)->whereKey($accountId)->exists()) {
            throw new RuntimeException('The linked Bank GL must be active, postable, company-owned and currency-compatible.');
        }

        return DB::transaction(fn (): Journal => Journal::query()->create([
            'company_id'         => $company->id,
            'currency_id'        => $currencyId,
            'default_account_id' => $accountId,
            'creator_id'         => auth()->id(),
            'name'               => $name,
            'code'               => $code,
            'type'               => JournalType::BANK,
            'show_on_dashboard'  => (bool) ($data['is_active'] ?? true),
        ]));
    }
}
