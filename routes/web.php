<?php

use Illuminate\Support\Facades\Route;

// The app is the Filament admin panel; there is no public landing page.
// Send the root straight to the panel (which then routes to login / tenant).
Route::redirect('/', '/admin');
