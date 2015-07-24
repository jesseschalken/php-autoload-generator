#!/usr/bin/env php
<?php

namespace AutoloadGenerator;

if (file_exists(__DIR__ . '/vendor/autoload.php'))
    require_once __DIR__ . '/vendor/autoload.php';
else
    require_once __DIR__ . '/../../autoload.php';

/**
 * Returns $path relative to $base
 * @param string $path
 * @param string $base
 * @return string
 */
function make_relative($path, $base) {
    $path = array_values(array_diff(explode(DIRECTORY_SEPARATOR, realpath($path)), ['']));
    $base = array_values(array_diff(explode(DIRECTORY_SEPARATOR, realpath($base)), ['']));

    $i = 0;
    while (
        isset($path[$i]) &&
        isset($base[$i]) &&
        $path[$i] === $base[$i]
    ) {
        $i++;
    }

    $path = array_slice($path, $i);
    $base = array_slice($base, $i);

    $path = array_merge(array_fill(0, count($base), '..'), $path);

    return implode(DIRECTORY_SEPARATOR, $path);
}

/**
 * @param string $path
 * @param bool $followSymLinks
 * @return \string[]
 */
function recursive_scan($path, $followSymLinks = false) {
    if (is_dir($path) && ($followSymLinks || !is_link($path))) {
        $r = [];
        foreach (array_diff(scandir($path), ['.', '..']) as $p) {
            foreach (recursive_scan($path . DIRECTORY_SEPARATOR . $p, $followSymLinks) as $p2) {
                $r[] = $p2;
            }
        }
        return $r;
    } else {
        return [$path];
    }
}

class Generator {
    /** @var string[][] */
    private $map = [];
    /** @var true[] */
    private $require = [];
    /** @var \PhpParser\Parser\Php5 */
    private $parser;

    function __construct() {
        $this->parser = new \PhpParser\Parser(new \PhpParser\Lexer);
    }

    /**
     * @param string $path
     */
    function addFile($path) {
        $contents = file_get_contents($path);

        // Remove the hash-bang line if there, since
        // PhpParser doesn't support it
        if (substr($contents, 0, 2) === '#!') {
            $contents = substr($contents, strpos($contents, "\n") + 1);
        }

        $nodes = $this->parser->parse($contents);

        foreach ($nodes as $node) {
            $this->processNode($path, $node);
        }
    }

