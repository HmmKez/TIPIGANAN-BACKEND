<?php

namespace App\Services;

use App\Models\Thesis;
use App\Support\SafeCache;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearchService
{
    // A healthy Meilisearch responds to /health in single-digit
    // milliseconds — 2 full seconds was only ever relevant to the failure
    // case, and on Windows a refused connection to "localhost" doesn't
    // always fail fast (IPv6 resolution can stall before falling back to
    // IPv4), so the old 2s timeout meant genuinely waiting close to the
    // full 2 seconds on the one request per 15s that pays this cost.
    // 0.5s is still generous for a real response and caps the worst case.
    protected float $timeout = 0.5;

    // $includeRestricted: restricted theses are visible to any logged-in
    // user but hidden from guests entirely — same rule as browsing.
    public function search(string $query, array $filters = [], bool $includeRestricted = false)
    {
        try {
            return $this->searchWithMeilisearch($query, $filters, $includeRestricted);
        } catch (Exception $e) {
            Log::warning('Meilisearch unavailable, falling back to database search.', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->searchWithDatabase($query, $filters, $includeRestricted);
        }
    }

    protected function searchWithMeilisearch(string $query, array $filters = [], bool $includeRestricted = false)
    {
        $this->pingMeilisearch();

        $statuses = $includeRestricted ? ['active', 'restricted'] : ['active'];

        $builder = Thesis::search($query)
            ->whereIn('status', $statuses)
            // Scout hydrates full models from the DB after Meilisearch
            // returns matching IDs — query() customizes that hydration
            // query, same views_count the Browse page relies on.
            ->query(fn($q) => $q->withCount(['readingHistory as views_count']));

        if (!empty($filters['category_id'])) {
            $builder->where('category_id', (int) $filters['category_id']);
        }

        if (!empty($filters['year_published'])) {
            $builder->where('year_published', (int) $filters['year_published']);
        }

        return $builder->paginate(12);
    }

    // Without caching, this health check runs on *every single search* —
    // when Meilisearch is offline that's a full 2-second timeout tax per
    // request before falling back to MySQL. Cache the result (both "up"
    // and "down") for a short window so only one request per 15 seconds
    // actually pays that cost; everyone else reads the cached verdict
    // from Redis in under a millisecond.
    protected function pingMeilisearch(): void
    {
        $isAvailable = SafeCache::remember('meilisearch:health', 15, function () {
            try {
                $response = Http::timeout($this->timeout)
                    ->get(config('scout.meilisearch.host') . '/health');

                return $response->successful() && $response->json('status') === 'available';
            } catch (\Throwable $e) {
                return false;
            }
        });

        if (!$isAvailable) {
            throw new Exception('Meilisearch health check failed.');
        }
    }

    protected function searchWithDatabase(string $query, array $filters = [], bool $includeRestricted = false)
    {
        $statuses = $includeRestricted ? ['active', 'restricted'] : ['active'];

        $builder = Thesis::query()
            ->withCount(['readingHistory as views_count'])
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($query) {
                $q->where('title',    'LIKE', "%{$query}%")
                  ->orWhere('authors',  'LIKE', "%{$query}%")
                  ->orWhere('adviser',  'LIKE', "%{$query}%")
                  ->orWhere('abstract', 'LIKE', "%{$query}%")
                  ->orWhere('keywords', 'LIKE', "%{$query}%");
            });

        if (!empty($filters['category_id'])) {
            $builder->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['year_published'])) {
            $builder->where('year_published', $filters['year_published']);
        }

        return $builder->paginate(12);
    }
}