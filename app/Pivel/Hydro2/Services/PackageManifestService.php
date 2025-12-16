<?php

namespace Pivel\Hydro2\Services;

use DirectoryIterator;
use Pivel\Hydro2\Hydro2;

class PackageManifestService
{
    private Hydro2 $_app;
    private ?array $pkg_manifest = null;

    private array $pkgDirs = [];

    public function __construct(Hydro2 $app)
    {
        $this->_app = $app;
        $this->pkgDirs = array_merge([$this->_app->MainAppDir], $this->_app->AdditionalAppDirs);
    }

    public function GetPackageManifest() : array {
        if ($this->pkg_manifest === null) {
            $this->pkg_manifest = [];
        
            foreach ($this->pkgDirs as $searchDir) {
                $dir = new DirectoryIterator($searchDir);
                foreach ($dir as $vendorDir) {
                    if ($vendorDir->isDot()) {
                        continue;
                    }
                    if (!$vendorDir->isDir()) {
                        continue;
                    }

                    $this->pkg_manifest[$vendorDir->getFilename()] ??= [];
                    $vdir = new DirectoryIterator($vendorDir->getPath().'/'.$vendorDir->getFilename());
                    foreach ($vdir as $fileinfo) {
                        if ($fileinfo->isDot()) {
                            continue;
                        }
                        if (!$fileinfo->isDir()) {
                            continue;
                        }
                    
                        if (!file_exists($fileinfo->getPath().'/'.$fileinfo->getFilename()."/manifest.json")) {
                            continue;
                        }
                    
                        if (!is_file($fileinfo->getPath().'/'.$fileinfo->getFilename()."/manifest.json")) {
                            continue;
                        }
                    
                        $manifest = json_decode(file_get_contents($fileinfo->getPath().'/'.$fileinfo->getFilename()."/manifest.json"), true);
    
                        $this->pkg_manifest[$vendorDir->getFilename()][$fileinfo->getFilename()] = $manifest;
                        $this->pkg_manifest[$vendorDir->getFilename()][$fileinfo->getFilename()]["has_dependent"] = false;
                    }
                }
            }

            // Prevent CCMSIndex from being uninstalled
            //self::$pkg_manifest["CCMSIndex"]["dependencies"]["has_dependent"] = true;
            // Check dependencies
            $missing_dependencies = false;
            do {
                foreach ($this->pkg_manifest as $vendor_name => $vendor_pkgs) {
                    foreach ($vendor_pkgs as $pkg_name => $pkg_info) {
                        //echo $module_name . '<br />';
                        //var_dump($module_info['dependencies']);
                        $dependencies = $pkg_info["dependencies"];
                        if (count($dependencies) === 0) {
                            $missing_dependencies = false;
                            continue;
                        }

                        foreach ($dependencies as $index => $dependency) {
                            if (!isset($this->pkg_manifest[$dependency["vendor"]][$dependency["name"]])) {
                                $missing_dependencies = true;
                                break;
                            }

                            $this->pkg_manifest[$dependency["vendor"]][$dependency["name"]]["has_dependent"] = true;

                            $minVer = $dependency["min_version"];
                            $depVer = $this->pkg_manifest[$dependency["vendor"]][$dependency["name"]]["version"];

                            $cmp = 8 * ($depVer[0] <=> $minVer[0]);
                            $cmp += 4 * ($depVer[1] <=> $minVer[1]);
                            $cmp += 2 * ($depVer[2] <=> $minVer[2]);
                            $cmp += 1 * ($depVer[3] <=> $minVer[3]);

                            $minVerStr = implode(".", $minVer);
                            $depVerStr = implode(".", $depVer);

                            if ($cmp < 0) {
                                $missing_dependencies = true;
                                break;
                            }
                            
                            $missing_dependencies = false;
                        }

                        if ($missing_dependencies) {
                            unset($this->pkg_manifest[$pkg_name]);
                            break;
                        }
                    }
                }
            } while ($missing_dependencies);

        }

        return $this->pkg_manifest;
    }
}