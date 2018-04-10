<?php
declare(strict_types=1);

namespace insolita\Scanner\Lib;

use insolita\Scanner\Exceptions\InvalidConfigException;
use Symfony\Component\Finder\Finder;

final class Runner
{
    const SUCCESS_CODE = 0;
    const GENERAL_ERROR_CODE = 1;
    const ARGUMENT_ERROR_CODE = 2;
    const CONFIG_ERROR_CODE = 4;
    const SCANNING_ERROR_CODE = 8;
    const HAS_UNUSED_CODE = 16;
    
    /**
     * @var string
     */
    private $configFile;
    
    /**
     * @var bool
     */
    private $silentMode;
    
    public function __construct(string $configFile, bool $silentMode)
    {
        $this->configFile = $configFile;
        $this->silentMode = $silentMode;
    }
    
    public function run()
    {
        try {
            $config = $this->makeConfig();
            $this->output(' - config prepared' . PHP_EOL);
            $map = $this->makeDependencyMap($config);
            $this->output(' - search patterns prepared' . PHP_EOL);
        } catch (\Throwable $e) {
            echo 'Error! ' . $e->getMessage() . PHP_EOL;
            echo $e->getTraceAsString() . PHP_EOL;
            $exitCode = $e instanceof InvalidConfigException ? self::CONFIG_ERROR_CODE : self::GENERAL_ERROR_CODE;
            return $exitCode;
        }
        try {
            $scanResult = (new Scanner($map, $config, new Finder(), [$this, 'onNextDirectory'], [$this, 'onProgress']))
                ->scan();
        } catch (\Throwable $e) {
            echo 'Error! ' . $e->getMessage() . PHP_EOL;
            echo $e->getTraceAsString() . PHP_EOL;
            return self::SCANNING_ERROR_CODE;
        }
        
        return $this->scanReport($map, $scanResult);
    }
    
    public function onNextDirectory(string $directory)
    {
        $this->output(PHP_EOL . ' - Scan ' . $directory . PHP_EOL);
    }
    
    public function onProgress(int $done, int $total)
    {
        $width = 60;
        $percentage = round(($done * 100) / ($total <=0?1:$total));
        $bar = (int)round(($width * $percentage) / 100);
        $this->output(sprintf("%s%%[%s>%s]\r", $percentage, str_repeat("=", $bar), str_repeat(" ", $width - $bar)));
    }
    
    private function makeConfig(): Config
    {
        $params = require_once $this->configFile;
        return Config::create($params);
    }
    
    private function makeDependencyMap(Config $config): array
    {
        $dependencies = (new ComposerReader($config))->fetchDependencies();
        return (new DependencyNamespaceMap($config, $dependencies))->build();
    }
    
    private function output(string $message)
    {
        if ($this->silentMode === false) {
            echo $message;
        }
    }
    
    private function scanReport(array $map, array $scanResult):int
    {
        $result = array_values(array_diff(array_unique(array_values($map)), $scanResult));
        if (empty($result)) {
            $this->output(PHP_EOL . 'Unused dependencies not found!' . PHP_EOL);
            return self::SUCCESS_CODE;
        } else {
            $this->output(PHP_EOL . 'Unused dependencies found!' . PHP_EOL);
            array_walk($result, function ($packageName) {
                echo ' -' . $packageName . PHP_EOL;
            });
            return self::HAS_UNUSED_CODE;
        }
    }
}
