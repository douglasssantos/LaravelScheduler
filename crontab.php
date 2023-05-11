<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;

class crontab extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crontab
    {--S|status : View operational system crontab status.}
    {--L|list : List all commands in the scheduler.}
    {--A|add : Add a command to the scheduler.}
    {--R|remove : Remove a command to the scheduler.}
    {--enable : Enable a command in the scheduler.}
    {--disable : Disable a command in the scheduler.}
    {--all : selected all a command in the scheduler.}
    {--repair : repair possible errors in the crontab file.}
    {--reset : Clean up crontab file and remove all commands.}
    {--i|install : Install laravel scheduler in operational system crontab (only compatible with Linux).}
    {--u|uninstall : remove the scheduler in the operational system crontab (only compatible with Linux).}
    {--s|start : start crontab from operational system.}
    {--p|stop : stop crontab from operational system.}
    {--r|restart : restart crontab from operational system.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run commands for the operational system crontab.';

    protected static $user = '$USER:$USER';
    protected static $path = "crontabs/";
    protected static $filename = "schedule.cron";

    public function __construct()
    {
        parent::__construct();

        self::$path = storage_path(self::$path);
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->clear()->repair();
        if($this->option("install")){ $this->clear()->install(); }
        elseif($this->option("uninstall")){ $this->clear()->uninstall(); }
        elseif($this->option("list")){ $this->clear()->list(); }
        elseif($this->option("add")){ $this->clear()->add(); }
        elseif($this->option("remove")){ $this->clear()->remove(); }
        elseif($this->option("enable")){ $this->clear()->enable(); }
        elseif($this->option("disable")){ $this->clear()->disable(); }
        elseif($this->option("repair")){ $this->clear()->repair(); }
        elseif($this->option("reset")){ $this->clear()->reset(); }
        elseif($this->option("status")){ $this->clear()->status(); }
        elseif($this->option("start")){ $this->clear()->start(); }
        elseif($this->option("stop")){ $this->clear()->stop(); }
        elseif($this->option("restart")){ $this->clear()->restart(); }
        else{
            $this->newLine();
            $choice = $this->choice("Which option do you want to perform?",
                [
                    'list', 'add', 'remove', 'enable', 'disable',
                    '<fg=blue>status</>', '<fg=green>start</>', '<fg=red>stop</>',
                    '<fg=magenta>restart</>', '<fg=bright-cyan>repair</>', '<bg=red> reset </>',
                    '<fg=default;bg=green> install </>', '<bg=bright-red> uninstall </>', "<fg=yellow>exit</>"
                ], 0, 3);

            $choice = str_replace([
                " ", "blue", "green", "red",
                "magenta", "bright-cyan", "default", "yellow", "<fg=>", "<bg=>","</>"
            ], "", $choice);

            call_user_func([$this, $choice]);
        }
    }

    public static function storage()
    {
        // Make sure the storage path exists and writeable
        if (!is_writable(self::$path)) {

            if(!is_dir(self::$path)) {
                Process::run(sprintf("sudo mkdir %s && sudo chown %s %s/",
                        self::$path, self::$user, self::$path));

                (new crontab())->log()->info("Created Crontabs folder successfully");
            }

            if(!is_dir(self::$path)){
                (new crontab())->log()->error("Failed to create Crontabs folder");
                return new \Exception("Failed to create Crontabs folder");
            }

        }

        return Storage::createLocalDriver(["root" => self::$path]);
    }

    public static function init(Schedule $schedule)
    {

        $storage = self::storage();

        if(!$storage->fileExists(self::$filename)){

            $storage->put(self::$filename, "");

        }

        $crons = file(self::$path.self::$filename);

        foreach($crons as $cron){

            $cron = str_replace("\n", "", $cron);

            $cronParams = explode(" | ", $cron);

            if((bool)$cronParams[2]) {

                $prefixArtisan = "artisan";

                if(str_contains($cronParams[1], $prefixArtisan)) {
                    $cronParams[1] = strstr($cronParams[1], $prefixArtisan);
                    $cronParams[1] = trim(str_replace($prefixArtisan, "", $cronParams[1]));
                }

                $schedule->command($cronParams[1])->cron($cronParams[0]);
            }

        }

    }

    public function getScheduledCommands(): bool|array
    {
        $storage = self::storage();

        if(!file_exists(self::$path.self::$filename)){

            $storage->put(self::$filename, "");
        }

        return file(self::$path.self::$filename);
    }

    public function parameterizeCommand()
    {
        $command = $this->ask("Enter the command ?");

        if(empty($command)) {
            $this->error("Null commands are not allowed, enter a correct command !");
            return $this->parameterizeCommand();
        }

        $minutes = $this->ask("Enter the minute (0 - 59) ?", "*");
        $hours = $this->ask("Enter the hour (0 - 23) ?", "*");
        $days = $this->ask("Enter the day of month (1 - 31) ?", "*");
        $months = $this->ask("Enter the month (1 - 12) ?", "*");
        $week = $this->ask("Enter the day of week? (0 - 6) ?", "*");

        $active = (string)$this->confirm("Do you want to activate the command now?", true);

        return "$minutes $hours $days $months $week | $command | " . ($active ? "true" : "false");

    }

    public function list($response = true)
    {
        $commands = [];

        foreach($this->getScheduledCommands() as $cron) {
            if(!empty($cron)) {

                $cron = str_replace("\n", "", $cron);

                $cronParams = explode(" | ", $cron);

                if ($response) {

                    $time = explode(" ", $cronParams[0]);

                    $next = "<fg=red>Stopped</>";

                    if ($cronParams[2] === "true") $next = "<fg=green>Running</>";

                    $commands[] = [$cronParams[1], $time[0], $time[1], $time[2], $time[3], $time[4], $next];

                } else {

                    $commands[] = [
                        'time' => $cronParams[0],
                        'command' => $cronParams[1],
                        'status' => $cronParams[2]
                    ];

                }
            }
        }

        if(!$response) return $commands;

        $this->newLine();
        $checkParams = new Table($this->output);
        $checkParams->setHeaderTitle("List of Commands in Laravel Scheduler");
        $checkParams->setHeaders(['Command to be executed', 'Minutes', 'Hours', 'Days', 'Months', 'Week', "Status"]);

        if(count($commands) > 0) {
            $checkParams->setRows($commands);
        }else{
            $checkParams->setRows([[new TableCell('No command added to laravel scheduler', ['colspan' => 7])]]);
        }

        $checkParams->render();
        $this->newLine();


        $this->log()->info("Listed Commands: ".json_encode($commands));

    }

    public function add()
    {
        $this->newLine();
        $this->info("<fg=default> Make sure you added the crontab::init(\$schedule); inside the kernel?\n </>");
        $this->info("<fg=red> Method needed in Kernel to work:</>\n <fg=blue>crontab</><fg=default>::</><fg=yellow>init<fg=default>(</><fg=magenta>\$schedule</><fg=default>)</>;</>");
        $this->newLine();

        if(!$this->confirm("was added?", true)) return;

        $params = $this->parameterizeCommand();

        self::storage()->append(self::$filename, $params);

        $this->log()->info("Command added : $params");

    }

    public function remove()
    {
        $storage = self::storage();

        if($this->option("all")) {
            if($this->confirm("Do you really want to remove all commands?")){
                $storage->put(self::$filename, "");
            }
            return;
        }

        $commands = [];
        foreach ($this->getScheduledCommands() as $cron){

            $cron = str_replace("\n", "", $cron);

            $cronParams = explode(" | ", $cron);

            $next = "<fg=red>Stopped</>";

            if($cronParams[2] === "true") $next = "<fg=green>Running</>";

            $commands[] = "Time: <fg=green>".$cronParams[0]."</> | Command: <fg=magenta>" . $cronParams[1]."</> | Status: ".$next;
        }

        $commandChoice = $this->choice("Which command do you want to remove from the scheduler ?: \n", $commands);

        $commandChoiceSelected = $commandChoice;

        $cleanChoice = str_replace(['Time: ', "Command: ", "Status: ", "green", "magenta", "red", "<fg=>", "</>"],
            "", $commandChoice);
        $cleanChoice = str_replace(["| Running", "| Stopped"], ["| true", "| false"], $cleanChoice);

        $crontabs = array_values(array_filter($this->getScheduledCommands(),
            fn($line) => !str_contains($line, $cleanChoice) && !empty(trim($line))
        ));

        if($this->confirm("<fg=default>Command Selected: \n\n {$commandChoiceSelected}\n\n Really want to delete the selected command ?</>")) {

            $this->info("Command removed: $commandChoiceSelected");
            $this->log()->info("Command removed successfully.");

            if($storage->put(self::$filename, join("", $crontabs))){
                $this->restart();
                $this->newLine(2);
                $this->alert("Updated command list");
                $this->log()->info("Updated command list.");
                $this->list();
            }
        }
    }

    public function enable()
    {
        $this->setStatus("activate");
    }
    public function disable()
    {
        $this->setStatus("unactivate");
    }

    public function setStatus($status)
    {
        $storage = self::storage();

        if($this->option("all")) {
            $commandEnabled = str_replace(
                ($status === "activate" ? "| false" : "| true"),
                ($status === "activate" ? "| true" : "| false"),
                $storage->get(self::$filename));

            $this->info("All commands {$status}d:");
            $this->log()->info("All commands {$status}d successfully.");

            if($storage->put(self::$filename, $commandEnabled)){
                $this->restart();
                $this->newLine(2);
                $this->alert("Updated command list");
                $this->log()->info("Updated command list.");
                $this->list();
            }

            return;
        }
        $statusCheck = ($status === "activate" ? "| false" : "| true");
        if(!str_contains($storage->get(self::$filename), $statusCheck)){
            $this->newLine();
            $this->comment("No command available to {$status}");
            $this->newLine();
            return;
        }

        $commands = [];
        foreach ($this->getScheduledCommands() as $cron){

            $cron = str_replace("\n", "", $cron);

            $cronParams = explode(" | ", $cron);

            $next = "<fg=red>Stopped</>";

            if($cronParams[2] === "true") $next = "<fg=green>Running</>";

            $commands[] = "Time: <fg=green>".$cronParams[0]."</> | Command: <fg=magenta>" . $cronParams[1]."</> | Status: ".$next;
        }
//        activate
        $commandChoice = $this->choice("Select the command you want to {$status}: \n", $commands);

        $commandChoiceSelected = $commandChoice;

        $cleanChoice = str_replace(['Time: ', "Command: ", "Status: ", "green", "magenta", "red", "<fg=>", "</>"],
            "", $commandChoice);
        $cleanChoice = str_replace(["| Running", "| Stopped"], ["| true", "| false"], $cleanChoice)."\n";

        $commandEnabled = str_replace($cleanChoice,
            str_replace(
                ($status === "activate" ?  "false" : "true"),
                ($status === "activate" ?  "true" : "false"),
                $cleanChoice),
            $storage->get(self::$filename));

        if($this->confirm("<fg=default>Command Selected: \n\n {$commandChoiceSelected}\n\n Really want to {$status} this command? ?</>")) {

            $this->info("Command {$status}d: $commandChoiceSelected");
            $this->log()->info("Command {$status}d successfully.");

            if($storage->put(self::$filename, $commandEnabled)){
                $this->restart();
                $this->newLine(2);
                $this->alert("Updated command list");
                $this->log()->info("Updated command list.");
                $this->list();
            }
        }
    }

    public function repair()
    {
        $crontabs = array_values(array_filter($this->getScheduledCommands(),
            fn($line) => !empty(trim($line))
        ));

        $crontabs = array_unique($crontabs);

        self::storage()->put(self::$filename, join("", $crontabs));

        $this->log()->info("The crontab file has been repaired.");
        if($this->option("verbose"))
        $this->info("The crontab file has been repaired.");
    }

    public function reset()
    {
        self::storage()->put(self::$filename, "");
        $this->info("The crontab file has been reset.");
        $this->log()->info("The crontab file has been reset.");

    }

    public function install()
    {
        $this->newLine();

        $isAlreadyInstalled = array_values(array_filter(file("/etc/crontab"),
            fn($line) => str_contains($line, "/artisan schedule:run >> /dev/null 2>&1")));

        if(count($isAlreadyInstalled) > 0 ) {

            $this->alert("The scheduler is already installed to the operational system cronjob.");
            $this->log()->info("The scheduler is already installed to the operational system cronjob.");

            $this->newLine();
            $this->line("<fg=white;bg=red> Attention: </></>\n<fg=yellow>Make sure you added the crontab method inside the kernel?</> ".
                "<fg=blue>crontab</><fg=default>::</><fg=yellow>init<fg=default>(</><fg=magenta>\$schedule</><fg=default>)</>;</> "
                ."\n<fg=yellow>must be added to the kernel inside the <fg=red>schedule()</> method,\n".
                "the kernel is located at:</>  <fg=green>/app/Console/Kernel.php</>");
            $this->newLine();
            return ;
        }

        $this->comment("Installing the laravel schedule in the operational system cron.");
        $this->log()->info("Installing the laravel schedule in the operational system cron.");


        $cronTabFile = file_get_contents("/etc/crontab");

        $cron = "*  *    * * *   root    php ".base_path()."/artisan schedule:run >> /dev/null 2>&1";
        $this->log()->info($cron);

        if(str_contains($cronTabFile, $cron)) {
            $this->newLine();
            $this->error(" Could not install cron because it already exists and is active (running). ");
            $this->log()->error("Could not install cron because it already exists and is active (running).");
            $this->newLine();

            $this->status();

            return false;
        }

        $result = Process::run(sprintf("sudo echo -e '%s' >> /etc/crontab", $cron));

        $result->output();

        $this->newLine();
        $this->alert("Successfully active scheduler.");
        $this->log()->info("Successfully active scheduler");
        $this->newLine();
        $this->line("<fg=white;bg=green> Successfully: </></>\n<fg=yellow>For the scheduler to work, add the method:</> ".
            "<fg=blue>crontab</><fg=default>::</><fg=yellow>init<fg=default>(</><fg=magenta>\$schedule</><fg=default>)</>;</> ".
            "\n<fg=yellow>to the kernel inside the <fg=red>schedule()</> method,\n".
            "the kernel is located at:</>  <fg=green>/app/Console/Kernel.php</>");

        if($this->status(true)) {

            $this->info("The operational system scheduler is running.");
            $this->log()->info("The operational system scheduler is running.");

        }else{

            $this->error("The operating system scheduler is stopped (dead).");
            $this->log()->error("The operating system scheduler is stopped (dead).");

            $this->newLine();

            if($this->confirm("Do you want to start the operating system scheduler?")) {
                $this->start();
            }

        }

    }

    public function uninstall()
    {
        $this->newLine();

        $isAlreadyInstalled = array_values(array_filter(file("/etc/crontab"),
            fn($line) => str_contains($line, "/artisan schedule:run >> /dev/null 2>&1")));

        if(count($isAlreadyInstalled) > 0 ) {

            if ($this->confirm("Do you really want to uninstall the scheduler?")) {

                $result = Process::run("sudo sed -i '/artisan schedule:run/d' /etc/crontab");
                if ($this->option("verbose")) $this->line($result->output());

                $this->restart();

                $this->info("scheduler successfully uninstalled.");
                $this->log()->warning("Scheduler successfully uninstalled.");

            }
        }else{
            if ($this->confirm("<fg=yellow>The laravel scheduler is not installed.</>\n\n Do you want to install it?")) {
                $this->install();
            }
        }
    }

    public function status()
    {
        $result = Process::run('service cron status');

        $output = $result->output();

        if(str_contains($output, "Active: active (running)")){

            $this->newLine();
            $this->info("<fg=default;bg=default> Crontab Status: </> active (running).");
            $this->log()->info("Crontab Status: active (running)");
            $this->newLine();

            if($this->option("verbose")) $this->line($output);

            return true;

        }elseif(str_contains($output, "Active: inactive (dead)")){

            $this->newLine();
            $this->line("<fg=default;bg=default> Crontab Status:</> <fg=red> inactive (dead).</>");
            $this->log()->alert("Crontab Status: inactive (dead)");
            $this->newLine();

            if($this->option("verbose")) $this->line($output);

            return false;

        }

    }

    public function start()
    {
        $this->newLine();
        $this->comment("Starting crontab.");
        $this->log()->info("Starting crontab.");

        $result = Process::run('service cron start');

        if($this->status(true)) {

            $this->info("The operating system scheduler started successfully.");
            $this->log()->info("The operating system scheduler started successfully.");

        }else{

            $this->error("Failed to initialize operating system scheduler.");
            $this->log()->error("Failed to initialize operating system scheduler.");

        }

        if($this->option("verbose")) $this->line($result->output());

    }

    public function stop()
    {
        $this->newLine();
        $this->comment("Stoping crontab.");
        $this->log()->info("Stoping crontab.");

        $result = Process::run('service cron stop');

        if($this->status(true)) {

            $this->info("The operating system scheduler is stopped.");
            $this->log()->info("The operating system scheduler is stopped.");

        }else{

            $this->error("Failed to kill operating system scheduler.");
            $this->log()->error("Failed to kill operating system scheduler.");

        }

        if($this->option("verbose")) $this->line($result->output());

    }

    public function restart()
    {
        $this->newLine();
        $this->comment("Restarting crontab.");
        $this->log()->info("Restarting crontab.");

        $result = Process::run('service cron restart');

        if($this->status(true)) {

            $this->info("The operating system scheduler has been restarted.");
            $this->log()->info("The operating system scheduler has been restarted.");

        }else{

            $this->log()->error("Failed to restart operating system scheduler.");
            $this->error("Failed to restart operating system scheduler.");

        }

        Artisan::call("optimize");

        if($this->option("verbose")){
            $this->line($result->output());
            $this->line(Artisan::output());
        }

    }

    public function exit()
    {
        $this->clear();
        return false;
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

    public function Log()
    {
        return Log::build([
            'driver' => 'single',
            'path' => storage_path('crontabs/schedule.log'),
        ]);
    }
}
