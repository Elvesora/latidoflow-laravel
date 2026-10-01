<?php

namespace LatidoFlow\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use LatidoFlow\Laravel\Runtime\ActivationDoctor;
use LatidoFlow\Laravel\Runtime\DoctorCheck;
use LatidoFlow\Laravel\Runtime\DoctorCheckStatus;

class DoctorCommand extends Command
{
    protected $signature = 'latidoflow:doctor
        {--skip-sync : Skip the mutating definition-sync authentication check}';

    protected $description = 'Diagnose LatidoFlow Laravel activation blockers without exposing secrets or runtime evidence';

    public function handle(Schedule $schedule, ActivationDoctor $doctor): int
    {
        $report = $doctor->diagnose($schedule, runSync: ! (bool) $this->option('skip-sync'));

        foreach ($report->checks as $check) {
            $this->renderCheck($check);
        }

        $this->newLine();
        $this->line($report->evidenceInstruction);

        return $report->failed() ? self::FAILURE : self::SUCCESS;
    }

    private function renderCheck(DoctorCheck $check): void
    {
        $suffix = $check->mutates ? ' (mutation)' : '';
        $message = "[{$check->status->value}] {$check->id}: {$check->summary}{$suffix}";

        match ($check->status) {
            DoctorCheckStatus::Pass => $this->components->info($message),
            DoctorCheckStatus::Warning => $this->components->warn($message),
            DoctorCheckStatus::Blocker => $this->components->error($message),
        };
    }
}
