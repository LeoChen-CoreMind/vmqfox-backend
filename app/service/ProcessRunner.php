<?php

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

class ProcessRunner
{
    public function isAvailable(): bool
    {
        $disabledFunctions = array_filter(array_map(
            'trim',
            explode(',', (string) ini_get('disable_functions'))
        ));

        return function_exists('proc_open')
            && !in_array('proc_open', $disabledFunctions, true);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool}
     */
    public function run(array $command, int $timeoutSeconds): array
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException(
                'proc_open is unavailable or disabled by the PHP disable_functions configuration.'
            );
        }
        if ($command === []) {
            throw new InvalidArgumentException('The command must contain an executable.');
        }
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('The command timeout must be at least one second.');
        }

        $stdoutStream = tmpfile();
        $stderrStream = tmpfile();
        if ($stdoutStream === false || $stderrStream === false) {
            throw new RuntimeException('Unable to create temporary process output files.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $stdoutStream,
            2 => $stderrStream,
        ];
        $pipes = [];
        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            fclose($stdoutStream);
            fclose($stderrStream);
            throw new RuntimeException('Unable to start the requested process.');
        }

        fclose($pipes[0]);
        $timedOut = false;
        $exitCode = -1;
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                usleep(100000);
                $terminatedStatus = proc_get_status($process);
                if ($terminatedStatus['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }

            usleep(10000);
        }

        $closeExitCode = proc_close($process);
        if ($exitCode === -1 && $closeExitCode !== -1) {
            $exitCode = $closeExitCode;
        }

        rewind($stdoutStream);
        rewind($stderrStream);
        $stdout = (string) stream_get_contents($stdoutStream);
        $stderr = (string) stream_get_contents($stderrStream);
        fclose($stdoutStream);
        fclose($stderrStream);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
        ];
    }
}
