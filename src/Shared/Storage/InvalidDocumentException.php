<?php

declare(strict_types=1);

namespace App\Shared\Storage;

use RuntimeException;

/** The storage provider rejected the file contents (e.g. a corrupted PDF). */
final class InvalidDocumentException extends RuntimeException
{
}
