<?php

namespace App\Support\SchoolDirectory;

use RuntimeException;

/**
 * The school's records could not be reached, or answered in a way that could
 * not be understood.
 *
 * Kept strictly separate from a null lookup result. Null means "the school says
 * there is no such ID"; this means "we do not know". Collapsing the two would
 * turn an outage on their side into a wave of legitimate students being told
 * their ID does not exist, which is both wrong and impossible to debug from a
 * support ticket.
 */
class DirectoryUnavailable extends RuntimeException
{
}
