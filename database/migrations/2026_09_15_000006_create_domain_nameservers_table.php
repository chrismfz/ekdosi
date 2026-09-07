<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains pillar (Πυλώνας A / A1) — docs/domains/README.md §3.5.
 * The delegation nameservers a domain USES (ns1.myip.gr, …) — NOT glue/child
 * hosts (those are domain_hosts, landing with the write phase A3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_nameservers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $t->string('host', 190);
            $t->unsignedTinyInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['company_id', 'domain_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_nameservers');
    }
};
