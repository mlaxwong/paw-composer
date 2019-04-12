<?php
namespace paw\composer;

use Exception;
use Throwable;
use Composer\Package\PackageInterface;

class InvalidPluginException extends Exception
{
    private $package;
    private $error;

    public function __construct(PackageInterface $package, $error = '', Throwable $previous = null)
    {
        $this->package = $package;
        $this->error = $error;
        parent::__construct("Couldn't install " . $package->getPrettyName() . ': ' . $error, 0, $previous);
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function getError()
    {
        return $this->error;
    }
}