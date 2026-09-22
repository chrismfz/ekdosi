<?php

namespace Tests\Feature\Import;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/** The «Εισαγωγή CSV» header action end to end on the customers list. */
class CsvImportActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::factory()->create(['country_code' => 'GR']);
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    private function upload(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pelates.csv', $csv);
    }

    public function test_upload_previews_then_imports(): void
    {
        $csv = "Επωνυμία;ΑΦΜ;Άσχετη στήλη\nΑλφα ΑΕ;094019245;x\nΛάθος;123456789;y\n";

        $page = Livewire::test(ListCustomers::class)
            ->mountAction('import_csv')
            ->setActionData(['file' => [$this->upload($csv)]]);

        $preview = $page->get('mountedActions')[0]['data']['preview'];
        $this->assertStringContainsString('Νέα: 1', $preview['summary']);
        $this->assertStringContainsString('Παραλείπονται (σφάλμα): 1', $preview['summary']);
        $this->assertSame(['Άσχετη στήλη'], $preview['ignored']);
        $this->assertStringContainsString('δεν περνά τον έλεγχο εγκυρότητας', $preview['notes'][0]['text']);
        $this->assertSame(0, Customer::where('company_id', $this->tenant->id)->count(), 'the preview writes nothing');

        $page->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(['Αλφα ΑΕ'], Customer::where('company_id', $this->tenant->id)->pluck('name')->all());
    }

    public function test_a_file_without_a_header_shows_why(): void
    {
        $page = Livewire::test(ListCustomers::class)
            ->mountAction('import_csv')
            ->setActionData(['file' => [$this->upload("\n\n")]]);

        $this->assertStringContainsString('κενό', $page->get('mountedActions')[0]['data']['preview']['error']);
    }

    public function test_the_template_downloads(): void
    {
        Livewire::test(ListCustomers::class)
            ->callAction(['import_csv', 'import_csv_template'])
            ->assertFileDownloaded('protypo-pelates.csv');
    }
}
