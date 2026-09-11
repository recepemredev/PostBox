<?php

declare(strict_types=1);

use App\Console\Commands\EnsurePartitionsCommand;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work is registered here. The scheduler container is a singleton, so
 * anything defined here runs exactly once per interval across the whole stack.
 */

// The partition horizon is opened three months ahead by default, so daily is
// far more often than the drift it guards against ever requires.
// withoutOverlapping() is the Redis lock this needs against itself, in case a
// run ever takes longer than a day.
Schedule::command(EnsurePartitionsCommand::class)->daily()->withoutOverlapping();
