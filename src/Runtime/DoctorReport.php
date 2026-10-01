<?php

namespace LatidoFlow\Laravel\Runtime;

final readonly class DoctorReport
{
    /**
     * @param  list<DoctorCheck>  $checks
     */
    public function __construct(
        public array $checks,
        public string $evidenceInstruction,
    ) {}

    public function failed(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === DoctorCheckStatus::Blocker) {
                return true;
            }
        }

        return false;
    }
}
