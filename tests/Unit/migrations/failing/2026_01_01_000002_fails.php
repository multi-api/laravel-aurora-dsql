<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'dsql';

    public function up(): void
    {
        Schema::create('b', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('y')->index();
        });

        throw new RuntimeException('this migration fails');
    }
};
