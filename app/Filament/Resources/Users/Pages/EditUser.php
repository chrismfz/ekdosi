<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\ResetTwoFactorAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Same «Επαναφορά 2FA» as the list — appears only when the user is
            // enrolled. Enabling 2FA stays self-service on the user's profile.
            ResetTwoFactorAction::make(),
            DeleteAction::make(),
        ];
    }
}
