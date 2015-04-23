#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';
spl_autoload_register( 'ezcBase::autoload' );

require_once 'chart_palette.php';
require_once 'line_chart.php';
require_once 'common.php';

$skipPattern = '~(/out/example|media\.example\.com|cdn\.example\.com)/~';

$aggregations = array(
    array('name' => 'checkout',     'use-case' => '~UseCase: Checkout~',     'uri' => '(.*)'),
    array('name' => 'noise',        'use-case' => '~UseCase: Noise~',        'uri' => '(.*)'),
    array('name' => 'search',       'use-case' => '~UseCase: Search~',       'uri' => '(.*)'),
    array('name' => 'registration', 'use-case' => '~UseCase: Registration~', 'uri' => '(.*)'),
    array('name' => '$customer',    'use-case' => '~\(Customer\)~',          'uri' => '(.*)'),
    array('name' => '$anonymous',   'use-case' => '~\(Anonymous\)~',         'uri' => '(.*)'),
);

$exportCharts = array(
    'Response time by UseCase (90%)' => array(
        'fields' => array('90%' => '90%Time'),
        'y-axis' => 'Seconds'
    ),
);

function parseJmeterLog( $logFile, $resolution = 10 )
{
    global $skipPattern, $aggregations;

    $log = array();
    $urlTimes = array();

    $doc = new XMLReader();
    $doc->open( $logFile );

    while ( $doc->read() )
    {
        if ( ( $doc->nodeType !== XMLReader::ELEMENT ) ||
             ( $doc->name !== 'httpSample' ) )
        {
            continue;
        }

        if ( $doc->hasAttributes )
        {
            $attributes = array();
            while( $doc->moveToNextAttribute() )
            {
                $attributes[$doc->name] = $doc->value;
            }

            /* Skip images and resources for evaluation */
            if (preg_match($skipPattern, $attributes['lb'])) {
                continue;
            }

            /* Skip all samples starting with https?, because these are just sub samples */
            if (preg_match('(^https?://)', $attributes['lb'])) {
                continue;
            }

            $rawUrl = $attributes['lb'];
            $normalizedUrl = normalizeUrl($rawUrl);

            $data = array(
                'ts'           => $attributes['ts'],
                'rawUrl'       => $rawUrl,
                'normalizedUrl' => $normalizedUrl,
                'useCase'      => $attributes['tn'],
                'responseCode' => $attributes['rc'],
                'success'      => $attributes['s'] === 'true',
                'time'         => (int) $attributes['t'],
            );

            $logTime = floor( $data['ts'] / ( 1000 * $resolution ) ) * $resolution;

            if ( !isset( $log[$logTime] ) )
            {
                $log[$logTime] = array(
                    'failures'      => 0,
                    'averageTime'   => 0,
                    'minTime'       => $data['time'],
                    'maxTime'       => $data['time'],
                    'medTime'       => array(),
                    '90%Time'       => array(),
                    '20x'           => 0,
                    '30x'           => 0,
                    '40x'           => 0,
                    '50x'           => 0,
                    'requests'      => 0,
                    '_aggregations' => array()
                );

                foreach ($aggregations as $aggregation) {
                    $log[$logTime]['_aggregations'][$aggregation['name']] = array(
                        'failures'      => 0,
                        'averageTime'   => 0,
                        'minTime'       => 0,
                        'maxTime'       => 0,
                        'medTime'       => array(),
                        '90%Time'       => array(),
                        '20x'           => 0,
                        '30x'           => 0,
                        '40x'           => 0,
                        '50x'           => 0,
                        'requests'      => 0,
                    );
                }
            }

            if (strpos($normalizedUrl, 'Error:') === false) {
                if (!isset($urlTimes[$normalizedUrl][$logTime])) {
                    $urlTimes[$normalizedUrl][$logTime] = array();
                }

                $urlTimes[$normalizedUrl][$logTime][] = $data['time'];
            }

            $log[$logTime]['failures']    += $data['success'] ? 0 : 1;
            $log[$logTime]['20x']         += '2' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime]['30x']         += '3' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime]['40x']         += '4' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime]['50x']         += '5' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime]['averageTime'] += $data['time'];
            $log[$logTime]['minTime']      = min( $log[$logTime]['minTime'], $data['time'] );
            $log[$logTime]['maxTime']      = max( $log[$logTime]['maxTime'], $data['time'] );
            $log[$logTime]['medTime'][]    = $data['time'];
            $log[$logTime]['90%Time'][]    = $data['time'];

            ++$log[$logTime]['requests'];

            foreach ($aggregations as $aggregation) {
                $name = $aggregation['name'];

                if (0 === preg_match($aggregation['use-case'], $data['useCase'])) {
                    continue;
                }
                if (0 === preg_match($aggregation['uri'], $data['rawUrl'])) {
                    continue;
                }

                $log[$logTime]['_aggregations'][$aggregation['name']]['failures']    += $data['success'] ? 0 : 1;
                $log[$logTime]['_aggregations'][$aggregation['name']]['20x']         += '2' == $data['responseCode'][0] ? 1 : 0;
                $log[$logTime]['_aggregations'][$aggregation['name']]['30x']         += '3' == $data['responseCode'][0] ? 1 : 0;
                $log[$logTime]['_aggregations'][$aggregation['name']]['40x']         += '4' == $data['responseCode'][0] ? 1 : 0;
                $log[$logTime]['_aggregations'][$aggregation['name']]['50x']         += '5' == $data['responseCode'][0] ? 1 : 0;
                $log[$logTime]['_aggregations'][$aggregation['name']]['averageTime'] += $data['time'];
                $log[$logTime]['_aggregations'][$aggregation['name']]['minTime']      = min( $log[$logTime]['_aggregations'][$aggregation['name']]['minTime'], $data['time'] );
                $log[$logTime]['_aggregations'][$aggregation['name']]['maxTime']      = max( $log[$logTime]['_aggregations'][$aggregation['name']]['maxTime'], $data['time'] );
                $log[$logTime]['_aggregations'][$aggregation['name']]['medTime'][]    = $data['time'];
                $log[$logTime]['_aggregations'][$aggregation['name']]['90%Time'][]    = $data['time'];

                ++$log[$logTime]['_aggregations'][$aggregation['name']]['requests'];
            }
        }
    }

    foreach ( $log as $time => $data )
    {
        $log[$time]['averageTime'] /= $data['requests'];
        $log[$time]['medTime']      = averageOfPercent($log[$time]['medTime'], 0.5);
        $log[$time]['90%Time']      = averageOfPercent($log[$time]['90%Time'], 0.9);

        foreach (array_keys($log[$time]['_aggregations']) as $name) {
            $log[$time]['_aggregations'][$name]['medTime'] = averageOfPercent($log[$time]['_aggregations'][$name]['medTime'], 0.5);
            $log[$time]['_aggregations'][$name]['90%Time'] = averageOfPercent($log[$time]['_aggregations'][$name]['90%Time'], 0.9);

            if (0 == $log[$time]['_aggregations'][$name]['requests']) {
                continue;
            }
            $log[$time]['_aggregations'][$name]['averageTime'] /= $log[$time]['_aggregations'][$name]['requests'];
        }
    }

    foreach ($urlTimes as $url => $times) {
        foreach ($times as $time => $data) {
            if (count($data) > 0) {
                $urlTimes[$url][$time] = array(
                    'requests' => count($data),
                    'averageTime' => array_sum($data) / count($data),
                    'medTime' => averageOfPercent($data, 0.5),
                    '90%Time' => averageOfPercent($data, 0.9)
                );
            } else {
                $urlTimes[$url][$time] = array('averageTime' => 0, 'requests' => 0, 'medTime' => 0, '90%Time' => 0);
            }
        }
    }

    return array('log' => $log, 'urls' => $urlTimes);
}

