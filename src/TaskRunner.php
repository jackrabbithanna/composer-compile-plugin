<?php
namespace Civi\CompilePlugin;

use Civi\CompilePlugin\Event\CompileEvents;
use Civi\CompilePlugin\Event\CompileTaskEvent;
use Civi\CompilePlugin\Exception\TaskFailedException;
use Civi\CompilePlugin\Util\TaskUIHelper;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

class TaskRunner
{

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * TaskRunner constructor.
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     */
    public function __construct(
        \Composer\Composer $composer,
        \Composer\IO\IOInterface $io
    ) {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Run the items in the $taskList, as per policy.
     *
     * @param \Civi\CompilePlugin\TaskList $taskList
     */
    public function runDefault(TaskList $taskList, $isDryRun = false)
    {
        $allowedTasks = $taskList->getByFilters($this->getWhitelistRules());
        $blockedTasks = array_filter($taskList->getAll(), function ($task) use ($allowedTasks) {
            return !isset($allowedTasks[$task->id]);
        });

        $mode = $this->getMode();
        if ($mode === 'prompt' && empty($blockedTasks)) {
            // If whitelist covers all extant tasks, then no need for user-interaction.
            $mode = 'all';
        }

        switch ($mode) {
            case 'all':
                $this->run($taskList->getAll(), $isDryRun);
                break;

            case 'none':
                $this->io->write(
                    "<error>ERROR</error>: Automatic compilation is disabled. These packages have compilation tasks which have not been executed:"
                );
                $this->io->write(TaskUIHelper::formatTaskSummary($taskList->getAll()));
                $this->io->writeError(sprintf("<error>Skipped %d compilation task(s)</error>", count($taskList->getAll())));
                $this->io->writeError("<error>You may run skipped tasks with \"composer compile --all\"</error>");
                break;

            case 'whitelist':
                $disabledCount = $taskList->disable($blockedTasks);
                $this->run($taskList->getAll(), $isDryRun);
                if ($disabledCount) {
                    $this->io->writeError(sprintf('<error>WARNING</error>: %d task(s) were omitted due to whitelist policy', $disabledCount));
                }
                $this->io->writeError("<error>You may run skipped tasks with \"composer compile --all\"</error>");
                break;

            case 'prompt':
                $choice = $this->askApproveTasks($blockedTasks);
                if ($choice === 'a') {
                    $this->addWhitelistRules(array_map(function ($task) {
                        return $task->packageName;
                    }, $blockedTasks));
                }

                switch ($choice) {
                    case 'y':
                    case 'a':
                        $this->run($taskList->getAll(), $isDryRun);
                        break;

                    case 'n':
                        $this->io->writeError(sprintf("<error>Skipped %d compilation task(s)</error>", count($blockedTasks)));
                        $this->io->writeError("<error>You may run skipped tasks with \"composer compile --all\"</error>");
                        break;
                }
        }
    }

    /**
     * Execute a list of compilation tasks.
     *
     * @param Task[] $tasks
     * @param bool $isDryRun
     */
    public function run(array $tasks, $isDryRun = false)
    {
        /** @var IOInterface $io */
        $io = $this->io;

        $dryRunText = $isDryRun ? '<error>(DRY-RUN)</error> ' : '';

        $tasks = $this->sortTasks($tasks);
        foreach ($tasks as $task) {
            /** @var \Civi\CompilePlugin\Task $task */

            $package = ($this->composer->getPackage()->getName() === $task->packageName)
              ? $this->composer->getPackage()
              : $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($task->packageName, '*');

            $event = new CompileTaskEvent(CompileEvents::PRE_COMPILE_TASK, $this->composer, $this->io, $package, $task, $isDryRun);
            $dispatcher = $this->composer->getEventDispatcher();
            $dispatcher->dispatch(CompileEvents::PRE_COMPILE_TASK, $event);

            if (!$task->active) {
                $io->write(
                    $dryRunText . '<error>Skip</error>: ' . ($task->title),
                    true,
                    IOInterface::VERBOSE
                );
                continue;
            }

            $io->write($dryRunText . '<info>Compile</info>: ' . ($task->title));

            if (!$isDryRun) {
                $this->runTask($task, $package);
            }

            $event = new CompileTaskEvent(CompileEvents::POST_COMPILE_TASK, $this->composer, $this->io, $package, $task, $isDryRun);
            $this->composer->getEventDispatcher()->dispatch(CompileEvents::POST_COMPILE_TASK, $event);
        }
    }

    protected function runTask(Task $task, PackageInterface $package)
    {
        $orig = [
            'pwd' => getcwd(),
        ];

        try {
            chdir($task->pwd);
            $isDryRun = false;
            $e = new CompileTaskEvent(null, $this->composer, $this->io, $package, $task, $isDryRun);
            call_user_func($task->callback, $e);
        } finally {
            chdir($orig['pwd']);
        }
    }

    /**
     * @param Task[] $tasks
     * @return Task[]
     */
    public function sortTasks($tasks)
    {
        usort($tasks, function ($a, $b) {
            $fields = ['weight', 'packageWeight', 'naturalWeight'];
            foreach ($fields as $field) {
                if ($a->{$field} > $b->{$field}) {
                    return 1;
                } elseif ($a->{$field} < $b->{$field}) {
                    return -1;
                }
            }
            return 0;
        });
        return $tasks;
    }

    /**
     * Determine whether compilation is enabled.
     *
     * @return string
     *   One of:
     *   - 'all': Automatically run all compilation tasks
     *   - 'whitelist': Automatically compile anything on the whitelist, and
     *     reject everything else.
     *   - 'prompt': Automatically compile anything on the whitelist, and
     *     prompt for everything else.
     *   - 'none': Do not compile automatically.
     */
    public function getMode()
    {
        $aliases = [
            '0' => 'none',
            '1' => 'all',
            'off' => 'none',
            'on' => 'all',
        ];

        $mode = getenv('COMPOSER_COMPILE');

        if ($mode === '' || $mode === false || $mode === null) {
            $extra = $this->composer->getPackage()->getExtra();
            $mode = $extra['compile-mode'] ?? '';
        }

        if ($mode === '' || $mode === false || $mode === null) {
            $mode = 'prompt';
        }

        $mode = strtolower($mode);
        if (isset($aliases[$mode])) {
            $mode = $aliases[$mode];
        }

        $options = ['all', 'prompt', 'whitelist', 'none'];
        if (in_array($mode, $options)) {
            return $mode;
        } else {
            throw new \InvalidArgumentException(
                "The compilation policy (COMPOSER_COMPILE or extra.compile-mode) is invalid. Valid options are \"" . implode('", "', $options) . "\"."
            );
        }
    }

    /**
     * Get a list of packages that are trusted to do compilation.
     *
     * @return array
     *   Ex: ['foo/bar', 'civicrm/*']
     */
    protected function getWhitelistRules()
    {
        $rules = $this->composer->getPackage()->getExtra()['compile-whitelist'] ?? [];

        // The root package is an ex-officio member of the whitelist.
        $root = $this->composer->getPackage();
        if (!in_array($root->getName(), $rules)) {
            $rules[] = $root->getName();
        }

        return $rules;
    }

    /**
     * Update list of packages that are trusted to do compilation.
     *
     * @param array $newRules
     *   Ex: ['foo/bar', 'civicrm/*']
     */
    protected function addWhitelistRules($newRules)
    {
        $oldRules = $this->composer->getPackage()->getExtra()['compile-whitelist'] ?? [];
        $rules = array_unique(array_merge($oldRules, $newRules));
        sort($rules);
        $this->composer->getConfig()->getConfigSource()->addProperty('extra.compile-whitelist', $rules);
    }

    /**
     * @param Task[] $blockedTasks
     *   List of tasks that we cannot currently run.
     * @return string
     *   Returns 'y' or 'n' or 'a'
     * @throws \Exception
     */
    protected function askApproveTasks($blockedTasks)
    {
        if (!$this->io->isInteractive()) {
            throw new \Exception(
                "Cannot prompt for compilation preferences. Please update COMPOSER_COMPILE, extra.compile-mode, or extra.compile-whitelist."
            );
        }

        $blockedTasks = $this->sortTasks($blockedTasks);

        $choice = null;
        do {
            if ($choice === null) {
                $this->io->write("");
                $this->io->write(sprintf("The following packages have new compilation tasks:"));
                $this->io->write(TaskUIHelper::formatTaskSummary($blockedTasks));
            }

            $actions = implode(', ', [
                '[<comment>y</comment>]es',
                '[<comment>a</comment>]lways',
                '[<comment>n</comment>]o',
                '[<comment>l</comment>]ist',
                '[<comment>h</comment>]elp'
            ]);
            $choice = $this->io->askAndValidate(
                '<info>Allow these packages to compile?</info> (' . $actions . ') ',
                function ($x) {
                    $x = strtolower($x);
                    return in_array($x, ['y', 'n', 'a', 'h', 'l']) ? $x : null;
                }
            );
            if ($choice === 'h') {
                $this->io->write("\n" . file_get_contents(__DIR__ . '/messages/prompt-help.txt'));
            }
            if ($choice === 'l') {
                $this->io->write(TaskUIHelper::formatTaskTable($blockedTasks, ['packageName', 'title', 'action']));
            }
        } while (!in_array($choice, ['y', 'n', 'a']));

        return $choice;
    }
}
