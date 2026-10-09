<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Standard columns for every tenant row that syncs to devices.
         * id: client-generated UUID (so offline devices can create rows).
         * version: optimistic concurrency, see docs/decisions.md D2.
         */
        Blueprint::macro('syncable', function (bool $branchScoped = true) {
            /** @var Blueprint $this */
            $this->uuid('id')->primary();
            $this->uuid('school_id')->index();
            if ($branchScoped) {
                $this->uuid('branch_id')->index();
            }
            $this->unsignedInteger('version')->default(1);
            $this->uuid('created_by')->nullable();
            $this->uuid('updated_by')->nullable();
            $this->timestamps();
        });

        // Append-only rows (money, punches, audit): never updated after insert.
        Blueprint::macro('appendOnly', function (bool $branchScoped = true) {
            /** @var Blueprint $this */
            $this->uuid('id')->primary();
            $this->uuid('school_id')->index();
            if ($branchScoped) {
                $this->uuid('branch_id')->index();
            }
            $this->uuid('created_by')->nullable();
            $this->uuid('device_id')->nullable();
            $this->timestamp('created_at')->useCurrent();
        });
    }
}
