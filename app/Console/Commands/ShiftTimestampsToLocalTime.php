<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time correction after switching the app timezone from UTC to
 * Asia/Phnom_Penh.
 *
 * The timestamp columns hold a wall clock with no zone attached. Rows written
 * while the app ran on UTC therefore read seven hours early now that the app
 * reads them as Cambodian time — an order placed at 00:14 on the 18th shows
 * as 17:14 on the 17th, and lands in the wrong day on the dashboard.
 *
 * This shifts those existing rows forward so history lines up with the new
 * setting. Rows written after the switch are already correct, so run it once
 * and only once.
 */
class ShiftTimestampsToLocalTime extends Command
{
    protected $signature = 'data:shift-timestamps
        {--hours=7 : How many hours to add}
        {--before= : Only rows created strictly before this timestamp (defaults to now)}
        {--apply : Actually write the change; without it this is a dry run}';

    protected $description = 'Shift pre-existing UTC timestamps onto the local timezone (one-time)';

    /**
     * table => the datetime columns on it worth correcting.
     *
     * @var array<string, array<int, string>>
     */
    protected array $targets = [
        'orders' => ['created_at', 'updated_at'],
        'order_items' => ['created_at', 'updated_at'],
        'payments' => ['created_at', 'updated_at', 'paid_at', 'expires_at', 'last_event_at'],
        'users' => ['created_at', 'updated_at'],
        'products' => ['created_at', 'updated_at'],
        'categories' => ['created_at', 'updated_at'],
        'carts' => ['created_at', 'updated_at'],
        'cart_items' => ['created_at', 'updated_at'],
        'stock_adjustments' => ['created_at', 'updated_at'],
        'coupons' => ['created_at', 'updated_at'],
    ];

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $before = $this->option('before') ?: now()->toDateTimeString();
        $apply = (bool) $this->option('apply');

        if ($hours === 0) {
            $this->error('Nothing to do: --hours is 0.');

            return self::FAILURE;
        }

        $this->line(($apply ? 'Shifting' : 'DRY RUN — would shift') . " rows created before {$before} by {$hours}h.");
        $this->newLine();

        $total = 0;

        foreach ($this->targets as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));

            if ($present === []) {
                continue;
            }

            // Only rows that predate the switch; anything newer is already right.
            $count = DB::table($table)->where('created_at', '<', $before)->count();

            if ($count === 0) {
                continue;
            }

            $this->line(sprintf('  %-20s %5d rows  (%s)', $table, $count, implode(', ', $present)));
            $total += $count;

            if (! $apply) {
                continue;
            }

            $sets = [];

            foreach ($present as $column) {
                // Null stays null; only real timestamps move.
                $sets[] = "\"{$column}\" = CASE WHEN \"{$column}\" IS NULL THEN NULL"
                    . " ELSE \"{$column}\" + INTERVAL '{$hours} hours' END";
            }

            DB::statement(
                "UPDATE \"{$table}\" SET " . implode(', ', $sets) . ' WHERE created_at < ?',
                [$before]
            );
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('Nothing needed shifting.');

            return self::SUCCESS;
        }

        if ($apply) {
            $this->info("Shifted {$total} rows by {$hours}h.");
        } else {
            $this->warn("{$total} rows would be shifted. Re-run with --apply to write it.");
        }

        return self::SUCCESS;
    }
}
