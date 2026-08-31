<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Cli;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs an allow-listed `bin/taw` command in the active theme directory and
 * captures its result. Uses `proc_open` with an argv array (no shell, no
 * interpolation) so a command/arg can't be turned into shell injection.
 *
 * Watch-outs carried from taw-hub's Part 6 notes:
 *  - CWD must be the theme root (where `bin/taw` lives).
 *  - The PHP binary is discovered (`PHP_BINARY`), never hard-coded.
 *  - Some `taw` commands boot WordPress (`inspect`, `fields:*`) → nested
 *    bootstrap. `sync` (the common case) deliberately does not. TODO: route
 *    the WP-booting commands through `WP_CLI::runcommand` when WP-CLI is loaded.
 *
 * @phpstan-type RunResult array{exit_code: int, stdout: string, stderr: string}
 */
final class TawRunner
{
    public function __construct(
        private CommandAllowlist $allowlist,
        private int $timeoutSeconds = 120,
    ) {
    }

    /**
     * @param list<string> $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(string $command, array $args = []): array
    {
        if ($command === '' || !$this->allowlist->allows($command)) {
            return $this->fail(126, "command not allowed: {$command}");
        }

        $themeDir = $this->themeDir();
        $binary   = $themeDir . '/bin/taw';
        if (!is_file($binary)) {
            return $this->fail(127, 'bin/taw not found in the active theme');
        }

        $argv = array_merge(
            [PHP_BINARY, $binary, $command],
            array_values(array_filter($args, 'is_string')),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($argv, $descriptors, $pipes, $themeDir);
        if (!is_resource($process)) {
            return $this->fail(1, 'could not start the taw process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $stderr .= "\n[taw-hub-companion] command timed out after {$this->timeoutSeconds}s";
                break;
            }
            usleep(50_000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout'    => $stdout,
            'stderr'    => $stderr,
        ];
    }

    private function themeDir(): string
    {
        if (function_exists('get_template_directory')) {
            return get_template_directory();
        }

        $cwd = getcwd();

        return $cwd !== false ? $cwd : '.';
    }

    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function fail(int $code, string $message): array
    {
        return ['exit_code' => $code, 'stdout' => '', 'stderr' => $message];
    }
}
