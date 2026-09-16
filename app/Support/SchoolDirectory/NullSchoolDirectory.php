<?php

namespace App\Support\SchoolDirectory;

/**
 * Verification switched off - the default.
 *
 * With this driver the app behaves exactly as it did before the school
 * directory existed: registration accepts whatever the person types and nobody
 * is checked against anything. That is what makes this feature safe to merge
 * before the school's system is available, and safe for teammates to pull
 * without any new setup.
 *
 * isEnabled() returning false is the signal every caller uses to skip
 * verification entirely, so lookup() should never actually be reached.
 */
class NullSchoolDirectory implements SchoolDirectory
{
    public function lookup(string $idNumber): ?DirectoryPerson
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
