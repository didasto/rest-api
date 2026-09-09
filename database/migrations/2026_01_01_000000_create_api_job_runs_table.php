<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();                  // which job type
            $table->string('batch_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('message')->nullable();
            $table->string('result_url', 2048)->nullable();
            $table->string('data_class')->nullable();
            $table->json('data')->nullable();                // JobData, never changed
            $table->string('result_class')->nullable();
            $table->json('result')->nullable();              // JobResult, grows with the chain
            $table->string('created_by')->nullable()->index();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    public function table(): string
    {
        return config('rest-api.jobs.table', 'api_job_runs');
    }
};
