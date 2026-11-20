<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// A unique index over rows that already hold duplicates: its build job fails.
return new class extends Migration
{
    protected $connection = 'dsql';

    public function up(): void
    {
        Schema::create('it_deferred_dup', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code');
        });

        DB::connection('dsql')->table('it_deferred_dup')->insert([
            ['id' => (string) Str::uuid7(), 'code' => 'same'],
            ['id' => (string) Str::uuid7(), 'code' => 'same'],
        ]);

        Schema::table('it_deferred_dup', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_deferred_dup');
    }
};
