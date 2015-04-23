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

function parseJmeterLog( $logFile, $resolution = 10 )
{
    global $skipPattern, $aggregations;

    $log = array('total' => array());

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

            if (preg_match('~\(Anonymous\)~', $attributes['tn'])) {
                $normalizedUrl .= ' (Anonymous)';
            } else if (preg_match('~\(Customer\)~', $attributes['tn'])) {
                $normalizedUrl .= ' (Customer)';
            }

            $data = array(
                'ts'           => $attributes['ts'],
                'url'          => $attributes['lb'],
                'useCase'      => $attributes['tn'],
                'responseCode' => $attributes['rc'],
                'success'      => $attributes['s'] === 'true',
                'time'         => (int) $attributes['t'],
            );

            $logTime = floor( $data['ts'] / ( 1000 * $resolution ) ) * $resolution;

            if (!isset($log[$logTime])) {
                fwrite(STDERR, ".");
            }

            if ( !isset( $log[$logTime][$normalizedUrl] ) )
            {
                $log[$logTime][$normalizedUrl] = array(
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
                    'requests'      => 0
                );
            }

            $log[$logTime][$normalizedUrl]['failures']    += $data['success'] ? 0 : 1;
            $log[$logTime][$normalizedUrl]['20x']         += '2' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime][$normalizedUrl]['30x']         += '3' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime][$normalizedUrl]['40x']         += '4' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime][$normalizedUrl]['50x']         += '5' == $data['responseCode'][0] ? 1 : 0;
            $log[$logTime][$normalizedUrl]['averageTime'] += $data['time'];
            $log[$logTime][$normalizedUrl]['minTime']      = min( $log[$logTime][$normalizedUrl]['minTime'], $data['time'] );
            $log[$logTime][$normalizedUrl]['maxTime']      = max( $log[$logTime][$normalizedUrl]['maxTime'], $data['time'] );
            $log[$logTime][$normalizedUrl]['medTime'][]    = $data['time'];
            $log[$logTime][$normalizedUrl]['90%Time'][]    = $data['time'];

            ++$log[$logTime][$normalizedUrl]['requests'];
        }
    }

    fwrite(STDERR, "\n");

    foreach ( $log as $time => $urls )
    {
        foreach ($urls as $url => $data) {
            $log[$time][$url]['Debug']        = implode(', ', $log[$time][$url]['medTime']);
            $log[$time][$url]['averageTime'] /= $data['requests'];
            $log[$time][$url]['medTime']      = averageOfPercent($log[$time][$url]['medTime'], 0.5);
            $log[$time][$url]['90%Time']      = averageOfPercent($log[$time][$url]['90%Time'], 0.9);

            if (!isset($log['total'][$url])) {
                $log['total'][$url] = array(
                    'requests' => $data['requests'],
                    'averageTime' => $log[$time][$url]['averageTime'],
                    'medTime' => 0,
                    '90%Time' => 0,
                );
            } else {
                $newTotal = $log['total'][$url]['requests'] + $data['requests'];
                $log['total'][$url]['averageTime'] =
                    (($log['total'][$url]['requests'] / $newTotal) * $log['total'][$url]['averageTime']) +
                    (($data['requests'] / $newTotal) * $log[$time][$url]['averageTime']);
                $log['total'][$url]['requests'] = $newTotal;
            }
        }
    }

    return $log;
}

################################################################################
#
#   CLI CODE STARTS HERE
#
################################################################################

$inputDir = __DIR__ . '/../build';
$outputDir = $inputDir . '/graphs';
$testName = date('Y-m-d\TH:i:s') . '_JMeter-Result';
$chartResolution = 300;
$maxPages = 10;
$debug = false;

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

        case '--debug':
            $debug = true;
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
foreach (glob("{$outputDir}/*.txt") as $graphFile) {
    unlink($graphFile);
}

$graphName = $outputDir . '/' . $testName;

fputs(STDERR, "Generating listings for $testName...\n");
if ($debug) {
    fputs(STDERR, "adding Debug information...\n");
}

$data = parseJmeterLog( $jmeterLog, $chartResolution );
array_pop($data);

fputcsv(STDOUT, array('Date', 'Seite', 'Requests', 'Average', 'Median', '90% Percentile', $debug ? 'Values' : ''), ';');
foreach ($data as $time => $urls) {
    uasort(
        $urls,
        function (array $a, array $b) {
            return $b['90%Time'] >= $a['90%Time'];
        }
    );

    foreach ($urls as $url => $timing) {
        if (strpos($url, 'Error:') !== false) {
            continue;
        }

        fputcsv(STDOUT, array(
            ($time == 'total') ? $time : date('Y/m/d H:i:s', $time),
            $url,
            $timing['requests'],
            sprintf('%.5f', ($timing['averageTime'] / 1000)),
            sprintf('%.5f', ($timing['medTime'] / 1000)),
            sprintf('%.5f', ($timing['90%Time'] / 1000)),
            $debug ? $timing['Debug'] : '',
        ), ';');
    }
}