################################################################################
#
#   CLI CODE STARTS HERE
#
################################################################################

$timestamp = date('Y-m-d\TH:i:s');
$inputDir = __DIR__ . '/../build';
$outputDir = $inputDir . '/graphs/' . $timestamp;
$testName = $timestamp . '_JMeter-Result';
$chartResolution = 60;

for ($i = 1; $i < $argc; ++$i) {

    switch ($argv[$i]) {
        case '-r':
        case '--resolution':
            $chartResolution = (int) $argv[++$i];
            break;

        case '-t':
        case '--testname':
            $testName = $argv[++$i];
            break;

        case '-o':
        case '--output':
            $outputDir = $argv[++$i];
            break;

        default:
            $inputDir = $argv[$i];
            break;
    }

}

/* Ensure one, but only one jmeter test log exists */
$jmeterLog = glob("{$inputDir}/*.jtl");
if (0 === count($jmeterLog)) {
    fwrite(STDERR, "Cannot find *.jtl test log in data directory '{$inputDir}'.\n");
    exit(42);
}
if (1 !== count($jmeterLog)) {
    fwrite(STDERR, "Cannot find unique *.jtl test log in data directory '{$inputDir}'.\n");
    exit(42);
}
$jmeterLog = $jmeterLog[0];

/* Ensure output directory exists */
if (false === file_exists($outputDir)) {
    mkdir($outputDir, 0755, true);
}
if (false === is_dir($outputDir)) {
    fwrite(STDERR, "Output directory '{$outputDir}' does not exist.\n");
    exit(23);
}
/* Remove old artifacts */
foreach (glob("{$outputDir}/*.svg") as $graphFile) {
    unlink($graphFile);
}

