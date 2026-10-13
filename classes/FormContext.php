<?php

namespace CCTC\SourceDataVerificationModule\Classes;

use InvalidArgumentException;

/**
 * Identifies one form instance: record + event + instrument + instance. Instance is always >= 1
 * (REDCap stores instance 1 as NULL in the data table; this module always stores 1).
 */
class FormContext
{
    public function __construct(
        public readonly int $projectId,
        public readonly string $record,
        public readonly int $eventId,
        public readonly string $instrument,
        public readonly int $instance
    ) {}

    // Builds a context from untrusted input, checking the event and instrument belong to the project
    public static function fromInput(\Project $proj, $record, $eventId, $instrument, $instance): self
    {
        $record = trim((string)$record);
        $eventId = (int)$eventId;
        $instrument = (string)$instrument;
        $instance = max(1, (int)$instance);

        if ($record === '' || strlen($record) > 100) {
            throw new InvalidArgumentException('Invalid record');
        }
        if (!isset($proj->forms[$instrument])) {
            throw new InvalidArgumentException('Invalid instrument');
        }
        if (!isset($proj->eventInfo[$eventId])) {
            throw new InvalidArgumentException('Invalid event');
        }
        if (!in_array($instrument, $proj->eventsForms[$eventId] ?? [], true)) {
            throw new InvalidArgumentException('Instrument is not designated for this event');
        }
        if ($instance > 1 && !$proj->isRepeatingFormOrEvent($eventId, $instrument)) {
            throw new InvalidArgumentException('Invalid instance');
        }

        return new self((int)$proj->project_id, $record, $eventId, $instrument, $instance);
    }

    public function keyParams(): array
    {
        return [$this->projectId, $this->record, $this->eventId, $this->instrument, $this->instance];
    }

    public function toArray(): array
    {
        return [
            'record' => $this->record,
            'eventId' => $this->eventId,
            'instrument' => $this->instrument,
            'instance' => $this->instance,
        ];
    }
}
