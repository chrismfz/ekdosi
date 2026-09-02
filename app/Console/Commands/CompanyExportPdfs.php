<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Portability\DocumentPdfArchive;
use Illuminate\Console\Command;
use Throwable;

/**
 * `company:export-pdfs` — every document of a tenant, rendered to PDF, in one zip.
 *
 * The handover artifact for a tenant that is LEAVING (MYD-025). The export bundle
 * (`company:export`) only means something to another ekdosi; this is what a human
 * and an accountant can read once the tenant no longer has access to the system —
 * and it is what makes deleting the tenant afterwards a defensible act rather
 * than a silent loss.
 *
 * Deliberately a COMMAND rather than a panel download: a tenant can hold tens of
 * thousands of documents and DomPDF is not fast, so this runs for minutes and must
 * not sit inside an HTTP request. A panel action would need a queued job plus a
 * download route; that is in `docs/BACKLOG.md`, not built.
 *
 *   php artisan company:export-pdfs --tenant=myip
 *   php artisan company:export-pdfs --tenant=myip --out=/backup/myip-docs.zip
 */
class CompanyExportPdfs extends Command
{
    protected $signature = 'company:export-pdfs
        {--tenant= : Company slug}
        {--out= : Target .zip path (default: storage/app/exports/<slug>-documents-<stamp>.zip)}';

    protected $description = 'Render every invoice + delivery note of a tenant to PDF and pack them into one zip.';

    public function handle(DocumentPdfArchive $archive): int
    {
        $slug = (string) $this->option('tenant');

        if ($slug === '') {
            $this->error('Δώσε --tenant=<slug>.');

            return self::FAILURE;
        }

        $company = Company::where('slug', $slug)->first();

        if ($company === null) {
            $this->error("Δεν βρέθηκε εταιρεία με slug «{$slug}».");

            return self::FAILURE;
        }

        $path = (string) ($this->option('out')
            ?: storage_path('app/exports/'.$company->slug.'-documents-'.now()->format('Ymd-His').'.zip'));

        $this->line("Παραγωγή PDF για «{$company->name}» → {$path}");
        $this->line('(μπορεί να πάρει αρκετή ώρα — ένα PDF ανά παραστατικό)');

        try {
            $result = $archive->build($company, $path, fn (string $line) => $this->line($line));
        } catch (Throwable $e) {
            $this->error('Απέτυχε: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Έτοιμο: %d παραστατικά + %d δελτία, %s',
            $result['invoices'],
            $result['delivery_notes'],
            $this->humanBytes($result['bytes']),
        ));
        $this->line($result['path']);

        if ($result['failed'] > 0) {
            // Loud, and a non-zero exit: a handover archive that is quietly missing
            // documents is worse than one that refuses to look finished.
            $this->warn("⚠ {$result['failed']} έγγραφα ΔΕΝ παρήχθησαν — δες errors.txt μέσα στο zip.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        // float, not int, division: casting at each step reported a 1.5 GB archive
        // as «1 GB», which matters when the number is how an operator decides
        // whether the handover fits on the medium they are using.
        $size = (float) $bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($size < 1024 || $unit === 'GB') {
                return round($size, 1).' '.$unit;
            }
            $size /= 1024;
        }

        return $bytes.' B';
    }
}
