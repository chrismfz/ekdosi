<?php

namespace App\Support\Dashboard;

/**
 * One palette for the «Αναφορές» charts so the same role reads the same colour
 * everywhere (focus year always blue, comparison always grey, cash green, ΦΠΑ
 * amber, forecast green-dashed). These are the values the widgets already used
 * inline — centralised here so a colour change is one edit, not nine. Chosen to
 * stay distinguishable in both light and dark mode.
 */
class ReportPalette
{
    /** Focus / primary series (the selected year). */
    public const PRIMARY = '#3b82f6';

    /** Comparison / baseline series (prior year, seasonal average). */
    public const MUTED = '#9ca3af';

    /** Cash / receipts. */
    public const POSITIVE = '#10b981';

    /** ΦΠΑ / highlighted bar. */
    public const WARNING = '#f59e0b';

    /** Forecast / projection. */
    public const FORECAST = '#16a34a';
}
