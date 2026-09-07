<?php

namespace App\Services\Support\Inbound;

/**
 * What one poll of a department's mailbox did (Πυλώνας E, Phase 3b) — recorded on
 * `ticket_poll_runs` and logged, so an operator can see «connected, N fetched, N
 * opened, N errors» without grepping the log.
 */
final class MailboxPollSummary
{
    /** @param  list<string>  $errors */
    public function __construct(
        public bool $connected = false,
        public int $fetched = 0,
        public int $processed = 0,
        public array $errors = [],
        public int $skipped = 0,
    ) {}

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    /** @return array{connected:bool, fetched:int, processed:int, skipped:int, errors:list<string>} */
    public function toArray(): array
    {
        return [
            'connected' => $this->connected,
            'fetched' => $this->fetched,
            'processed' => $this->processed,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
