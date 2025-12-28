<?php

namespace App\Console\Commands;

use App\Services\TrainingSignalsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTrainingSignalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'signals:backfill
                            {--workoutId= : Process single workout ID}
                            {--fromId= : Start from workout ID (inclusive)}
                            {--toId= : End at workout ID (inclusive)}
                            {--limit= : Maximum number of workouts to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate TrainingSignals v1 for existing workouts';

    /**
     * Execute the console command.
     */
    public function handle(TrainingSignalsService $service): int
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
    private function handleSingleWorkout(TrainingSignalsService $service, int $workoutId): int
    {
        $this->info("Processing workout ID: {$workoutId}");

        $workout = DB::table('workouts')->where('id', $workoutId)->first();
        
        if (!$workout) {
            $this->error("Workout with ID {$workoutId} not found.");
            return Command::FAILURE;
        }

        $service->upsertForWorkout($workoutId);
        $this->info("✓ Generated signals for workout ID: {$workoutId}");

        return Command::SUCCESS;
    }

    /**
     * Handle batch processing.
     */
    private function handleBatch(TrainingSignalsService $service): int
    {
        $fromId = $this->option('fromId');
        $toId = $this->option('toId');
        $limit = $this->option('limit');

        $query = DB::table('workouts')
            ->leftJoin('training_signals_v1', 'workouts.id', '=', 'training_signals_v1.workout_id')
            ->whereNull('training_signals_v1.workout_id')
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
            $this->info('No workouts found that need signals generated.');
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

        $this->info("✓ Completed. Generated signals for {$processed} workout(s).");

        return Command::SUCCESS;
    }
}

