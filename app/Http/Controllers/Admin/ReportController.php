<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRequest;
use App\Services\ReportService;
use App\Support\Money;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(ReportRequest $request, ReportService $report): Response
    {
        $timezone = $request->timezone();
        $days = $report->byDay($request->from(), $request->to(), $timezone);
        $users = $report->byUser($request->from(), $request->to(), $timezone);

        return Inertia::render('admin/Report', [
            'timezone' => $timezone,
            'filters' => $request->filters(),
            'days' => array_map(fn (array $d) => ['date' => $d['date'], ...self::amounts($d)], $days),
            'users' => array_map(fn (array $u) => ['name' => $u['name'], 'email' => $u['email'], ...self::amounts($u)], $users),
            'totals' => self::amounts($report->totals($days)),
        ]);
    }

    /**
     * @param  array{generations: int, price_nano: int, cost_nano: int, profit_nano: int}  $row
     * @return array{generations: int, charged: string, cost: string, profit: string}
     */
    private static function amounts(array $row): array
    {
        return [
            'generations' => $row['generations'],
            'charged' => Money::format($row['price_nano']),
            'cost' => Money::format($row['cost_nano']),
            'profit' => Money::format($row['profit_nano']),
        ];
    }
}
