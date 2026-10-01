<?php

namespace LatidoFlow\Laravel\Runtime;

enum DoctorCheckStatus: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Blocker = 'blocker';
}
