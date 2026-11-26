<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use stdClass;

/**
 * Admin numbers. Everything is integer nano-USD; formatting is the caller's job.
 *
 * Only charged generations count (price_nano set): a failed one costs and
 * charges nothing. Days are grouped by completed_at.
 */
class ReportService
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * Charged, provider cost and profit per day, days in $timezone.
     * $from and $to are calendar dates in that timezone, both inclusive.
     *
     * @return list<array{date: string, generations: int, price_nano: int, cost_nano: int, profit_nano: int}>
     */
    public function byDay(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $rows = $this->charged($from, $to, $timezone)
            ->selectRaw("to_char((completed_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day", [$timezone])
            ->selectRaw('count(*) as generations, sum(price_nano) as price, sum(cost_nano) as cost')
            ->groupBy('day')
            ->orderBy('day')
            ->toBase()
            ->get();

        return array_values($rows->map(fn (stdClass $row) => [
            'date' => (string) $row->day,
            ...$this->rowTotals($row),
        ])->all());
    }

    /**
     * Same numbers per user, highest charged first.
     *
     * @return list<array{user_id: int, name: string, email: string, generations: int, price_nano: int, cost_nano: int, profit_nano: int}>
     */
    public function byUser(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $rows = $this->charged($from, $to, $timezone)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->select('users.id as user_id', 'users.name', 'users.email')
            ->selectRaw('count(*) as generations, sum(generations.price_nano) as price, sum(generations.cost_nano) as cost')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('price')
            ->orderBy('users.id')
            ->toBase()
            ->get();

        return array_values($rows->map(fn (stdClass $row) => [
            'user_id' => (int) $row->user_id,
            'name' => (string) $row->name,
            'email' => (string) $row->email,
            ...$this->rowTotals($row),
        ])->all());
    }

    /**
     * Our provider cost vs GET /v1/usage, per UTC day, for the current API key
     * only (the usage endpoint is per key). Exact comparison in nano-USD.
     * Provider errors propagate: a failed lookup must never look like a match.
     *
     * @return list<array{date: string, our_generations: int, provider_generations: int, our_cost_nano: int, provider_cost_nano: int, difference_nano: int}>
     */
    public function reconciliation(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->setTimezone('UTC')->startOfDay();
        $to = $to->setTimezone('UTC')->startOfDay();

        $usage = $this->client->usage($from->toDateString(), $to->toDateString());

        $provider = [];
        foreach ((array) ($usage['data'] ?? []) as $day) {
            $provider[(string) $day['date']] = [
                'generations' => (int) ($day['generations'] ?? 0),
                'cost' => Money::fromDecimalString((string) $day['cost_usd']),
            ];
        }

        $ours = Generation::query()
            ->where('api_key_fingerprint', $this->client->apiKeyFingerprint())
            ->whereNotNull('cost_nano')
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $to->addDay())
            ->selectRaw("to_char(completed_at, 'YYYY-MM-DD') as day, count(*) as generations, sum(cost_nano) as cost")
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');

        $rows = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $ourCost = (int) ($ours[$date]->cost ?? 0);
            $providerCost = $provider[$date]['cost'] ?? 0;

            $rows[] = [
                'date' => $date,
                'our_generations' => (int) ($ours[$date]->generations ?? 0),
                'provider_generations' => $provider[$date]['generations'] ?? 0,
                'our_cost_nano' => $ourCost,
                'provider_cost_nano' => $providerCost,
                'difference_nano' => $ourCost - $providerCost,
            ];
        }

        return $rows;
    }

    /**
     * Sent with the current key and not final yet: accepted (queued, processing)
     * or still pending after an ambiguous send, which may already exist and be
     * billed at the provider. What "Sync pending" can still change.
     */
    public function openAtProvider(): int
    {
        return Generation::query()
            ->where('api_key_fingerprint', $this->client->apiKeyFingerprint())
            ->where(fn (Builder $q) => $q
                ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
                ->orWhere(fn (Builder $q) => $q->where('status', GenerationStatus::Pending)->where('ambiguous', true)))
            ->count();
    }

    /**
     * Sum of byDay() or byUser() rows.
     *
     * @param  list<array{generations: int, price_nano: int, cost_nano: int, profit_nano: int}>  $rows
     * @return array{generations: int, price_nano: int, cost_nano: int, profit_nano: int}
     */
    public function totals(array $rows): array
    {
        return [
            'generations' => array_sum(array_column($rows, 'generations')),
            'price_nano' => array_sum(array_column($rows, 'price_nano')),
            'cost_nano' => array_sum(array_column($rows, 'cost_nano')),
            'profit_nano' => array_sum(array_column($rows, 'profit_nano')),
        ];
    }

    /**
     * @return Builder<Generation>
     */
    private function charged(CarbonImmutable $from, CarbonImmutable $to, string $timezone): Builder
    {
        // Calendar dates in $timezone -> half-open UTC range on the completed_at index.
        $start = CarbonImmutable::parse($from->toDateString(), $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to->toDateString(), $timezone)->addDay()->startOfDay()->utc();

        return Generation::query()
            ->where('generations.status', GenerationStatus::Succeeded)
            ->whereNotNull('generations.price_nano')
            ->where('generations.completed_at', '>=', $start)
            ->where('generations.completed_at', '<', $end);
    }

    /**
     * @return array{generations: int, price_nano: int, cost_nano: int, profit_nano: int}
     */
    private function rowTotals(stdClass $row): array
    {
        $price = (int) $row->price;
        $cost = (int) $row->cost;

        return [
            'generations' => (int) $row->generations,
            'price_nano' => $price,
            'cost_nano' => $cost,
            'profit_nano' => $price - $cost,
        ];
    }
}
