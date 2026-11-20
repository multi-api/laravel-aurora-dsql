<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// On the default (SQLite) connection: the DSQL deferral must leave it alone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c', function (Blueprint $table) {
            $table->id();
            $table->string('w')->index();
        });
    }
};
