<?php

namespace JesseSchalken\AutoloadGenerator;

use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Parser;

/**
 * Returns $path relative to $base
 * @param string $path
 * @param string $base
 * @return string
 */
function make_relative($path, $base) {
    $path = \explode(\DIRECTORY_SEPARATOR, \realpath($path));
    $base = \explode(\DIRECTORY_SEPARATOR, \realpath($base));
    $count = \min(\count($path), \count($base));

    for ($i = 0; $i < $count && $path[$i] === $base[$i]; $i++) {
    }

    $path = \array_slice($path, $i);
    $base = \array_slice($base, $i);

    $path = \array_merge(\array_fill(0, \count($base), '..'), $path);

    return \implode(\DIRECTORY_SEPARATOR, $path);
}

/**
 * @param string $command
 * @param string $input
 * @return string
 * @throws \Exception
 */
function run_command($command, $input = '') {
    $process = \proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    ), $pipes);

    \fwrite($pipes[0], $input);
    \fclose($pipes[0]);

    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);

    $exitCode = \proc_close($process);

    if ($exitCode !== 0) {
        throw new \Exception("Command '$command' returned exit code $exitCode\n\n$stderr");
    }

    return $stdout;
}

function compile_hack($hack) {
    return run_command(\escapeshellarg(__DIR__ . '/h2tp-stdin'), $hack);
}

final class ParsedFile {
    public static function parse($code) {
        // Remove the hash-bang line if there, since PhpParser doesn't support it
        if (\substr($code, 0, 2) === '#!') {
            $code = \substr($code, strpos($code, "\n") + 1);
        }

        // Compile Hack to PHP
        if (\substr($code, 0, 4) === '<?hh') {
            $code = compile_hack($code);
        }

        $parser = new Parser(new Lexer);
        $self = new self();
        foreach ($parser->parse($code) as $node) {
            $self->processNode($node, '');
        }
        return $self;
    }

    /** @var string[] */
    private $classes = array();
    /** @var bool */
    private $loadEagerly = false;

    /**
     * @param Node   $node
     * @param string $prefix
     */
    private function processNode(Node $node, $prefix) {
        if ($node instanceof Node\Stmt\Const_ && $node->consts) {
            $this->loadEagerly = true;
        } else if ($node instanceof Node\Stmt\Function_) {
            $this->loadEagerly = true;
        } else if ($node instanceof Node\Expr\FuncCall) {
            // Try to catch constants defined with define()
            if (
                $node->name instanceof Node\Name &&
                $node->name->parts === array('define')
            ) {
                $this->loadEagerly = true;
            }
        } else if ($node instanceof Node\Stmt\ClassLike) {
            $this->classes[] = $prefix . $node->name;
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            /** @var Node\Name|null $name */
            $name = $node->name;
            if ($name && $name->parts) {
                $prefix = join('\\', $name->parts) . '\\';
            } else {
                $prefix = '';
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $var = $node->$name;
            if ($var instanceof Node) {
                $this->processNode($var, $prefix);
            } else if (\is_array($var)) {
                foreach ($var as $var2) {
                    if ($var2 instanceof Node) {
                        $this->processNode($var2, $prefix);
                    }
                }
            }
        }
    }

    public function getClasses() {
        return $this->classes;
    }

    public function getLoadEagerly() {
        return $this->loadEagerly;
    }
}

final class Generator {
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
    --follow-symlinks          Follow symbolic links.
    --hack                     Emit a Hack file (in partial mode) instead of a PHP file.

s;

    public static function main(array $argv) {
        $args = \Docopt::handle(self::DOC, array('argv' => \array_slice($argv, 1)));

        $self = new self($args['<outfile>']);
        $self->generatedBy = \join(' ', $argv);
        $self->followSymlinks = $args['--follow-symlinks'];
        $self->requireMethod = $args['--require-method'];
        $self->prependAutoload = $args['--prepend'];
        $self->caseInsensitive = $args['--case-insensitive'];
        $self->useHack = $args['--hack'];

        $files = $self->flattenInputPaths($args['<files>'] ?: array($self->baseDir));
        $files = \array_diff($files, $self->flattenInputPaths($args['--exclude']));

        foreach ($files as $file) {
            $parsed = ParsedFile::parse(\file_get_contents($file));
            if ($parsed->getLoadEagerly()) {
                $self->eagerFiles[] = $file;
            }
            foreach ($parsed->getClasses() as $class) {
                $self->classMap[$class] = $file;
            }
            print "Scanning $file\n";
        }
        print "\n";
        \file_put_contents($self->outFile, $self->generate());
        print "Output written to $self->outFile\n";
    }

