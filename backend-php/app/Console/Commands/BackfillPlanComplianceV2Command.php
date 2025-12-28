<?php

namespace App\Console\Commands;

use App\Services\PlanComplianceV2Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPlanComplianceV2Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'compliance:backfill-v2
                            {--workoutId= : Process single workout ID}
                            {--fromId= : Start from workout ID (inclusive)}
                            {--toId= : End at workout ID (inclusive)}
                            {--limit= : Maximum number of workouts to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate PlanCompliance v2 for existing workouts';

    /**
     * Execute the console command.
     */
    public function handle(PlanComplianceV2Service $service): int
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
    private function handleSingleWorkout(PlanComplianceV2Service $service, int $workoutId): int
    {
        $this->info("Processing workout ID: {$workoutId}");

        $workout = DB::table('workouts')->where('id', $workoutId)->first();
        
        if (!$workout) {
            $this->error("Workout with ID {$workoutId} not found.");
            return Command::FAILURE;
        }

        $service->upsertForWorkout($workoutId);
        $this->info("✓ Generated compliance v2 for workout ID: {$workoutId}");

        return Command::SUCCESS;
    }

    /**
     * Handle batch processing.
     */
    private function handleBatch(PlanComplianceV2Service $service): int
    {
        $fromId = $this->option('fromId');
        $toId = $this->option('toId');
        $limit = $this->option('limit');

        $query = DB::table('workouts')
            ->leftJoin('plan_compliance_v2', 'workouts.id', '=', 'plan_compliance_v2.workout_id')
            ->whereNull('plan_compliance_v2.workout_id')
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
            $this->info('No workouts found that need compliance v2 generated.');
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

        $this->info("✓ Completed. Generated compliance v2 for {$processed} workout(s).");

        return Command::SUCCESS;
    }
}

