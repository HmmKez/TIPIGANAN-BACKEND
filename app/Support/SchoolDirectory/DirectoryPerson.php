<?php

namespace App\Support\SchoolDirectory;

/**
 * One person as the school's records describe them.
 *
 * This is the only shape the rest of TIPIGANAN ever sees. Whatever the school's
 * system actually returns - JSON with different field names, a database row, a
 * line from a nightly CSV - gets translated into this object by whichever
 * SchoolDirectory implementation is in use. Nothing outside that translation
 * step should know or care what the source looked like.
 */
final class DirectoryPerson
{
    /**
     * Statuses that mean "this person is currently part of the school".
     * Anything else - graduated, resigned, dropped - means their access ends.
     */
    private const ACTIVE_STATUSES = ['enrolled', 'active', 'employed'];

    public function __construct(
        public readonly string $idNumber,
        public readonly string $status,
        public readonly string $type,
        public readonly ?string $fullName = null,
        public readonly ?string $email = null,
        public readonly ?string $program = null,
        /**
         * A value that identifies the PERSON rather than the ID number, and
         * never changes or gets reused. Null when the school cannot supply one,
         * in which case a reissued ID number has to be assumed to be a new
         * person. See Known Issue #24.
         */
        public readonly ?string $personKey = null,
    ) {
    }

    /** Is this person currently entitled to an account? */
    public function isActive(): bool
    {
        return in_array(strtolower($this->status), self::ACTIVE_STATUSES, true);
    }

    /**
     * The school describes people as students or faculty; TIPIGANAN's roles are
     * "student" and "teacher". Map here so the difference in vocabulary stops
     * at this boundary instead of leaking into the rest of the app.
     */
    public function appRole(): string
    {
        return in_array(strtolower($this->type), ['faculty', 'teacher', 'staff'], true)
            ? 'teacher'
            : 'student';
    }

    /**
     * Just the given name, for the greeting shown after an ID is verified.
     *
     * The verify endpoint returns this instead of the full name on purpose: it
     * keeps the "the system recognised me" moment while making the endpoint far
     * less useful to somebody enumerating ID numbers to harvest names.
     */
    public function firstName(): ?string
    {
        if (! $this->fullName) {
            return null;
        }

        // "Dela Cruz, Juan" (records order) vs "Juan Dela Cruz" (natural order).
        $name = str_contains($this->fullName, ',')
            ? trim(substr($this->fullName, strpos($this->fullName, ',') + 1))
            : trim($this->fullName);

        return explode(' ', $name)[0] ?: null;
    }
}