$graphName = $outputDir . '/' . $testName;

echo "Generating graphs for $testName...\n";

$result = parseJmeterLog( $jmeterLog, $chartResolution );
$data = $result['log'];
array_pop($data);

try {
    $chart = new LineChart( 'Response time' );
    $chart->data['Minimum'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['minTime'] / 1000;
            },
            $data
        )
    );
    $chart->data['Average'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['averageTime'] / 1000;
            },
            $data
        )
    );
    $chart->data['Maximum'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['maxTime'] / 1000;
            },
            $data
        )
    );
    $chart->data['Median'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['medTime'] / 1000;
            },
            $data
        )
    );
    $chart->data['90%'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['90%Time'] / 1000;
            },
            $data
        )
    );

    $chart->yAxis->label = 'Seconds';

    $chart->render( 500, 300, $graphName . '_response_time.svg' );
} catch ( Exception $e ) { /* Ignore */ }

foreach ($result['urls'] as $normalizedUrl => $urlData) {
    try {
        $chart = new LineChart( 'Response time: ' . $normalizedUrl );
        $chart->data['Average'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['averageTime'] / 1000;
                },
                $urlData
            )
        );
        $chart->data['Median'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['medTime'] / 1000;
                },
                $urlData
            )
        );
        $chart->data['90%'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['90%Time'] / 1000;
                },
                $urlData
            )
        );
        $chart->data['Requests'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['requests'];
                },
                $urlData
            )
        );

        // Use a different axis for the norwegian dataset
        $chart->additionalAxis['requests'] = $nAxis = new ezcGraphChartElementNumericAxis();
        $nAxis->position = ezcGraph::BOTTOM;
        $nAxis->chartPosition = 1;
        $nAxis->min = 0;
        $nAxis->label = 'r/5m';
        $chart->options->lineThickness = 1;

        $chart->data['Requests']->yAxis = $nAxis;

        $chart->yAxis->label = 'Seconds';

        $chart->render( 500, 300, $graphName . '_response_time_' . slugize($normalizedUrl) . '.svg' );
    } catch ( Exception $e ) { /* Ignore */ }
}

