<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI service detects the ecosystem while processing the log and folds it
 * into the signature hash, but nothing stored it — so at analysis time the
 * embedding could not be composed the same way the signature was, and retrieval
 * silently lost a dimension of context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('failures', function (Blueprint $table): void {
            $table->string('ecosystem', 30)->nullable()->after('error_type');
        });
    }

    public function down(): void
    {
        Schema::table('failures', function (Blueprint $table): void {
            $table->dropColumn('ecosystem');
        });
    }
};
