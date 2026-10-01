<?php

namespace LatidoFlow\Laravel\Runtime;

final readonly class DoctorCheck
{
    public function __construct(
        public string $id,
        public DoctorCheckStatus $status,
        public string $summary,
        public bool $mutates = false,
    ) {}
}
