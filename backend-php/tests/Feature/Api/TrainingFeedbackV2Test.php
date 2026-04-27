<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingFeedbackV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);

        User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_generate_and_question_feedback_v2_flow(): void
    {
        $workout = Workout::create([
            'user_id' => 1,
            'action' => 'save',
            'kind' => 'training',
            'summary' => [
                'startTimeIso' => '2026-04-20T10:00:00Z',
                'durationSec' => 1800,
                'distanceM' => 5000,
                'intensity' => 40,
                'paceEquality' => 0.82,
                'hrDrift' => 1.2,
            ],
            'source' => 'manual',
            'source_activity_id' => 'feedback-v2-test',
            'dedupe_key' => 'manual:feedback-v2-test',
        ]);

        $generate = $this->postJson("/api/training-feedback-v2/{$workout->id}/generate");
        $generate->assertOk();
        $generate->assertJsonStructure(['feedbackId', 'character', 'coachSignals', 'metrics']);
        $feedbackId = (int) $generate->json('feedbackId');

        $signals = $this->getJson("/api/training-feedback-v2/signals/{$workout->id}");
        $signals->assertOk();
        $signals->assertJsonStructure(['intensityClass', 'hrStable', 'economyFlag', 'loadImpact', 'warnings']);

        $question = $this->postJson("/api/training-feedback-v2/{$feedbackId}/question", [
            'question' => 'Jak oceniasz tętno?',
        ]);
        $question->assertOk();
        $question->assertJsonStructure(['answer']);
        $question->assertHeader('x-ai-cache', 'miss');
    }

    public function test_feedback_v2_uses_training_analysis_for_load_risk(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27T10:00:00Z'));

        try {
            foreach ([22, 15, 8] as $daysAgo) {
                Workout::create([
                    'user_id' => 1,
                    'action' => 'save',
                    'kind' => 'training',
                    'summary' => [
                        'startTimeIso' => Carbon::now()->subDays($daysAgo)->toIso8601String(),
                        'durationSec' => 300,
                        'distanceM' => 1000,
                        'sport' => 'run',
                        'intensity' => 999,
                    ],
                    'source' => 'manual',
                    'dedupe_key' => "feedback-v2-analysis-base-{$daysAgo}",
                ]);
            }

            for ($i = 4; $i >= 2; $i--) {
                Workout::create([
                    'user_id' => 1,
                    'action' => 'save',
                    'kind' => 'training',
                    'summary' => [
                        'startTimeIso' => Carbon::now()->subDays($i)->toIso8601String(),
                        'durationSec' => 1200,
                        'distanceM' => 3000,
                        'sport' => 'run',
                        'intensity' => 1,
                    ],
                    'source' => 'manual',
                    'dedupe_key' => "feedback-v2-analysis-current-{$i}",
                ]);
            }

            $workout = Workout::create([
                'user_id' => 1,
                'action' => 'save',
                'kind' => 'training',
                'summary' => [
                    'startTimeIso' => Carbon::now()->subDay()->toIso8601String(),
                    'durationSec' => 1200,
                    'distanceM' => 3000,
                    'sport' => 'run',
                    'intensity' => 1,
                    'paceEquality' => 0.82,
                    'hrDrift' => 1.0,
                ],
                'source' => 'manual',
                'source_activity_id' => 'feedback-v2-analysis-risk',
                'dedupe_key' => 'manual:feedback-v2-analysis-risk',
            ]);

            $generate = $this->postJson("/api/training-feedback-v2/{$workout->id}/generate");
            $generate->assertOk();
            $generate->assertJsonPath('metrics.weeklyLoadContribution', 20);
            $generate->assertJsonPath('metrics.spikeLoad', true);
            $generate->assertJsonPath('coachSignals.loadHeavy', true);

            $signals = $this->getJson("/api/training-feedback-v2/signals/{$workout->id}");
            $signals->assertOk();
            $signals->assertJsonPath('loadImpact', 'high');
            $signals->assertJsonPath('warnings.overloadRisk', true);
        } finally {
            Carbon::setTestNow();
        }
    }
}
