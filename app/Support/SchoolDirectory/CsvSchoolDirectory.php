<?php

namespace App\Support\SchoolDirectory;

use App\Support\SchoolRoster;

/**
 * The school's records as imported from a CSV (App\Support\SchoolRoster).
 *
 * Everyone on the list counts as current: the school exports who may use the
 * system, so being listed is the "enrolled / employed" answer and being absent
 * is the refusal. Never unavailable - the list lives in our own database.
 */
class CsvSchoolDirectory implements SchoolDirectory
{
    public function lookup(string $idNumber): ?DirectoryPerson
    {
        $row = SchoolRoster::find($idNumber);

        if (! $row) {
            return null;
        }

        return new DirectoryPerson(
            idNumber:  $row->id_number,
            status:    $row->role === 'student' ? 'enrolled' : 'employed',
            type:      $row->role,
            fullName:  trim($row->first_name.' '.($row->last_name ?? '')),
            givenName: $row->first_name,
        );
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
