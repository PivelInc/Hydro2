<?php

namespace Package\Pivel\Hydro2\Core;

use \Package\Pivel\Hydro2\Core\Utilities;

// Manually require Utilities.php because we don't have a working autoloader yet
require_once $_SERVER["DOCUMENT_ROOT"]."/pkg/Pivel/Hydro2/Core/Utilities.php";

class Autoloader
{
    
    protected $prefixes = array();

    public function register()
    {
        spl_autoload_register(array($this, 'loadClass'));
    }

    public function addNamespace($prefix, $base_dir, $prepend = false)
    {
        // normalize namespace prefix
        $prefix = trim($prefix, '\\') . '\\';

        // normalize the base directory with a trailing separator
        $base_dir = rtrim($base_dir, DIRECTORY_SEPARATOR) . '/';

        // initialize the namespace prefix array
        if (isset($this->prefixes[$prefix]) === false) {
            $this->prefixes[$prefix] = array();
        }

        // retain the base directory for the namespace prefix
        if ($prepend) {
            array_unshift($this->prefixes[$prefix], $base_dir);
        } else {
            array_push($this->prefixes[$prefix], $base_dir);
        }
    }

    public function loadClass($class)
    {
        // the current namespace prefix
        $prefix = $class;

        // work backwards through the namespace names of the fully-qualified
        // class name to find a mapped file name
        while (false !== $pos = strrpos($prefix, '\\')) {

            // retain the trailing namespace separator in the prefix
            $prefix = substr($class, 0, $pos + 1);

            // the rest is the relative class name
            $relative_class = substr($class, $pos + 1);

            // try to load a mapped file for the prefix and relative class
            $mapped_file = $this->loadMappedFile($prefix, $relative_class);
            if ($mapped_file) {
                return $mapped_file;
            }

            // remove the trailing namespace separator for the next iteration
            // of strrpos()
            $prefix = rtrim($prefix, '\\');
        }

        // never found a mapped file
        return false;
    }

    protected function loadMappedFile($prefix, $relative_class)
    {
        // are there any base directories for this namespace prefix?
        if (isset($this->prefixes[$prefix]) === false) {
            return false;
        }

        $vendor_name = explode("\\", $relative_class)[0];
        $pkg_name = explode("\\", $relative_class)[1];

        if (!isset(Utilities::getPackageManifest()[$vendor_name][$pkg_name])) {
            echo "Couldn't load class \"{$prefix}{$relative_class}\" because package \"{$pkg_name}\" is either missing or has missing dependencies.<br />\n";
            return false;
        }

        // look through base directories for this namespace prefix
        foreach ($this->prefixes[$prefix] as $base_dir) {
            // replace the namespace prefix with the base directory,
            // replace namespace separators with directory separators
            // in the relative class name, append with .php
            $file = $base_dir
                  . str_replace('\\', '/', $relative_class)
                  . '.php';

            // if the mapped file exists, require it
            if ($this->requireFile($file)) {
                // yes, we're done
                return $file;
            }
            
            // Also check for a class in a directory with the same name
            $matches = [];
            if (preg_match('/[^\\\\]*$/', $relative_class, $matches))
            {
                $file = $base_dir
                  . str_replace('\\', '/', $relative_class)
                  . '/'
                  . $matches[0]
                  . '.php';
                
                if ($this->requireFile($file)) {
                    return $file;
                }
            }
        }

        // never found it
        return false;
    }

    protected function requireFile($file)
    {
        if (file_exists($file)) {
            require $file;
            return true;
        }
        return false;
    }
}