<?php

declare(strict_types=1);

namespace Semitexa\Storage;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'storage.files',
    summary: 'One file API over local disk and S3-compatible object stores.',
    useWhen: 'The application keeps files whose home should not be decided in the handler that writes them.',
    avoidWhen: 'The bytes are images needing variants - semitexa/media builds on this and adds that pipeline.',
    replaces: [
        'file_put_contents against a path assembled in a handler, then an S3 SDK call once the disk fills',
        'a driver switch written by hand in every place that touches a file',
    ],
    seeAlso: 'semitexa/media',
)]
final class Capabilities
{
}
