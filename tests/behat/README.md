# Core acceptance tests

From the repository root, with PHP 8.5+, Composer 2 and the documented Quanta
extensions (including curl and GD):

```sh
composer install --no-interaction --prefer-dist --no-scripts
composer test:behat
```

`--no-scripts` skips the existing application/site Git-hook installation; an
installed Quanta site is not required for this suite. Dependencies remain under
`require-dev`, and `composer.lock` follows the repository's existing ignore rule.

For one scenario, or a machine-readable report:

```sh
composer test:behat -- --name='Authenticate with the correct password'
composer test:behat -- --format=junit --out=/tmp/quanta-behat-report
```

The suite runs on Linux/macOS and requires permission to create symlinks,
start local PHP processes and bind an ephemeral loopback port. It uses the PHP
binary that runs Behat, including its normal PHP configuration. Select another
PHP configuration through `PHPRC`/`PHP_INI_SCAN_DIR` when necessary; those variables
are inherited by the subprocesses.

Run this suite **without the optional `quanta_db` extension**, using a PHP
configuration that does not load it. The harness refuses to run with that
extension in either the parent or child process so it cannot accidentally
connect to an existing application's shared database daemon. Extension coverage
is provided separately by `qdb/tests/run-tests.sh` and
`qdb/tests/run-quanta-tests.sh`.

## Coverage

Eight scenarios verify homepage rendering, successful login, incorrect password,
unknown user, logout, node creation/reload/rendering, title updates and deletion.
Login/logout submit the application's real JSON form actions through
`src/boot.php`. Requests preserve the session cookie; identity assertions use the
rendered `USER_ATTRIBUTE` Qtag on a subsequent HTTP request. Creating, changing,
loading and deleting nodes invoke Quanta's real APIs in separate PHP processes,
so stale in-process objects cannot satisfy persistence assertions.

The homepage deliberately starts with `[RENDER]`. This covers a cold-autoload
regression: `Render` formerly extended `QTag`, but the case-sensitive class map
contains `Qtag`. Previously seven scenarios failed with HTTP 500 on a cold page;
using the actual mapped parent name fixes it without changing the autoloader.

## Isolation and limits

Each scenario creates its own random, mode-0700 directory in the operating
system's temporary directory. Only `src/` and `vendor/` are symlinked from the
checkout. All content, class maps, sessions, cookies and generated files live in
the disposable directory. The fixture contains small deterministic documents
and creates an administrator through `UserFactory`; it does not run Doctor or
depend on demo media. The password in the feature file belongs only to this
throwaway administrator, never to an installed site.

The HTTP server binds only to `127.0.0.1`. After each scenario (including failures)
the server stops and the temporary directory is removed, without following
symlinks. A shutdown cleanup also handles normal process exits; SIGKILL or a
machine crash can leave a `quanta-behat-*` temporary directory behind.

These are real HTTP and filesystem acceptance tests, not JavaScript/browser UI
tests. They do not claim to cover Shadow's client-side interactions, Doctor
installation, or native database-extension behaviour. The independent
`behat.yml` suite can be extended with browser scenarios later.
