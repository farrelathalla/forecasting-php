<?php
declare(strict_types=1);

/** Current tiers, SPC state, trend projections and recent transitions. */

[$config, $db] = require dirname(__DIR__) . '/bootstrap.php';

use Tbw\Repository\AlarmRepository;
use Tbw\Web\Json;

Json::endpoint(static function () use ($db): array {
    $alarms = new AlarmRepository($db);

    $states = $alarms->currentStates();
    $counts = ['ALARM' => 0, 'WARN' => 0, 'OK' => 0];
    foreach ($states as $row) {
        $tier = (string) $row['tier'];
        $counts[$tier] = ($counts[$tier] ?? 0) + 1;
    }

    return [
        'counts'      => $counts,
        'states'      => $states,
        'spc'         => $alarms->latestSpc(),
        'projections' => $alarms->latestProjections(),
        'events'      => $alarms->recentEvents(30),
    ];
});
