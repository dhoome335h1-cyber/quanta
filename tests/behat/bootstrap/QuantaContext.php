<?php
declare(strict_types=1);

use Behat\Behat\Context\Context;
use Symfony\Component\Process\Process;

final class QuantaContext implements Context
{
    private string $root = '';
    private ?Process $server = null;
    private string $url = '';
    private string $body = '';
    private int $status = 0;

    /** @BeforeScenario */
    public function startSite(): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The acceptance suite requires ext-curl.');
        }
        if (extension_loaded('quanta_db')) {
            throw new RuntimeException('Run this suite without the optional quanta_db extension; see tests/behat/README.md.');
        }
        $this->root = sys_get_temp_dir() . '/quanta-behat-' . bin2hex(random_bytes(12));
        if (!mkdir($this->root, 0700)) {
            throw new RuntimeException('Cannot create the temporary acceptance site.');
        }
        file_put_contents($this->root . '/.quanta-behat', 'disposable');
        register_shutdown_function([$this, 'stopSite']);
        try {
            $repository = dirname(__DIR__, 3);
            foreach (['src', 'vendor'] as $directory) {
                if (!symlink($repository . '/' . $directory, $this->root . '/' . $directory)) {
                    throw new RuntimeException('Cannot link ' . $directory);
                }
            }
            $site = $this->root . '/sites/behat.test';
            foreach (['_modules', '_tpl', 'db/_languages', 'db/_translations', 'db/_users', 'db/_roles',
                'db/_statuses', 'db/_system', 'db/pages'] as $path) {
                mkdir($site . '/' . $path, 0700, true);
            }
            mkdir($this->root . '/static/tmp/behat.test', 0700, true);
            mkdir($this->root . '/sessions', 0700);
            file_put_contents($site . '/.env', '');
            file_put_contents($site . '/index.html', '<!doctype html><html><body><main>[RENDER]</main>'
                . '<div id="session-user">[USER_ATTRIBUTE|name=username]</div></body></html>');
            file_put_contents($site . '/db/pages/tpl^.html', '<h1>[TITLE]</h1><div>[BODY]</div>');
            foreach (['pages' => 'Pages', '_roles/anonymous' => 'Anonymous', '_roles/logged' => 'Logged',
                '_roles/admin' => 'Admin', '_system/403' => 'Forbidden', '_system/404' => 'Not found',
                '_statuses/node-status-published' => 'Published'] as $path => $title) {
                $this->seedDocument($site . '/db/' . $path, ['title' => $title]);
            }
            $this->seedDocument($site . '/db/pages/home', [
                'title' => 'Acceptance home',
                'body' => '<p>Welcome to the acceptance site</p>',
                'status' => 'node-status-published',
                'permissions' => ['node_view' => 'anonymous'],
            ]);
            $seed = $this->storage('seed');
            self::expect(($seed['seeded'] ?? false) === true, 'Administrator fixture was not created.');

            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($socket === false) {
                throw new RuntimeException('Cannot allocate a loopback port: ' . $error);
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $this->url = 'http://' . $address;
            $this->server = new Process([
                PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $this->root . '/sessions',
                '-S', $address, dirname(__DIR__) . '/support/router.php',
            ], $this->root, ['QUANTA_BEHAT_ROOT' => $this->root, 'PHP_CLI_SERVER_WORKERS' => false]);
            $this->server->setTimeout(null);
            $this->server->start();
            $deadline = microtime(true) + 10;
            do {
                if (!$this->server->isRunning()) {
                    throw new RuntimeException('Acceptance server failed: ' . $this->server->getErrorOutput());
                }
                $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    return;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Acceptance server did not start within 10 seconds.');
        } catch (Throwable $exception) {
            $this->stopSite();
            throw $exception;
        }
    }

    /** @AfterScenario */
    public function stopSite(): void
    {
        if ($this->server !== null) {
            $this->server->stop(1);
            $this->server = null;
        }
        if ($this->root !== '' && is_file($this->root . '/.quanta-behat')) {
            $this->removeTree($this->root);
        }
        $this->root = '';
    }

    /** @When I visit :path */
    public function visit(string $path): void
    {
        $this->request($path);
    }

    /** @When I log in as :username with password :password */
    public function login(string $username, string $password): void
    {
        $this->request('/home/', [
            'json' => json_encode([
                'action' => ['value' => ['login']],
                'username' => ['value' => [$username]],
                'password' => ['value' => [$password]],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::expect($this->status === 200, 'Login action failed with HTTP ' . $this->status);
        $result = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        self::expect(isset($result['redirect']), 'Login action did not return its normal response.');
    }

    /** @When I log out */
    public function logout(): void
    {
        $this->request('/home/', ['json' => json_encode(['action' => ['value' => ['logout']]])]);
        self::expect($this->status === 200, 'Logout action failed.');
    }

    /** @Then the current username is :username */
    public function usernameIs(string $username): void
    {
        $this->statusIs(200);
        $this->contains('<div id="session-user">' . $username . '</div>');
    }

    /** @Then the response status is :status */
    public function statusIs(int $status): void
    {
        self::expect($this->status === $status, 'Expected HTTP ' . $status . ', got ' . $this->status
            . "\n" . ($this->server?->getErrorOutput() ?? ''));
    }

    /** @Then the page contains :text */
    public function contains(string $text): void
    {
        self::expect(str_contains($this->body, $text), 'Response is missing: ' . $text . "\n" . $this->body);
    }

    /** @Then the page does not contain :text */
    public function doesNotContain(string $text): void
    {
        self::expect(!str_contains($this->body, $text), 'Response unexpectedly contains: ' . $text);
    }

    /** @Then the page has no unresolved Qtags */
    public function noUnresolvedTags(): void
    {
        self::expect(preg_match('/\[[A-Z][A-Z_]*(?:\||:|\])/', $this->body) === 0,
            'Response contains an unresolved Qtag: ' . $this->body);
    }

    /** @When I create a page :name titled :title */
    public function createPage(string $name, string $title): void
    {
        $this->storage('create', $name, $title);
    }

    /** @When I change the title of :name to :title */
    public function updatePage(string $name, string $title): void
    {
        $this->storage('update', $name, $title);
    }

    /** @Then loading :name returns title :title */
    public function storedTitleIs(string $name, string $title): void
    {
        $node = $this->storage('load', $name);
        self::expect(($node['exists'] ?? false) === true && ($node['title'] ?? null) === $title,
            'A fresh process did not load the expected node: ' . json_encode($node));
    }

    /** @When I delete the page :name */
    public function deletePage(string $name): void
    {
        $this->storage('delete', $name);
    }

    /** @Then the page :name does not exist in storage */
    public function pageDoesNotExist(string $name): void
    {
        $node = $this->storage('load', $name);
        self::expect(($node['exists'] ?? null) === false, 'Deleted node still loads.');
    }

    private function request(string $path, ?array $post = null): void
    {
        self::expect(str_starts_with($path, '/') && !str_starts_with($path, '//'), 'Use a local path.');
        $curl = curl_init($this->url . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Host: behat.test'],
            CURLOPT_COOKIEFILE => $this->root . '/cookies.txt',
            CURLOPT_COOKIEJAR => $this->root . '/cookies.txt',
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROXY => '',
        ]);
        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $this->status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // Flush cookies now; the next request uses a new cURL handle.
        curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
        unset($curl);
        self::expect($body !== false, 'HTTP request failed: ' . $error);
        $this->body = $body;
    }

    private function storage(string ...$arguments): array
    {
        $process = new Process(array_merge([
            PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/support/site.php', $this->root,
        ], $arguments), $this->root);
        $process->setTimeout(30);
        $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedDocument(string $directory, array $document): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory . '/data.json', json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function removeTree(string $path): void
    {
        // Never follow src/vendor symlinks back into the actual checkout.
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (new FilesystemIterator($path) as $entry) {
            $this->removeTree($entry->getPathname());
        }
        rmdir($path);
    }

    private static function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
