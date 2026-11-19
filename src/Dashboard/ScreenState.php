<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

enum ScreenState: string
{
    case Ready = 'ready';
    case Empty = 'empty';
    case RecordingDisabled = 'recording_disabled';
    case BufferUnavailable = 'buffer_unavailable';
    case NoConnection = 'no_connection';
    case StorageError = 'storage_error';
}
