<?php

declare(strict_types=1);

namespace Expo\Push\Result;

/**
 * Which endpoint an operation talks to.
 *
 * The retry rules differ: a repeated send can produce a duplicate notification,
 * a repeated receipt lookup cannot.
 */
enum OperationType: string
{
    case Send = 'send';

    case Receipts = 'receipts';
}
