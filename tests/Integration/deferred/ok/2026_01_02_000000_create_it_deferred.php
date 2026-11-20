<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Indexes on a new table, a unique added to it afterwards, then DML and an upsert that needs that unique.
return new class extends Migration
{
    protected $connection = 'dsql';

    public function up(): void
    {
        Schema::create('it_deferred', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code');
            $table->string('kind');
            $table->integer('qty')->default(0);
            $table->index('kind');
            $table->index(['kind', 'code']);
        });

        Schema::table('it_deferred', function (Blueprint $table) {
            $table->unique('code');
        });

        DB::connection('dsql')->table('it_deferred')->insert(['id' => (string) Str::uuid7(), 'code' => 'a', 'kind' => 'x']);

        DB::connection('dsql')->table('it_deferred')->upsert(
            [['id' => (string) Str::uuid7(), 'code' => 'a', 'kind' => 'x', 'qty' => 5]],
            ['code'],
            ['qty']
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('it_deferred');
    }
};
