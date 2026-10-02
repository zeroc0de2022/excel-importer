<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rows', function (Blueprint $table) {
            // The id from the file is the primary key. numeric(20,0) holds the full
            // unsigned bigint range (up to 18446744073709551615); Postgres bigint is signed.
            $table->decimal('id', 20, 0)->primary();
            $table->string('name');
            $table->date('date')->index();

            // Which import and which spreadsheet row created this record.
            // Lets a retried chunk recognise its own rows instead of reporting them as duplicates.
            $table->foreignUuid('import_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_number');

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rows');
    }
};
