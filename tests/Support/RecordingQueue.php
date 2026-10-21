<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Queue\NullQueue;
use Illuminate\Support\Str;

/**
 * Stands in for the redis-long connection in tests, so a job built for it
 * never reaches Redis (CI has none). It goes through the real enqueue path,
 * so after-commit dispatch, unique locks and delays behave as in production,
 * and it remembers what was pushed for a test to look at.
 */
class RecordingQueue extends NullQueue
{
    /** @var list<array{job: mixed, queue: ?string, delay: mixed}> */
    public array $pushed = [];

    public function push($job, $data = '', $queue = null)
    {
        return $this->record($job, $data, $queue, null);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->record($job, $data, $queue, $delay);
    }

    private function record(mixed $job, mixed $data, ?string $queue, mixed $delay): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?? 'default', $data, $delay),
            $queue,
            $delay,
            function () use ($job, $queue, $delay): string {
                $this->pushed[] = ['job' => $job, 'queue' => $queue, 'delay' => $delay];

                return (string) Str::uuid();
            },
        );
    }
}
