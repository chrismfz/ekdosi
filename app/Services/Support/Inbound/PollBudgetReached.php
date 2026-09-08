<?php

namespace App\Services\Support\Inbound;

use RuntimeException;

/**
 * Internal control-flow sentinel — thrown to stop a {@see WebklexImapMailbox::poll}
 * chunked walk once MAX_PER_POLL messages have been handled, so one poll never runs
 * unbounded. Caught right around the chunked() call; never escapes the poller.
 */
final class PollBudgetReached extends RuntimeException {}
