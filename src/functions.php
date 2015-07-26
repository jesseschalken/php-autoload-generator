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
 * @return \Generator
 */
function recursive_scan($path, $followLinks = false) {
    if (is_dir($path) && ($followLinks || !is_link($path))) {
        foreach (array_diff(scandir($path), ['.', '..']) as $p) {
            foreach (recursive_scan($path . DIRECTORY_SEPARATOR . $p, $followLinks) as $p2)
                yield $p2;
        }
    } else {
        yield $path;
    }
}

