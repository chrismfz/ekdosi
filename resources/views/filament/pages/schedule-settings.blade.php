<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400">
        Ενεργοποίηση/απενεργοποίηση κάθε προγραμματισμένης εργασίας. Η ρύθμιση εδώ
        υπερισχύει του <code>EKDOSI_SCHEDULE_*</code> (env = προεπιλογή). Τίποτα δεν τρέχει
        αν δεν υπάρχει cron (<code>schedule:run</code>) + queue worker — δες «Υγεία συστήματος».
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Αποθήκευση
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
