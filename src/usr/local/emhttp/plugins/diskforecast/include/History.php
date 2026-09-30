<?php

declare(strict_types=1);

namespace DiskForecast;

/**
 * An ordered run of readings for one target.
 */
final class History
{
    /**
     * @param list<Sample> $samples Ascending by time.
     */
    private function __construct(private readonly array $samples)
    {
    }

    /**
     * Builds a history from readings in any order.
     *
     * @param list<Sample> $samples
     */
    public static function fromSamples(array $samples): self
    {
        usort($samples, static fn (Sample $a, Sample $b): int => $a->time <=> $b->time);

        return new self($samples);
    }

    /**
     * All readings, oldest first.
     *
     * @return list<Sample>
     */
    public function samples(): array
    {
        return $this->samples;
    }

    /**
     * Number of readings.
     */
    public function count(): int
    {
        return count($this->samples);
    }

    /**
     * The oldest reading, or null when empty.
     */
    public function first(): ?Sample
    {
        return $this->samples[0] ?? null;
    }

    /**
     * The newest reading, or null when empty.
     */
    public function latest(): ?Sample
    {
        return $this->samples === [] ? null : $this->samples[count($this->samples) - 1];
    }

    /**
     * Readings with a time in [from, to].
     */
    public function between(int $from, int $to): self
    {
        $start = $this->firstIndexAtOrAfter($from);
        $end = $this->firstIndexAtOrAfter($to + 1);

        return new self(array_slice($this->samples, $start, $end - $start));
    }

    /**
     * The newest reading at or before the time, or null when there is none.
     */
    public function atOrBefore(int $time): ?Sample
    {
        $index = $this->firstIndexAtOrAfter($time + 1) - 1;

        return $index >= 0 ? $this->samples[$index] : null;
    }

    /**
     * Reduces the readings to at most the given number of points by averaging
     * equal-width time buckets, so fits cost the same whatever the reading interval.
     */
    public function toPoints(int $maxPoints): Points
    {
        $first = $this->first();
        if ($first === null) {
            return new Points(0, [], []);
        }

        $origin = $first->time;
        if (count($this->samples) <= $maxPoints) {
            $times = [];
            $values = [];
            foreach ($this->samples as $sample) {
                $times[] = (float) ($sample->time - $origin);
                $values[] = (float) $sample->used;
            }

            return new Points($origin, $times, $values);
        }

        $width = ($this->latest()->time - $origin) / $maxPoints;
        $timeSums = array_fill(0, $maxPoints, 0.0);
        $valueSums = array_fill(0, $maxPoints, 0.0);
        $counts = array_fill(0, $maxPoints, 0);
        foreach ($this->samples as $sample) {
            $offset = $sample->time - $origin;
            $bucket = min($maxPoints - 1, (int) floor($offset / $width));
            $timeSums[$bucket] += $offset;
            $valueSums[$bucket] += $sample->used;
            $counts[$bucket]++;
        }

        $times = [];
        $values = [];
        for ($bucket = 0; $bucket < $maxPoints; $bucket++) {
            if ($counts[$bucket] > 0) {
                $times[] = $timeSums[$bucket] / $counts[$bucket];
                $values[] = $valueSums[$bucket] / $counts[$bucket];
            }
        }

        return new Points($origin, $times, $values);
    }

    private function firstIndexAtOrAfter(int $time): int
    {
        $low = 0;
        $high = count($this->samples);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->samples[$middle]->time < $time) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
