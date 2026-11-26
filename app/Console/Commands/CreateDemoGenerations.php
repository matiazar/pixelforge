<?php

namespace App\Console\Commands;

use App\Exceptions\DailyLimitReached;
use App\Exceptions\InsufficientBalance;
use App\Models\User;
use App\Services\GenerationService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Example generations for the demo account, sent for real through the normal
 * flow (estimate, hold, dispatcher, webhook/polling). Run after db:seed, locally
 * or on the server. Fixed form tokens make a second run a no-op.
 */
class CreateDemoGenerations extends Command
{
    protected $signature = 'pixelforge:demo';

    protected $description = 'Queue the example generations for the demo account';

    /**
     * Cheapest first, so a short balance still gets some through.
     * "[fail]" and "[slow]" are the provider's magic words: one failure to show
     * the retry button, one slow run to show the live status.
     *
     * @var list<array{prompt: string, size: string, quality: string}>
     */
    public const SAMPLES = [
        ['prompt' => 'A lighthouse on a rocky coast at dawn, soft watercolor', 'size' => '1024x1024', 'quality' => 'low'],
        ['prompt' => 'Isometric cozy coffee shop interior, warm evening light', 'size' => '1536x1024', 'quality' => 'medium'],
        ['prompt' => 'Portrait of a red fox in a snowy forest, photorealistic', 'size' => '1024x1536', 'quality' => 'high'],
        ['prompt' => '[slow] A futuristic city skyline at night, neon reflections on wet streets', 'size' => '1024x1024', 'quality' => 'medium'],
        ['prompt' => '[fail] An origami crane on a wooden desk', 'size' => '1024x1024', 'quality' => 'low'],
    ];

    public function handle(GenerationService $generations): int
    {
        $email = (string) config('app.seed.demo_email');
        $demo = User::where('email', $email)->first();

        if ($demo === null) {
            $this->error("Demo account [{$email}] not found. Run php artisan db:seed first.");

            return self::FAILURE;
        }

        foreach (self::SAMPLES as $i => $sample) {
            try {
                $generation = $generations->create($demo, $sample + ['form_token' => 'demo-sample-'.$i]);
            } catch (InsufficientBalance|DailyLimitReached $e) {
                $this->error(($e instanceof InsufficientBalance ? 'Not enough balance. ' : '').$e->getMessage());

                return self::FAILURE;
            }

            $this->line("Queued {$generation->id}: {$sample['prompt']} (hold \$".Money::format($generation->estimate_max_nano).')');
        }

        $this->info('Done. The dispatcher sends them; watch them on the dashboard.');

        return self::SUCCESS;
    }
}
