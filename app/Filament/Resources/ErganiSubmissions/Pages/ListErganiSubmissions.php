<?php

namespace App\Filament\Resources\ErganiSubmissions\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\ErganiSubmissions\ErganiSubmissionResource;

class ListErganiSubmissions extends BaseListRecords
{
    protected static string $resource = ErganiSubmissionResource::class;

    public function getSubheading(): ?string
    {
        return 'Κάθε δήλωση και ανάκληση που έκανε το ekdosi στο ΕΡΓΑΝΗ, με πρωτόκολλο και επίσημο PDF. Δεν διαγράφεται — είναι το αρχείο ελέγχου.';
    }
}
