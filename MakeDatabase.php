<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use PDO;

class MakeDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:database {--seed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create databases configured in config';

    private $connections, $PDO, $driver, $host, $username, $password;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->connections = config('database.connections');

        // Creating all databases

        foreach($this->connections as $connectionname => $params){

            $this->createDatabase($connectionname, $params['database']);

        }

        $this->comment("");
        $this->comment("Running the migrations");

        Artisan::call("migrate:fresh", ['--seed' => $this->option('seed')] );

        $this->info(Artisan::output());     
        
        $this->info("Databases created and run migrations with success!");
        
    }

    public function setConfig($connection){

        $this->driver = $this->connections[$connection]['driver'];
        $this->host = $this->connections[$connection]['host'];
        $this->username = $this->connections[$connection]['username'];
        $this->password = $this->connections[$connection]['password'];

        return $this;
    }

    public function PDOInstance(){

        try {

            $this->PDO = new PDO($this->driver . ":host=" . $this->host, $this->username, $this->password);
            $this->PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        } catch(PDOException $e) {

            echo 'ERROR: ' . $e->getMessage();

        }
    }

    public function createDatabase($connection, $dbname){

        $this->setConfig($connection)->PDOInstance();
        
        $dbExists = $this->PDO->query(sprintf("SELECT * FROM pg_database WHERE datname='%s' ;", $dbname));

        if($dbExists->rowCount() > 0 )
            return $this->info(sprintf("%s database already exists", $dbname));

        $this->comment(sprintf("Creating the database: %s", $dbname));

        try {

            $statement = sprintf('CREATE DATABASE %s ;', $dbname);

            $pdo = $this->PDO->exec($statement);        
            
            return $this->info(sprintf("The %s database has been created", $dbname));

        } catch (PDOException $e) {

            $this->error(sprintf("failed to create database %s.", $dbname)); 

            return $this->error($e->getMessage());      

        }
        
    }
}