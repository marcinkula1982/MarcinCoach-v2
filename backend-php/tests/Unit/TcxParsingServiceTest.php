<?php

namespace Tests\Unit;

use App\Services\TcxParsingService;
use PHPUnit\Framework\TestCase;

class TcxParsingServiceTest extends TestCase
{
    private TcxParsingService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TcxParsingService();
    }

    private function runTcx(string $sport, string $time, int $distanceM, int $totalSec, string $trackpoints = ''): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="{$sport}">
      <Id>{$time}</Id>
      <Lap StartTime="{$time}">
        <TotalTimeSeconds>{$totalSec}</TotalTimeSeconds>
        <DistanceMeters>{$distanceM}</DistanceMeters>
        <Track>{$trackpoints}</Track>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>
XML;
    }

    private function tp(string $time, ?int $hr = null): string
    {
        $hrXml = $hr !== null
            ? "<HeartRateBpm><Value>{$hr}</Value></HeartRateBpm>"
            : '';
        return "<Trackpoint><Time>{$time}</Time>{$hrXml}</Trackpoint>";
    }

    public function test_parses_running_activity_and_computes_pace(): void
    {
        $xml = $this->runTcx('Running', '2026-04-20T10:00:00Z', 10000, 3000);
        $parsed = $this->parser->parse($xml);

        $this->assertSame('run', $parsed['sport']);
        $this->assertSame('2026-04-20T10:00:00Z', $parsed['startTimeIso']);
        $this->assertSame(10000, $parsed['distanceM']);
        $this->assertSame(3000, $parsed['durationSec']);
        $this->assertSame(300, $parsed['avgPaceSecPerKm']); // 3000s / 10km = 5:00/km
        $this->assertSame(1, $parsed['laps']);
    }

    public function test_pace_is_null_for_non_running_sport(): void
    {
        $xml = $this->runTcx('Biking', '2026-04-20T10:00:00Z', 20000, 3600);
        $parsed = $this->parser->parse($xml);

        $this->assertSame('bike', $parsed['sport']);
        $this->assertNull($parsed['avgPaceSecPerKm']);
    }

    public function test_sport_mapping_falls_back_to_other_for_unknown_sport(): void
    {
        $xml = $this->runTcx('Yoga', '2026-04-20T10:00:00Z', 4000, 3600);
        $parsed = $this->parser->parse($xml);
        $this->assertSame('other', $parsed['sport']);
    }

    public function test_maps_hiking_to_walk_hike(): void
    {
        $xml = $this->runTcx('Hiking', '2026-04-20T10:00:00Z', 4000, 3600);
        $parsed = $this->parser->parse($xml);
        $this->assertSame('walk_hike', $parsed['sport']);
    }

    public function test_relaxes_missing_distance_meters(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Swimming">
      <Id>2026-04-20T10:00:00Z</Id>
      <Lap StartTime="2026-04-20T10:00:00Z">
        <TotalTimeSeconds>1800</TotalTimeSeconds>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>
XML;
        $parsed = $this->parser->parse($xml);
        $this->assertSame('swim', $parsed['sport']);
        $this->assertSame(1800, $parsed['durationSec']);
        $this->assertSame(0, $parsed['distanceM']);
        $this->assertNull($parsed['avgPaceSecPerKm']);
    }

    public function test_missing_total_time_seconds_still_rejected(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Running">
      <Id>2026-04-20T10:00:00Z</Id>
      <Lap StartTime="2026-04-20T10:00:00Z">
        <DistanceMeters>5000</DistanceMeters>
      </Lap>
    </Activity>
  </Activities>
</TrainingCenterDatabase>
XML;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TotalTimeSeconds/');
        $this->parser->parse($xml);
    }

    public function test_missing_laps_rejected(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
  <Activities>
    <Activity Sport="Running">
      <Id>2026-04-20T10:00:00Z</Id>
    </Activity>
  </Activities>
</TrainingCenterDatabase>
XML;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Lap/');
        $this->parser->parse($xml);
    }

    public function test_malformed_xml_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('<garbage');
    }

    public function test_computes_hr_stats_from_trackpoints(): void
    {
        $tps = $this->tp('2026-04-20T10:00:00Z', 120)
             . $this->tp('2026-04-20T10:00:10Z', 140)
             . $this->tp('2026-04-20T10:00:20Z', 160);
        $xml = $this->runTcx('Running', '2026-04-20T10:00:00Z', 1000, 300, $tps);
        $parsed = $this->parser->parse($xml);

        $this->assertSame(140, $parsed['hr']['avgBpm']);
        $this->assertSame(160, $parsed['hr']['maxBpm']);
    }

    public function test_computes_deeper_trackpoint_metrics(): void
    {
        $tps = <<<XML
<Trackpoint>
  <Time>2026-04-20T10:00:00Z</Time>
  <DistanceMeters>0</DistanceMeters>
  <AltitudeMeters>100</AltitudeMeters>
  <Cadence>80</Cadence>
  <Extensions><TPX xmlns="http://www.garmin.com/xmlschemas/ActivityExtension/v2"><Watts>200</Watts></TPX></Extensions>
</Trackpoint>
<Trackpoint>
  <Time>2026-04-20T10:01:00Z</Time>
  <DistanceMeters>200</DistanceMeters>
  <AltitudeMeters>112</AltitudeMeters>
  <Cadence>82</Cadence>
  <Extensions><TPX xmlns="http://www.garmin.com/xmlschemas/ActivityExtension/v2"><Watts>220</Watts></TPX></Extensions>
</Trackpoint>
<Trackpoint>
  <Time>2026-04-20T10:02:00Z</Time>
  <DistanceMeters>400</DistanceMeters>
  <AltitudeMeters>106</AltitudeMeters>
  <Cadence>84</Cadence>
  <Extensions><TPX xmlns="http://www.garmin.com/xmlschemas/ActivityExtension/v2"><Watts>240</Watts></TPX></Extensions>
</Trackpoint>
XML;
        $xml = $this->runTcx('Running', '2026-04-20T10:00:00Z', 400, 120, $tps);
        $parsed = $this->parser->parse($xml);

        $this->assertSame(120, $parsed['elapsedTimeSec']);
        $this->assertSame(120, $parsed['movingTimeSec']);
        $this->assertSame(82, $parsed['cadence']['avgSpm']);
        $this->assertSame(84, $parsed['cadence']['maxSpm']);
        $this->assertSame(220, $parsed['power']['avgWatts']);
        $this->assertSame(240, $parsed['power']['maxWatts']);
        $this->assertSame(12.0, $parsed['elevationGainMeters']);
        $this->assertSame(6.0, $parsed['elevationLossMeters']);
        $this->assertTrue($parsed['dataAvailability']['cadence']);
        $this->assertTrue($parsed['dataAvailability']['power']);
        $this->assertTrue($parsed['dataAvailability']['elevation']);
        $this->assertSame('estimated', $parsed['paceZones']['status']);
    }

    public function test_computes_hr_time_in_zone_when_zones_provided(): void
    {
        $tps = $this->tp('2026-04-20T10:00:00Z', 100)  // z1
             . $this->tp('2026-04-20T10:00:10Z', 100)  // z1 (10s spent in z1)
             . $this->tp('2026-04-20T10:00:20Z', 160)  // z4 (10s spent in z1 because we use cur hr for the interval)
             . $this->tp('2026-04-20T10:00:30Z', 160); // z4 (10s spent in z4)
        $xml = $this->runTcx('Running', '2026-04-20T10:00:00Z', 500, 30, $tps);

        $hrZones = [
            'z1' => ['min' => 80, 'max' => 130],
            'z2' => ['min' => 130, 'max' => 145],
            'z3' => ['min' => 145, 'max' => 155],
            'z4' => ['min' => 155, 'max' => 165],
            'z5' => ['min' => 165, 'max' => 200],
        ];

        $parsed = $this->parser->parse($xml, $hrZones);

        // Sample interpretation: for each consecutive pair (i, i+1), bucket the dt=10s
        // according to HR at i. That gives z1=10+10, z4=10.
        $this->assertGreaterThan(0, $parsed['intensityBuckets']['z1Sec']);
        $this->assertGreaterThan(0, $parsed['intensityBuckets']['z4Sec']);
        $this->assertSame(30, $parsed['intensityBuckets']['totalSec']);
    }

    public function test_pace_based_bucket_fallback_when_no_hr(): void
    {
        $xml = $this->runTcx('Running', '2026-04-20T10:00:00Z', 10000, 3000); // 5:00/km → Z2
        $parsed = $this->parser->parse($xml);

        $this->assertSame(3000, $parsed['intensityBuckets']['z2Sec']);
        $this->assertSame(3000, $parsed['intensityBuckets']['totalSec']);
    }

    public function test_lumped_fallback_for_non_run_without_hr(): void
    {
        $xml = $this->runTcx('Swimming', '2026-04-20T10:00:00Z', 0, 1200);
        $parsed = $this->parser->parse($xml);

        $this->assertSame(0, $parsed['intensityBuckets']['z1Sec']);
        $this->assertSame(0, $parsed['intensityBuckets']['z5Sec']);
        $this->assertSame(1200, $parsed['intensityBuckets']['totalSec']);
    }

    public function test_parse_heart_rate_trackpoints_returns_only_valid_hr(): void
    {
        $tps = $this->tp('2026-04-20T10:00:00Z', 120)
             . $this->tp('2026-04-20T10:00:05Z')
             . $this->tp('2026-04-20T10:00:10Z', 1000); // out of range → dropped
        $xml = $this->runTcx('Running', '2026-04-20T10:00:00Z', 500, 30, $tps);
        $points = $this->parser->parseHeartRateTrackpoints($xml);

        $this->assertCount(1, $points);
        $this->assertSame(120, $points[0]['hr']);
    }

    public function test_parse_heart_rate_trackpoints_empty_on_malformed(): void
    {
        $this->assertSame([], $this->parser->parseHeartRateTrackpoints('<garbage'));
    }
}
