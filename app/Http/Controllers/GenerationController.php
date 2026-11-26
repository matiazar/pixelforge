<?php

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\RetryGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private GenerationService $generations) {}

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->withExists('retriedBy')
            ->latest()
            ->latest('id')
            ->paginate(10);

        return Inertia::render('Dashboard', [
            'generations' => GenerationResource::collection($generations),
            'options' => [
                'sizes' => PricingService::SIZES,
                'qualities' => PricingService::QUALITIES,
                'max_references' => PricingService::MAX_REFERENCES,
                'max_prompt' => StoreGenerationRequest::MAX_PROMPT,
            ],
        ]);
    }

    public function estimate(EstimateGenerationRequest $request, PricingService $pricing): JsonResponse
    {
        $draft = $request->draft();
        $estimate = $pricing->estimate($draft['prompt'], $draft['size'], $draft['quality'], $draft['reference_count'], $pricing->currentTable());

        return response()->json($estimate->formattedPrice());
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: array<int, string>, form_token: string} $data */
        $data = $request->validated();
        $generation = $this->generations->create($request->user(), $data);

        return back()->with('status', $this->statusMessage($generation));
    }

    public function retry(RetryGenerationRequest $request, Generation $generation): RedirectResponse
    {
        $retry = $this->generations->retry($request->user(), $generation);

        return back()->with('status', $this->statusMessage($retry));
    }

    private function statusMessage(Generation $generation): string
    {
        return $this->generations->waitsForSlot($generation)
            ? 'Your request is in the queue'
            : 'Your request was sent';
    }
}
