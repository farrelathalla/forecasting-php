<?php
declare(strict_types=1);

/**
 * Operating limits for trend projection (notebook section 15.3).
 *
 * A drift extrapolated to one of these becomes a date, and a date can go on a work
 * order. An alarm light cannot.
 *
 * The dT limits come from the notebook's own threshold_projections.csv, which set them
 * at the upper control limit of the healthy window rather than at a manufacturer figure.
 * That is the honest choice while the plant has not supplied real trip settings — the
 * projection then answers "when does this leave the behaviour we know was healthy",
 * which is a defensible question, rather than pretending to know a failure threshold.
 *
 * REPLACE THESE with real operating limits once the plant confirms them.
 */
return [
    // Discharge temperature rise. The F2 degradation signature.
    'dT|TBW1' => 8.967384,
    'dT|TBW3' => 2.576902,

    // Efficiency proxy. F2 predicted the thermal signal would lead the efficiency
    // signal; flow_per_kW|TBW1 at -2.55 sigma is that prediction arriving, so a
    // lower limit is what matters here.
    'flow_per_kW|TBW1' => 0.17887406,
    'flow_per_kW|TBW3' => 0.13,

    // F4: the sawtooth counter. Projecting its level to the observed reset ceiling
    // estimates the next reset, which coincides with a stoppage of the same asset.
    'INLET_PRESS_level|TBW1' => 265.0,
    'INLET_PRESS_level|TBW3' => 265.0,
];