    /**
     * @param string $file
     * @param \PhpParser\Node $node
     * @param string $prefix
     */
    private function processNode($file, \PhpParser\Node $node, $prefix = '') {
        if ($node instanceof \PhpParser\Node\Stmt\Const_ && $node->consts) {
            $this->require[$file] = true;
        } else if ($node instanceof \PhpParser\Node\Stmt\Function_) {
            $this->require[$file] = true;
        } else if ($node instanceof \PhpParser\Node\Expr\FuncCall) {
            // Try to catch constants defined with define()
            if (
                $node->name instanceof \PhpParser\Node\Name &&
                $node->name->parts === ['define']
            ) {
                $this->require[$file] = true;
            }
        } else if ($node instanceof \PhpParser\Node\Stmt\ClassLike) {
            $this->map[$prefix . $node->name][] = $file;
        }

        if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
            /** @var \PhpParser\Node\Name|null $name */
            $name = $node->name;
            if ($name && $name->parts) {
                $prefix2 = join('\\', $name->parts) . '\\';
            } else {
                $prefix2 = '';
            }

            foreach ($node->stmts as $node2)
                $this->processNode($file, $node2, $prefix . $prefix2);
        } else {
            // Cheating a little. I really just want to traverse the tree
            // recursively without needing to write code specifically for
            // each kind of node. This seems to be what \PhpParser\NodeTraverser
            // does anyway.
            foreach ($node->getSubNodeNames() as $name) {
                $var = $node->$name;
                if ($var instanceof \PhpParser\Node) {
                    $this->processNode($file, $var, $prefix);
                } else if (is_array($var)) {
                    foreach ($var as $var2) {
                        if ($var2 instanceof \PhpParser\Node) {
                            $this->processNode($file, $var2, $prefix);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $method
     * @param string $base
     * @param bool $prepend
     * @param bool $caseInsensitive
     * @return string
     */
    private function generateClassAutoload($method, $base, $prepend = false, $caseInsensitive) {
        if ($caseInsensitive) {
            $map2 = [];
            foreach ($this->map as $class => $files)
                foreach ($files as $file)
                    $map2[strtolower($class)][] = $file;
        } else {
            $map2 = $this->map;
        }

        $map = [];
        foreach ($map2 as $class => $files) {
            $set = [];
            foreach ($files as $file) {
                $file = make_relative($file, $base);
                $file = str_replace(DIRECTORY_SEPARATOR, '/', $file);
                $set[] = $file;
            }
            $set = array_unique($set);
            sort($set, SORT_STRING);

            if (!$set)
                continue;
            else if (count($set) == 1)
                $value = $set[0];
            else
                $value = $set;

            $map[$class] = $value;
        }

        ksort($map, SORT_STRING);
        $map = var_export($map, true);
        $prepend = var_export($prepend, true);

        $lowered = $caseInsensitive ? 'strtolower($class)' : '$class';
        return <<<s
spl_autoload_register(function (\$class) {
    static \$map = $map;

    \$file =& \$map[$lowered];

    if (is_string(\$file)) {
        $method __DIR__ . "/\$file";
    }
}, true, $prepend);

s;
    }

    /**
     * @param string $method
     * @param string $base
     * @return string
     */
    private function generateRequiredFiles($method, $base) {
        $php = '';
        $require = array_keys($this->require);
        $require = array_unique($require);
        foreach ($require as &$file) {
            $file = make_relative($file, $base);
            unset($file);
        }
        $require = array_unique($require);
        sort($require, SORT_STRING);
        foreach ($require as $file) {
            $file = str_replace(DIRECTORY_SEPARATOR, '/', $file);
            $file = var_export("/$file", true);
            $php .= "$method __DIR__ . $file;\n";
        }

        return $php;
    }

    /**
     * @param string $base
     * @param bool $prepend
     * @param string $method
     * @param bool $caseInsensitive
     * @return string
     */
    function generate($base, $prepend = false, $method, $caseInsensitive) {
        $autoload = $this->generateClassAutoload($method, $base, $prepend, $caseInsensitive);
        $required = $this->generateRequiredFiles($method, $base);

        return <<<s
<?php

// !! this file is automatically generated !!

$autoload
$required

s;
    }
}

/**
 * @param string[] $inFiles
 * @return string[]
 */
function parse_files_input(array $inFiles) {
    $files = [];
    foreach ($inFiles as $file) {
        $files2 = recursive_scan($file);
        if ($files2 === [$file]) {
            $files[] = $file;
        } else {
            foreach ($files2 as $file2) {
                if (pathinfo($file2, PATHINFO_EXTENSION) === 'php') {
                    $files[] = $file2;
                }
            }
        }
    }

    return $files;
}

function main() {
    $args = \Docopt::handle(<<<s
Usage:
  generate-autoload.php [options] <outfile> [<files>...] [--exclude <file>]...

Options:
  --require-method=<method>  One of "include", "require", "include_once" or "require_once" [default: require_once]
  --case-insensitive         Autoload classes case insensitively. Will involve a strtolower() call every time a class is loaded
  --prepend                  Third parameter to spl_autoload_register()
  --exclude <file>           Exclude a file/directory

s
    );
    $method = $args['--require-method'];
    $outFile = $args['<outfile>'];
    $files = parse_files_input($args['<files>'] ?: [dirname($outFile)]);
    $exclude = parse_files_input($args['--exclude']);
    $prepend = $args['--prepend'];
    $caseInsensitive = $args['--case-insensitive'];

    $exclude = array_fill_keys(array_map('realpath', $exclude), true);

    foreach ($files as $k => $file)
        if (isset($exclude[realpath($file)]))
            unset($files[$k]);

    $generator = new Generator;

    foreach ($files as $file) {
        print "Scanning $file\n";
        $generator->addFile($file);
    }
    print "\n";

    file_put_contents($outFile, $generator->generate(dirname($outFile), $prepend, $method, $caseInsensitive));
    print "Output written to $outFile\n";
}

ini_set('memory_limit', '-1');
ini_set('xdebug.max_nesting_level', '10000');

main();
