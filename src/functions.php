<?php

namespace AutoloadGenerator;

/**
 * Returns $path relative to $base
 * @param string $path
 * @param string $base
 * @return string
 */
function make_relative($path, $base) {
    $path  = explode(DIRECTORY_SEPARATOR, realpath($path));
    $base  = explode(DIRECTORY_SEPARATOR, realpath($base));
    $count = min(count($path), count($base));

    for ($i = 0; $i < $count && $path[$i] === $base[$i]; $i++) {
    }

    $path = array_slice($path, $i);
    $base = array_slice($base, $i);

    $path = array_merge(array_fill(0, count($base), '..'), $path);

    return implode(DIRECTORY_SEPARATOR, $path);
}

/**
 * @param string $path
 * @param bool   $followLinks
 * @return string[]
 */
function recursive_scan($path, $followLinks = false) {
    $result = array($path);

    if (is_dir($path) && ($followLinks || !is_link($path))) {
        foreach (array_diff(scandir($path), array('.', '..')) as $p) {
            foreach (recursive_scan($path . DIRECTORY_SEPARATOR . $p, $followLinks) as $p2) {
                $result[] = $p2;
            }
        }
    }

    return $result;
}

