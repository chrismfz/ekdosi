<?php

namespace Tests\Feature\Filament;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Tests\TestCase;

/**
 * Panel-wide date/time picker defaults (AppServiceProvider::boot configureUsing).
 *
 * The bug: Filament's date pickers default to a NATIVE browser control whose
 * displayed format follows the VIEWER'S browser locale, so an operator on an
 * en-US browser saw the invoice «Ημερομηνία έκδοσης» as M/D/Y even though
 * APP_LOCALE=el. The fix forces Filament's own JS picker (native:false) with an
 * el-GR display format, registered ONCE on the base DateTimePicker (DatePicker AND
 * TimePicker both extend it) via the DEFAULT-format slots — so getDisplayFormat()
 * still resolves the right shape per field. This locks that in place.
 */
class PanelDatePickerDefaultsTest extends TestCase
{
    public function test_date_picker_defaults_to_non_native_greek_format(): void
    {
        $picker = DatePicker::make('some_date');

        $this->assertFalse($picker->isNative(), 'DatePicker must NOT be native (browser-locale) by default');
        $this->assertSame('d/m/Y', $picker->getDisplayFormat());
    }

    public function test_datetime_picker_uses_greek_day_first_format(): void
    {
        // Bare DateTimePicker keeps Filament's default seconds → d/m/Y H:i:s…
        $withSeconds = DateTimePicker::make('some_datetime');
        $this->assertFalse($withSeconds->isNative(), 'DateTimePicker must NOT be native by default');
        $this->assertSame('d/m/Y H:i:s', $withSeconds->getDisplayFormat());

        // …and the invoice «Ημερομηνία έκδοσης» shape (->seconds(false)) reads d/m/Y H:i.
        $noSeconds = DateTimePicker::make('issued_at')->seconds(false);
        $this->assertSame('d/m/Y H:i', $noSeconds->getDisplayFormat());
    }

    public function test_time_only_picker_keeps_a_time_format_not_a_date_one(): void
    {
        // Regression guard: DatePicker AND TimePicker both extend DateTimePicker, so the
        // base configureUsing reaches a TimePicker too. It must inherit native:false but
        // keep a TIME format with NO date tokens — an explicit date displayFormat would
        // have wrongly forced «d/m/Y …» onto a field that has no date part.
        $picker = TimePicker::make('some_time');

        $this->assertFalse($picker->isNative());
        $this->assertStringNotContainsString('d/m/Y', $picker->getDisplayFormat());
        $this->assertStringContainsString('H:i', $picker->getDisplayFormat());
    }

    public function test_a_per_field_display_format_override_still_wins(): void
    {
        // The default-format slots are only a fallback; a chained ->displayFormat()
        // must override it (e.g. CompanyForm's ISO Y-m-d min-date pickers stay ISO).
        $picker = DatePicker::make('iso')->displayFormat('Y-m-d');

        $this->assertSame('Y-m-d', $picker->getDisplayFormat());
    }
}
