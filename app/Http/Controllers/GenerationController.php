<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Services\GenerationService;
use Illuminate\Http\RedirectResponse;

class GenerationController extends Controller
{
    public function __construct(private GenerationService $generations) {}

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: array<int, string>, form_token: string} $data */
        $data = $request->validated();
        $generation = $this->generations->create($request->user(), $data);

        $status = $this->generations->waitsForSlot($generation)
            ? 'Your request is in the queue'
            : 'Your request was sent';

        return back()->with('status', $status);
    }
}
