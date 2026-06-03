<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'email_verified_at'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPanelShield, HasRoles, Notifiable;

    /**
     * Gate panel access. Filament only HONOURS this when the model implements
     * the FilamentUser contract; without it, Filament falls back to "allow in
     * `local` env only, 403 everywhere else" — which is why the panel 403'd the
     * moment APP_ENV was corrected from (a wrong) `local` to `production`.
     *
     * An internal operator can reach the panel iff they belong to ≥1 tenant
     * (this is an operators-only app, no customer portal). Tenancy
     * (canAccessTenant) + Shield policies gate everything beyond. We use tenant
     * membership rather than HasPanelShield's super_admin/panel_user check
     * because `panel_user` was never managed while the app ran (wrongly) as
     * `local`, so real users may not carry it.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->companies()->exists()) {
            return true;
        }

        // Leave a breadcrumb: a bare 403 with no log is undebuggable months
        // later. This is the single line that turns "why is this user locked
        // out?" into a grep.
        \Illuminate\Support\Facades\Log::warning('Panel access denied — user belongs to no company.', [
            'user_id' => $this->getKey(),
            'email' => $this->email,
            'panel' => $panel->getId(),
        ]);

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // TOTP secret + recovery codes encrypted at rest.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    // ── Filament TOTP two-factor (App MFA) ──────────────────────────────────

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /** Label shown in the authenticator app. */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->companies;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->companies()->whereKey($tenant->getKey())->exists();
    }
}
