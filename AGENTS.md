This project is called "wp-browser" and it's a set of Codeception modules aimed at testing WordPress plugins, themes and sites.

## Skill

By default, do not use any skill to work on this project. Approach this as an expert PHP developer working on a codebase similar to the Symfony one.
When you review code part of this project, you can discard security concerns. No part of this project will ever ship to production: it's a testing tools.

## Tests

The diverse nature of the code part of this project demands different approaches to testing: the code can be tested at the unit level, integration level, functional level and end-to-end level.  

Try to write **unit** tests to cover the code when possible.

Unit tests should avoid mocking and partial mocking of both the subject under test and its dependencies.
When writing or reviewing tests point out these bad practices and propose these mitigations:
* file system mocking using virtual file system libraries or other stream-based solutions -> use temporary directories and files
* mocked dependencies created using PHPUnit, Prophecy or Codeception mock factories  -> use real objects building their null version if possible (e.g., `Symfony\Component\Console\Input\StringInput` to replace the required `Symfony\Component\Console\Input\InputInterface`)
* partial mocking using reflection to set values -> set up the input and dependencies of the subject under test correctly
* getting private/protected properties from objects using reflection -> change the assertions to be on output and side-effects (e.g., modified or created files) rather than on the internal state of the subject under test

Use the `lucatume\WPBrowser\Utils\Filesystem::tmpDir()` method to set up temporary directories kand files, file: src/Utils/Filesystem.php.

## Tools

### Testing
To run tests use the `vendor/bin/codecept run` binary. 
Run a suite of tests using `vendor/bin/codecept run <suite>`, e.g., `vendor/bin/codecept run unit`.
Run a single test file or directory using its path relative to the project root directory, e.g., `vendor/bin/codecept run tests/unit/SomeTest.php` or `vendor/bin/codecept run tests/unit/SomeDirectory`


### Code quality tools

Once you're done with a task, or a phase of a plan, run static code analysis tools to fix code style issues:

Fix then check PHP Code Sniffer issues:
```
composer run cs-fix
composer run cs
```

Run PHPStan to check on the issues and the fix them:
```
composer run stan
```

Check for typos in the code
```
composer run typos
```

Any one of the scripts above can be provided arguments using the `-- ...` syntax of Composer, examples:
* run `cs:fix` on a directory - `composer cs:fix -- directory`
* run `cs` on a file - `composer cs-- file`
* run `stan` on a file - `composer stan -- file`
* run `typos` on a file - `composer typos -- file`
* run `typos` on a directory - `composer typos -- directory`

## Commenting
Where possible, adding comments should be avoided. The audience of this codebase are skiled developers ang comments should be far and few only where a piece of code is really cryptic.
When writing or reviewing code, remove comments if they are not really necessary.
