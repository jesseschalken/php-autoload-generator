<?php

namespace AutoloadGenerator;

/**
 * @param string[] $paths
 * @return \Generator
 */
function flatten_input_paths(array $paths) {
    foreach ($paths as $path) {
        foreach (recursive_scan($path) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                yield $file;
            }
        }
    }
}

function main() {
    $args = \Docopt::handle(<<<s
A PHP class autoload generator with support for functions and constants.

Example:

    generate-autoload src/autoload.php

        Will write an autoloader for everything in "src/" to "src/autoload.php".

    generate-autoload autoload.php src --exclude src/Bar lib/functions.php

        Will write to an autoloader for everything in "src/" and
        "lib/functions.php", except for everything in "src/Bar", to "autoload.php".

Usage:
    generate-autoload [options] <outfile> [<files>...] [--exclude <file>]...
    generate-autoload -h|--help

Options:
    --require-method=<method>  One of "include", "require", "include_once" or
                               "require_once". [default: require_once]
    --case-insensitive         Autoload classes case insensitively. Will involve
                               a strtolower() call every time a class is loaded.
    --prepend                  Third parameter to spl_autoload_register().
    --exclude <file>           Exclude a file/directory.

s
    );

    $outFile = $args['<outfile>'];
    $files   = array_diff(
        iterator_to_array(flatten_input_paths($args['<files>'] ?: [dirname($outFile)])),
        iterator_to_array(flatten_input_paths($args['--exclude']))
    );

    global $argv;

    $options                  = new GeneratorOptions;
    $options->requireMethod   = $args['--require-method'];
    $options->prependAutoload = $args['--prepend'];
    $options->caseInsensitive = $args['--case-insensitive'];
    $options->generatedBy     = join(' ', $argv);

    $generator = new Generator;

    foreach ($files as $file) {
        print "Scanning $file\n";
        $generator->addFile($file);
    }
    print "\n";

    file_put_contents($outFile, $generator->generate(dirname($outFile), $options));
    print "Output written to $outFile\n";
}