foreach ($exportCharts as $exportName => $exportChart) {
    try {
        $chart = new LineChart($exportName);

        foreach ($aggregations as $aggregation) {
            $aggregationName = $aggregation['name'];
            foreach ($exportChart['fields'] as $exportLabel => $exportField) {
                $chart->data[$aggregationName] = new ezcGraphArrayDataSet(
                    array_map(
                        function($value) use ($aggregationName, $exportField) {
                            return $value['_aggregations'][$aggregationName][$exportField] / 1000;
                        },
                        $data
                    )
                );
            }
        }

        $chart->yAxis->label = $exportChart['y-axis'];

        $chart->render(
            500,
            300,
            sprintf(
                '%s_%s.svg',
                $graphName,
                preg_replace(
                    array('(\s+)', '([^a-z0-9_\-]+)'),
                    '_',
                    strtolower($exportName)
                )
            )
        );
    } catch ( Exception $e ) { /* Ignore */ }
}

try {
    $chart = new LineChart( 'Response time (no extremes)' );
    $chart->data['Average'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['averageTime'] / 1000;
            },
            $data
        )
    );
    $chart->data['Median'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['medTime'] / 1000;
            },
            $data
        )
    );
    $chart->data['90%'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['90%Time'] / 1000;
            },
            $data
        )
    );

    $chart->yAxis->label = 'Seconds';

    $chart->render( 500, 300, $graphName . '_response_time_(no_extremes).svg' );
} catch ( Exception $e ) { /* Ignore */ }

try {
    $chart = new LineChart( 'Requests per second' );
    $chart->data['Average'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value ) use ( $chartResolution )
            {
                return $value['requests'] / $chartResolution;
            },
            $data
        )
    );

    $chart->yAxis->label = 'R/s';

    $chart->render( 500, 300, $graphName . '_requests_per_second.svg' );
} catch ( Exception $e ) { /* Ignore */ }

try {
    $chart = new LineChart( 'Request failures' );
    $chart->data['Failures'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['failures'] / $value['requests'] * 100;
            },
            $data
        )
    );
    $chart->data['Failures']->color = '#A00000';

    $chart->yAxis->label = 'Percent';
    $chart->yAxis->max = 100;

    $chart->render( 500, 300, $graphName . '_request_failures.svg' );
} catch ( Exception $e ) { /* Ignore */ }

try {
    $chart = new LineChart( 'Request / Response time / Failures' );
    $chart->data['Average'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value ) use ( $chartResolution )
            {
                return $value['requests'] / $chartResolution;
            },
            $data
        )
    );
    $chart->data['Median'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['medTime'] / 1000;
            },
            $data
        )
    );
    $chart->data['90%'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['90%Time'] / 1000;
            },
            $data
        )
    );
    $chart->data['Failures'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['failures'] / $value['requests'] * 100;
            },
            $data
        )
    );
    $chart->data['Failures']->color = '#A00000';

    $chart->yAxis->label = 'Total / Seconds / Percent';

    $chart->render( 500, 300, $graphName . '_request_response_failure.svg' );
} catch ( Exception $e ) { /* Ignore */ }

try {
    $chart = new LineChart( 'Response Codes' );

    $chart->data['20x'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['20x'] / $value['requests'] * 100;
            },
            $data
        )
    );
    $chart->data['30x'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['30x'] / $value['requests'] * 100;
            },
            $data
        )
    );
    $chart->data['40x'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['40x'] / $value['requests'] * 100;
            },
            $data
        )
    );
    $chart->data['50x'] = new ezcGraphArrayDataSet(
        array_map(
            function( $value )
            {
                return $value['50x'] / $value['requests'] * 100;
            },
            $data
        )
    );

    $chart->yAxis->label = 'Percent';
    $chart->yAxis->max = 100;

    $chart->render( 500, 300, $graphName . '_response_codes.svg' );
} catch ( Exception $e ) { /* Ignore */ }

