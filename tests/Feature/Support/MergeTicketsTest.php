<?php

namespace Tests\Feature\Support;

use App\Actions\Support\MergeTickets;
use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 — ticket merge. Same-owner duplicates consolidate into the
 * survivor (messages/watchers/tags move, source closes terminally); a cross-owner
 * merge is forbidden (it would leak one party's thread into the other's portal).
 */
class MergeTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'mrg-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create(['company_id' => $company->id, 'name' => 'Γ', 'is_active' => true]);
    }

    private function ticketFor(Company $company, TicketDepartment $dept, ?Customer $customer, ?string $email, string $subject): Ticket
    {
        return app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer?->id, 'ticket_department_id' => $dept->id,
            'requester_email' => $email, 'subject' => $subject, 'body' => 'αρχικό '.$subject,
            'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
    }

    public function test_merge_moves_content_and_closes_source(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $target = $this->ticketFor($company, $dept, $customer, 'c@e.gr', 'Κύριο');
        $source = $this->ticketFor($company, $dept, $customer, 'c@e.gr', 'Διπλότυπο');

        // source gets an extra operator reply (the newest message overall) + a watcher + a tag
        app(PostTicketMessage::class)->handle($source, ['author_role' => TicketMessage::ROLE_OPERATOR, 'body' => 'απάντηση']);
        $source->addEmailWatcher('cc@third.tld');
        $tag = Tag::create(['company_id' => $company->id, 'name' => 'urgent']);
        $source->tags()->attach($tag->id);

        app(MergeTickets::class)->handle($source, $target);

        $target->refresh();
        $source->refresh();

        // source is terminal
        $this->assertSame(TicketStatus::Closed, $source->status);
        $this->assertSame($target->id, $source->merged_into_id);
        $this->assertTrue($source->isMerged());

        // messages moved: target now holds target-open + source-open + operator reply + its own «ενσωματώθηκε» note
        $this->assertSame(4, $target->messages()->count());
        // source keeps only its own «συγχωνεύθηκε» system note
        $this->assertSame(1, $source->messages()->count());

        // watcher + tag moved to the survivor
        $this->assertContains('cc@third.tld', $target->watcherEmailAddresses());
        $this->assertSame(0, $source->watchers()->count());
        $this->assertTrue($target->tags()->where('tags.id', $tag->id)->exists());

        // last-reply recomputed from the combined thread (the operator reply was newest)
        $this->assertSame('operator', $target->last_reply_role);
    }

    public function test_cross_customer_merge_is_blocked(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $a = Customer::create(['company_id' => $company->id, 'name' => 'Α', 'email' => 'a@e.gr']);
        $b = Customer::create(['company_id' => $company->id, 'name' => 'Β', 'email' => 'b@e.gr']);

        $ta = $this->ticketFor($company, $dept, $a, 'a@e.gr', 'Α');
        $tb = $this->ticketFor($company, $dept, $b, 'b@e.gr', 'Β');

        $this->assertFalse($ta->canMergeInto($tb));
        $this->expectException(\InvalidArgumentException::class);
        app(MergeTickets::class)->handle($ta, $tb);
    }

    public function test_guest_merge_requires_the_same_requester_email(): void
    {
        $company = $this->company();
        $dept = $this->department($company);

        $g1 = $this->ticketFor($company, $dept, null, 'guest@e.gr', 'Ένα');
        $g2 = $this->ticketFor($company, $dept, null, 'guest@e.gr', 'Δύο');
        $g3 = $this->ticketFor($company, $dept, null, 'other@e.gr', 'Τρία');

        $this->assertTrue($g1->canMergeInto($g2), 'same guest email → mergeable');
        $this->assertFalse($g1->canMergeInto($g3), 'different guest email → blocked');
    }

    public function test_a_registered_and_a_guest_ticket_are_different_owners(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $registered = $this->ticketFor($company, $dept, $customer, 'c@e.gr', 'Reg');
        $guest = $this->ticketFor($company, $dept, null, 'c@e.gr', 'Guest'); // same email but no account

        $this->assertFalse($registered->canMergeInto($guest), 'registered vs guest = different owners even on the same email');
    }

    public function test_a_merged_ticket_cannot_be_a_merge_source_or_target_again(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $target = $this->ticketFor($company, $dept, $customer, 'c@e.gr', 'Κύριο');
        $source = $this->ticketFor($company, $dept, $customer, 'c@e.gr', 'Διπλό');
        $third = $this->ticketFor($company, $dept, $customer, 'c@e.gr', 'Τρίτο');

        app(MergeTickets::class)->handle($source, $target);
        $source->refresh();

        $this->assertFalse($source->canMergeInto($third), 'an already-merged ticket cannot merge again');
        $this->assertFalse($third->canMergeInto($source), 'nor be merged into a merged one');
    }
}
