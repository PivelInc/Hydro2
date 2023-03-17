<?php

namespace Pivel\Hydro2\Services;

use Pivel\Hydro2\Services\Utilities;

class Autoloader
{
    protected string $base_dir;

    public function __construct(?string $base_dir=null)
    {
        $this->base_dir = rtrim($base_dir??$_SERVER["DOCUMENT_ROOT"], DIRECTORY_SEPARATOR);
        
        // Manually require Utilities.php because we don't have a working autoloader yet
        require_once $base_dir."/Pivel/Hydro2/Services/Utilities.php";
    }

    public function register()
    {
        spl_autoload_register(array($this, 'loadClass'));
    }

    public function loadClass($class)
    {
        $class_parts = explode("\\", $class);

        if (count($class_parts) < 3) {
            // Hydro2 package namespaces must have at least [vendor]\[package]\[class], (2x '\' or 3x parts minimum).
            return false;
        }

        // Only load the class if the package has all its dependencies installed.
        $vendor_name = explode("\\", $class)[0];
        $pkg_name = explode("\\", $class)[1];

        if (!isset(Utilities::getPackageManifest()[$vendor_name][$pkg_name])) {
            echo "Couldn't load class \"{$class}\" because package \"{$pkg_name}\" is either missing dependencies or has an invalid manifest.<br />\n";
            return false;
        }
        
        $file = $this->base_dir . DIRECTORY_SEPARATOR . str_replace("\\", DIRECTORY_SEPARATOR, $class) . '.php';

        // if the mapped file exists, require it
        if ($this->requireFile($file)) {
            // yes, we're done
            return $file;
        }

        // no matching file.
        return false;
    }

    protected function requireFile($file)
    {
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
        return false;
    }
}