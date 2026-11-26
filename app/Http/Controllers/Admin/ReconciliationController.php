<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiThrottled;
use App\Exceptions\ProviderRejected;
use App\Exceptions\ProviderUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminRequest;
use App\Http\Requests\Admin\ReconciliationRequest;
use App\Jobs\DispatchPendingGenerations;
use App\Jobs\SyncOpenGenerations;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ReconciliationController extends Controller
{
    public function index(ReconciliationRequest $request, ReportService $report): Response
    {
        $rows = [];
        $error = null;

        try {
            $rows = array_map(fn (array $r) => [
                'date' => $r['date'],
                'our_generations' => $r['our_generations'],
                'provider_generations' => $r['provider_generations'],
                'our_cost' => Money::format($r['our_cost_nano']),
                'provider_cost' => Money::format($r['provider_cost_nano']),
                'difference' => Money::format($r['difference_nano']),
                'matches' => $r['difference_nano'] === 0 && $r['our_generations'] === $r['provider_generations'],
            ], $report->reconciliation($request->from(), $request->to()));
        } catch (ProviderRejected|ProviderUnavailable|ApiThrottled $e) {
            // Shown on the page; never rendered as an empty (matching) table.
            Log::warning('Reconciliation usage lookup failed', ['reason' => $e->getMessage()]);
            $error = 'Pixelforge usage could not be loaded. Try again in a moment.';
        } catch (InvalidArgumentException $e) {
            // An amount we cannot convert exactly: never compare a rounded value.
            report($e);
            $error = 'Pixelforge usage could not be read exactly. The admin was notified.';
        }

        return Inertia::render('admin/Reconciliation', [
            'filters' => $request->filters(),
            'rows' => $rows,
            'error' => $error,
            'open' => $report->openAtProvider(),
        ]);
    }

    /**
     * "Sync pending": a forced polling pass (also on generations polled seconds
     * ago) and a dispatcher pass for ambiguous sends. Both are queued: each can
     * take many provider round trips of up to the client timeout.
     */
    public function sync(AdminRequest $request): RedirectResponse
    {
        SyncOpenGenerations::dispatch(force: true);
        DispatchPendingGenerations::dispatch();

        return back()->with('status', 'Sync started: open generations and ambiguous sends are being checked with Pixelforge in the background.');
    }
}
