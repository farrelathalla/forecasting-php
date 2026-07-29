<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\EarlyWarning\Spc;
use Tests\TestCase;

/**
 * SPC on physics ratios catches the plausible-looking fault: a signal that sits inside
 * its normal range while its relationship to the others has broken. Physics ratios have
 * a CV of a few percent, so a fixed threshold gives very few false positives.
 */
final class SpcTest extends TestCase
{
    private function spc(): Spc
    {
        return Spc::fromCsv(dirname(__DIR__, 2) . '/config/spc_limits.csv');
    }

    public function testLoadsTheFrozenLimitsFromTheHealthyWindow(): void
    {
        $limits = $this->spc()->limits();
        $this->assertTrue(isset($limits['dT|TBW1']));
        $this->assertTrue(isset($limits['flow_per_kW|TBW1']));
        $this->assertFloatEquals(-3.18462, $limits['dT|TBW1']['mu'], 1e-5);
        $this->assertFloatEquals(2.0253341, $limits['dT|TBW1']['sigma'], 1e-5);
    }

    public function testReproducesTheNotebookDriftForDischargeTemperature(): void
    {
        // F2 quantified. dT|TBW3 read +8.57 sigma in the notebook; the same value
        // through the same limits must give the same number here, or the two are not
        // talking about the same thing.
        $state = $this->spc()->evaluate('dT|TBW3', 6.675438);
        $this->assertFloatEquals(8.566775, $state['drift_sigma'], 1e-4);
        $this->assertSame('ALARM', $state['tier']);
    }

    public function testFlagsTheEfficiencyChannelAsWarn(): void
    {
        // flow_per_kW|TBW1 at -2.55 sigma is F2's predicted second stage arriving: the
        // thermal signal leads, the efficiency signal follows.
        $state = $this->spc()->evaluate('flow_per_kW|TBW1', 0.17992942);
        $this->assertFloatEquals(-2.5535238, $state['drift_sigma'], 1e-4);
        $this->assertSame('WARN', $state['tier']);
    }

    public function testHealthyChannelIsOk(): void
    {
        $state = $this->spc()->evaluate('P_over_I|TBW1', 0.9942685);
        $this->assertSame('OK', $state['tier']);
        $this->assertLessThan(1.0, abs($state['drift_sigma']));
    }

    public function testLimitsAreNeverRecomputedFromRunningData(): void
    {
        // The classic SPC mistake: if the baseline is refitted on live data, slow
        // degradation drags its own reference along and the alarm never fires.
        $spc = $this->spc();
        $before = $spc->limits()['dT|TBW1'];

        for ($i = 0; $i < 200; $i++) {
            $spc->evaluate('dT|TBW1', 50.0);
        }

        $after = $spc->limits()['dT|TBW1'];
        $this->assertFloatEquals($before['mu'], $after['mu'], 1e-12);
        $this->assertFloatEquals($before['sigma'], $after['sigma'], 1e-12);
    }

    public function testUnknownChannelReturnsNullRatherThanInventingLimits(): void
    {
        $this->assertNull($this->spc()->evaluate('nonsense|TBW9', 1.0));
    }

    public function testNullValueYieldsNoDrift(): void
    {
        $state = $this->spc()->evaluate('dT|TBW1', null);
        $this->assertNull($state['drift_sigma']);
        $this->assertSame('OK', $state['tier']);
    }

    public function testZeroSigmaDoesNotDivideByZero(): void
    {
        $spc = new Spc(['flat|TBW1' => ['mu' => 5.0, 'sigma' => 0.0, 'lcl' => 5.0, 'ucl' => 5.0]]);
        $state = $spc->evaluate('flat|TBW1', 7.0);
        $this->assertNull($state['drift_sigma']);
    }

    public function testNeverExpressesDriftAsPercentOfMean(): void
    {
        // dT's mean crosses zero, so any "% of mean" on it is meaningless. The API
        // exposes sigma units only, which is what makes it safe on this channel.
        $state = $this->spc()->evaluate('dT|TBW1', 6.3695965);
        $this->assertFalse(array_key_exists('drift_pct', $state));
        $this->assertTrue(array_key_exists('drift_sigma', $state));
    }

    public function testBreachedFlagFollowsTheControlLimits(): void
    {
        $spc = $this->spc();
        $this->assertTrue($spc->evaluate('dT|TBW3', 6.675438)['breached']);
        $this->assertFalse($spc->evaluate('P_over_I|TBW1', 0.9942685)['breached']);
    }

    public function testEvaluatesEveryConfiguredChannelInOnePass(): void
    {
        $result = $this->spc()->evaluateAll([
            'dT|TBW1'          => 6.3695965,
            'dT|TBW3'          => 6.675438,
            'flow_per_kW|TBW1' => 0.17992942,
            'P_over_I|TBW1'    => 0.9942685,
        ]);
        $this->assertCount(4, $result);
        $tiers = array_column($result, 'tier');
        $this->assertSame(2, count(array_filter($tiers, static fn(string $t) => $t === 'ALARM')));
    }
}
