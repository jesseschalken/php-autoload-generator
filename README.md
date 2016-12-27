# autoload-generator

Generates class-map based autoloaders for PHP projects, with support for functions and constants.

### Usage

1. Include the composer package:
    ```
    php composer.phar require --dev jesseschalken/autoload-generator
    ```

2. Run `./vendor/bin/php-generate-autoload <outfile> [<files>...]`

    For example, if your project has all it's source files in a `src/` directory, you can do:
    ```
    ./vendor/bin/php-generate-autoload src/autoload.php
    ```

    and then use `src/autoload.php` as the entrypoint for your project.
    
    See `./vendor/bin/php-generate-autoload --help` for more info.

3. Update `composer.json` to point to your autoloader, if applicable. For example:

    ```
    "autoload": {
        "files": ["src/autoload.php"]
    }
    ```

### How it works

`php-generate-autoload` parses all PHP files using [nikic/PHP-Parser](https://github.com/nikic/PHP-Parser) and
creates a map from class names to file paths to be used in an autoloader registered with `spl_autoload_register()`. Any
files containing global function or constant definitions are included directly.

For example, consider a project with three files `src/Foo.php`, `src/Bar.php` and `src/lots of classes.php` containing
classes, `src/functions.php` containing functions and `src/constants.php` containing constants.

After running

```
./vendor/bin/php-generate-autoload src/autoload.php
```

`src/autoload.php` would contain something like:

```php
<?php

spl_autoload_register(function ($class) {
  static $map = array (
  'Project\\Foo' => 'Foo.php',
  'Project\\Bar' => 'Bar.php',
  'Project\\Class1' => 'lots of classes.php',
  'Project\\Class2' => 'lots of classes.php',
  'Project\\Class3' => 'lots of classes.php',
);

  if (isset($map[$class])) {
    require_once __DIR__ . "/{$map[$class]}";
  }
}, true, false);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/functions.php';

```