// Draw graphs from load logs
foreach ( glob( "{$inputDir}/*-vmstat.log" ) as $loadLog )
{
    preg_match('(/([^/]+)\-vmstat\.log$)', $loadLog, $match);
    $serverName = $match[1];

    $data = parseLoadLog( $loadLog, $chartResolution );
    foreach ( $data as $time => $set )
    {
        $data[$time]['cpu ticks'] =
            $set['non-nice user cpu ticks'] +
            $set['nice user cpu ticks'] +
            $set['system cpu ticks'] +
            $set['idle cpu ticks'] +
            $set['IO-wait cpu ticks'] +
            $set['IRQ cpu ticks'] +
            $set['softirq cpu ticks'] +
            $set['stolen cpu ticks'];
    }

    try {
        $chart = new LineChart( 'CPU usage' );
        $chart->data['System'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['system cpu ticks']
                        / $value['cpu ticks'] * 100;
                },
                $data
            )
        );
        $chart->data['User'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return ( $value['system cpu ticks'] + $value['nice user cpu ticks'] + $value['non-nice user cpu ticks'] )
                        / $value['cpu ticks'] * 100;
                },
                $data
            )
        );

        $chart->yAxis->label = 'Percent';
        $chart->yAxis->max = 100;

        $chart->render( 500, 300, $graphName . '_' . $serverName . '_cpu_usage.svg' );
    } catch ( Exception $e ) { /* Ignore */ }

    try {
        $chart = new LineChart( 'IO Wait' );
        $chart->data['IO Wait'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['IO-wait cpu ticks']
                        / $value['cpu ticks'] * 100;
                },
                $data
            )
        );

        $chart->yAxis->label = 'Percent';

        $chart->render( 500, 300, $graphName . '_' . $serverName . '_io_wait.svg' );
    } catch ( Exception $e ) { /* Ignore */ }

    try {
        $chart = new LineChart( 'Memory usage' );
        $chart->data['Used Memory'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['K active memory'] / $value['K total memory'] * 100;
                },
                $data
            )
        );
        $chart->data['Shared'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return ( $value['K active memory'] + $value['K inactive memory'] ) / $value['K total memory'] * 100;
                },
                $data
            )
        );
        $chart->data['Cached'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['K used memory'] / $value['K total memory'] * 100;
                },
                $data
            )
        );

        $chart->yAxis->label = 'Percent';

        $chart->yAxis->min = 0;
        $chart->yAxis->max = 100;

        $chart->render( 500, 300, $graphName . '_' . $serverName . '_memory_usage.svg' );
    } catch ( Exception $e ) { /* Ignore */ }

    try {
        $chart = new LineChart( 'Swap usage' );
        $chart->data['Used Swap'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    if (0 == $value['K total swap']) {
                        return 0;
                    }
                    return $value['K used swap'] / $value['K total swap'] * 100;
                },
                $data
            )
        );

        $chart->yAxis->label = 'Percent';

        $chart->yAxis->min = 0;
        $chart->yAxis->max = 100;

        $chart->render( 500, 300, $graphName . '_' . $serverName . '_swap_usage.svg' );
    } catch ( Exception $e ) { /* Ignore */ }

    try {
        $chart = new LineChart( 'Context switches' );
        $chart->data['CPU context switches'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['CPU context switches'];
                },
                $data
            )
        );

        $chart->yAxis->label = '#';

        $chart->render( 500, 300, $graphName . '_' . $serverName . '_context_switches.svg' );
    } catch ( Exception $e ) { /* Ignore */ }

    try {
        $chart = new LineChart( 'Pages paged' );
        $chart->data['Into memory'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['pages paged in'];
                },
                $data
            )
        );
        $chart->data['Out of memory'] = new ezcGraphArrayDataSet(
            array_map(
                function( $value )
                {
                    return $value['pages paged out'];
                },
                $data
            )
        );

        $chart->yAxis->label = '#';

        $chart->render( 500, 300, $graphName . '_' . $serverName . '_pages.svg' );
    } catch ( Exception $e ) { /* Ignore */ }
}
