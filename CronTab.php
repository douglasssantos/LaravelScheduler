<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Finder\Finder;

class CronTab extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crontab
    {--S|status : View operational system crontab status.}
    {--L|list : List all commands in the scheduler.}
    {--A|add : Add a task to the scheduler.}
    {--R|remove : Add a task to the scheduler.}
    {--i|install : Install laravel scheduler in operating system crontab (only compatible with Linux).}
    {--s|start : start crontab from operational system.}
    {--p|stop : stop crontab from operational system.}
    {--r|restart : restart crontab from operational system.}';

    protected static $scheduleCommand = "";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run commands for the operational system crontab.';
    private mixed $instanceClassCommand = null;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->clear();
        if($this->option("install")){ $this->clear()->install(); }
        elseif($this->option("add")){ $this->clear()->add(); }
        elseif($this->option("list")){ $this->clear()->list(); }
        elseif($this->option("status")){ $this->clear()->status(); }
        elseif($this->option("start")){ $this->clear()->start(); }
        elseif($this->option("stop")){ $this->clear()->stop(); }
        elseif($this->option("restart")){ $this->clear()->restart(); }
        else{
          $choice = $this->choice("Which option do you want to perform?",
              ['install', 'status', 'add', 'list', 'start', 'stop', 'restart']);
            call_user_func([$this, $choice]);
        }
    }

    public function install()
    {
        $this->newLine();

        $this->comment("Installing the laravel schedule in the operational system cron.");

        $cronTabFile = file_get_contents("/etc/crontab");

        $cron = "*  *    * * *   root    cd ".base_path()." && php artisan schedule:run >> /dev/null 2>&1";

        if(str_contains($cronTabFile, $cron)) {
            $this->newLine();
            $this->error(" Could not install cron because it already exists and is active (running). ");
            $this->newLine();

            $this->status();

            return false;
        }

        $result = Process::run(sprintf("sudo echo -e '%s' >> /etc/crontab", $cron));

        $result->output();

        $this->newLine();
        $this->alert("Successfully active scheduler.");
        $this->newLine();

        if($this->status(true)) {

            $this->info("The operational system scheduler is running.");

        }else{

            $this->error("The operating system scheduler is stopped (dead).");

            $this->newLine();

            if($this->confirm("Do you want to start the operating system scheduler?")) {
                $this->start();
            }

        }

    }

    public function add()
    {
        $this->info(" Check if you added the scheduler properties to the created command ?");
        $this->newLine();
        $this->line(" <fg=red>Example:</>");
        $this->line(" <fg=yellow>protected</> <fg=magenta>\$scheduleCommand</> <fg=white>=</> <fg=green>\"migrate:fresh --seed\";</> <fg=red>(Required)</>");
        $this->line(" <fg=yellow>protected</> <fg=magenta>\$scheduleTimer</> <fg=white>=</> <fg=green>\"25 * 5 * 1\";</> <fg=cyan>(Optional)</>");

        if(!$this->confirm("was added?")) return;

        $paths = __DIR__;

        $paths = array_unique(Arr::wrap($paths));

        $paths = array_filter($paths, fn ($path) => is_dir($path));

        if (empty($paths)) return;

        $commands = [];

        foreach ((new Finder)->in($paths)->files() as $command) {
            $command = "App\\".str_replace( ['/', '.php'], ['\\', ''],
                    Str::after($command->getRealPath(), realpath(app_path()).DIRECTORY_SEPARATOR)
                );

            if (is_subclass_of($command, Command::class) && !(new ReflectionClass($command))->isAbstract()) {

                if(!empty($this->getClass($command)->command()))
                    $commands = array_merge($commands, [$command]);

            }
        }

        $commands = array_filter(array_filter($commands), fn($command) => !str_contains($command, "CronTab"));

        $commandChoice = $this->choice("Which command do you want to add to the scheduler?", $commands);

        $this->line("Selected command: <fg=green>{$commandChoice}</>");

        $commandSelected = $this->getClass($commandChoice);

        if(!empty($commandSelected->timer()) &&
            $this->confirm(
            "the command already has a scheduled time, do you want to use it in the scheduler?")) {

            $timer = explode(" ", $commandSelected->timer());
            $minutes = $timer[0];
            $hours = $timer[1];
            $days = $timer[2];
            $months = $timer[3];
            $week = $timer[4];

        }else{

            $minutes = $this->ask("Enter the minute (0 - 59) ?", "*");
            $hours = $this->ask("Enter the hour (0 - 23) ?", "*");
            $days = $this->ask("Enter the day of month (1 - 31) ?", "*");
            $months = $this->ask("Enter the month (1 - 12) ?", "*");
            $week = $this->ask("Enter the day of week? (0 - 6) ?", "*");

        }

        $addCron = sprintf("\$schedule->command(\"%s\")->cron(\"%s %s %s %s %s\");",
            $commandSelected->command(), $minutes, $hours, $days, $months, $week);


        $this->newLine();

        $checkParams = new Table($this->output);
        $checkParams->setHeaderTitle("Laravel Scheduler");
        $checkParams->setHeaders(['Command to be executed', 'minutes', 'hours', 'days', 'months', 'week']);
        $checkParams->setRows([["artisan:{$commandSelected->command()}", $minutes, $hours, $days, $months, $week]]);
        $checkParams->render();

        if(!$this->confirm("Is the scheduler information correct?"))
            return $this->clear()->add();

        $kernel = str_replace("Commands","Kernel.php", __DIR__);

        $getLine = array_values(array_filter(file($kernel), fn($line) => str_contains($line, $commandSelected->command())));
        $getLine = str_replace("\n", "", trim($getLine[0]));

        $updateFileKernel = str_replace($getLine, $addCron, file_get_contents($kernel));

        $openFileKernel = fopen($kernel, "w+");

        if(fwrite($openFileKernel, $updateFileKernel)){

            $this->info("Command added to scheduler successfully.");

        }else{

            $this->error(" Failed to add command in scheduler. ");

        }

        fclose($openFileKernel);

        Artisan::call("optimize:clear");
        Artisan::call("optimize" );

        $this->info(Artisan::output());

    }

    public function list()
    {
        $paths = __DIR__;

        $paths = array_unique(Arr::wrap($paths));

        $paths = array_filter($paths, fn ($path) => is_dir($path));

        if (empty($paths)) return;

        $commands = [];

        $kernel = str_replace("Commands","Kernel.php", __DIR__);
        $kernelContents = file_get_contents($kernel);

        foreach ((new Finder)->in($paths)->files() as $command) {
            $command = "App\\".str_replace( ['/', '.php'], ['\\', ''],
                    Str::after($command->getRealPath(), realpath(app_path()).DIRECTORY_SEPARATOR)
                );

            if (is_subclass_of($command, Command::class) && !(new ReflectionClass($command))->isAbstract()) {

                if(!empty($this->getClass($command)->command())) {

                    $isCommandAdded = str_contains($kernelContents, $this->getClass($command)->command());

                    if($isCommandAdded) {

                        $isRunning = $isCommandAdded ? "active" : "unactive";

                        $getLine = array_values(array_filter(file($kernel), fn($line) => str_contains($line, $this->getClass($command)->command())));
                        $getLine = str_replace("\n", "", trim($getLine[0]));
                        preg_match_all('/"(.*?)"/', $getLine, $match);

                        $timer = explode(" ", $match[1][1]);

                        $commands = array_merge($commands, [$match[1][0]], $timer, [$isRunning]);

                    }
                }

            }
        }

        $this->newLine();
        $checkParams = new Table($this->output);
        $checkParams->setHeaderTitle("List of Commands in Laravel Scheduler");
        $checkParams->setHeaders(['Command to be executed', 'minutes', 'hours', 'days', 'months', 'week', "status"]);

        if(count($commands) > 0) {
            $checkParams->setRows([$commands]);
        }else{
            $checkParams->setRows([[new TableCell('No command added to laravel scheduler', ['colspan' => 7])]]);
        }

        $checkParams->render();
        $this->newLine();


//        $getLine = array_values(array_filter(file($kernel), fn($line) => str_contains($line, $commandSelected->command())));
//        $getLine = str_replace("\n", "", trim($getLine[0]));
//        $updateFileKernel = str_replace($getLine, $addCron, file_get_contents($kernel));

//        $openFileKernel = fopen($kernel, "w+");
//
//        if(fwrite($openFileKernel, $updateFileKernel)){
//
//            $this->info("Command added to scheduler successfully.");
//
//        }else{
//
//            $this->error(" Failed to add command in scheduler. ");
//
//        }
//
//        fclose($openFileKernel);
//
//        Artisan::call("optimize:clear");
//        Artisan::call("optimize" );
//
//        $this->info(Artisan::output());


    }

    public function status($returnBoolean = false)
    {
//        $this->comment("Checking status crontab.");

        $result = Process::run('service cron status');

        $output = $result->output();

        if(str_contains($output, "Active: active (running)")){

            $this->info("Crontab Status: active (running).");
            $this->newLine();

            if($this->option("verbose")) $this->line($output);

            return true;

        }elseif(str_contains($output, "Active: inactive (dead)")){

            $this->error("Crontab Status: inactive (dead).");
            $this->newLine();

            if($this->option("verbose")) $this->line($output);

            return false;

        }

    }
    public function start()
    {
        $this->comment("Starting crontab.");

        $result = Process::run('service cron start');

        if($this->status(true)) {

            $this->info("The operating system scheduler started successfully.");

        }else{

            $this->error("Failed to initialize operating system scheduler.");

        }

        if($this->option("verbose")) $this->line($result->output());

    }
    public function stop()
    {
        $this->comment("stoping crontab.");

        $result = Process::run('service cron stop');

        if($this->status(true)) {

            $this->info("The operating system scheduler is stopped.");

        }else{

            $this->error("Failed to kill operating system scheduler.");

        }

        if($this->option("verbose")) $this->line($result->output());

    }
    public function restart()
    {
        $this->comment("Restarting crontab.");

        $result = Process::run('service cron restart');

        if($this->status(true)) {

            $this->info("The operating system scheduler has been restarted.");

        }else{

            $this->error("Failed to restart operating system scheduler.");

        }

        if($this->option("verbose")) $this->line($result->output());

    }

    public function getClass($namespace)
    {
        return eval('return new class extends '.$namespace.' {
                public function command(){
                    return ($this->scheduleCommand ?? null);
                }
                public function timer(){
                    return ($this->scheduleTimer ?? null);
                }
            };');

    }

    public function clear()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            system('cls');
        } else {
            system('clear');
        }

        return $this;
    }
}
