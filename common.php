<?php

function parseLoadLog( $logFile, $resolution = 10 )
{
    fwrite(STDERR, "Open Load Log ({$logFile})\n");
    $log = array();
    $fp  = fopen( $logFile, 'r' );

    $last     = null;
    $lastTime = null;
    do {
        $line = fgets( $fp );

        if ( empty( $line ) )
        {
            continue;
        }

        list( $date, $data ) = explode( ';', trim( $line ) );
        $date = new DateTime( trim( $date ) );
        $time = (int) $date->format( 'U' );

        if ( $time < ( $lastTime + $resolution ) )
        {
            continue;
        }

        preg_match_all( '((?P<value>\\d+)\\s*(?P<type>\\D+))', trim( $data ), $matches );
        $matches['type'] = array_map( 'trim', $matches['type'] );
        $data = array_combine( $matches['type'], $matches['value'] );

        if ( $last !== null )
        {
            foreach ( $data as $name => $value )
            {
                if ( strpos( $name, 'memory' ) || strpos( $name, 'swap' ) )
                {
                    $log[$time][$name] = $value;
                }
                else
                {
                    $log[$time][$name] = $value - $last[$name];
                }
            }
        }

        $last     = $data;
        $lastTime = $time;
    } while ( !feof( $fp ) );

    return $log;
}


function averageOfPercent(array $data, $percent)
{
    $length = ceil(count($data) * $percent);

    if (0 == $length) {
        return 0;
    }

    sort($data);

    return $data[$length - 1];
}

function normalizeUrl($url)
{
    if (preg_match('~^https?://[^/]+(/.*\d+\-\d+([\-\da-z]+)?\.html(\?.+)?)$~i', $url, $match)) {
        $match = array_pad($match, 4, '');

        return sprintf(
            'Product detail page { params: [%s], filter: [] }',
            normalizeUrlQuery($match[3])
        );
    } else if (preg_match('~^Start Product Search \(([^\)]+)\)$~i', $url, $match)) {

        return sprintf('Product search { }');
    } else if (preg_match('~^Add Product to Basket \(([^\)]+)\)$~i', $url, $match)) {

        return sprintf('Add product to basket { }');
    } else if (preg_match('~^Select Product Variant \(([^\)]+)\)$~i', $url, $match)) {

        return sprintf('Select product variant { }');
    } else if (preg_match('~^Open [^\(]+\((/.*\d+\-\d+([\-\da-z]+)?\.html(\?.+)?)\)$~i', $url, $match)) {
        $match = array_pad($match, 4, '');

        return sprintf(
            'Product detail page { params: [%s], filter: [] }',
            normalizeUrlQuery($match[3])
        );
    } else if (preg_match('~^Open Site \((/.*/)(_[a-z0-9\-_]+)?\)$~i', $url, $match)) {
        $match = array_pad($match, 3, '');

        return sprintf(
            'Category page { params: [], filter: [%s] }',
            normalizeUrlFilter($match[2])
        );
    } else if (preg_match('~^https?://[^/]+(/.*/)(_[a-z0-9\-_]+)?$~i', $url, $match)) {
        $match = array_pad($match, 3, '');

        return sprintf(
            'Category page { params: [], filter: [%s] }',
            normalizeUrlFilter($match[2])
        );
    } else if (preg_match('~^Open Site \((/.*/)(_[a-z0-9\-_]+)?\?(.*)\)$~i', $url, $match)) {
        //if ($match[2]) { var_dump($match); }
        $match = array_pad($match, 4, '');
        return sprintf(
            'Category page { params: [%s], filter: [%s] }',
            normalizeUrlQuery($match[3]),
            normalizeUrlFilter($match[2])
        );
    } else if (preg_match('~^https?://[^/]+(/.*/)(_[a-z0-9\-_]+)?\?(.*)$~i', $url, $match)) {
        $match = array_pad($match, 4, '');

        return sprintf(
            'Category page { params: [%s], filter: [%s] }',
            normalizeUrlQuery($match[3]),
            normalizeUrlFilter($match[2])
        );
    }

    return $url;
}

function normalizeUrlQuery($queryString)
{
    parse_str(ltrim($queryString, '?'), $query);
    return join(', ', array_keys($query));
}

function normalizeUrlFilter($filterString)
{
    if (0 === strpos($filterString, '__groesse-')) {
        return 'size';
    } else if (0 === strpos($filterString, '_')) {
        return 'color';
    }
    return '';
}

function slugize($str)
{
    $str = strtolower(trim($str));

    $chars = array("ä", "ö", "ü", "ß");
    $replacements = array("ae", "oe", "ue", "ss");
    $str = str_replace($chars, $replacements, $str);

    $pattern = array(":", "!", "?", ".", "/", "'");
    $str = str_replace($pattern, "", $str);

    $pattern = array("([^a-z0-9\(\)-]+)", "/-+/");
    $str = preg_replace($pattern, "-", $str);

    return $str;
}

