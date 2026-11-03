<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// An account waiting to be activated cannot sign in or be seen in User
// Management, and activation gives it a new email anyway. So the email it holds
// is given up when someone activating needs that address, and an account whose
// ID number passed to someone else keeps none. Both leave the column empty.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNull('email')->update([
            'email' => DB::raw("CONCAT('released-', id, '@invalid.example')"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
