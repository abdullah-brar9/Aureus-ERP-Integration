<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\MoveLine as BaseMoveLine;

class JournalItem extends BaseMoveLine
{
    public function fsTag(): BelongsTo
    {
        return $this->belongsTo(FsTag::class, 'fs_tag_id');
    }
}
