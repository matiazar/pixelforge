<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuyCreditsRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    public function index(Request $request): Response
    {
        $movements = LedgerEntry::where('user_id', $request->user()->id)
            ->with('generation:id,request_payload')
            ->latest()
            ->latest('id')
            ->paginate(15);

        return Inertia::render('Credits', [
            'balance' => Money::format($request->user()->balance_nano),
            'packs' => config('pixelforge.credit_packs'),
            'movements' => LedgerEntryResource::collection($movements),
        ]);
    }

    public function store(BuyCreditsRequest $request, LedgerService $ledger): RedirectResponse
    {
        $amount = Money::fromDecimalString($request->string('pack')->toString());
        $ledger->topUp($request->user(), $amount);

        return back()->with('status', 'Added $'.Money::format($amount).' to your balance');
    }
}