    /** @var string */
    private $outFile;
    /** @var string */
    private $generatedBy = '';
    /** @var bool */
    private $followSymlinks = false;
    /** @var string */
    private $baseDir;
    /** @var string[] */
    private $classMap = array();
    /** @var string[] */
    private $eagerFiles = array();
    /** @var bool */
    private $caseInsensitive = false;
    /** @var bool */
    private $prependAutoload = false;
    /** @var string */
    private $requireMethod = 'require_once';
    /** @var bool */
    private $useHack = false;

    private function __construct($outFile) {
        $this->outFile = $outFile;
        $this->baseDir = \dirname($outFile);
    }

    private function generate() {
        $code = "";
        if ($this->useHack) {
            $code .= "<?hh\n";
        } else {
            $code .= "<?php\n";
        }
        $code .= "\n";
        $code .= "// Generated by " . $this->generatedBy . "\n";
        $code .= "\n";
        $code .= $this->generateSplAutoload();
        $code .= "\n";
        $code .= $this->generateEagerRequires();
        $code .= "\n";
        return $code;
    }

    private function generateEagerRequires() {
        $self = $this;

        $files = $this->eagerFiles;
        $files = \array_unique($files);
        $files = \array_map(function ($file) use ($self) {
            return $self->cleanPath($file);
        }, $files);
        $files = \array_unique($files);

        \sort($files, SORT_STRING);

        $code = '';
        foreach ($files as $file) {
            $code .= "$this->requireMethod __DIR__ . " . var_export("/$file", true) . ";\n";
        }
        return $code;
    }

    private function generateSplAutoload() {
        $self = $this;

        $classMap = $this->classMap;
        $classMap = \array_map(function ($file) use ($self) {
            return $self->cleanPath($file);
        }, $classMap);
        if ($this->caseInsensitive) {
            $classMap = \array_change_key_case($classMap, CASE_LOWER);
        }

        \ksort($classMap, SORT_STRING);

        $code = "";
        $code .= "\\spl_autoload_register(function (\$class) {\n";
        $code .= "  static \$map = " . \var_export($classMap, true) . ";\n";
        $code .= "\n";
        if ($this->caseInsensitive) {
            $code .= "  \$class = \\strtolower(\$class);\n";
        }
        $code .= "  if (isset(\$map[\$class])) {\n";
        $code .= "    $this->requireMethod __DIR__ . '/' . \$map[\$class];\n";
        $code .= "  }\n";
        $code .= "}, true, " . ($this->prependAutoload ? 'true' : 'false') . ");\n";
        return $code;
    }

    public function cleanPath($path) {
        $path = make_relative($path, $this->baseDir);
        $path = \str_replace(DIRECTORY_SEPARATOR, '/', $path);
        return $path;
    }

    private function recursiveScan($path) {
        if ($this->followSymlinks) {
            $isDir = \is_dir($path);
        } else {
            $isDir = \filetype($path) === 'dir';
        }
        $result = array($path);
        if ($isDir) {
            $scan = \scandir($path);
            $scan = \array_diff($scan, array('.', '..'));
            foreach ($scan as $path2) {
                $path2 = $path . DIRECTORY_SEPARATOR . $path2;
                foreach ($this->recursiveScan($path2) as $p2) {
                    $result[] = $p2;
                }
            }
        }
        return $result;
    }

    private function flattenInputPaths($paths) {
        $result = array();
        foreach ($paths as $path) {
            $path = \realpath($path);
            foreach ($this->recursiveScan($path) as $file) {
                $ext = \pathinfo($file, PATHINFO_EXTENSION);
                if ($ext === 'php' || $ext === 'hh') {
                    $result[] = $file;
                }
            }
        }
        return $result;
    }
}
