<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\Account\Models\Account as BaseAccount;

class Account extends BaseAccount
{
    public function sourceRows(): HasMany
    {
        return $this->hasMany(CoaImportSourceRow::class, 'canonical_account_id');
    }

    public function latestSourceRow(): HasOne
    {
        return $this->hasOne(CoaImportSourceRow::class, 'canonical_account_id')->latestOfMany();
    }
}
