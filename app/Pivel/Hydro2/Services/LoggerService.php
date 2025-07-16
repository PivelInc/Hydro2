<?php

namespace Pivel\Hydro2\Services;

use DateTime;
use DateTimeZone;
use Pivel\Hydro2\Hydro2;

class LoggerService implements ILoggerService
{
    private string $logFilePath;
    private int $logFileSizeLimit;

    // fields: date time
    public function __construct()
    {
        $this->logFilePath = Hydro2::$Current->MainAppDir . DIRECTORY_SEPARATOR . '.logs';
        $this->logFileSizeLimit = 10*1024*1024; // 10 MB

        if (!is_dir($this->logFilePath)) {
            mkdir($this->logFilePath, recursive: true);
        }

        if (!file_exists($this->logFilePath . DIRECTORY_SEPARATOR . 'hydro2.log')) {
            $this->CreateLogFile();
        }
    }

    private function CreateLogFile()
    {
        $newLog = "#Version: 1.0\n";
        $newLog .= "#Software: Hydro2\n";
        $newLog .= "#Fields: date time type package message\n";
        file_put_contents($this->logFilePath . DIRECTORY_SEPARATOR . 'hydro2.log', $newLog);
    }

    private function AppendLine(array $fields)
    {
        $line = implode("\t", $fields) . "\n";
        file_put_contents($this->logFilePath . DIRECTORY_SEPARATOR . 'hydro2.log', $line, FILE_APPEND);
    }

    private function Log(string $type, string $package, string $message) : void
    {
        $now = new DateTime(timezone: new DateTimeZone('UTC'));
        $this->AppendLine([
            $now->format('Y-m-d'),
            $now->format('H:i:s.v'),
            $type,
            $package,
            $message,
        ]);

        if (filesize($this->logFilePath . DIRECTORY_SEPARATOR . 'hydro2.log') >= $this->logFileSizeLimit) {
            rename($this->logFilePath . DIRECTORY_SEPARATOR . 'hydro2.log', $this->logFilePath . DIRECTORY_SEPARATOR . "hydro2_{$now->getTimestamp()}.log");
            $this->CreateLogFile();
        }
    }

    public function Info(string $package, string $message) : void
    {
        $this->Log('INFO', $package, $message);
    }

    public function Warn(string $package, string $message) : void
    {
        $this->Log('WARN', $package, $message);
    }

    public function Error(string $package, string $message) : void
    {
        $this->Log('ERROR', $package, $message);
    }

    public function Debug(string $package, string $message) : void
    {
        $this->Log('DEBUG', $package, $message);
    }
}