<?php

namespace App\Support\SchoolDirectory;

use Illuminate\Support\Facades\File;

/**
 * A stand-in for the school's records, backed by a JSON fixture.
 *
 * This exists so the whole registration flow can be built, demonstrated and
 * tested before the school's developer provides anything - and it does not get
 * deleted afterwards, because the test suite must never call the school's real
 * system.
 *
 * The fixture deliberately contains the awkward cases, not just happy ones: a
 * graduate, someone on leave, a faculty member, an ID with a leading zero, and
 * a person with no email on record. Those are the paths that break in
 * production if nobody exercises them.
 *
 * Set SCHOOL_DIRECTORY_UNAVAILABLE=true to simulate the school's system being
 * down, which is the only practical way to test that path on demand.
 */
class FakeSchoolDirectory implements SchoolDirectory
{
    private ?array $people = null;

    public function __construct(
        private readonly string $fixturePath,
        private readonly bool $simulateOutage = false,
    ) {
    }

    public function lookup(string $idNumber): ?DirectoryPerson
    {
        if ($this->simulateOutage) {
            throw new DirectoryUnavailable('Simulated outage (SCHOOL_DIRECTORY_UNAVAILABLE=true).');
        }

        $record = $this->people()[$idNumber] ?? null;

        if (! $record) {
            return null;
        }

        return new DirectoryPerson(
            idNumber:  $idNumber,
            status:    $record['status'],
            type:      $record['type'],
            fullName:  $record['full_name']    ?? null,
            email:     $record['school_email'] ?? null,
            program:   $record['program']      ?? null,
            personKey: $record['person_id']    ?? null,
        );
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Load and index the fixture by ID number.
     *
     * Keys are forced to string so a JSON key of "00042" survives - PHP would
     * otherwise turn a purely numeric array key into an integer and quietly
     * drop the leading zero, which is exactly the bug this fixture is meant to
     * catch rather than cause.
     */
    private function people(): array
    {
        if ($this->people !== null) {
            return $this->people;
        }

        if (! File::exists($this->fixturePath)) {
            throw new DirectoryUnavailable("Directory fixture not found at {$this->fixturePath}.");
        }

        $decoded = json_decode(File::get($this->fixturePath), true);

        if (! is_array($decoded)) {
            throw new DirectoryUnavailable("Directory fixture at {$this->fixturePath} is not valid JSON.");
        }

        $indexed = [];
        foreach ($decoded as $record) {
            if (isset($record['id_number'])) {
                $indexed[(string) $record['id_number']] = $record;
            }
        }

        return $this->people = $indexed;
    }
}
