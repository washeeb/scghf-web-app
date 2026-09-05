<?php

declare(strict_types=1);

namespace App\Media\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to delete a file that is still referenced.
 *
 * Its own class rather than a plain RuntimeException so a Filament action can
 * catch exactly this and show the message as a notification, while any other
 * failure during a delete still surfaces as an error somebody investigates.
 *
 * The message is written for the person who clicked, and is safe to show them:
 * `MediaUsage::explain()` withholds the identifying detail of confidential uses
 * unless the asker is entitled to see it.
 */
class MediaInUse extends RuntimeException {}
