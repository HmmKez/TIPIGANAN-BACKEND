<?php

namespace App\Support\SchoolDirectory;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The real thing: TIPIGANAN asking the school's records system about one ID.
 *
 * NOT YET IN USE. It is written and wired so that the day the school's
 * developer provides an endpoint, going live is a matter of filling in .env and
 * setting SCHOOL_DIRECTORY_DRIVER=http - no controller, frontend, test or
 * database change.
 *
 * ============================================================================
 * WHEN THE SCHOOL'S API DETAILS ARRIVE, THIS IS THE ONLY FILE THAT CHANGES.
 * ============================================================================
 * Their response will almost certainly not use the field names guessed at in
 * map() below - it may not even be JSON. Translating whatever they actually
 * send into a DirectoryPerson is this class's entire job, and the reason that
 * translation lives in one place instead of being spread across the
 * controllers. Adjust map(), and everything else keeps working untouched.
 *
 * See the integration brief (PROJECT-STATUS #26) for the questions whose
 * answers determine what map() needs to look like.
 */
class HttpSchoolDirectory implements SchoolDirectory
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {
    }

    public function lookup(string $idNumber): ?DirectoryPerson
    {
        try {
            $request = Http::timeout($this->timeout)
                ->acceptJson()
                // Retry once on a connection-level failure: a single dropped
                // packet should not read as "the school is down" and refuse a
                // legitimate registration.
                ->retry(2, 200, throw: false);

            if ($this->apiKey) {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->get(rtrim($this->baseUrl, '/').'/'.urlencode($idNumber));
        } catch (Throwable $e) {
            // Never let the school's error text reach the user - it can carry
            // internal hostnames and stack traces.
            Log::warning('School directory unreachable', ['id_number' => $idNumber, 'error' => $e->getMessage()]);

            throw new DirectoryUnavailable('The school records system could not be reached.', 0, $e);
        }

        // A 404 is a real answer: no such ID. Anything else in the 4xx/5xx
        // range is a fault on one side or the other, and must NOT be reported
        // as "this person does not exist".
        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('School directory returned an error', [
                'id_number' => $idNumber,
                'status'    => $response->status(),
            ]);

            throw new DirectoryUnavailable('The school records system returned an error.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new DirectoryUnavailable('The school records system returned an unreadable response.');
        }

        return $this->map($idNumber, $payload);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Translate the school's payload into a DirectoryPerson.
     *
     * THE FIELD NAMES BELOW ARE A PLACEHOLDER GUESS. Replace them with whatever
     * the school actually sends. Some endpoints signal "not found" with a body
     * such as {"found": false} and a 200 status rather than a 404, which is why
     * that case is handled here as well as above.
     */
    private function map(string $idNumber, array $payload): ?DirectoryPerson
    {
        if (array_key_exists('found', $payload) && ! $payload['found']) {
            return null;
        }

        if (empty($payload['status']) || empty($payload['type'])) {
            throw new DirectoryUnavailable('The school records system omitted the status or type field.');
        }

        return new DirectoryPerson(
            idNumber:  $idNumber,
            status:    (string) $payload['status'],
            type:      (string) $payload['type'],
            fullName:  $payload['full_name']    ?? null,
            email:     $payload['school_email'] ?? null,
            program:   $payload['program']      ?? null,
            personKey: $payload['person_id']    ?? null,
        );
    }
}
