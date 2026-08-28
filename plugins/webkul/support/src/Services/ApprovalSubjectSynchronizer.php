<?php

namespace Webkul\Support\Services;

use Webkul\Support\Models\ApprovalRequest;

class ApprovalSubjectSynchronizer
{
    public function synchronize(ApprovalRequest $request): void
    {
        $subject = $request->subject;
        if ($subject && method_exists($subject, 'synchronizeApprovalState')) {
            $subject->synchronizeApprovalState($request);
        }
    }
}
