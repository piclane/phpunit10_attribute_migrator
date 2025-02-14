PHPUnit Annotation Migration Script
----

## Overview

This script is a migration tool designed to convert PHPUnit 9-style annotations (written as PHP comments) into PHPUnit 10 attributes, leveraging PHP's native attributes introduced in recent versions. By utilizing this tool, you can modernize your test codebase to take advantage of PHP's native language features and improve code clarity.

## Features

- **Recursive Directory Support:** Automatically processes all PHP files in the specified directory (and its subdirectories).
- **Customizable Exclusions:** Exclude specific files or directories based on glob patterns.
- **Automated Transformation:** Analyzes, modifies, and reformats all identified PHP files to replace PHPUnit annotations with attributes.
- **Code Beautification:** Ensures that transformed code is properly formatted for readability.

## Requirements

- **PHP Version:** PHP 8.0 or higher (due to attribute support).
- **Dependencies:**
  - [`nikic/php-parser`](https://github.com/nikic/PHP-Parser) for parsing and transforming the PHP code.
- **PHPUnit Version:** Recommended for PHPUnit 10 or higher.

## Installation

1. Clone the repository:

```shell script
git clone <repository-url>
cd <repository-directory>
```

2. Install dependencies using Composer:

```shell script
composer install
```

## Usage

To run the script, use the following command:

```shell script
php migrator.php [--exclude <glob-pattern>] <directory-path>
```

### Arguments

- `<directory-path>`: The path to the directory containing PHP files to be processed. All `.php` files in this directory and its subdirectories will be considered.
- `--exclude <glob-pattern>` *(optional)*: A glob pattern to exclude files or directories from processing. Examples:
  - `--exclude */bootstrap.php`
  - `--exclude /path/to/exclude/*`

### Examples

#### Basic Usage

Process all PHP files in the `tests` directory:

```shell script
php migrator.php /path/to/test
```

#### Exclude Specific Files

Process all PHP files in the `tests` directory, excluding `tests/bootstrap.php`:

```shell script
php migrator.php --exclude */bootstrap.php /path/to/test
```

#### Multiple Exclusions

Process all PHP files in `src` while excluding `src/legacy` and any files ending with `TestBase.php`:

```shell script
php migrator.php --exclude 'src/legacy/*,*/TestBase.php' src
```

## How it Works

1. **File Collection:**
  - The script recursively scans the given directory for `.php` files, applying any specified exclusion patterns.

2. **Parsing and Transforming:**
  - Using the `PhpParser` library, the script parses each PHP file's Abstract Syntax Tree (AST), identifies PHPUnit annotations, and replaces them with the equivalent PHPUnit 10 attributes.

3. **Code Upgrades:**
  - Adds necessary `use` statements for required attributes.
  - Removes outdated annotations and ensures proper reformatted code output.

4. **Code Formatting:**
  - Combines the power of `PhpParser` with a custom pretty-printer (`CustomPrettyPrinter`) to produce readable and maintainable code.

## Limitations

- **Custom Annotations:** If your code uses custom annotations unrelated to PHPUnit, these will not be processed.
- **Manual Verifications:** While the migration process is automated, complex test classes might require manual review to confirm correctness.
- **Unsupported Patterns:** Non-standard PHP code may cause parsing errors.

## Development

### File Structure

- `migrator.php`: Entry point of the script.
- `CustomPrettyPrinter.php`: Handles formatting of the transformed PHP code.
- `PhpUnitAnnotationTransformer.php`: Contains logic to transform PHPUnit annotations into attributes.
- `vendor/`: Contains dependencies installed via Composer.

### Adding New Features

To add new transformation capabilities (e.g., for other frameworks or annotations), extend `PhpUnitAnnotationTransformer.php`.

### Running Tests

If the repository includes unit tests for the script, you can run them using PHPUnit:

```shell script
vendor/bin/phpunit
```

## License

This project is open-source and available under the [MIT License](LICENSE).

## Contributions

Contributions are welcome! Feel free to fork this repository, make improvements, and submit a pull request.

---

Feel free to reach out if you experience issues or wish to enhance this script further.
