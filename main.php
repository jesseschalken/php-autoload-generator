<?php

namespace AutoloadGenerator;

class GeneratorOptions {
    public $requireMethod   = 'require_once';
    public $prependAutoload = false;
    public $caseInsensitive = false;
    public $generatedBy     = '';
}

class Generator {
    /** @var string[] */
    private $map = array();
    /** @var true[] */
    private $require = array();
    /** @var \PhpParser\Parser */
    private $parser;

    public function __construct() {
        $this->parser = new \PhpParser\Parser(new \PhpParser\Lexer);
    }

    /**
     * @param string $path
     */
    public function addFile($path) {
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
     * @param string          $file
     * @param \PhpParser\Node $node
     * @param string          $prefix
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
                $node->name->parts === array('define')
            ) {
                $this->require[$file] = true;
            }
        } else if ($node instanceof \PhpParser\Node\Stmt\ClassLike) {
            $this->map[$prefix . $node->name] = $file;
        }

        if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
            /** @var \PhpParser\Node\Name|null $name */
            $name = $node->name;
            if ($name && $name->parts) {
                $prefix2 = join('\\', $name->parts) . '\\';
            } else {
                $prefix2 = '';
            }

            foreach ($node->stmts as $node2) {
                $this->processNode($file, $node2, $prefix . $prefix2);
            }
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
     * @param string           $base
     * @param GeneratorOptions $options
     * @return string
     */
    private function generateClassAutoload($base, GeneratorOptions $options) {
        $map = array();
        foreach ($this->map as $class => $file) {
            $file = make_relative($file, $base);
            $file = str_replace(DIRECTORY_SEPARATOR, '/', $file);

            if ($options->caseInsensitive) {
                $class = strtolower($class);
            }

            $map[$class] = $file;
        }

        ksort($map, SORT_STRING);
        $map = var_export($map, true);
        $php = <<<s
spl_autoload_register(function (\$class) {
    static \$map = $map;


s;

        if ($options->caseInsensitive) {
            $php .= <<<s
    \$class = strtolower(\$class);


s;
        }

        $prepend = var_export($options->prependAutoload, true);

        $php .= <<<s
    if (isset(\$map[\$class])) {
        $options->requireMethod __DIR__ . "/{\$map[\$class]}";
    }
}, true, $prepend);

s;

        return $php;
    }

    /**
     * @param string           $base
     * @param GeneratorOptions $options
     * @return string
     */
    private function generateRequiredFiles($base, GeneratorOptions $options) {
        $php = '';

        $require = array_keys($this->require);
        $require = array_unique($require);
        $require = array_map(function ($file) use ($base) {
            $file = make_relative($file, $base);
            $file = str_replace(DIRECTORY_SEPARATOR, '/', $file);
            return $file;
        }, $require);
        $require = array_unique($require);
        sort($require, SORT_STRING);
        foreach ($require as $file) {
            $file = var_export("/$file", true);
            $php .= "$options->requireMethod __DIR__ . $file;\n";
        }

        return $php;
    }

    /**
     * @param string           $base
     * @param GeneratorOptions $options
     * @return string
     */
    public function generate($base, GeneratorOptions $options) {
        $autoload = $this->generateClassAutoload($base, $options);
        $required = $this->generateRequiredFiles($base, $options);

        return <<<s
<?php

// !! Generated by: $options->generatedBy

$autoload
$required

s;
    }
}

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

/**
 * @param string[] $paths
 * @return string[]
 */
function flatten_input_paths(array $paths) {
    $result = array();
    foreach ($paths as $path) {
        foreach (recursive_scan(realpath($path)) as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if ($extension === 'php' || $extension === 'hh') {
                $result[] = $file;
            }
        }
    }
    return $result;
}

const DOC = <<<'s'
A PHP class autoload generator with support for functions and constants.

Example:

    php-generate-autoload src/autoload.php

        Will write an autoloader for everything in "src/" to "src/autoload.php".

    php-generate-autoload autoload.php src --exclude src/Bar lib/functions.php

        Will write to an autoloader for everything in "src/" and
        "lib/functions.php", except for everything in "src/Bar", to "autoload.php".

Usage:
    php-generate-autoload [options] <outfile> [<files>...] [--exclude <file>]...
    php-generate-autoload -h|--help

Options:
    --require-method=<method>  One of "include", "require", "include_once" or
                               "require_once". [default: require_once]
    --case-insensitive         Autoload classes case insensitively. Will involve
                               a strtolower() call every time a class is loaded.
    --prepend                  Third parameter to spl_autoload_register().
    --exclude <file>           Exclude a file/directory.

s;

function main(array $argv) {
    $args = \Docopt::handle(DOC, array('argv' => \array_slice($argv, 1)));

    $outFile = $args['<outfile>'];
    $files   = array_diff(
        flatten_input_paths($args['<files>'] ?: array(dirname($outFile))),
        flatten_input_paths($args['--exclude'])
    );

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

