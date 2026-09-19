<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Start everything the shop needs in development, in one terminal.
 *
 * Running the site by hand means three windows — the web server, the queue
 * worker that handles payment webhooks, and the scheduler that sweeps for
 * payments whose webhook never arrived. Forgetting the second one is the
 * classic "I paid but the order is still Pending" bug, so this starts all
 * three together and labels whose output is whose.
 */
class Dev extends Command
{
    protected $signature = 'dev
        {--host=127.0.0.1 : Address to serve on}
        {--port=8000 : Port to serve on}
        {--no-queue : Skip the queue worker}
        {--no-schedule : Skip the scheduler}';

    protected $description = 'Run the web server, queue worker and scheduler together';

    /** Set by the Ctrl+C handler; the loop below notices and shuts down. */
    protected bool $stopping = false;

    /** @var array<string, Process> */
    protected array $processes = [];

    /** Partial lines held back until their newline arrives. @var array<string, string> */
    protected array $buffers = [];

    public function handle(): int
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';

        $host = $this->option('host');
        $port = $this->option('port');

        if ($this->portIsTaken($host, (int) $port)) {
            $this->components->error("Something is already listening on {$host}:{$port}.");
            $this->line('  <fg=gray>A server left over from last time, most likely. Close it, or pass --port=8001.</>');

            return self::FAILURE;
        }

        $jobs = [
            'server' => [$php, 'artisan', 'serve', "--host={$host}", "--port={$port}"],
            'queue' => [$php, 'artisan', 'queue:work', '--tries=3'],
            'schedule' => [$php, 'artisan', 'schedule:work'],
        ];

        if ($this->option('no-queue')) {
            unset($jobs['queue']);
        }

        if ($this->option('no-schedule')) {
            unset($jobs['schedule']);
        }

        foreach ($jobs as $name => $command) {
            // No timeout: these are meant to run until you stop them.
            $process = new Process($command, base_path(), null, null, null);
            $process->start();

            $this->processes[$name] = $process;
            $this->buffers[$name] = '';
        }

        $this->banner($host, $port);
        $this->listenForCtrlC();

        return $this->pump();
    }

    /**
     * Relay the children's output, prefixed, until one stops or we are asked to.
     */
    protected function pump(): int
    {
        $finished = null;

        while (! $this->stopping) {
            foreach ($this->processes as $name => $process) {
                $this->relay($name, $process->getIncrementalOutput());
                $this->relay($name, $process->getIncrementalErrorOutput());

                if (! $process->isRunning()) {
                    $finished = $name;
                    break 2;
                }
            }

            usleep(120_000);
        }

        $this->shutdown($finished);

        return $finished === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Print whole lines only, so a prefix never lands mid-sentence.
     */
    protected function relay(string $name, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->buffers[$name] .= $chunk;

        while (($at = strpos($this->buffers[$name], "\n")) !== false) {
            $line = rtrim(substr($this->buffers[$name], 0, $at), "\r");
            $this->buffers[$name] = substr($this->buffers[$name], $at + 1);

            if (trim($line) !== '') {
                $this->line($this->tag($name).' '.$line);
            }
        }
    }

    protected function tag(string $name): string
    {
        $colour = ['server' => 'blue', 'queue' => 'magenta', 'schedule' => 'yellow'][$name] ?? 'gray';

        return sprintf('<fg=%s>%-9s</>', $colour, $name);
    }

    /**
     * Ctrl+C: put the flag up rather than dying here, so the children get
     * stopped properly instead of being orphaned.
     */
    protected function listenForCtrlC(): void
    {
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function () {
                $this->stopping = true;
            });

            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
        }
    }

    protected function shutdown(?string $finished): void
    {
        $this->newLine();

        if ($finished !== null) {
            $this->components->error(sprintf(
                '%s stopped on its own (exit %s) — shutting the rest down.',
                $finished,
                $this->processes[$finished]->getExitCode() ?? '?'
            ));
        }

        foreach ($this->processes as $name => $process) {
            $this->relay($name, $process->getIncrementalOutput());
            $this->relay($name, $process->getIncrementalErrorOutput());

            $this->stopTree($process);
        }

        // Anything left without a trailing newline.
        foreach ($this->buffers as $name => $rest) {
            if (trim($rest) !== '') {
                $this->line($this->tag($name).' '.rtrim($rest));
            }
        }

        $this->components->info('Stopped.');
    }

    /**
     * Stop a child and everything it started.
     *
     * `artisan serve` supervises a second process — the PHP built-in server —
     * and Symfony only knows about the first. Killing the supervisor alone
     * leaves that server holding the port, so on Windows walk the tree with
     * taskkill and elsewhere signal the whole process group.
     */
    protected function stopTree(Process $process): void
    {
        if (! $process->isRunning()) {
            return;
        }

        $pid = $process->getPid();

        if ($pid && PHP_OS_FAMILY === 'Windows') {
            (new Process(['taskkill', '/F', '/T', '/PID', (string) $pid]))->run();
        }

        if ($process->isRunning()) {
            $process->stop(5);
        }
    }

    /**
     * Is something already listening there?
     */
    protected function portIsTaken(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $code, $message, 0.4);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    protected function banner(string $host, string $port): void
    {
        $this->newLine();
        $this->line('  <fg=green;options=bold>'.config('app.name').'</> is running.');
        $this->newLine();

        $this->line(sprintf('  %s  <options=bold>http://%s:%s</>', $this->tag('server'), $host, $port));

        if (isset($this->processes['queue'])) {
            $this->line(sprintf('  %s  waiting for jobs — silent until one arrives', $this->tag('queue')));
        }

        if (isset($this->processes['schedule'])) {
            $this->line(sprintf('  %s  running cutluy:reconcile every five minutes', $this->tag('schedule')));
        }

        $this->newLine();
        $this->line('  <fg=gray>Press Ctrl+C to stop everything.</>');
        $this->newLine();
    }
}
