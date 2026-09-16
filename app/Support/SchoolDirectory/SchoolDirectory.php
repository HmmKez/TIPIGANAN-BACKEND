<?php

namespace App\Support\SchoolDirectory;

/**
 * The seam between TIPIGANAN and the school's student/faculty records.
 *
 * Everything in the app is written against this interface and never against a
 * particular source, which is what makes the eventual switch from the fixture
 * to the school's real system a one-line configuration change rather than a
 * rewrite. See config/school.php for how an implementation gets chosen.
 */
interface SchoolDirectory
{
    /**
     * Look up one ID number.
     *
     * Returns null when the school has no such ID on record - that is a
     * definite "this person does not exist", not a failure.
     *
     * @throws DirectoryUnavailable when the source could not be reached or gave
     *         an answer that could not be understood. This is deliberately a
     *         different outcome from null: "we don't know" must never be
     *         treated as "no such person", or an outage would silently start
     *         rejecting legitimate students as though they were impostors.
     */
    public function lookup(string $idNumber): ?DirectoryPerson;

    /**
     * Is directory verification switched on at all?
     *
     * False for the null driver, which is how the system keeps behaving exactly
     * as it did before this feature existed.
     */
    public function isEnabled(): bool;
}
