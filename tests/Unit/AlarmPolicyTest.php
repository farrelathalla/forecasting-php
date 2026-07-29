<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\EarlyWarning\AlarmPolicy;
use Tbw\EarlyWarning\AlarmState;
use Tests\TestCase;

/**
 * Tiering matters as much as detection. Giving a sensor glitch and an imminent trip the
 * same severity trains operators to ignore both.
 */
final class AlarmPolicyTest extends TestCase
{
    private function policy(int $minConsecutive = 4): AlarmPolicy
    {
        return new AlarmPolicy(
            warnSigma: 3.0,
            alarmSigma: 4.0,
            minConsecutive: $minConsecutive,
            clearMarginSigma: 0.5,
        );
    }

    public function testMinConsecutiveIsActuallyEnforced(): void
    {
        // Roadmap E5. In the notebook AlarmPolicy.min_consecutive was defined and never
        // referenced by classify(), so the persistence requirement silently did nothing.
        // Three breaches must not raise the tier; the fourth must.
        $policy = $this->policy(4);
        $state = AlarmState::initial('dT|TBW1');

        for ($i = 0; $i < 3; $i++) {
            $state = $policy->classify($state, 5.0);
            $this->assertSame('OK', $state->tier, "raised the tier after " . ($i + 1) . " breach(es)");
        }

        $state = $policy->classify($state, 5.0);
        $this->assertSame('ALARM', $state->tier, 'the fourth consecutive breach must raise the tier');
    }

    public function testConsecutiveCounterResetsOnAQuietSample(): void
    {
        $policy = $this->policy(4);
        $state = AlarmState::initial('dT|TBW1');

        $state = $policy->classify($state, 5.0);
        $state = $policy->classify($state, 5.0);
        $state = $policy->classify($state, 5.0);
        $state = $policy->classify($state, 0.1);
        $this->assertSame(0, $state->consecutive);

        $state = $policy->classify($state, 5.0);
        $this->assertSame('OK', $state->tier, 'the count must start again after a quiet sample');
    }

    public function testWarnAndAlarmAreDistinctTiers(): void
    {
        $policy = $this->policy(1);
        $state = AlarmState::initial('flow_per_kW|TBW1');

        $this->assertSame('WARN', $policy->classify($state, 3.4)->tier);
        $this->assertSame('ALARM', $policy->classify($state, 4.6)->tier);
        $this->assertSame('OK', $policy->classify($state, 1.0)->tier);
    }

    public function testMagnitudeIsAbsoluteSoNegativeDriftAlarmsToo(): void
    {
        // flow_per_kW|TBW1 drifts negative (-2.55 sigma). A one-sided policy would
        // never see the efficiency channel move at all.
        $policy = $this->policy(1);
        $state = AlarmState::initial('flow_per_kW|TBW1');
        $this->assertSame('ALARM', $policy->classify($state, -4.5)->tier);
    }

    public function testRaisesImmediatelyOnceCountIsMetButClearsWithHysteresis(): void
    {
        // Tiers go up fast and come down slowly, so an alarm does not flicker on and off
        // around the threshold and get dismissed as noise.
        $policy = $this->policy(1);
        $state = AlarmState::initial('dT|TBW3');

        $state = $policy->classify($state, 4.5);
        $this->assertSame('ALARM', $state->tier);

        $state = $policy->classify($state, 3.8);
        $this->assertSame('ALARM', $state->tier, 'just below the threshold must not clear the alarm');

        $state = $policy->classify($state, 3.4);
        $this->assertSame('WARN', $state->tier, 'clearing needs the margin');
    }

    public function testTransitionIsReportedOnlyWhenTheTierChanges(): void
    {
        $policy = $this->policy(1);
        $state = AlarmState::initial('dT|TBW1');

        $state = $policy->classify($state, 4.5);
        $this->assertTrue($state->changed);
        $this->assertSame('OK', $state->previousTier);

        $state = $policy->classify($state, 4.6);
        $this->assertFalse($state->changed, 'a steady alarm must not re-fire every cycle');
    }

    public function testNullMagnitudeLeavesTheTierUntouched(): void
    {
        // A missing sample is not evidence of recovery. Silently clearing an alarm
        // because the data stopped arriving is the worst possible failure here.
        $policy = $this->policy(1);
        $state = AlarmState::initial('dT|TBW1');
        $state = $policy->classify($state, 4.5);

        $after = $policy->classify($state, null);
        $this->assertSame('ALARM', $after->tier);
        $this->assertFalse($after->changed);
    }

    public function testRecordsTheEvidenceThatTriggeredTheTier(): void
    {
        $policy = $this->policy(1);
        $state = $policy->classify(AlarmState::initial('dT|TBW3'), 8.57);
        $this->assertFloatEquals(8.57, $state->value, 1e-9);
        $this->assertFloatEquals(4.0, $state->threshold, 1e-9);
    }

    public function testSeverityOrderingIsUsableForSorting(): void
    {
        $this->assertGreaterThan(AlarmPolicy::severity('WARN'), AlarmPolicy::severity('ALARM'));
        $this->assertGreaterThan(AlarmPolicy::severity('OK'), AlarmPolicy::severity('WARN'));
    }

    public function testThresholdScalesWithADegradedForecaster(): void
    {
        // Section 15 measured it: the alarm rate is a function of the calibration of the
        // model being monitored. LGBM at cov80 68.4% gave 16.59% alarms; Chronos-2 at
        // 84.2% gave 7.78%, same detector, same folds. Under the naive fallback the
        // intervals are wider, so the CUSUM threshold moves with it rather than pretending
        // nothing changed.
        $this->assertLessThan(
            AlarmPolicy::cusumThreshold(false, 20.0, 12.0),
            AlarmPolicy::cusumThreshold(true, 20.0, 12.0)
        );
    }
}
