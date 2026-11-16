<?php

declare(strict_types=1);

namespace App\Services\LastFm;

/**
 * Result of an image download attempt.
 *
 *  - Stored: a real image was downloaded and cached.
 *  - Placeholder: Last.fm answered definitively that there is no image
 *    (404, its default star/cover, or a stub binary).
 *  - Failed: transient problem (blocked, timeout, challenge page, undecodable
 *    or unwritable image). Never recorded as a placeholder, so it is retried.
 */
enum ImageOutcome: string
{
    case Stored = 'stored';
    case Placeholder = 'placeholder';
    case Failed = 'failed';
}
