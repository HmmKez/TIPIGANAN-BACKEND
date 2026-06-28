<?php

namespace App\Services;

use App\Models\Thesis;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearchService
{
    protected int $timeout = 2;

    public function search(string $query, array $filters = [])
    {
        try {
            return $this->searchWithMeilisearch($query, $filters);
        } catch (Exception $e) {
            Log::warning('Meilisearch unavailable, falling back to database search.', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->searchWithDatabase($query, $filters);
        }
    }

    protected function searchWithMeilisearch(string $query, array $filters = [])
    {
        $this->pingMeilisearch();

        $builder = Thesis::search($query)
            ->where('status', 'active');

        if (!empty($filters['category_id'])) {
            $builder->where('category_id', (int) $filters['category_id']);
        }

        if (!empty($filters['year_published'])) {
            $builder->where('year_published', (int) $filters['year_published']);
        }

        return $builder->paginate(12);
    }

    protected function pingMeilisearch(): void
    {
        $response = Http::timeout($this->timeout)
            ->get(config('scout.meilisearch.host') . '/health');

        if (!$response->successful() || $response->json('status') !== 'available') {
            throw new Exception('Meilisearch health check failed.');
        }
    }

    protected function searchWithDatabase(string $query, array $filters = [])
    {
        $builder = Thesis::query()
            ->where('status', 'active')
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