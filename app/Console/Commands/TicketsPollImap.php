<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\TicketDepartment;
use App\Models\TicketPollRun;
use App\Services\Support\Inbound\ImapMailbox;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\Support\Inbound\ParsedInboundEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * `tickets:poll-imap` (Πυλώνας E, Phase 3b) — poll each support-enabled tenant's
 * mail-configured departments, route inbound email into tickets via the
 * {@see InboundTicketRouter} choke-point, and record a {@see TicketPollRun} health
 * row per department. Per-department isolation (one bad mailbox never aborts the
 * sweep). Default OFF on the scheduler; safe to run by hand anytime.
 */
class TicketsPollImap extends Command
{
    protected $signature = 'tickets:poll-imap
        {--tenant= : Limit to one Company slug (default: every support-enabled tenant).}';

    protected $description = 'Πυλώνας E: poll support department mailboxes (IMAP) and route inbound email into tickets.';

    public function handle(ImapMailbox $mailbox, InboundTicketRouter $router): int
    {
        $tenants = $this->resolveTenants((string) $this->option('tenant'));
        if ($tenants === null) {
            return self::INVALID;
        }
        if ($tenants->isEmpty()) {
            $this->info('No support-enabled tenant with a mail-configured department. Nothing to do.');

            return self::SUCCESS;
        }

        $fetched = 0;
        $processed = 0;

        foreach ($tenants as $tenant) {
            $departments = TicketDepartment::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $tenant->id)
                ->where('is_active', true)
                ->get()
                ->filter(fn (TicketDepartment $d): bool => $d->canPollMail());

            foreach ($departments as $department) {
                $summary = $mailbox->poll($department, function (ParsedInboundEmail $email) use ($department, $router): bool {
                    // route() returns a Ticket or null (a deliberate clients_only reject);
                    // both are "handled" → mark the source message seen. Only a THROW
                    // (caught by the mailbox per-message) leaves it unread for next time.
                    $router->route($department, $email);

                    return true;
                });

                TicketPollRun::create([
                    'company_id' => $tenant->id,
                    'ticket_department_id' => $department->id,
                    'connected' => $summary->connected,
                    'fetched' => $summary->fetched,
                    'processed' => $summary->processed,
                    'errors' => $summary->errors !== [] ? $summary->errors : null,
                ]);

                $fetched += $summary->fetched;
                $processed += $summary->processed;

                $line = "{$tenant->slug}/{$department->name}: "
                    .($summary->connected ? 'connected' : 'FAILED')
                    .", fetched {$summary->fetched}, processed {$summary->processed}";

                if ($summary->errors !== []) {
                    $this->warn($line.' — '.count($summary->errors).' error(s)');
                    Log::warning('tickets:poll-imap', [
                        'tenant' => $tenant->slug,
                        'department_id' => $department->id,
                        'summary' => $summary->toArray(),
                    ]);
                } else {
                    $this->line($line);
                }
            }
        }

        $this->info("Done. Fetched {$fetched}, processed {$processed}.");

        return self::SUCCESS;
    }

    /**
     * The tenant set: one slug (support-enabled), or every support-enabled tenant.
     * null = unknown slug (exit INVALID).
     *
     * @return Collection<int, Company>|null
     */
    private function resolveTenants(string $slug): ?Collection
    {
        if ($slug !== '') {
            $tenant = Company::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                $this->error("No tenant with slug='{$slug}'.");

                return null;
            }

            return collect([$tenant])->filter(fn (Company $c): bool => $c->hasSupport())->values();
        }

        return Company::query()->where('support_enabled', true)->get();
    }
}
