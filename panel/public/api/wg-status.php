<?php

require __DIR__ . '/../../src/bootstrap.php';

use App\Http;
use App\WgMonitor;

// Только чтение; логин обеспечивает Http::guard.
Http::guard(['GET']);
Http::json(WgMonitor::summary());
