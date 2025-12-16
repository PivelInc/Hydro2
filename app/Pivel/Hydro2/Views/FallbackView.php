<?php

namespace Pivel\Hydro2\Views;

use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Services\PackageManifestService;

class FallbackView extends BaseWebView
{
    public function __construct(
        PackageManifestService $packageManifestService,
        protected ?string $CoreVersion=null,
    ) {
        $v = $packageManifestService->GetPackageManifest()['Pivel']['Hydro2']['version'];
        $this->CoreVersion = join('.', $v);
    }
}