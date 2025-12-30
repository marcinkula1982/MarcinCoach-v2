<?php

namespace App\Console\Commands;

use App\Services\TrainingAlertsV1Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTrainingAlertsV1Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:backfill-v1
                            {--workoutId= : Process single workout ID}
                            {--fromId= : Start from workout ID (inclusive)}
                            {--toId= : End at workout ID (inclusive)}
                            {--limit= : Maximum number of workouts to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate TrainingAlerts v1 for existing workouts';

    /**
     * Execute the console command.
     */
    public function handle(TrainingAlertsV1Service $service): int
    {
        $workoutId = $this->option('workoutId');
        
        if ($workoutId !== null) {
            return $this->handleSingleWorkout($service, (int) $workoutId);
        }

        return $this->handleBatch($service);
    }

    /**
     * Handle single workout ID.
     */
    private function handleSingleWorkout(TrainingAlertsV1Service $service, int $workoutId): int
    {
        $this->info("Processing workout ID: {$workoutId}");

        $workout = DB::table('workouts')->where('id', $workoutId)->first();
        
        if (!$workout) {
            $this->error("Workout with ID {$workoutId} not found.");
            return Command::FAILURE;
        }

        $service->upsertForWorkout($workoutId);
        $this->info("✓ Generated alerts v1 for workout ID: {$workoutId}");

        return Command::SUCCESS;
    }

    /**
     * Handle batch processing.
     */
    private function handleBatch(TrainingAlertsV1Service $service): int
    {
        $fromId = $this->option('fromId');
        $toId = $this->option('toId');
        $limit = $this->option('limit');

        $query = DB::table('workouts')
            ->select('workouts.id')
            ->orderBy('workouts.id', 'asc');

        if ($fromId !== null) {
            $query->where('workouts.id', '>=', (int) $fromId);
        }

        if ($toId !== null) {
            $query->where('workouts.id', '<=', (int) $toId);
        }

        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        $workoutIds = $query->pluck('id')->toArray();

        if (empty($workoutIds)) {
            $this->info('No workouts found to process.');
            return Command::SUCCESS;
        }

        $total = count($workoutIds);
        $this->info("Found {$total} workout(s) to process.");

        $processed = 0;
        foreach ($workoutIds as $id) {
            $service->upsertForWorkout($id);
            $processed++;

            if ($processed % 100 === 0) {
                $this->info("Processed {$processed}/{$total} workouts...");
            }
        }

        $this->info("✓ Completed. Generated alerts v1 for {$processed} workout(s).");

        return Command::SUCCESS;
    }
}

