<?php

namespace Webkul\Accounting\Services\Coa;

use RuntimeException;

/**
 * A user-facing upload problem (nothing chosen, file expired/unreadable, could
 * not be prepared). Thrown by {@see CoaUploadPathResolver} so the import page
 * can surface a clean validation message instead of a raw, path-leaking
 * exception. The message is always safe to show verbatim to an end user.
 */
class CoaUploadException extends RuntimeException {}
