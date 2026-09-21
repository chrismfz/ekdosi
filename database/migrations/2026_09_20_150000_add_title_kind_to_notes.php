<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Σημειώσεις πελάτη» (joplin-junior) — evolve the ONE polymorphic Note model
 * additively (per CLAUDE.md: one model, grown, never cloned) so it can carry a
 * customer's long-form technical dossier alongside the short chronological log:
 *
 *   - `title` — a scannable heading for the note list (was body-only, so a list
 *     of notes could only show the first N chars). Nullable: legacy/imported
 *     notes and quick one-liners stay title-less.
 *   - `kind`  — a light split between the append-style «Γενική» note and a living
 *     «Τεχνικό δελτίο» (RouterOS export, IP tables, TeamViewer/AnyDesk ids) so the
 *     two don't bury each other. NOT NULL, defaults to 'general' so every existing
 *     row keeps its meaning. Tags (the taggables morph pivot, added by HasTags on
 *     Note) do the finer, cross-cutting classification — no schema change needed.
 *
 * The `body` widening to MEDIUMTEXT is MySQL/MariaDB-only: there TEXT caps at
 * 64 KiB, which a full router config can exceed; SQLite TEXT is already unbounded
 * and a ->change() there forces a full table rebuild for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('notable_id');
            $table->string('kind', 32)->default('general')->after('title');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `notes` MODIFY `body` MEDIUMTEXT NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn(['title', 'kind']);
        });

        // `body` is deliberately LEFT widened: MEDIUMTEXT is a superset of TEXT,
        // so keeping it costs nothing, whereas narrowing back would ERROR (MariaDB
        // strict mode) or SILENTLY TRUNCATE any note >64 KiB written in the
        // meantime — the exact large-export case this migration widened it for.
    }
};
